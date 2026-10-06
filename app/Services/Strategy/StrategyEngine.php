<?php

namespace App\Services\Strategy;

use App\Services\Crypto\CandleSanitizer;
use App\Services\Crypto\Indicators;

/**
 * The single rule-based strategy used by the auto-trader, scanner, chart and Telegram.
 *
 * Non-repainting by construction: every decision at bar i uses only candles up to and
 * including bar i, and no state is carried from one signal to the next.
 *
 * Core setups (traded + alerted): TREND_PULLBACK, SQUEEZE_BREAKOUT.
 * Shadow setups (recorded + measured only): SWING_REVERSAL, EMA_CROSS.
 */
class StrategyEngine
{
    public const SETUP_LABELS = [
        'SQUEEZE_BREAKOUT' => 'Squeeze Breakout',
        'EARLY_BREAKOUT' => 'Early Breakout',
        'TREND_PULLBACK' => 'Trend Pullback',
        'EMA_CROSS' => 'EMA 9/21 Cross',
        'SWING_REVERSAL' => 'Swing Reversal',
    ];

    /** Setup priority when several trigger on the same bar. */
    protected const PRIORITY = ['SQUEEZE_BREAKOUT', 'TREND_PULLBACK', 'EMA_CROSS', 'SWING_REVERSAL'];

    /** A setup does not re-fire on the same side within this many bars. */
    protected const REPEAT_SUPPRESS_BARS = 6;

