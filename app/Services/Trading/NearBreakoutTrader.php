<?php

namespace App\Services\Trading;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Binance\BinanceFuturesClient;
use App\Services\Crypto\BinanceClient;
use App\Services\Crypto\CandleSanitizer;
use App\Services\Crypto\Indicators;
use App\Services\Notifications\TelegramGateway;
use Illuminate\Support\Facades\Cache;

/**
 * "NEAR 1H Volatility-Gated Breakout", the user's Pine v6 script (2026-11-11), the only strategy:
 *  - long only, flat only, on each closed 1h NEARUSDT candle: close crosses above the highest close of the
 *    previous break_len candles, the candle is green, close > EMA(ema_len), ATR% >= min_atr_pct and the ATR%
 *    percentile rank (previous gate_len values, lagged one bar) is below high_gate;
 *  - size = equity x 1.0 at 1x when the rank is <= low_gate, x 0.5 otherwise;
 *  - exits: a closed candle below the EMA (here), or the trail_mult x ATR trail that starts once price is that
 *    far above entry (DynamicTradeManager::manageNear, on the mark price every engine pass).
 * Not in the script: an exchange stop safety_atr x ATR below entry (capped at max_sl_pct) for crashes between runs.
 * Telegram alerts follow the script whether or not the bot trades: BUY on the entry signal, then EXIT on a close below
 * the EMA or when the trail is hit (checkSignalTrail on the engine's 5s loop). While a bot trade is open its own close
 * message reports the exit instead.
 * Test (docs/near-vgb.md): PF 3.68 when the trail is replayed on 1h candles, PF 0.92 on the real 5m price path.
 */
class NearBreakoutTrader
{
    public const SETUP = 'NEAR_VGB';

    public const BAR_MS = 3_600_000;

    public const LAST_KEY = 'near-vgb:last';

    /** The script's position as the alerts see it: {entry, d, high, armed, at}. */
    public const SIGNAL_KEY = 'near-vgb:signal';

    public function __construct(
        protected BinanceClient $market,
        protected OrderExecutor $executor,
        protected DynamicTradeManager $tradeManager,
        protected TelegramGateway $telegram,
        protected BinanceFuturesClient $client,
    ) {}

    public static function enabled(): bool
    {
        return BotSwitches::on('near');
    }

    public static function symbol(): string
    {
        return strtoupper((string) config('trading.near_vgb.symbol', 'NEARUSDT'));
    }

    public static function hasOpenTrades(): bool
    {
        return Trade::where('status', 'OPEN')->where('setup_tag', self::SETUP)->exists();
    }

