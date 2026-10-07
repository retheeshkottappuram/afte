<?php

namespace App\Services\Strategy;

use App\Models\CryptoSignal;
use App\Models\Setting;
use App\Services\Crypto\BinanceClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Minute breakout watcher: the hourly scan lists coins coiled in a squeeze (filters already passed),
 * and every minute this checks their live price. When price trades through the 20-bar box with
 * volume on pace and the candle holding near its extreme, it emits a Squeeze Breakout signal at
 * the current price instead of waiting for the candle to close.
 */
class BreakoutWatcher
{
    public const WATCH_KEY = 'breakout_watch';

    /** The candle must close in its top (long) or bottom (short) 30% at the moment of entry. */
    protected const MIN_CLOSE_POSITION = 0.7;

    /** Volume so far must be on pace for 1.5x the 20-bar average. */
    protected const VOLUME_PACE = 1.5;

    /** Volume pace is judged on at least this share of the candle, so early spikes need real volume. */
    protected const MIN_ELAPSED_SHARE = 0.25;

    public function __construct(
        protected BinanceClient $market,
        protected StrategyEngine $engine,
        protected SignalScorer $scorer,
        protected SignalLedger $ledger
    ) {}

    /** Runs when breakout alerts or early-breakout auto-trading is on. */
    public static function enabled(): bool
    {
        return self::alertsEnabled() || self::tradingEnabled();
    }

    public static function alertsEnabled(): bool
    {
        return (bool) config('trading.strategy.breakout_alerts', true);
    }

    public static function tradingEnabled(): bool
    {
        return (bool) config('trading.strategy.intrabar_breakouts', true);
    }

    /**
     * Replace the watch list after an hourly scan.
     *
     * @param  array<string, array<string, mixed>>  $watches  Keyed "SYMBOL:SIDE" (or symbol), from StrategyEngine::watchCandidates()
     */
    public function store(string $interval, array $watches): void
    {
        $first = reset($watches) ?: null;

        Setting::putValue(self::WATCH_KEY, [
            'interval' => $interval,
            'bar_open_ms' => $first['bar_open_ms'] ?? null,
            'bar_close_ms' => $first['bar_close_ms'] ?? null,
            'updated_at' => now()->toIso8601String(),
            'coins' => $watches,
        ]);
    }

    /**
     * Coins still being watched for the current candle.
     *
     * @return array<string, array<string, mixed>>
     */
    public function watching(): array
    {
        $state = (array) Setting::getValue(self::WATCH_KEY, []);
        if (($state['bar_close_ms'] ?? 0) <= now()->getTimestampMs()) {
            return [];
        }

        return (array) ($state['coins'] ?? []);
    }

    /**
     * Number of distinct coins being watched (a coin can be watched on both box edges).
     */
    public function watchingCoins(): int
    {
        return self::coinCount($this->watching());
    }

    /**
     * @param  array<string|int, array<string, mixed>>  $watches
     */
    public static function coinCount(array $watches): int
    {
        $symbols = [];
        foreach ($watches as $key => $watch) {
            $symbols[(string) ($watch['symbol'] ?? $key)] = true;
        }

        return count($symbols);
    }

    /**
     * Check every watched coin against its live price and return the breakouts that triggered.
     *
     * @return array<int, array{signal: Signal, record: CryptoSignal, level: float, volume_pace: float}>
     */
    public function check(): array
    {
        $state = (array) Setting::getValue(self::WATCH_KEY, []);
        $coins = $this->watching();
        if ($coins === []) {
            return [];
        }

        $prices = [];
        foreach ($this->market->get24hrTickers() as $ticker) {
            $prices[strtoupper((string) ($ticker['symbol'] ?? ''))] = (float) ($ticker['lastPrice'] ?? 0);
        }

        $fresh = [];
        foreach ($coins as $key => $watch) {
            $symbol = (string) ($watch['symbol'] ?? $key);
            if (! isset($coins[$key])) {
                continue; // the other edge of this coin already fired this candle
            }
            $price = $prices[$symbol] ?? 0.0;
            $isLong = $watch['side'] === 'LONG';
            $trigger = $this->engine->triggerPrice($watch);
            if ($price <= 0 || ($isLong ? $price < $trigger : $price > $trigger)) {
                continue;
            }

            try {
                $pace = $this->confirmed($watch, $price);
                if ($pace === null) {
                    continue;
                }

                $signal = $this->scorer->score($this->engine->intrabarSignal($watch, $price, intdiv((int) $watch['bar_close_ms'], 1000)));
                $fresh[] = ['signal' => $signal, 'record' => $this->ledger->record($signal, 'watcher'), 'level' => (float) $watch['level'], 'volume_pace' => $pace];
                // One breakout per coin per candle: stop watching the opposite edge too.
                $coins = array_filter($coins, fn (array $other, string|int $otherKey): bool => (string) ($other['symbol'] ?? $otherKey) !== $symbol, ARRAY_FILTER_USE_BOTH);
            } catch (Throwable $e) {
                Log::warning("[BreakoutWatcher] {$symbol}: {$e->getMessage()}");
            }
        }

        if ($fresh !== []) {
            $state['coins'] = $coins;
            Setting::putValue(self::WATCH_KEY, $state);
        }

        return $fresh;
    }

    /**
     * The forming candle backs the breakout: volume on pace and price holding near the candle's extreme.
     * Returns the volume pace (1.0 = the 20-candle average for the time elapsed), or null when not confirmed.
     *
     * @param  array<string, mixed>  $watch
     */
    protected function confirmed(array $watch, float $price): ?float
    {
        $candles = $this->market->klines((string) $watch['symbol'], (string) $watch['interval'], 2);
        $last = count($candles['closes'] ?? []) - 1;
        if ($last < 0 || (int) $candles['closeTimes'][$last] !== (int) $watch['bar_close_ms']) {
            return null;
        }

        $high = max((float) $candles['highs'][$last], $price);
        $low = min((float) $candles['lows'][$last], $price);
        $closePosition = ($price - $low) / max(1e-12, $high - $low);
        $holding = $watch['side'] === 'LONG' ? $closePosition >= self::MIN_CLOSE_POSITION : $closePosition <= 1 - self::MIN_CLOSE_POSITION;

        $span = max(1, (int) $watch['bar_close_ms'] - (int) $watch['bar_open_ms']);
        $elapsed = min(1.0, max(self::MIN_ELAPSED_SHARE, (now()->getTimestampMs() - (int) $watch['bar_open_ms']) / $span));
        $pace = (float) $candles['volumes'][$last] / max(1e-12, (float) $watch['vol_sma'] * $elapsed);

        return $holding && $pace >= self::VOLUME_PACE ? round($pace, 2) : null;
    }
}