    /** @var array<string, mixed> */
    protected array $config;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config = [], protected ?ExitPlan $exitPlan = null)
    {
        $this->config = array_merge([
            'min_quote_volume_24h' => 50000000.0,
            'min_listing_days' => 30,
            'min_atr_pct' => 0.35,
            'max_atr_pct' => 4.0,
            'max_adverse_funding' => 0.0005,
            'regime_min_adx' => 18.0,
            'min_sl_pct' => 0.6,
            'max_sl_pct' => 1.8,
            'max_sl_pct_by_setup' => [],
            'core_setups' => ['TREND_PULLBACK', 'SQUEEZE_BREAKOUT'],
        ], (array) config('trading.strategy', []), $config);

        $this->exitPlan ??= ExitPlan::fromConfig();
    }

    /**
     * Widest allowed stop (in %) for a setup, from the app config (used outside the engine).
     */
    public static function configuredMaxSlPct(?string $setup = null): float
    {
        $bySetup = (array) config('trading.strategy.max_sl_pct_by_setup', []);

        return (float) ($setup !== null && isset($bySetup[$setup]) ? $bySetup[$setup] : config('trading.strategy.max_sl_pct', 1.8));
    }

    /**
     * Widest allowed stop (in %) for a setup with this engine's config.
     */
    public function maxSlPct(?string $setup = null): float
    {
        $bySetup = (array) ($this->config['max_sl_pct_by_setup'] ?? []);

        return (float) ($setup !== null && isset($bySetup[$setup]) ? $bySetup[$setup] : $this->config['max_sl_pct']);
    }

    /**
     * Analyse a symbol.
     *
     * @param  array<string, array<int, float|int>>  $base  Base timeframe candles (opens, highs, lows, closes, volumes, closeTimes)
     * @param  array<string, array<int, float|int>>|null  $regime  Regime timeframe candles (4h)
     * @param  array<string, array<int, float|int>>|null  $btcBase  BTC base timeframe candles
     * @param  array<string, array<int, float|int>>|null  $btcRegime  BTC regime timeframe candles
     * @param  array{symbol?: string, interval?: string, lookback?: int, now_ms?: int, quote_volume_24h?: ?float, listing_days?: ?int, funding_rate?: ?float, tradable_size?: ?array{pass: bool, detail: string}, daily?: ?array<string, array<int, float|int>>}  $context
     * @return array{signals: array<int, Signal>, latest: ?Signal, state: array<string, mixed>, series: array<string, mixed>}
     */
    public function analyze(array $base, ?array $regime = null, ?array $btcBase = null, ?array $btcRegime = null, array $context = []): array
    {
        $nowMs = (int) ($context['now_ms'] ?? (int) (microtime(true) * 1000));
        $base = CandleSanitizer::onlyClosedCandles($base, $nowMs);
        $regime = $regime !== null ? CandleSanitizer::onlyClosedCandles($regime, $nowMs) : null;
        $btcBase = $btcBase !== null ? CandleSanitizer::onlyClosedCandles($btcBase, $nowMs) : null;
        $btcRegime = $btcRegime !== null ? CandleSanitizer::onlyClosedCandles($btcRegime, $nowMs) : null;

        $symbol = strtoupper((string) ($context['symbol'] ?? 'UNKNOWN'));
        $interval = (string) ($context['interval'] ?? '1h');
        $n = count($base['closes']);
        $empty = ['signals' => [], 'latest' => null, 'state' => ['bias' => 'NONE', 'status' => 'WAIT', 'reason' => 'Not enough candle history.', 'checklist' => []], 'series' => []];

        if ($n < 60) {
            return $empty;
        }

        $s = $this->computeSeries($base);
        $regimeAt = $this->regimeMap($base['closeTimes'], $regime);
        $btc = $this->btcMap($base['closeTimes'], $btcBase, $btcRegime, $symbol);

        $lookback = max(1, (int) ($context['lookback'] ?? 140));
        $start = max(52, $n - $lookback);

        // Raw triggers for each bar (needed for stateless repeat suppression).
        $raw = [];
        for ($i = max(52, $start - self::REPEAT_SUPPRESS_BARS); $i < $n; $i++) {
            $raw[$i] = $this->rawTriggers($i, $base, $s);
        }

        $signals = [];
        $lastIndex = $n - 1;

        for ($i = $start; $i < $n; $i++) {
            $trigger = $this->pickTrigger($i, $raw);
            if ($trigger === null) {
                continue;
            }

            $isLatest = $i === $lastIndex;
            // Live data (volume, funding) only describes the newest candle; the volume rank is stable enough for every bar.
            $signals[] = $this->buildSignal($symbol, $interval, $i, $trigger, $base, $s, $regimeAt[$i], $btc[$i], $isLatest ? $context : array_intersect_key($context, ['volume_rank' => true]));
        }

        $latest = null;
        if ($signals !== []) {
            $last = end($signals);
            if ($last->time === intdiv((int) $base['closeTimes'][$lastIndex], 1000)) {
                $latest = $last;
            }
        }

        // Coin is coiled for a breakout on the candle now forming (unless a squeeze signal fired recently).
        $watch = null;
        $suppressed = false;
        for ($j = $n - self::REPEAT_SUPPRESS_BARS; $j < $n; $j++) {
            foreach ($raw[$j] ?? [] as $previous) {
                $suppressed = $suppressed || $previous['setup'] === 'SQUEEZE_BREAKOUT';
            }
        }
        if (! $suppressed) {
            $watch = $this->watchCandidate($symbol, $interval, $lastIndex, $base, $s, $regimeAt[$lastIndex], $btc[$lastIndex], $context);
        }

        return [
            'signals' => $signals,
            'latest' => $latest,
            'state' => $this->currentState($symbol, $lastIndex, $base, $s, $regimeAt[$lastIndex], $btc[$lastIndex], $context, $latest),
            'series' => $s,
            'watch' => $watch,
        ];
    }

    /**
     * Early (intrabar) squeeze breakouts on candle history, for backtesting the minute watcher: the trade
     * fills as soon as a candle trades through the trigger price instead of waiting for the close.
     *
     * @param  array<string, array<int, float|int>>  $base
     * @param  array<string, array<int, float|int>>|null  $regime
     * @param  array<string, array<int, float|int>>|null  $btcBase
     * @param  array<string, array<int, float|int>>|null  $btcRegime
     * @param  array<string, mixed>  $context
     * @param  array{stop_mode?: string, anticipate_pct?: float, volume_filter?: bool}  $options
     * @return array<int, Signal>
     */
    public function intrabarSignals(array $base, ?array $regime = null, ?array $btcBase = null, ?array $btcRegime = null, array $context = [], array $options = []): array
    {
        $nowMs = (int) ($context['now_ms'] ?? (int) (microtime(true) * 1000));
        $base = CandleSanitizer::onlyClosedCandles($base, $nowMs);
        $regime = $regime !== null ? CandleSanitizer::onlyClosedCandles($regime, $nowMs) : null;
        $btcBase = $btcBase !== null ? CandleSanitizer::onlyClosedCandles($btcBase, $nowMs) : null;
        $btcRegime = $btcRegime !== null ? CandleSanitizer::onlyClosedCandles($btcRegime, $nowMs) : null;

        $symbol = strtoupper((string) ($context['symbol'] ?? 'UNKNOWN'));
        $interval = (string) ($context['interval'] ?? '1h');
        $n = count($base['closes']);
        if ($n < 120) {
            return [];
        }

        $s = $this->computeSeries($base);
        $regimeAt = $this->regimeMap($base['closeTimes'], $regime);
        $btc = $this->btcMap($base['closeTimes'], $btcBase, $btcRegime, $symbol);
        $signals = [];
        $lastFired = ['LONG' => -100, 'SHORT' => -100];
        $volumeFilter = (bool) ($options['volume_filter'] ?? false);

        for ($i = 105; $i < $n; $i++) {
            $watch = $this->watchCandidate($symbol, $interval, $i - 1, $base, $s, $regimeAt[$i - 1], $btc[$i - 1], $context);
            if ($watch === null || $i - $lastFired[$watch['side']] <= self::REPEAT_SUPPRESS_BARS) {
                continue;
            }

            $trigger = $this->triggerPrice($watch, $options['anticipate_pct'] ?? null);
            $crossed = $watch['side'] === 'LONG' ? $base['highs'][$i] >= $trigger : $base['lows'][$i] <= $trigger;
            // Approximates the live volume-pace check with the whole candle's volume (optimistic).
            if (! $crossed || ($volumeFilter && $base['volumes'][$i] < 1.5 * $watch['vol_sma'])) {
                continue;
            }

            // A gap through the trigger fills at the open, not at the trigger.
            $entry = $watch['side'] === 'LONG' ? max($trigger, $base['opens'][$i]) : min($trigger, $base['opens'][$i]);
            $signals[] = $this->intrabarSignal($watch, $entry, intdiv((int) $base['closeTimes'][$i], 1000), $options['stop_mode'] ?? null);
            $lastFired[$watch['side']] = $i;
        }

        return $signals;
    }

    /**
     * Price that triggers an early entry: the breakout level, or a little before it when anticipating.
     *
     * @param  array<string, mixed>  $watch
     */
    public function triggerPrice(array $watch, ?float $anticipatePct = null): float
    {
        $anticipatePct ??= (float) ($this->config['early_breakout']['anticipate_pct'] ?? 0.0);
        $direction = $watch['side'] === 'LONG' ? 1 : -1;

        return (float) $watch['level'] * (1 - $direction * $anticipatePct / 100);
    }

    /**
     * Breakout watch entry for the candle after bar k: the coin is in a squeeze, every market filter
     * passes for the trend side, and price has not broken out yet. Null when there is nothing to watch.
     *
     * @param  array<string, array<int, float|int>>  $c
     * @param  array<string, array<int, float|null>>  $s
     * @param  array{side: string, adx: ?float, detail: string}  $regime
     * @param  array{block_long: bool, block_short: bool, state: string, returns_24: ?float}  $btc
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    protected function watchCandidate(string $symbol, string $interval, int $k, array $c, array $s, array $regime, array $btc, array $context): ?array
    {
        $side = $regime['side'];
        $atr = (float) ($s['atr'][$k] ?? 0);
        $volSma = (float) ($s['vol_sma'][$k] ?? 0);
        if ($k < 104 || ! in_array($side, ['LONG', 'SHORT'], true) || $atr <= 0 || $volSma <= 0) {
            return null;
        }

        $squeezePct = $this->recentSqueezePercentile($k + 1, $s['bbw']);
        if ($squeezePct === null || $squeezePct > 0.20) {
            return null;
        }

        $close = (float) $c['closes'][$k];
        $boxHigh = max(array_slice($c['highs'], $k - 19, 20));
        $boxLow = min(array_slice($c['lows'], $k - 19, 20));

        // The 4h trend alone called the break direction right only ~50% of the time (12-month test);
        // price at the box edge + rising/falling highs and lows + volume balance agreeing was right ~78%.
        if (($this->config['early_breakout']['require_box_bias'] ?? true) && $this->boxBias($k, $c, $atr) !== $side) {
            return null;
        }

        $level = $side === 'LONG' ? $boxHigh * 1.001 : $boxLow * 0.999;
        if (($side === 'LONG' && $close >= $level) || ($side === 'SHORT' && $close <= $level)) {
            return null;
        }

        $slPct = max((float) $this->config['min_sl_pct'], 1.2 * $atr / $level * 100);
        $filters = $this->evaluateFilters($side, $regime, $btc, $atr / $close * 100, $slPct, $context, 'SQUEEZE_BREAKOUT');
        foreach ($filters as $filter) {
            if (! $filter['pass']) {
                return null;
            }
        }

        $indicators = $this->indicatorsAt($k, $c, $s, $regime, $btc, $side);
        $barMs = (int) $c['closeTimes'][$k] - (int) $c['closeTimes'][$k - 1];

        return [
            'symbol' => $symbol,
            'interval' => $interval,
            'side' => $side,
            'level' => $level,
            'box_high' => $boxHigh,
            'box_low' => $boxLow,
            'atr' => $atr,
            'vol_sma' => $volSma,
            'bar_open_ms' => (int) $c['closeTimes'][$k] + 1,
            'bar_close_ms' => (int) $c['closeTimes'][$k] + $barMs,
            'filters' => $filters,
            'indicators' => $indicators,
            'confluences' => $this->confluences($indicators, $side, $context['daily'] ?? null),
            'features' => $this->features('SQUEEZE_BREAKOUT', $side, $indicators, $slPct, (int) $c['closeTimes'][$k] + $barMs, $context),
        ];
    }

    /**
     * Which way a 20-bar squeeze box is likely to break, from inside the box: close in the top/bottom quarter,
     * highs and lows drifting the same way, and more volume on up (or down) candles. Null unless all three agree.
     *
     * @param  array<string, array<int, float|int>>  $c
     */
    public function boxBias(int $k, array $c, float $atr): ?string
    {
        if ($k < 19 || $atr <= 0) {
            return null;
        }

        $highs = array_slice($c['highs'], $k - 19, 20);
        $lows = array_slice($c['lows'], $k - 19, 20);
        $boxHigh = max($highs);
        $boxLow = min($lows);
        if ($boxHigh <= $boxLow) {
            return null;
        }

        $position = ((float) $c['closes'][$k] - $boxLow) / ($boxHigh - $boxLow);
        $edge = $position >= 0.75 ? 'LONG' : ($position <= 0.25 ? 'SHORT' : null);

        $drift = (self::slope($highs) + self::slope($lows)) / $atr;
        $structure = $drift > 0.02 ? 'LONG' : ($drift < -0.02 ? 'SHORT' : null);

        $upVolume = 0.0;
        $downVolume = 0.0;
        for ($j = $k - 19; $j <= $k; $j++) {
            if ($c['closes'][$j] >= $c['opens'][$j]) {
                $upVolume += (float) $c['volumes'][$j];
            } else {
                $downVolume += (float) $c['volumes'][$j];
            }
        }
        $volume = $upVolume > 1.2 * $downVolume ? 'LONG' : ($downVolume > 1.2 * $upVolume ? 'SHORT' : null);

        return $edge !== null && $edge === $structure && $edge === $volume ? $edge : null;
    }

    /**
     * Least-squares slope per bar.
     *
     * @param  array<int, float|int>  $values
     */
    protected static function slope(array $values): float
    {
        $n = count($values);
        $meanX = ($n - 1) / 2;
        $meanY = array_sum($values) / $n;
        $num = 0.0;
        $den = 0.0;
        foreach (array_values($values) as $x => $y) {
            $num += ($x - $meanX) * ((float) $y - $meanY);
            $den += ($x - $meanX) ** 2;
        }

        return $den > 0 ? $num / $den : 0.0;
    }

    /**
     * Early Breakout signal entered during the candle at the given price. Its time is the forming
     * candle's close, so the close-based Squeeze Breakout for the same candle is the same trade.
     *
     * Stop modes: atr = 1.2 x ATR from entry; inside = back inside the box (level -/+ 0.6 x ATR);
     * mid = box midpoint. The minimum stop distance always applies.
     *
     * @param  array<string, mixed>  $watch  From watchCandidate()
     */
    public function intrabarSignal(array $watch, float $entry, int $time, ?string $stopMode = null): Signal
    {
        $side = (string) $watch['side'];
        $direction = $side === 'LONG' ? 1 : -1;
        $atr = (float) $watch['atr'];
        $stopMode ??= (string) ($this->config['early_breakout']['stop_mode'] ?? 'atr');

        $sl = match ($stopMode) {
            'inside' => (float) $watch['level'] - $direction * 0.6 * $atr,
            'mid' => isset($watch['box_high'], $watch['box_low']) ? ((float) $watch['box_high'] + (float) $watch['box_low']) / 2 : $entry - $direction * 1.2 * $atr,
            default => $entry - $direction * 1.2 * $atr,
        };
        $minDistance = $entry * (float) $this->config['min_sl_pct'] / 100;
        if (($entry - $sl) * $direction < $minDistance) {
            $sl = $entry - $direction * $minDistance;
        }
        $slPct = abs($entry - $sl) / $entry * 100;
        $targets = $this->exitPlan->targets($side, $entry, $sl);

        $filters = (array) $watch['filters'];
        $maxSl = $this->maxSlPct('EARLY_BREAKOUT');
        $filters['stop_width'] = ['pass' => $slPct <= $maxSl + 1e-9, 'detail' => sprintf('Stop %.2f%% (max %.2f%%)', $slPct, $maxSl)];
        $features = (array) $watch['features'];
        $features['setup'] = 'EARLY_BREAKOUT';
        $features['sl_pct'] = round($slPct, 3);
        // Edge of the squeeze box: a candle closing back inside it means the breakout failed.
        $features['box_edge'] = (float) ($side === 'LONG' ? ($watch['box_high'] ?? $watch['level']) : ($watch['box_low'] ?? $watch['level']));

        return new Signal(
            symbol: (string) $watch['symbol'],
            interval: (string) $watch['interval'],
            side: $side,
            setup: 'EARLY_BREAKOUT',
            setupLabel: self::SETUP_LABELS['EARLY_BREAKOUT'],
            time: $time,
            entry: $entry,
            stopLoss: $sl,
            tp1: $targets['tp1'],
            tp2: $targets['tp2'],
            tp3: $targets['tp3'],
            atr: $atr,
            isShadow: ! in_array('EARLY_BREAKOUT', (array) $this->config['core_setups'], true),
            filters: $filters,
            confluences: (array) $watch['confluences'],
            features: $features,
            indicators: (array) $watch['indicators'],
        );
    }

    /**
     * @param  array<string, array<int, float|int>>  $c
     * @return array<string, array<int, float|null>>
     */
    protected function computeSeries(array $c): array
    {
        $dmi = Indicators::dmi($c['highs'], $c['lows'], $c['closes'], 14, 14);

        return [
            'ema9' => Indicators::ema($c['closes'], 9),
            'ema21' => Indicators::ema($c['closes'], 21),
            'ema50' => Indicators::ema($c['closes'], 50),
            'ema200' => Indicators::ema($c['closes'], 200),
            'rsi' => Indicators::rsi($c['closes'], 14),
            'atr' => Indicators::atr($c['highs'], $c['lows'], $c['closes'], 14),
            'adx' => $dmi['adx'],
            'vol_sma' => Indicators::sma($c['volumes'], 20),
            'bbw' => Indicators::bbWidthPercent($c['closes'], 20, 2.0),
        ];
    }

    /**
     * Map each base bar to the regime (LONG / SHORT / NONE) of the last closed regime candle.
     *
     * @param  array<int, int>  $baseCloseTimes
     * @param  array<string, array<int, float|int>>|null  $regime
     * @return array<int, array{side: string, adx: ?float, detail: string}>
     */
    protected function regimeMap(array $baseCloseTimes, ?array $regime): array
    {
        $out = [];
        $none = ['side' => 'NONE', 'adx' => null, 'detail' => 'No 4h data'];

        if ($regime === null || count($regime['closes']) < 60) {
            foreach (array_keys($baseCloseTimes) as $i) {
                $out[$i] = $none;
            }

            return $out;
        }

        $closes = $regime['closes'];
        $ema50 = Indicators::ema($closes, 50);
        $ema200 = Indicators::ema($closes, 200);
        $adx = Indicators::dmi($regime['highs'], $regime['lows'], $closes, 14, 14)['adx'];
        $minAdx = (float) $this->config['regime_min_adx'];
        $k = -1;
        $m = count($closes);

        foreach ($baseCloseTimes as $i => $closeTime) {
            while ($k + 1 < $m && $regime['closeTimes'][$k + 1] <= $closeTime) {
                $k++;
            }

            if ($k < 0 || $ema50[$k] === null) {
                $out[$i] = $none;

                continue;
            }

            $close = $closes[$k];
            $e50 = (float) $ema50[$k];
            $e200 = $ema200[$k] !== null ? (float) $ema200[$k] : null;
            $adxValue = $adx[$k] !== null ? (float) $adx[$k] : null;
            $strong = $adxValue !== null && $adxValue >= $minAdx;

            $side = 'NONE';
            if ($strong && $close > $e50 && ($e200 === null || $e50 > $e200)) {
                $side = 'LONG';
            } elseif ($strong && $close < $e50 && ($e200 === null || $e50 < $e200)) {
                $side = 'SHORT';
            }

            $detail = $side === 'NONE'
                ? sprintf('4h trend unclear (ADX %s)', $adxValue !== null ? round($adxValue, 1) : 'n/a')
                : sprintf('4h %s trend, ADX %.1f', $side === 'LONG' ? 'up' : 'down', $adxValue);

            $out[$i] = ['side' => $side, 'adx' => $adxValue, 'detail' => $detail];
        }

        return $out;
    }

    /**
     * BTC macro context per base bar: blocks alt longs when BTC is weak on both 1h and 4h (and mirror).
     *
     * @param  array<int, int>  $baseCloseTimes
     * @param  array<string, array<int, float|int>>|null  $btcBase
     * @param  array<string, array<int, float|int>>|null  $btcRegime
     * @return array<int, array{block_long: bool, block_short: bool, state: string, returns_24: ?float}>
     */
    protected function btcMap(array $baseCloseTimes, ?array $btcBase, ?array $btcRegime, string $symbol): array
    {
        $out = [];
        $neutral = ['block_long' => false, 'block_short' => false, 'state' => 'n/a', 'returns_24' => null];

        if ($symbol === 'BTCUSDT' || $btcBase === null || count($btcBase['closes']) < 60) {
            foreach (array_keys($baseCloseTimes) as $i) {
                $out[$i] = $neutral;
            }

            return $out;
        }

        $ema50 = Indicators::ema($btcBase['closes'], 50);
        $regimeAt = $this->regimeMap($btcBase['closeTimes'], $btcRegime);
        $j = -1;
        $m = count($btcBase['closes']);

        foreach ($baseCloseTimes as $i => $closeTime) {
            while ($j + 1 < $m && $btcBase['closeTimes'][$j + 1] <= $closeTime) {
                $j++;
            }

            if ($j < 0 || $ema50[$j] === null) {
                $out[$i] = $neutral;

                continue;
            }

            $weak1h = $btcBase['closes'][$j] < $ema50[$j];
            $regimeSide = $regimeAt[$j]['side'];
            $returns = $j >= 24 && $btcBase['closes'][$j - 24] > 0 ? ($btcBase['closes'][$j] / $btcBase['closes'][$j - 24] - 1) * 100 : null;

            $out[$i] = [
                'block_long' => $weak1h && $regimeSide === 'SHORT',
                'block_short' => ! $weak1h && $regimeSide === 'LONG',
                'state' => $regimeSide === 'NONE' ? ($weak1h ? 'BTC soft' : 'BTC firm') : ($regimeSide === 'LONG' ? 'BTC uptrend' : 'BTC downtrend'),
                'returns_24' => $returns,
            ];
        }

        return $out;
    }

    /**
     * Raw setup triggers on bar i (both sides), before filters.
     *
     * @param  array<string, array<int, float|int>>  $c
     * @param  array<string, array<int, float|null>>  $s
     * @return array<int, array{setup: string, side: string, sl: float}>
     */
    protected function rawTriggers(int $i, array $c, array $s): array
    {
        foreach (['ema9', 'ema21', 'ema50', 'rsi', 'atr', 'vol_sma'] as $key) {
            if (($s[$key][$i] ?? null) === null || ($s[$key][$i - 1] ?? null) === null) {
                return [];
            }
        }

        $open = $c['opens'][$i];
        $high = $c['highs'][$i];
        $low = $c['lows'][$i];
        $close = $c['closes'][$i];
        $range = max(1e-12, $high - $low);
        $closePos = ($close - $low) / $range;
        $bodyRatio = abs($close - $open) / $range;
        $atr = (float) $s['atr'][$i];
        $ema9 = (float) $s['ema9'][$i];
        $ema21 = (float) $s['ema21'][$i];
        $ema50 = (float) $s['ema50'][$i];
        $prevClose = $c['closes'][$i - 1];
        $prevEma9 = (float) $s['ema9'][$i - 1];

        $triggers = [];

        // 1. Squeeze Breakout
        $squeezePct = $this->recentSqueezePercentile($i, $s['bbw']);
        if ($squeezePct !== null && $squeezePct <= 0.20 && $i >= 21) {
            $priorHigh = max(array_slice($c['highs'], $i - 20, 20));
            $priorLow = min(array_slice($c['lows'], $i - 20, 20));
            $volumeOk = $c['volumes'][$i] >= 1.5 * (float) $s['vol_sma'][$i - 1];

            if ($volumeOk && $close > $priorHigh * 1.001 && $closePos >= 0.7) {
                $triggers[] = ['setup' => 'SQUEEZE_BREAKOUT', 'side' => 'LONG', 'sl' => max($low, $close - 1.2 * $atr)];
            }
            if ($volumeOk && $close < $priorLow * 0.999 && $closePos <= 0.3) {
                $triggers[] = ['setup' => 'SQUEEZE_BREAKOUT', 'side' => 'SHORT', 'sl' => min($high, $close + 1.2 * $atr)];
            }
        }

        // 2. Trend Pullback into the EMA21-EMA50 zone, then a close back beyond EMA9
        $window = range(max(0, $i - 4), $i);
        $rsiWindow = array_map(fn (int $j): float => (float) ($s['rsi'][$j] ?? 50), $window);

        if ($ema21 > $ema50 && $close > $ema9 && $prevClose <= $prevEma9 && $closePos >= 0.6 && $close > $ema21) {
            $touched = false;
            foreach ($window as $j) {
                if ($s['ema21'][$j] !== null && $s['ema50'][$j] !== null && $c['lows'][$j] <= $s['ema21'][$j] && $c['closes'][$j] >= $s['ema50'][$j] * 0.995) {
                    $touched = true;
                }
            }
            $minRsi = min($rsiWindow);
            if ($touched && $minRsi >= 38 && $minRsi <= 50) {
                $swingLow = min(array_map(fn (int $j): float => $c['lows'][$j], $window));
                $triggers[] = ['setup' => 'TREND_PULLBACK', 'side' => 'LONG', 'sl' => $swingLow - 0.2 * $atr];
            }
        }

        if ($ema21 < $ema50 && $close < $ema9 && $prevClose >= $prevEma9 && $closePos <= 0.4 && $close < $ema21) {
            $touched = false;
            foreach ($window as $j) {
                if ($s['ema21'][$j] !== null && $s['ema50'][$j] !== null && $c['highs'][$j] >= $s['ema21'][$j] && $c['closes'][$j] <= $s['ema50'][$j] * 1.005) {
                    $touched = true;
                }
            }
            $maxRsi = max($rsiWindow);
            if ($touched && $maxRsi >= 50 && $maxRsi <= 62) {
                $swingHigh = max(array_map(fn (int $j): float => $c['highs'][$j], $window));
                $triggers[] = ['setup' => 'TREND_PULLBACK', 'side' => 'SHORT', 'sl' => $swingHigh + 0.2 * $atr];
            }
        }

        // 3. EMA 9/21 cross (shadow)
        $prevEma21 = (float) $s['ema21'][$i - 1];
        if ($prevEma9 <= $prevEma21 && $ema9 > $ema21 && $close > $ema9 && $close > $open && $bodyRatio >= 0.4) {
            $triggers[] = ['setup' => 'EMA_CROSS', 'side' => 'LONG', 'sl' => min($low, $c['lows'][$i - 1]) - 0.2 * $atr];
        }
        if ($prevEma9 >= $prevEma21 && $ema9 < $ema21 && $close < $ema9 && $close < $open && $bodyRatio >= 0.4) {
            $triggers[] = ['setup' => 'EMA_CROSS', 'side' => 'SHORT', 'sl' => max($high, $c['highs'][$i - 1]) + 0.2 * $atr];
        }

        // 4. Swing reversal at a 20-bar extreme (shadow)
        if ($i >= 22) {
            $recentLow = min(array_slice($c['lows'], $i - 20, 20));
            $recentHigh = max(array_slice($c['highs'], $i - 20, 20));
            $rsiNow = (float) $s['rsi'][$i];
            $rsiPrev = (float) $s['rsi'][$i - 1];
            $last3Rsi = array_slice($rsiWindow, -3);

            if (min($low, $c['lows'][$i - 1]) <= $recentLow * 1.003 && min($last3Rsi) <= 35 && $rsiNow > $rsiPrev && $close > $open && $closePos >= 0.6 && $close > $ema9) {
                $triggers[] = ['setup' => 'SWING_REVERSAL', 'side' => 'LONG', 'sl' => min(array_slice($c['lows'], $i - 2, 3)) - 0.2 * $atr];
            }
            if (max($high, $c['highs'][$i - 1]) >= $recentHigh * 0.997 && max($last3Rsi) >= 65 && $rsiNow < $rsiPrev && $close < $open && $closePos <= 0.4 && $close < $ema9) {
                $triggers[] = ['setup' => 'SWING_REVERSAL', 'side' => 'SHORT', 'sl' => max(array_slice($c['highs'], $i - 2, 3)) + 0.2 * $atr];
            }
        }

        return $triggers;
    }

    /**
     * Lowest BB-width percentile over the 3 bars before bar i, relative to the prior 100 bars.
     *
     * @param  array<int, float|null>  $bbw
     */
    protected function recentSqueezePercentile(int $i, array $bbw): ?float
    {
        if ($i < 104) {
            return null;
        }

        $history = array_values(array_filter(array_slice($bbw, $i - 100, 100), fn ($v): bool => $v !== null));
        if (count($history) < 50) {
            return null;
        }

        $best = null;
        for ($j = $i - 3; $j <= $i - 1; $j++) {
            if ($bbw[$j] === null) {
                continue;
            }
            $below = count(array_filter($history, fn (float $v): bool => $v < $bbw[$j]));
            $pct = $below / count($history);
            $best = $best === null ? $pct : min($best, $pct);
        }

        return $best;
    }

    /**
     * Highest-priority trigger on bar i that did not already fire within the suppression window.
     *
     * @param  array<int, array<int, array{setup: string, side: string, sl: float}>>  $raw
     * @return array{setup: string, side: string, sl: float}|null
     */
    protected function pickTrigger(int $i, array $raw): ?array
    {
        $candidates = $raw[$i] ?? [];
        usort($candidates, fn (array $a, array $b): int => array_search($a['setup'], self::PRIORITY, true) <=> array_search($b['setup'], self::PRIORITY, true));

        foreach ($candidates as $candidate) {
            $repeat = false;
            for ($j = $i - self::REPEAT_SUPPRESS_BARS; $j < $i; $j++) {
                foreach ($raw[$j] ?? [] as $previous) {
                    if ($previous['setup'] === $candidate['setup'] && $previous['side'] === $candidate['side']) {
                        $repeat = true;
                    }
                }
            }

            if (! $repeat) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array{setup: string, side: string, sl: float}  $trigger
     * @param  array<string, array<int, float|int>>  $c
     * @param  array<string, array<int, float|null>>  $s
     * @param  array{side: string, adx: ?float, detail: string}  $regime
     * @param  array{block_long: bool, block_short: bool, state: string, returns_24: ?float}  $btc
     * @param  array<string, mixed>  $context  Only supplied for the latest bar
     */
    protected function buildSignal(string $symbol, string $interval, int $i, array $trigger, array $c, array $s, array $regime, array $btc, array $context): Signal
    {
        $side = $trigger['side'];
        $direction = $side === 'LONG' ? 1 : -1;
        $entry = (float) $c['closes'][$i];
        $atr = (float) $s['atr'][$i];
        $atrPct = $entry > 0 ? $atr / $entry * 100 : 0.0;

        // Enforce the minimum stop distance; a too-wide stop is flagged, never widened.
        $sl = (float) $trigger['sl'];
        $slPct = abs($entry - $sl) / $entry * 100;
        $minSl = (float) $this->config['min_sl_pct'];
        if ($slPct < $minSl) {
            $sl = $entry - $direction * $entry * $minSl / 100;
            $slPct = $minSl;
        }
        $targets = $this->exitPlan->targets($side, $entry, $sl);

        $filters = $this->evaluateFilters($side, $regime, $btc, $atrPct, $slPct, $context, $trigger['setup']);
        $indicators = $this->indicatorsAt($i, $c, $s, $regime, $btc, $side);
        $confluences = $this->confluences($indicators, $side, $context['daily'] ?? null);

        return new Signal(
            symbol: $symbol,
            interval: $interval,
            side: $side,
            setup: $trigger['setup'],
            setupLabel: self::SETUP_LABELS[$trigger['setup']],
            time: intdiv((int) $c['closeTimes'][$i], 1000),
            entry: $entry,
            stopLoss: $sl,
            tp1: $targets['tp1'],
            tp2: $targets['tp2'],
            tp3: $targets['tp3'],
            atr: $atr,
            isShadow: ! in_array($trigger['setup'], (array) $this->config['core_setups'], true),
            filters: $filters,
            confluences: $confluences,
            features: $this->features($trigger['setup'], $side, $indicators, $slPct, (int) $c['closeTimes'][$i], $context),
            indicators: $indicators,
        );
    }

    /**
     * @param  array{side: string, adx: ?float, detail: string}  $regime
     * @param  array{block_long: bool, block_short: bool, state: string, returns_24: ?float}  $btc
     * @param  array<string, mixed>  $context
     * @return array<string, array{pass: bool, detail: string}>
     */
    protected function evaluateFilters(string $side, array $regime, array $btc, float $atrPct, float $slPct, array $context, ?string $setup = null): array
    {
        $minAtr = (float) $this->config['min_atr_pct'];
        // Some setups need more movement: on quiet coins the stop is tight, so fees eat a large share of each R.
        $minAtr = max($minAtr, (float) (((array) ($this->config['min_atr_pct_by_setup'] ?? []))[$setup ?? ''] ?? 0));
        $maxAtr = (float) $this->config['max_atr_pct'];
        $maxSl = $this->maxSlPct($setup);
        $btcBlocked = $side === 'LONG' ? $btc['block_long'] : $btc['block_short'];

        $filters = [
            'regime' => ['pass' => $regime['side'] === $side, 'detail' => $regime['detail']],
            'btc_macro' => ['pass' => ! $btcBlocked, 'detail' => $btcBlocked ? "{$btc['state']}: against this {$side}" : $btc['state']],
            'volatility' => ['pass' => $atrPct >= $minAtr && $atrPct <= $maxAtr, 'detail' => sprintf('ATR %.2f%% (band %.2f-%.1f%%)', $atrPct, $minAtr, $maxAtr)],
            'stop_width' => ['pass' => $slPct <= $maxSl + 1e-9, 'detail' => sprintf('Stop %.2f%% (max %.2f%%)', $slPct, $maxSl)],
        ];

        // Squeeze breakouts only kept an edge on the most traded coins (12-month test by volume rank).
        $maxRank = ((array) ($this->config['max_volume_rank_by_setup'] ?? []))[$setup ?? ''] ?? null;
        $rank = $context['volume_rank'] ?? null;
        if ($maxRank !== null && $rank !== null) {
            $filters['volume_rank'] = ['pass' => (int) $rank <= (int) $maxRank, 'detail' => sprintf('Volume rank #%d (this setup: top %d)', $rank, $maxRank)];
        }

        if (array_key_exists('quote_volume_24h', $context) && $context['quote_volume_24h'] !== null) {
            $volume = (float) $context['quote_volume_24h'];
            $minVolume = (float) $this->config['min_quote_volume_24h'];
            $listingDays = $context['listing_days'] ?? null;
            $listedOk = $listingDays === null || $listingDays >= (int) $this->config['min_listing_days'];
            $filters['liquidity'] = [
                'pass' => $volume >= $minVolume && $listedOk,
                'detail' => sprintf('24h volume $%sM%s', number_format($volume / 1e6, 1), $listedOk ? '' : ", listed {$listingDays}d"),
            ];
        }

        if (array_key_exists('funding_rate', $context) && $context['funding_rate'] !== null) {
            $funding = (float) $context['funding_rate'];
            $adverse = $side === 'LONG' ? $funding : -$funding;
            $filters['funding'] = [
                'pass' => $adverse <= (float) $this->config['max_adverse_funding'],
                'detail' => sprintf('Funding %.4f%%', $funding * 100),
            ];
        }

        if (isset($context['tradable_size']) && is_array($context['tradable_size'])) {
            $filters['position_size'] = $context['tradable_size'];
        }

        return $filters;
    }

    /**
     * @param  array<string, array<int, float|int>>  $c
     * @param  array<string, array<int, float|null>>  $s
     * @param  array{side: string, adx: ?float, detail: string}  $regime
     * @param  array{block_long: bool, block_short: bool, state: string, returns_24: ?float}  $btc
     * @return array<string, float|int|string|null>
     */
    protected function indicatorsAt(int $i, array $c, array $s, array $regime, array $btc, string $side): array
    {
        $close = (float) $c['closes'][$i];
        $atr = (float) ($s['atr'][$i] ?? 0);
        $volSma = (float) ($s['vol_sma'][$i - 1] ?? 0);
        $coinReturn = $i >= 24 && $c['closes'][$i - 24] > 0 ? ($close / $c['closes'][$i - 24] - 1) * 100 : 0.0;
        $relative = $btc['returns_24'] !== null ? $coinReturn - $btc['returns_24'] : 0.0;

        $bbwHistory = array_values(array_filter(array_slice($s['bbw'], max(0, $i - 100), 100), fn ($v): bool => $v !== null));
        $bbwPct = ($s['bbw'][$i] !== null && $bbwHistory !== [])
            ? count(array_filter($bbwHistory, fn (float $v): bool => $v < $s['bbw'][$i])) / count($bbwHistory)
            : 0.5;

        return [
            'rsi' => round((float) ($s['rsi'][$i] ?? 50), 2),
            'adx' => round((float) ($s['adx'][$i] ?? 0), 2),
            'adx_4h' => $regime['adx'] !== null ? round($regime['adx'], 2) : null,
            'atr' => $atr,
            'atr_pct' => $close > 0 ? round($atr / $close * 100, 3) : 0.0,
            'volume_ratio' => $volSma > 0 ? round($c['volumes'][$i] / $volSma, 2) : 1.0,
            'ema21_dist_atr' => $atr > 0 && $s['ema21'][$i] !== null ? round(($close - (float) $s['ema21'][$i]) / $atr, 3) : 0.0,
            'bbw_pct' => round($bbwPct, 3),
            'rs_vs_btc' => round($relative, 3),
            'regime' => $regime['side'],
            'btc_regime' => $btc['state'],
            'btc_aligned' => $side === 'LONG' ? ($btc['block_long'] ? -1 : ($btc['block_short'] ? 1 : 0)) : ($btc['block_short'] ? -1 : ($btc['block_long'] ? 1 : 0)),
        ];
    }

    /**
     * @param  array<string, float|int|string|null>  $indicators
     * @param  array<string, array<int, float|int>>|null  $daily
     * @return array<int, string>
     */
    protected function confluences(array $indicators, string $side, ?array $daily): array
    {
        $direction = $side === 'LONG' ? 1 : -1;
        $out = [];

        if ((float) $indicators['volume_ratio'] >= 2.0) {
            $out[] = 'Volume '.round((float) $indicators['volume_ratio'], 1).'x';
        }
        if ((float) $indicators['adx'] >= 25) {
            $out[] = 'Strong 1h trend (ADX '.round((float) $indicators['adx']).')';
        }
        if ((float) $indicators['rs_vs_btc'] * $direction > 0.5) {
            $out[] = ($side === 'LONG' ? 'Outperforming' : 'Underperforming').' BTC';
        }

        if ($daily !== null && count($daily['closes'] ?? []) >= 50) {
            $ema50 = Indicators::ema($daily['closes'], 50);
            $last = count($daily['closes']) - 1;
            if ($ema50[$last] !== null && ($daily['closes'][$last] - $ema50[$last]) * $direction > 0) {
                $out[] = 'Daily trend agrees';
            }
        } elseif ($indicators['adx_4h'] !== null && (float) $indicators['adx_4h'] >= 25) {
            $out[] = 'Strong 4h trend';
        }

        return $out;
    }

    /**
     * Model features captured at signal time (also stored for retraining the AI model).
     *
     * @param  array<string, float|int|string|null>  $indicators
     * @param  array<string, mixed>  $context
     * @return array<string, float|int|string>
     */
    protected function features(string $setup, string $side, array $indicators, float $slPct, int $closeTimeMs, array $context): array
    {
        $direction = $side === 'LONG' ? 1 : -1;
        $hour = (int) gmdate('G', intdiv($closeTimeMs, 1000));
        $funding = isset($context['funding_rate']) ? (float) $context['funding_rate'] : 0.0;

        return [
            'setup' => $setup,
            'side' => $direction,
            'adx_4h' => (float) ($indicators['adx_4h'] ?? 0),
            'adx_1h' => (float) $indicators['adx'],
            'rsi' => (float) $indicators['rsi'],
            'volume_ratio' => min(10.0, (float) $indicators['volume_ratio']),
            'atr_pct' => (float) $indicators['atr_pct'],
            'ema21_dist_atr' => (float) $indicators['ema21_dist_atr'] * $direction,
            'bbw_pct' => (float) $indicators['bbw_pct'],
            'rs_vs_btc' => (float) $indicators['rs_vs_btc'] * $direction,
            'btc_aligned' => (int) $indicators['btc_aligned'],
            'funding_adverse' => round($funding * $direction * 10000, 3),
            'hour_sin' => round(sin(2 * M_PI * $hour / 24), 4),
            'hour_cos' => round(cos(2 * M_PI * $hour / 24), 4),
            'sl_pct' => round($slPct, 3),
        ];
    }

    /**
     * Current status for the chart inspector and scanner watchlist.
     *
     * @param  array<string, array<int, float|int>>  $c
     * @param  array<string, array<int, float|null>>  $s
     * @param  array{side: string, adx: ?float, detail: string}  $regime
     * @param  array{block_long: bool, block_short: bool, state: string, returns_24: ?float}  $btc
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function currentState(string $symbol, int $i, array $c, array $s, array $regime, array $btc, array $context, ?Signal $latest): array
    {
        $bias = $regime['side'];
        $close = (float) $c['closes'][$i];
        $atr = (float) ($s['atr'][$i] ?? 0);
        $atrPct = $close > 0 ? $atr / $close * 100 : 0.0;
        $checkSide = $bias === 'NONE' ? 'LONG' : $bias;
        $checklist = $this->evaluateFilters($checkSide, $regime, $btc, $atrPct, 0.0, $context);
        unset($checklist['stop_width']);

        $near = null;
        if ($bias !== 'NONE' && $i >= 21 && $s['ema21'][$i] !== null && $atr > 0) {
            $priorHigh = max(array_slice($c['highs'], $i - 19, 20));
            $priorLow = min(array_slice($c['lows'], $i - 19, 20));
            $ema21 = (float) $s['ema21'][$i];

            if ($bias === 'LONG' && $priorHigh > 0 && ($priorHigh - $close) / $priorHigh * 100 <= 0.5) {
                $near = ['type' => 'BREAKOUT_LEVEL', 'level' => $priorHigh, 'detail' => sprintf('%.2f%% below the 20-bar high', ($priorHigh - $close) / $priorHigh * 100)];
            } elseif ($bias === 'SHORT' && $priorLow > 0 && ($close - $priorLow) / $priorLow * 100 <= 0.5) {
                $near = ['type' => 'BREAKDOWN_LEVEL', 'level' => $priorLow, 'detail' => sprintf('%.2f%% above the 20-bar low', ($close - $priorLow) / $priorLow * 100)];
            } elseif (abs($close - $ema21) <= 0.3 * $atr) {
                $near = ['type' => 'PULLBACK_ZONE', 'level' => $ema21, 'detail' => 'Price is testing the EMA21 pullback zone'];
            }
        }

        if ($latest !== null) {
            $status = $latest->isTradable() ? $latest->side : 'WAIT';
            $reason = $latest->isTradable()
                ? "{$latest->setupLabel} {$latest->side} on the last closed candle."
                : "{$latest->setupLabel} {$latest->side} printed but did not pass: ".implode('; ', $latest->failedFilters()).($latest->isShadow ? ' (shadow setup, tracked only)' : '');
        } else {
            $status = 'WAIT';
            $reason = match ($bias) {
                'NONE' => 'No clear 4h trend. Standing aside.',
                'LONG' => $near !== null ? "Uptrend. Watching: {$near['detail']}." : 'Uptrend. Waiting for a pullback into EMA21-50 or a squeeze breakout.',
                default => $near !== null ? "Downtrend. Watching: {$near['detail']}." : 'Downtrend. Waiting for a rally into EMA21-50 or a squeeze breakdown.',
            };
        }

        return [
            'symbol' => $symbol,
            'bias' => $bias,
            'status' => $status,
            'reason' => $reason,
            'checklist' => $checklist,
            'near' => $near,
            'price' => $close,
            'atr' => $atr,
            'atr_pct' => round($atrPct, 3),
            'rsi' => $s['rsi'][$i] !== null ? round((float) $s['rsi'][$i], 1) : null,
            'adx' => $s['adx'][$i] !== null ? round((float) $s['adx'][$i], 1) : null,
            'btc' => $btc['state'],
            'candle_time' => intdiv((int) $c['closeTimes'][$i], 1000),
        ];
    }
}
