<?php

namespace App\Services\Trading;

use App\Models\Trade;
use App\Services\Crypto\BinanceClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SignalAlgo PRO chart data for the NEAR 1H Volatility-Gated Breakout: candles, EMA50, the breakout level, the
 * script's BUY / EXIT signals replayed over the window (from NearBreakoutTrader::series, the code the bot and the
 * Telegram alerts use), the trail line, the bot's own trades and what the next candle needs.
 *
 * The trail exit is replayed on 5m candles, the way the live bot meets prices. TradingView replays it on the 1h
 * candle and shows many more winners (docs/near-vgb.md). Binance is read at most once per closed hour (cached).
 */
class NearChart
{
    public const WINDOW = 500;

    protected const HOUR_MS = 3_600_000;

    public function __construct(
        protected BinanceClient $market,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(?int $nowMs = null): array
    {
        $nowMs ??= now()->getTimestampMs();
        $lastClose = intdiv($nowMs, self::HOUR_MS) * self::HOUR_MS - 1;
        $data = Cache::remember("near-chart:{$lastClose}", 3700, fn (): array => $this->build($lastClose));

        return $data + $this->live($data, $nowMs);
    }

    /**
     * The hourly part: candles, indicators and the replayed signals up to the last closed candle.
     *
     * @return array<string, mixed>
     */
    protected function build(int $lastClose): array
    {
        $symbol = NearBreakoutTrader::symbol();
        $p = (array) config('trading.near_vgb');
        $warm = (int) ($p['gate_len'] ?? 1440) + (int) ($p['atr_len'] ?? 14) + 3;
        $start = $lastClose + 1 - (self::WINDOW + $warm) * self::HOUR_MS;
        $c = $this->market->klinesRange($symbol, '1h', $start, $lastClose);
        $n = count($c['closes'] ?? []);
        $from = max(0, $n - self::WINDOW);
        $rows = NearBreakoutTrader::series($c, $from);

        $fiveMin = [];
        try {
            $m = $this->market->klinesRange($symbol, '5m', (int) ($c['openTimes'][$from] ?? $start), $lastClose);
            foreach ($m['openTimes'] ?? [] as $i => $t) {
                $fiveMin[intdiv((int) $t, self::HOUR_MS)][] = [(float) $m['opens'][$i], (float) $m['highs'][$i], (float) $m['lows'][$i], (float) $m['closes'][$i]];
            }
        } catch (Throwable $e) {
            Log::warning("[NearChart] 5m candles unavailable, the trail is replayed on 1h candles: {$e->getMessage()}");
        }

        $time = fn (int $i): int => intdiv((int) $c['openTimes'][$i], 1000);
        $candles = $volumes = $ema = $level = $trail = $markers = $signals = [];
        $mult = (float) ($p['trail_mult'] ?? 0.10);
        $pos = null;

        for ($i = $from; $i < $n; $i++) {
            $t = $time($i);
            [$o, $h, $l, $cl] = [(float) $c['opens'][$i], (float) $c['highs'][$i], (float) $c['lows'][$i], (float) $c['closes'][$i]];
            $candles[] = ['time' => $t, 'open' => $o, 'high' => $h, 'low' => $l, 'close' => $cl];
            $volumes[] = ['time' => $t, 'value' => (float) $c['volumes'][$i], 'color' => $cl >= $o ? 'rgba(16,185,129,0.35)' : 'rgba(239,68,68,0.35)'];
            $row = $rows[$i];
            if ($row['ema'] !== null) {
                $ema[] = ['time' => $t, 'value' => round((float) $row['ema'], 6)];
            }
            if ($row['highest'] !== null) {
                $level[] = ['time' => $t, 'value' => $row['highest']];
            }

            if ($pos !== null) {
                $exit = $this->trailExit($pos, $fiveMin[intdiv((int) $c['openTimes'][$i], self::HOUR_MS)] ?? [[$o, $h, $l, $cl]]);
                $reason = $exit !== null ? 'trail' : null;
                if ($exit === null && $row['ema'] !== null && $cl < $row['ema']) {
                    [$exit, $reason] = [$cl, 'EMA'];
                }
                if ($exit !== null) {
                    $pct = ($exit / $pos['entry'] - 1) * 100;
                    $markers[] = ['time' => $t, 'position' => 'aboveBar', 'color' => $pct >= 0 ? '#22d3ee' : '#f97316', 'shape' => 'arrowDown', 'text' => sprintf('EXIT %s %+.2f%%', $reason, $pct)];
                    $signals[] = ['time' => $t, 'type' => 'EXIT', 'price' => round($exit, 6), 'reason' => $reason === 'EMA' ? 'close below EMA50' : 'trail hit', 'pct' => round($pct, 2), 'size' => $pos['size']];
                    $trail[] = $reason === 'trail' ? ['time' => $t, 'value' => round($exit, 6)] : ['time' => $t];
                    $pos = null;
                } else {
                    $pos['d'] = $mult * (float) $row['atr'];
                    $trail[] = $pos['armed'] ? ['time' => $t, 'value' => round($pos['high'] - $pos['d'], 6)] : ['time' => $t];
                }
            }

            if ($pos === null && $row['entry']) {
                $pos = ['entry' => $cl, 'd' => $mult * (float) $row['atr'], 'high' => $cl, 'armed' => false, 'size' => $row['size']];
                $markers[] = ['time' => $t, 'position' => 'belowBar', 'color' => '#10b981', 'shape' => 'arrowUp', 'text' => sprintf('BUY %d%%', round($row['size'] * 100))];
                $signals[] = ['time' => $t, 'type' => 'BUY', 'price' => $cl, 'reason' => sprintf('breakout above %s, rank %.0f', $row['highest'], $row['rank']), 'pct' => null, 'size' => $row['size']];
            } elseif ($pos === null && ($trail === [] || isset(end($trail)['value']))) {
                $trail[] = ['time' => $t];
            }
        }

        // What the next closed candle needs (its rank depends only on candles already closed).
        $k = $n - 1;
        $next = null;
        if ($k >= 12) {
            $probe = $c;
            foreach (['opens', 'highs', 'lows', 'closes'] as $key) {
                $probe[$key][] = (float) $c['closes'][$k];
            }
            $probe['volumes'][] = 0.0;
            $nextRow = NearBreakoutTrader::series($probe, $n)[$n] ?? null;
            $closes = array_map('floatval', array_slice($c['closes'], $k - 11, 12));
            $next = [
                'level' => max($closes),
                'cross_possible' => (float) $c['closes'][$k] <= max(array_map('floatval', array_slice($c['closes'], $k - 12, 12))),
                'ema' => $rows[$k]['ema'] ?? null,
                'rank' => $nextRow['rank'] ?? null,
                'size' => $nextRow['size'] ?? null,
            ];
        }

        return [
            'symbol' => $symbol,
            'interval' => '1h',
            'last_close' => $lastClose,
            'candles' => $candles,
            'volumes' => $volumes,
            'ema' => $ema,
            'level' => $level,
            'trail' => $trail,
            'markers' => $markers,
            'signals' => array_reverse(array_slice($signals, -30)),
            'stats' => $this->stats($signals),
            'next' => $next,
            'last' => $k >= 0 ? $rows[$k] ?? null : null,
            'replay_open' => $pos,
            'trail_source' => $fiveMin === [] ? '1h' : '5m',
        ];
    }

    /**
     * Walk the 5m candles of one hour (open, then the nearer extreme, the other extreme, close); returns the exit
     * price when the armed trail is hit, null otherwise. Updates the position's high and arming.
     *
     * @param  array<string, mixed>  $pos
     * @param  array<int, array{0: float, 1: float, 2: float, 3: float}>  $bars
     */
    protected function trailExit(array &$pos, array $bars): ?float
    {
        $d = (float) $pos['d'];
        foreach ($bars as [$o, $h, $l, $cl]) {
            $path = abs($h - $o) < abs($l - $o) ? [$o, $h, $l, $cl] : [$o, $l, $h, $cl];
            foreach ($path as $idx => $price) {
                if ($pos['armed'] && $price <= $pos['high'] - $d) {
                    // A gap through the trail fills at that price; a move down through it fills at the trail.
                    return $idx === 0 ? $price : $pos['high'] - $d;
                }
                $pos['high'] = max($pos['high'], $price);
                if (! $pos['armed'] && $pos['high'] >= $pos['entry'] + $d) {
                    $pos['armed'] = true;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $signals
     * @return array{trades: int, win_rate: ?float, profit_factor: ?float, return_pct: float}
     */
    protected function stats(array $signals): array
    {
        $cost = 2 * (float) config('trading.exits.fee_rate', 0.0005) * 100;
        $results = array_values(array_filter($signals, fn (array $s): bool => $s['type'] === 'EXIT'));
        $wins = $gain = $loss = 0.0;
        $equity = 1.0;
        foreach ($results as $s) {
            $r = (float) $s['pct'] - $cost;
            if ($r > 0) {
                $wins++;
                $gain += $r * $s['size'];
            } else {
                $loss -= $r * $s['size'];
            }
            $equity *= 1 + $r * $s['size'] / 100;
        }

        return [
            'trades' => count($results),
            'win_rate' => $results === [] ? null : round(100 * $wins / count($results), 1),
            'profit_factor' => $loss > 0 ? round($gain / $loss, 2) : null,
            'return_pct' => round(($equity - 1) * 100, 2),
        ];
    }

    /**
     * Fresh on every request: the forming candle, the bot's trades, the last engine decision and the alert position.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function live(array $data, int $nowMs): array
    {
        $forming = null;
        try {
            $k = $this->market->klines($data['symbol'], '1h', 2);
            $i = count($k['closes'] ?? []) - 1;
            if ($i >= 0 && (int) $k['closeTimes'][$i] > $nowMs) {
                $forming = ['time' => intdiv((int) $k['openTimes'][$i], 1000), 'open' => (float) $k['opens'][$i], 'high' => (float) $k['highs'][$i], 'low' => (float) $k['lows'][$i], 'close' => (float) $k['closes'][$i], 'volume' => (float) $k['volumes'][$i]];
            }
        } catch (Throwable) {
            // The browser's live stream fills the forming candle anyway.
        }

        $firstTime = (int) ($data['candles'][0]['time'] ?? 0);
        $hour = fn ($at): int => intdiv($at->getTimestamp(), 3600) * 3600;
        $tradeMarkers = $trades = [];
        $botTrades = Trade::where('setup_tag', NearBreakoutTrader::SETUP)->where('opened_at', '>=', now()->setTimestamp($firstTime))->orderBy('opened_at')->get();
        foreach ($botTrades as $t) {
            // Entries happen just after a candle closes: mark that candle. Exits are marked on the candle they happened in.
            $tradeMarkers[] = ['time' => $hour($t->opened_at) - 3600, 'position' => 'belowBar', 'color' => '#a855f7', 'shape' => 'circle', 'text' => strtoupper($t->mode).' bot buy'];
            if ($t->closed_at !== null) {
                $pnl = (float) $t->net_pnl;
                $tradeMarkers[] = ['time' => $hour($t->closed_at), 'position' => 'aboveBar', 'color' => $pnl >= 0 ? '#a855f7' : '#f43f5e', 'shape' => 'circle', 'text' => sprintf('bot exit %s$%.2f', $pnl >= 0 ? '+' : '-', abs($pnl))];
            }
            $trades[] = [
                'id' => $t->id, 'mode' => $t->mode, 'status' => $t->status, 'entry' => (float) $t->entry_price, 'exit' => $t->exit_price !== null ? (float) $t->exit_price : null,
                'stop' => (float) $t->current_sl, 'opened_at' => $t->opened_at?->toIso8601String(), 'closed_at' => $t->closed_at?->toIso8601String(),
                'reason' => $t->exit_reason, 'net_pnl' => $t->net_pnl !== null ? (float) $t->net_pnl : null,
                'trail' => ! empty($t->meta['trail_active']) ? round((float) $t->highest_price - (float) ($t->meta['trail_d'] ?? 0), 6) : null,
            ];
        }

        return [
            'forming' => $forming,
            'trade_markers' => $tradeMarkers,
            'bot_trades' => array_reverse($trades),
            'engine' => Cache::get(NearBreakoutTrader::LAST_KEY),
            'alert_position' => Cache::get(NearBreakoutTrader::SIGNAL_KEY),
            'bot_enabled' => NearBreakoutTrader::enabled(),
        ];
    }
}
