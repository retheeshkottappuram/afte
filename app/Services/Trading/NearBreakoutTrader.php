<?php

namespace App\Services\Trading;

use App\Models\Trade;
use App\Models\TradingAccount;
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
 * Test (docs/near-vgb.md): PF 3.68 when the trail is replayed on 1h candles, PF 0.92 on the real 5m price path.
 */
class NearBreakoutTrader
{
    public const SETUP = 'NEAR_VGB';

    public const BAR_MS = 3_600_000;

    public const LAST_KEY = 'near-vgb:last';

    public function __construct(
        protected BinanceClient $market,
        protected OrderExecutor $executor,
        protected DynamicTradeManager $tradeManager,
        protected TelegramGateway $telegram,
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
            $this->telegram->send(sprintf('⏭ <b>NEAR breakout %s LONG</b> (1h) not traded: %s.', e($symbol), e($why)));

            return $this->remember($c, $k, $e, $mode, 'skipped', "Entry signal, but new entries are off: {$why}.");
        }

        $account = TradingAccount::getForMode($mode);
        $price = (float) $e['close'];
        $maxSl = (float) config('trading.near_vgb.max_sl_pct', 8.0);
        $sl = max($price - (float) config('trading.near_vgb.safety_atr', 3.0) * (float) $e['atr'], $price * (1 - $maxSl / 100));
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
            $this->telegram->send(sprintf('⏭ <b>NEAR breakout %s LONG</b> (1h) not traded: %s', e($symbol), e($result['message'])));

            return $this->remember($c, $k, $e, $mode, 'skipped', $result['message']);
        }

        if (($trade = $result['trade'] ?? null) instanceof Trade) {
            $trade->meta = array_merge($trade->meta ?? [], ['trail_d' => $trailD, 'size_mult' => $e['size'], 'rank' => $e['rank']]);
            $trade->save();
        }

        return $this->remember($c, $k, $e, $mode, 'opened', $result['message']);
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