    /**
     * Indicators and the entry decision on the last candle of $c (closed candles only).
     *
     * @param  array<string, array<int, float|int>>  $c
     * @return array{ok: bool, entry: bool, size: float, close: float, open: float, ema: ?float, atr: ?float, atr_pct: ?float, rank: ?float, highest: ?float, why: string}
     */
    public static function evaluate(array $c): array
    {
        $p = (array) config('trading.near_vgb');
        $breakLen = (int) ($p['break_len'] ?? 12);
        $gateLen = (int) ($p['gate_len'] ?? 1440);
        $closes = array_map('floatval', $c['closes'] ?? []);
        $k = count($closes) - 1;
        $none = ['ok' => false, 'entry' => false, 'size' => 0.0, 'close' => $k >= 0 ? $closes[$k] : 0.0, 'open' => $k >= 0 ? (float) $c['opens'][$k] : 0.0, 'ema' => null, 'atr' => null, 'atr_pct' => null, 'rank' => null, 'highest' => null];

        $ema = Indicators::ema($closes, (int) ($p['ema_len'] ?? 50));
        $atr = Indicators::atr($c['highs'], $c['lows'], $closes, (int) ($p['atr_len'] ?? 14));
        $atrPct = fn (int $i): ?float => $atr[$i] === null || $closes[$i] <= 0 ? null : 100.0 * $atr[$i] / $closes[$i];

        // Percent rank on the previous candle: share of its previous gate_len ATR% values that are <= its own.
        $j = $k - 1;
        if ($k < $breakLen + 1 || $ema[$k] === null || $atr[$k] === null || $j - $gateLen < 0 || $atrPct($j - $gateLen) === null) {
            return $none + ['why' => sprintf('not enough 1h history (%d closed candles, %d needed)', $k + 1, $gateLen + (int) ($p['atr_len'] ?? 14) + 2)];
        }
        $current = $atrPct($j);
        $below = 0;
        for ($i = $j - $gateLen; $i < $j; $i++) {
            if ($atrPct($i) <= $current) {
                $below++;
            }
        }
        $rank = 100.0 * $below / $gateLen;
        $size = match (true) {
            $rank >= (float) ($p['high_gate'] ?? 80) => 0.0,
            $rank <= (float) ($p['low_gate'] ?? 20) => 1.0,
            default => 0.5,
        };

        $highest = fn (int $i): float => max(array_slice($closes, $i - $breakLen + 1, $breakLen));
        $cross = $closes[$k] > $highest($k - 1) && $closes[$k - 1] <= $highest($k - 2);
        $nowPct = (float) $atrPct($k);
        $checks = [
            'close crossed above the 12-candle high' => $cross,
            'close above EMA'.((int) ($p['ema_len'] ?? 50)) => $closes[$k] > $ema[$k],
            'green candle' => $closes[$k] > (float) $c['opens'][$k],
            sprintf('ATR %.2f%% >= %.2f%%', $nowPct, (float) ($p['min_atr_pct'] ?? 0.3)) => $nowPct >= (float) ($p['min_atr_pct'] ?? 0.3),
            sprintf('volatility rank %.0f below %.0f', $rank, (float) ($p['high_gate'] ?? 80)) => $size > 0,
        ];
        $failed = array_keys(array_filter($checks, fn (bool $ok): bool => ! $ok));

        return [
            'ok' => true,
            'entry' => $failed === [],
            'size' => $size,
            'close' => $closes[$k],
            'open' => (float) $c['opens'][$k],
            'ema' => (float) $ema[$k],
            'atr' => (float) $atr[$k],
            'atr_pct' => round($nowPct, 4),
            'rank' => round($rank, 2),
            'highest' => $highest($k - 1),
            'why' => $failed === [] ? 'all entry conditions met' : 'no entry: not '.implode(', not ', $failed),
        ];
    }

    /**
     * Once per closed 1h candle (called every engine minute; a failed pass is retried): the EMA exit for an open
     * trade, the trail distance update, or a new entry.
     *
     * @return array{action: string, message: string}
     */
    public function run(string $mode, bool $entriesAllowed, ?int $nowMs = null): array
    {
        $nowMs ??= now()->getTimestampMs();
        $lastClose = intdiv($nowMs, self::BAR_MS) * self::BAR_MS - 1;
        $symbol = self::symbol();
        $doneKey = "near-vgb:{$mode}:{$lastClose}";
        if (Cache::has($doneKey)) {
            return ['action' => 'none', 'message' => 'Candle already handled.'];
        }

        $c = CandleSanitizer::onlyClosedCandles($this->market->klines($symbol, '1h', 1500), $nowMs);
        $k = count($c['closes'] ?? []) - 1;
        if ($k < 0 || (int) $c['closeTimes'][$k] !== $lastClose) {
            return ['action' => 'none', 'message' => 'The last 1h candle is not available yet.'];
        }

        $result = $this->handleCandle($symbol, $mode, $entriesAllowed, $c);
        Cache::put($doneKey, true, now()->addHours(3));

        return $result;
    }

    /**
     * @param  array<string, array<int, float|int>>  $c
     * @return array{action: string, message: string}
     */
    protected function handleCandle(string $symbol, string $mode, bool $entriesAllowed, array $c): array
    {
        $e = self::evaluate($c);
        $k = count($c['closes']) - 1;
        $trailD = $e['atr'] !== null ? (float) config('trading.near_vgb.trail_mult', 0.10) * $e['atr'] : null;
        $open = Trade::where('mode', $mode)->where('symbol', $symbol)->where('status', 'OPEN')->where('setup_tag', self::SETUP)->first();
        $this->trackSignal($symbol, $e, $trailD, $open !== null, (int) $c['closeTimes'][$k]);

        if ($open !== null) {
            if ($e['ema'] !== null && $e['close'] < $e['ema']) {
                $result = $this->tradeManager->closeTrade($open, $e['close'], 'EMA_EXIT');

                return $this->remember($c, $k, $e, $mode, 'closed', 'Close '.$e['close'].' below EMA '.round($e['ema'], 4).": {$result['message']}");
            }
            if ($trailD !== null) {
                // strategy.exit is re-sent every candle in the script, so the trail follows the latest ATR.
                $open->meta = array_merge($open->meta ?? [], ['trail_d' => $trailD]);
                $open->save();
            }

            return $this->remember($c, $k, $e, $mode, 'holding', 'In a trade; trail distance '.round((float) $trailD, 5).'.');
        }

        if (! $e['entry']) {
            return $this->remember($c, $k, $e, $mode, 'none', ucfirst($e['why']).'.');
        }

        if (! $entriesAllowed) {
            $why = self::enabled() ? 'auto-trading is stopped or paused (or a loss breaker is active)' : 'the NEAR bot switch is off';
            $this->telegram->send(sprintf('⏭ <b>NEAR breakout %s LONG</b> (1h) not traded by the bot: %s.', e($symbol), e($why)), priority: true);

            return $this->remember($c, $k, $e, $mode, 'skipped', "Entry signal, but new entries are off: {$why}.");
        }

        $account = TradingAccount::getForMode($mode);
        $price = (float) $e['close'];
        $sl = self::safetyStop($price, (float) $e['atr']);
        $margin = max(0.0, (float) $account->balance) * $e['size'] * (float) config('trading.near_vgb.equity_buffer', 0.98);

        $result = $this->executor->executeSignal([
            'symbol' => $symbol,
            'side' => 'LONG',
            'price' => $price,
            'initial_sl' => $sl,
            'setup' => self::SETUP,
            'setup_label' => sprintf('NEAR 1h volatility-gated breakout (size %d%%, volatility rank %.0f)', round($e['size'] * 100), $e['rank']),
            'interval' => '1h',
            'grade' => $e['size'] >= 1.0 ? 'A' : 'B',
            'indicators' => ['atr' => $e['atr'], 'ema' => $e['ema'], 'rank' => $e['rank'], 'atr_pct' => $e['atr_pct']],
            'fixed_margin' => round($margin, 4),
            'fixed_leverage' => 1,
        ], $mode);

        if ($result['status'] !== 'opened') {
            $this->telegram->send(sprintf('⏭ <b>NEAR breakout %s LONG</b> (1h) not traded by the bot: %s', e($symbol), e($result['message'])), priority: true);

            return $this->remember($c, $k, $e, $mode, 'skipped', $result['message']);
        }

        if (($trade = $result['trade'] ?? null) instanceof Trade) {
            $trade->meta = array_merge($trade->meta ?? [], ['trail_d' => $trailD, 'size_mult' => $e['size'], 'rank' => $e['rank']]);
            $trade->save();
        }

        return $this->remember($c, $k, $e, $mode, 'opened', $result['message']);
    }

    public static function safetyStop(float $price, float $atr): float
    {
        $maxSl = (float) config('trading.near_vgb.max_sl_pct', 8.0);

        return max($price - (float) config('trading.near_vgb.safety_atr', 3.0) * $atr, $price * (1 - $maxSl / 100));
    }

    /**
     * The script's own position for the alerts, on each closed candle: BUY when it enters from flat, EXIT on a close
     * below the EMA (reported by the trade's close message when the bot holds it), else the latest trail distance.
     *
     * @param  array<string, mixed>  $e
     */
    protected function trackSignal(string $symbol, array $e, ?float $trailD, bool $botInTrade, int $candleClose): void
    {
        $sig = Cache::get(self::SIGNAL_KEY);
        if (is_array($sig)) {
            if ($e['ema'] !== null && $e['close'] < $e['ema']) {
                Cache::forget(self::SIGNAL_KEY);
                if (! $botInTrade) {
                    $this->telegram->send(sprintf("📉 <b>NEAR SELL / exit signal</b> %s (1h)\nThe 1h candle closed at <code>%s</code>, below EMA50 <code>%s</code>: the strategy exits here.\nFrom the BUY at <code>%s</code>: %+.2f%%",
                        e($symbol), $this->fmt($e['close']), $this->fmt((float) $e['ema']), $this->fmt((float) $sig['entry']), ($e['close'] / (float) $sig['entry'] - 1) * 100), priority: true);
                }

                return;
            }
            if ($trailD !== null) {
                Cache::forever(self::SIGNAL_KEY, ['d' => $trailD] + $sig);
            }

            return;
        }

        if (! $e['entry'] || $botInTrade) {
            return;
        }

        $price = (float) $e['close'];
        Cache::forever(self::SIGNAL_KEY, ['entry' => $price, 'd' => (float) $trailD, 'high' => $price, 'armed' => false, 'at' => $candleClose]);
        $this->telegram->send(sprintf(
            "📈 <b>NEAR BUY signal</b> %s (1h close %s UTC)\n"
            ."Entry ≈ <code>%s</code> (market now)\n"
            ."EMA50 <code>%s</code> · ATR %.2f%% · volatility rank %.0f → size %d%% of the balance at 1x\n"
            ."Trail: arms at <code>%s</code>, then exits %s below the highest price\n"
            ."Also exits on a 1h close below EMA50 · safety stop <code>%s</code>\n"
            .'<i>Backtest on the real 5m price path: PF 0.92. Not proven.</i>',
            e($symbol), now()->setTimestamp(intdiv($candleClose + 1, 1000))->format('H:i'), $this->fmt($price), $this->fmt((float) $e['ema']),
            (float) $e['atr_pct'], (float) $e['rank'], round($e['size'] * 100), $this->fmt($price + (float) $trailD), $this->fmt((float) $trailD),
            $this->fmt(self::safetyStop($price, (float) $e['atr']))
        ), priority: true);
    }

    /**
     * Every engine pass while the script is long: arm the trail and send the trail EXIT alert. The bot's own trade
     * is managed by DynamicTradeManager::manageNear, so nothing is sent here while it is open.
     *
     * @return string|null the alert sent: 'armed' or 'exit'
     */
    public function checkSignalTrail(?float $price = null): ?string
    {
        $sig = Cache::get(self::SIGNAL_KEY);
        if (! is_array($sig) || (float) ($sig['d'] ?? 0) <= 0) {
            return null;
        }
        $price ??= (float) $this->client->getMarkPrice(self::symbol());
        if ($price <= 0) {
            return null;
        }

        $d = (float) $sig['d'];
        $sig['high'] = max((float) $sig['high'], $price);
        $botInTrade = self::hasOpenTrades();
        $sent = null;
        if (! $sig['armed'] && $sig['high'] >= (float) $sig['entry'] + $d) {
            $sig['armed'] = true;
            if (! $botInTrade) {
                $this->telegram->send(sprintf('↗️ <b>NEAR trail armed</b>: exit if price falls to <code>%s</code> (moves up with the high).', $this->fmt($sig['high'] - $d)), priority: true);
                $sent = 'armed';
            }
        }
        if ($sig['armed'] && $price <= (float) $sig['high'] - $d) {
            Cache::forget(self::SIGNAL_KEY);
            if (! $botInTrade) {
                $this->telegram->send(sprintf("📉 <b>NEAR SELL / exit signal</b> %s: trail hit\nPrice <code>%s</code> fell %s below the high <code>%s</code>.\nFrom the BUY at <code>%s</code>: %+.2f%%",
                    e(self::symbol()), $this->fmt($price), $this->fmt($d), $this->fmt((float) $sig['high']), $this->fmt((float) $sig['entry']), ($price / (float) $sig['entry'] - 1) * 100), priority: true);

                return 'exit';
            }

            return null;
        }
        Cache::forever(self::SIGNAL_KEY, $sig);

        return $sent;
    }

    protected function fmt(float $price): string
    {
        return rtrim(rtrim(number_format($price, $price >= 1 ? 4 : 8, '.', ''), '0'), '.');
    }

    /**
     * Keep the last decision for the dashboard.
     *
     * @param  array<string, array<int, float|int>>  $c
     * @param  array<string, mixed>  $e
     * @return array{action: string, message: string}
     */
    protected function remember(array $c, int $k, array $e, string $mode, string $action, string $message): array
    {
        Cache::forever(self::LAST_KEY, [
            'mode' => $mode,
            'candle_close' => (int) $c['closeTimes'][$k],
            'at' => now()->toIso8601String(),
            'action' => $action,
            'message' => $message,
            'close' => $e['close'],
            'ema' => $e['ema'] !== null ? round($e['ema'], 5) : null,
            'atr_pct' => $e['atr_pct'],
            'rank' => $e['rank'],
            'size' => $e['size'],
            'highest' => $e['highest'],
        ]);

        return ['action' => $action, 'message' => $message];
    }
}
