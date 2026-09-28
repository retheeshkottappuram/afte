<?php

namespace App\Services\Crypto;

/**
 * SignalEngine v2 — selective multi-confirmation signal engine.
 *
 * Implements:
 * - Trend: Fast (9), Slow (21), Baseline Trend (200) EMAs
 * - Dual HTF Confluence (HTF1 & HTF2)
 * - ADX & DMI Trend Strength & Polarity
 * - RSI Momentum & Sweet-Spot Filtering
 * - Volume Confirmation & On-Balance Volume (OBV)
 * - Candlestick Quality (Strong Body / Engulfing)
 * - Market Structure (Breakouts & Swing Highs/Lows)
 * - Volatility Gating & Bollinger Band Expansion
 * - Hard Gates: Overextension (< 2.5 ATR), 2-Swing Divergence, Persistence, R:R >= 1.5
 * - Grade Scoring: 'A' (>= 90), 'B' (>= 82), 'C'
 */
class SignalEngine
{
    /**
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            // Trend
            'fast_len' => 9,
            'slow_len' => 21,
            'trend_len' => 200,
            'use_htf1' => true,
            'use_htf2' => true,

            // RSI Momentum
            'rsi_len' => 14,
            'rsi_long_min' => 52.0,
            'rsi_short_max' => 48.0,

            // ADX
            'adx_len' => 14,
            'adx_min' => 20.0,

            // Volume
            'vol_len' => 20,
            'vol_mult' => 1.30,
            'obv_lookback' => 5,

            // Structure
            'structure_len' => 20,
            'breakout_buffer_pct' => 0.15,

            // Volatility / ATR
            'atr_len' => 14,
            'min_atr_pct' => 0.05,
            'max_atr_pct' => 6.0,

            // Bollinger Bands
            'bb_len' => 20,
            'bb_mult' => 2.0,
            'bb_expansion_lookback' => 3,

            // Consistency & Divergence
            'persistence_bars' => 3,
            'divergence_lookback' => 24,

            // Risk
            'sl_mult' => 1.5,
            'tp1_mult' => 1.8,
            'tp2_mult' => 3.5,
            'tp3_mult' => 5.0,
            'use_structure_sl' => true,
            'min_rr' => 2.0,

            // Thresholds (Institutional High-Confluence Tunables)
            'minimum_score' => 82,
            'grade_a' => 90,
            'grade_b' => 82,
            'signal_cooldown_bars' => 4,
            'opposite_cooldown_bars' => 3,
            'enable_reversals' => false,
            'reversal_min_score' => 80,
            'reversal_rsi_overbought' => 68.0,
            'reversal_rsi_oversold' => 32.0,
        ], $config);

        // Map legacy/convenience alias keys
        if (isset($config['fast_ema'])) {
            $this->config['fast_len'] = (int) $config['fast_ema'];
        }
        if (isset($config['slow_ema'])) {
            $this->config['slow_len'] = (int) $config['slow_ema'];
        }
        if (isset($config['trend_ema'])) {
            $this->config['trend_len'] = (int) $config['trend_ema'];
        }
        if (isset($config['min_score'])) {
            $this->config['minimum_score'] = (int) $config['min_score'];
        }
        if (isset($config['use_htf'])) {
            $this->config['use_htf1'] = (bool) $config['use_htf'];
        }
    }

    /**
     * Evaluate candle data for buy/sell signal on the last fully closed candle.
     *
     * @param  array<string, mixed>  $candles
     * @param  array<string, mixed>|null  $htf1
     * @param  array<string, mixed>|null  $htf2
     * @return array<string, mixed>|null
     */
    public function evaluate(array $candles, ?array $htf1 = null, ?array $htf2 = null, ?array $btcCandles = null): ?array
    {
        return $this->evaluateDetailed($candles, $htf1, $htf2, $btcCandles)['signal'];
    }

    /**
     * Evaluate candle data and return both signal and diagnostics metrics.
     *
     * @param  array<string, mixed>  $candles
     * @param  array<string, mixed>|null  $htf1
     * @param  array<string, mixed>|null  $htf2
     * @param  array<string, mixed>|null  $btcCandles
     * @return array{signal: array<string, mixed>|null, diagnostics: array<string, mixed>}
     */
    public function evaluateDetailed(array $candles, ?array $htf1 = null, ?array $htf2 = null, ?array $btcCandles = null): array
    {
        $c = $this->config;
        $closes = $candles['closes'] ?? [];
        $highs = $candles['highs'] ?? [];
        $lows = $candles['lows'] ?? [];
        $opens = $candles['opens'] ?? [];
        $volumes = $candles['volumes'] ?? [];
        $closeTimes = $candles['closeTimes'] ?? [];

        $count = count($closes);
        $i = $count - 2;

        $minHistory = max((int) $c['trend_len'], (int) $c['structure_len'] + 2, (int) $c['adx_len'] * 2, (int) $c['divergence_lookback'] + 2);
        if ($i < $minHistory) {
            return [
                'signal' => null,
                'diagnostics' => [
                    'rejection' => "Insufficient candles (need at least {$minHistory}, got {$count})",
                ],
            ];
        }

        $emaFast = Indicators::ema($closes, (int) $c['fast_len']);
        $emaSlow = Indicators::ema($closes, (int) $c['slow_len']);
        $emaTrend = Indicators::ema($closes, (int) $c['trend_len']);
        $rsi = Indicators::rsi($closes, (int) $c['rsi_len']);
        $atr = Indicators::atr($highs, $lows, $closes, (int) $c['atr_len']);
        $volSma = Indicators::sma($volumes, (int) $c['vol_len']);
        $obv = Indicators::obv($closes, $volumes);
        $bbWidth = Indicators::bbWidthPercent($closes, (int) $c['bb_len'], (float) $c['bb_mult']);
        $ttm = Indicators::ttmSqueeze($highs, $lows, $closes, 20, 2.0, 1.5, 20);
        $dmiRes = Indicators::dmi($highs, $lows, $closes, (int) $c['adx_len'], (int) $c['adx_len']);
        $plusDI = $dmiRes['plus_di'] ?? $dmiRes[0];
        $minusDI = $dmiRes['minus_di'] ?? $dmiRes[1];
        $adx = $dmiRes['adx'] ?? $dmiRes[2];

        $rsRatio = 1.0;
        if ($btcCandles && ! empty($btcCandles['closes'])) {
            $rsRatio = Indicators::relativeStrength($closes, $btcCandles['closes'], 24);
        }

        if (
            $emaFast[$i] === null ||
            $emaSlow[$i] === null ||
            $rsi[$i] === null ||
            $atr[$i] === null ||
            $volSma[$i] === null ||
            $adx[$i] === null
        ) {
            return [
                'signal' => null,
                'diagnostics' => ['rejection' => 'Indicator values not ready at evaluated bar'],
            ];
        }

        $close = (float) $closes[$i];
        $open = (float) $opens[$i];
        $high = (float) $highs[$i];
        $low = (float) $lows[$i];

        $trendBullAt = fn (int $idx): bool => $idx >= 0 &&
            $emaFast[$idx] !== null &&
            $emaSlow[$idx] !== null &&
            $emaFast[$idx] >= $emaSlow[$idx] &&
            ($emaTrend[$idx] === null || $closes[$idx] >= (float) $emaTrend[$idx] * 0.995);

        $trendBearAt = fn (int $idx): bool => $idx >= 0 &&
            $emaFast[$idx] !== null &&
            $emaSlow[$idx] !== null &&
            $emaFast[$idx] <= $emaSlow[$idx] &&
            ($emaTrend[$idx] === null || $closes[$idx] <= (float) $emaTrend[$idx] * 1.005);

        $trendBull = $trendBullAt($i);
        $trendBear = $trendBearAt($i);

        [$htf1Bull, $htf1Bear] = $this->htfTrend($htf1, (int) $c['trend_len']);
        [$htf2Bull, $htf2Bear] = $this->htfTrend($htf2, (int) $c['trend_len']);

        $htf1OK = ! $c['use_htf1'] || $htf1 === null || $htf1Bull;
        $htf1OKBear = ! $c['use_htf1'] || $htf1 === null || $htf1Bear;

        $candleRange = max(0.0000001, $high - $low);
        $body = abs($close - $open);
        $bodyRatio = $body / $candleRange;
        $upperWick = $high - max($close, $open);
        $lowerWick = min($close, $open) - $low;
        $upperWickRatio = $upperWick / $candleRange;
        $lowerWickRatio = $lowerWick / $candleRange;

        $bullCandle = ($close > $open) && ($bodyRatio >= 0.40) && ($upperWickRatio <= 0.30);
        $bearCandle = ($close < $open) && ($bodyRatio >= 0.40) && ($lowerWickRatio <= 0.30);

        // Institutional market structure discovery
        $structureLen = (int) $c['structure_len'];
        $lookbackHighs = array_slice($highs, max(0, $i - $structureLen), $structureLen);
        $lookbackLows = array_slice($lows, max(0, $i - $structureLen), $structureLen);
        $previousHigh = max($lookbackHighs);
        $previousLow = min($lookbackLows);

        $distToResPct = $previousHigh > 0 ? (($previousHigh - $close) / $previousHigh) * 100.0 : 999.0;
        $distToSupPct = $previousLow > 0 ? (($close - $previousLow) / $previousLow) * 100.0 : 999.0;

        // Local structure swings
        $low1 = $lows[$i];
        $low2 = $lows[$i - 1];
        $low3 = $lows[$i - 2];
        $hasHigherLows = ($low1 >= $low2 * 0.999 && $low2 >= $low3 * 0.999);

        $high1 = $highs[$i];
        $high2 = $highs[$i - 1];
        $high3 = $highs[$i - 2];
        $hasLowerHighs = ($high1 <= $high2 * 1.001 && $high2 <= $high3 * 1.001);

        // Volatility Squeeze Check (BB inside KC or narrow width)
        $bbCompression = false;
        if (isset($bbWidth[$i]) && $bbWidth[$i] !== null) {
            $recentBbWidths = array_slice(array_filter($bbWidth), -25);
            if (! empty($recentBbWidths)) {
                $minWidth = min($recentBbWidths);
                $bbCompression = ($bbWidth[$i] <= $minWidth * 1.25);
            }
        }
        $isCompressed = ($ttm['squeeze_on'][$i] ?? false) || $bbCompression;

        $volRatio = ($volSma[$i] !== null && $volSma[$i] > 0) ? round($volumes[$i] / $volSma[$i], 2) : 1.0;
        $above200Ema = $emaTrend[$i] === null || $close > (float) $emaTrend[$i];
        $below200Ema = $emaTrend[$i] === null || $close < (float) $emaTrend[$i];

        $breakoutBuffer = (float) $c['breakout_buffer_pct'] / 100.0;
        $atrPct = $close > 0 ? ($atr[$i] / $close * 100.0) : 0.0;

        // ==========================================
        // HIGH-CONFLUENCE PRE-BREAKOUT SETUPS
        // ==========================================
        $setupType = null;
        $setupLabel = null;
        $side = null;
        $rawSl = 0.0;
        $score = 0;

        // Long Setup 1: Wyckoff Spring Liquidity Reversal
        $isSpringLong = ($low < $previousLow || $lows[$i - 1] < $previousLow)
            && ($close > $previousLow)
            && ($close > $open)
            && ($lowerWickRatio >= 0.40)
            && ! $htf1Bear
            && $close > $emaFast[$i]
            && $above200Ema
            && $rsRatio >= 0.995;

        // Long Setup 2: Pre-Breakout Ascending Coil Squeeze
        $isPreBreakoutLong = ($distToResPct >= 0.10 && $distToResPct <= 0.85)
            && ($hasHigherLows || $close > $emaFast[$i])
            && $bullCandle
            && $upperWickRatio <= 0.25
            && $rsRatio >= 0.995
            && $htf1OK
            && $trendBull
            && $above200Ema;

        // Long Setup 3: Institutional 21-EMA Value Pullback Bounce
        $isPullbackBounceLong = $trendBull
            && $htf1OK
            && $above200Ema
            && $rsRatio >= 0.995
            && ($low <= $emaSlow[$i] * 1.003 && $close > $emaFast[$i])
            && $bullCandle
            && ($lowerWickRatio >= 0.25)
            && ($rsi[$i] >= 46.0 && $rsi[$i] <= 68.0);

        // Long Setup 4: Confirmed Donchian Breakout
        $isDonchianBreakoutLong = $close > ($previousHigh * (1.0 + $breakoutBuffer))
            && $bullCandle && $htf1OK && $volRatio >= 1.15 && $above200Ema && $rsRatio >= 0.995;

        // Long Setup 5: Breakout Retest Bounce
        $isRetestLong = ($i >= 2 && $closes[$i - 1] > $previousHigh && $low <= ($previousHigh * 1.003) && $close >= $previousHigh && $bullCandle && $htf1OK && $above200Ema);

        // Short Setup 1: Wyckoff Upthrust Liquidity Reversal
        $isUpthrustShort = ($high > $previousHigh || $highs[$i - 1] > $previousHigh)
            && ($close < $previousHigh)
            && ($close < $open)
            && ($upperWickRatio >= 0.40)
            && ! $htf1Bull
            && $close < $emaFast[$i]
            && $below200Ema
            && $rsRatio <= 1.005;

        // Short Setup 2: Pre-Breakdown Descending Coil Squeeze
        $isPreBreakdownShort = ($distToSupPct >= 0.10 && $distToSupPct <= 0.85)
            && ($hasLowerHighs || $close < $emaFast[$i])
            && $bearCandle
            && $lowerWickRatio <= 0.25
            && $rsRatio <= 1.005
            && $htf1OKBear
            && $trendBear
            && $below200Ema;

        // Short Setup 3: Institutional 21-EMA Value Pullback Rejection
        $isPullbackRejectShort = $trendBear
            && $htf1OKBear
            && $below200Ema
            && $rsRatio <= 1.005
            && ($high >= $emaSlow[$i] * 0.997 && $close < $emaFast[$i])
            && $bearCandle
            && ($upperWickRatio >= 0.25)
            && ($rsi[$i] <= 54.0 && $rsi[$i] >= 32.0);

        // Short Setup 4: Confirmed Donchian Breakdown
        $isDonchianBreakdownShort = $close < ($previousLow * (1.0 - $breakoutBuffer))
            && $bearCandle && $htf1OKBear && $volRatio >= 1.15 && $below200Ema && $rsRatio <= 1.005;

        // Short Setup 5: Breakdown Retest Rejection
        $isRetestShort = ($i >= 2 && $closes[$i - 1] < $previousLow && $high >= ($previousLow * 0.997) && $close <= $previousLow && $bearCandle && $htf1OKBear && $below200Ema);

        if ($isSpringLong) {
            $side = 'BUY';
            $setupType = 'WYCKOFF_SPRING';
            $setupLabel = 'WYCKOFF SPRING REVERSAL';
            $score = 95;
            $rawSl = min($low, $lows[$i - 1]) * 0.998;
        } elseif ($isPreBreakoutLong) {
            $side = 'BUY';
            $setupType = 'PRE_BREAKOUT_COIL';
            $setupLabel = 'PRE-BREAKOUT ASCENDING COIL';
            $score = 92;
            $rawSl = min($low1, $low2) * 0.998;
        } elseif ($isUpthrustShort) {
            $side = 'SELL';
            $setupType = 'WYCKOFF_UPTHRUST';
            $setupLabel = 'WYCKOFF UPTHRUST REVERSAL';
            $score = 95;
            $rawSl = max($high, $highs[$i - 1]) * 1.002;
        } elseif ($isPreBreakdownShort) {
            $side = 'SELL';
            $setupType = 'PRE_BREAKOUT_COIL';
            $setupLabel = 'PRE-BREAKDOWN DESCENDING COIL';
            $score = 92;
            $rawSl = max($high1, $high2) * 1.002;
        } elseif ($isPullbackBounceLong) {
            $side = 'BUY';
            $setupType = 'PULLBACK_VALUE';
            $setupLabel = '21-EMA VALUE PULLBACK BOUNCE';
            $score = 88;
            $rawSl = $low * 0.998;
        } elseif ($isPullbackRejectShort) {
            $side = 'SELL';
            $setupType = 'PULLBACK_VALUE';
            $setupLabel = '21-EMA VALUE PULLBACK REJECTION';
            $score = 88;
            $rawSl = $high * 1.002;
        } elseif ($isDonchianBreakoutLong) {
            $side = 'BUY';
            $setupType = 'BREAKOUT_CONFIRMED';
            $setupLabel = 'RESISTANCE BREAKOUT CONFIRMED';
            $score = 88;
            $rawSl = max($previousHigh * 0.995, $low * 0.998);
        } elseif ($isDonchianBreakdownShort) {
            $side = 'SELL';
            $setupType = 'BREAKDOWN_CONFIRMED';
            $setupLabel = 'SUPPORT BREAKDOWN CONFIRMED';
            $score = 88;
            $rawSl = min($previousLow * 1.005, $high * 1.002);
        } elseif ($isRetestLong) {
            $side = 'BUY';
            $setupType = 'RETEST_ENTRY';
            $setupLabel = 'BREAKOUT RETEST & BOUNCE';
            $score = 86;
            $rawSl = $low * 0.998;
        } elseif ($isRetestShort) {
            $side = 'SELL';
            $setupType = 'RETEST_ENTRY';
            $setupLabel = 'BREAKDOWN RETEST & REJECTION';
            $score = 86;
            $rawSl = $high * 1.002;
        }

        if ($side !== null) {
            if ($isCompressed) {
                $score += 3;
            }
            if ($volRatio >= 1.35) {
                $score += 2;
            }
            if ($side === 'BUY' && $rsRatio >= 1.01) {
                $score += 2;
            } elseif ($side === 'SELL' && $rsRatio <= 0.99) {
                $score += 2;
            }
            $score = min(99, $score);
        }

        $minScore = (int) $c['minimum_score'];
        $diagnostics = [
            'buy_score' => $side === 'BUY' ? $score : ($trendBull ? 72 : 50),
            'sell_score' => $side === 'SELL' ? $score : ($trendBear ? 72 : 50),
            'minimum_score' => $minScore,
            'rsi' => round((float) $rsi[$i], 2),
            'adx' => round((float) $adx[$i], 2),
            'volume_ratio' => $volRatio,
            'atr_pct' => round($atrPct, 2),
            'close' => round($close, 4),
            'rs_ratio' => round($rsRatio, 4),
            'rejection' => null,
        ];

        if ($side === null || $score < $minScore) {
            if ($side === null) {
                $diagnostics['rejection'] = 'No institutional setup (Spring, Pre-Breakout Coil, or 21-EMA Pullback)';
            } else {
                $diagnostics['rejection'] = "Setup score ({$score}) below minimum threshold ({$minScore})";
            }

            return [
                'signal' => null,
                'diagnostics' => $diagnostics,
            ];
        }

        // ==========================================
        // STRICT ASYMMETRIC RISK-REWARD ENGINE (Min 0.75%, Max 1.35% SL)
        // ==========================================
        $entry = $close;
        $minSlDist = $entry * 0.0075;
        $maxSlDist = $entry * 0.0135;
        $rawRisk = abs($entry - $rawSl);
        $risk = max($minSlDist, min($maxSlDist, $rawRisk));

        $sl = $side === 'BUY' ? round($entry - $risk, 6) : round($entry + $risk, 6);
        $tp1 = $side === 'BUY' ? round($entry + ($risk * 1.35), 6) : round($entry - ($risk * 1.35), 6); // 1:1.35 R:R (+15% ROE)
        $tp2 = $side === 'BUY' ? round($entry + ($risk * 2.80), 6) : round($entry - ($risk * 2.80), 6); // 1:2.80 R:R (+30% ROE)
        $tp3 = $side === 'BUY' ? round($entry + ($risk * 4.50), 6) : round($entry - ($risk * 4.50), 6); // 1:4.50 R:R (+50% ROE)

        $slDistancePct = $entry > 0 ? round(($risk / $entry) * 100.0, 2) : 1.0;
        $tp1Pct = $entry > 0 ? round(abs($tp1 - $entry) / $entry * 100.0, 2) : 1.35;
        $tp2Pct = $entry > 0 ? round(abs($tp2 - $entry) / $entry * 100.0, 2) : 2.80;
        $tp3Pct = $entry > 0 ? round(abs($tp3 - $entry) / $entry * 100.0, 2) : 4.50;
        $rrRatio = $slDistancePct > 0 ? round($tp2Pct / $slDistancePct, 1) : 2.8;

        $grade = $score >= (int) $c['grade_a'] ? 'A' : ($score >= (int) $c['grade_b'] ? 'B' : 'C');
        $recLeverage = '5x - 10x';
        $levMult = 10;

        $perpetualOptions = [
            'recommended_leverage' => $recLeverage,
            'margin_mode' => 'Isolated Margin',
            'order_type' => 'Limit / Market Entry',
            'risk_per_trade' => '1.0% - 2.0% Account Balance',
            'risk_reward' => "1 : {$rrRatio}",
            'sl_pct' => $slDistancePct,
            'tp1_pct' => $tp1Pct,
            'tp2_pct' => $tp2Pct,
            'tp3_pct' => $tp3Pct,
            'sl_leveraged_pct' => round($slDistancePct * $levMult, 1),
            'tp1_leveraged_pct' => round($tp1Pct * $levMult, 1),
            'tp2_leveraged_pct' => round($tp2Pct * $levMult, 1),
            'tp3_leveraged_pct' => round($tp3Pct * $levMult, 1),
            'leverage_multiplier' => $levMult,
            'liquidation_buffer' => '> 15% safety cushion',
        ];

        $signal = [
            'side' => $side,
            'score' => $score,
            'grade' => $grade,
            'setup_type' => $setupType,
            'setup_label' => $setupLabel,
            'entry' => round($entry, 6),
            'sl' => round($sl, 6),
            'tp1' => round($tp1, 6),
            'tp2' => round($tp2, 6),
            'tp3' => round($tp3, 6),
            'risk_reward' => "1 : {$rrRatio}",
            'rsi' => round((float) $rsi[$i], 2),
            'adx' => round((float) $adx[$i], 2),
            'volume_ratio' => $volRatio,
            'atr_pct' => round($atrPct, 2),
            'candle_close_time' => $closeTimes[$i] ?? 0,
            'rs_ratio' => round($rsRatio, 4),
            'breakout_level' => $side === 'BUY' ? round($previousHigh, 4) : round($previousLow, 4),
            'distance_pct' => $side === 'BUY' ? round($distToResPct, 2) : round($distToSupPct, 2),
            'perpetual_options' => $perpetualOptions,
        ];

        return [
            'signal' => $signal,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * Evaluate historical candles and return series data with SignalAlgo PRO signal markers.
     *
     * @param  array<string, mixed>  $candles
     * @param  array<string, mixed>|null  $htf1
     * @param  array<string, mixed>|null  $htf2
     * @param  array<string, mixed>|null  $btcCandles
     * @return array{
     *     candles: array<int, array{time: int, open: float, high: float, low: float, close: float, volume: float}>,
     *     markers: array<int, array<string, mixed>>,
     *     ema9: array<int, array{time: int, value: float}>,
     *     ema21: array<int, array{time: int, value: float}>,
     *     ema200: array<int, array{time: int, value: float}>
     * }
     */
    public function evaluateHistory(array $candles, ?array $htf1 = null, ?array $htf2 = null, int $lookback = 140, ?array $btcCandles = null): array
    {
        $c = $this->config;
        $closes = $candles['closes'] ?? [];
        $highs = $candles['highs'] ?? [];
        $lows = $candles['lows'] ?? [];
        $opens = $candles['opens'] ?? [];
        $volumes = $candles['volumes'] ?? [];
        $closeTimes = $candles['closeTimes'] ?? [];

        $count = count($closes);
        if ($count < 30) {
            return [
                'candles' => [],
                'markers' => [],
                'ema9' => [],
                'ema21' => [],
                'ema200' => [],
            ];
        }

        $fastLen = (int) $c['fast_len'];
        $slowLen = (int) $c['slow_len'];
        $trendLen = (int) $c['trend_len'];
        $rsiLen = (int) $c['rsi_len'];
        $atrLen = (int) $c['atr_len'];
        $volLen = (int) $c['vol_len'];
        $bbLen = (int) $c['bb_len'];
        $bbMult = (float) $c['bb_mult'];
        $adxLen = (int) $c['adx_len'];

        $emaFast = Indicators::ema($closes, $fastLen);
        $emaSlow = Indicators::ema($closes, $slowLen);
        $emaTrend = Indicators::ema($closes, $trendLen);
        $rsi = Indicators::rsi($closes, $rsiLen);
        $atr = Indicators::atr($highs, $lows, $closes, $atrLen);
        $volSma = Indicators::sma($volumes, $volLen);
        $bbWidth = Indicators::bbWidthPercent($closes, $bbLen, $bbMult);
        $ttm = Indicators::ttmSqueeze($highs, $lows, $closes, 20, 2.0, 1.5, 20);
        $dmiRes = Indicators::dmi($highs, $lows, $closes, $adxLen, $adxLen);
        $adx = $dmiRes['adx'] ?? $dmiRes[2];

        $rsRatio = 1.0;
        if ($btcCandles && ! empty($btcCandles['closes'])) {
            $rsRatio = Indicators::relativeStrength($closes, $btcCandles['closes'], 24);
        }

        [$htf1Bull, $htf1Bear] = $this->htfTrend($htf1, $trendLen);
        $htf1OK = ! $c['use_htf1'] || $htf1 === null || $htf1Bull;
        $htf1OKBear = ! $c['use_htf1'] || $htf1 === null || $htf1Bear;

        $chartCandles = [];
        $ema9Series = [];
        $ema21Series = [];
        $ema200Series = [];
        $markers = [];

        $startIndex = max(0, $count - $lookback);
        $minScore = (int) $c['minimum_score'];
        $cooldownBars = (int) ($c['signal_cooldown_bars'] ?? 4);
        $oppositeCooldownBars = (int) ($c['opposite_cooldown_bars'] ?? 3);
        $activePosition = 'NONE';
        $barsSinceSignal = 999;
        $structureLen = (int) $c['structure_len'];
        $breakoutBuffer = (float) $c['breakout_buffer_pct'] / 100.0;

        for ($i = $startIndex; $i < $count; $i++) {
            $barsSinceSignal++;
            $timeSec = (int) floor(($closeTimes[$i] ?? 0) / 1000);
            $curClose = (float) $closes[$i];
            $curOpen = (float) $opens[$i];
            $curHigh = (float) $highs[$i];
            $curLow = (float) $lows[$i];
            $curVol = (float) $volumes[$i];

            $chartCandles[] = [
                'time' => $timeSec,
                'open' => round($curOpen, 4),
                'high' => round($curHigh, 4),
                'low' => round($curLow, 4),
                'close' => round($curClose, 4),
                'volume' => round($curVol, 2),
            ];

            if ($emaFast[$i] !== null) {
                $ema9Series[] = ['time' => $timeSec, 'value' => round((float) $emaFast[$i], 4)];
            }
            if ($emaSlow[$i] !== null) {
                $ema21Series[] = ['time' => $timeSec, 'value' => round((float) $emaSlow[$i], 4)];
            }
            if ($emaTrend[$i] !== null) {
                $ema200Series[] = ['time' => $timeSec, 'value' => round((float) $emaTrend[$i], 4)];
            }

            // Only evaluate historical signals on fully formed bars up to $count - 2
            if (
                $i <= ($count - 2) &&
                $i >= $structureLen + 2 &&
                $emaFast[$i] !== null &&
                $emaSlow[$i] !== null &&
                $rsi[$i] !== null &&
                $atr[$i] !== null &&
                $volSma[$i] !== null &&
                $curClose > 0 &&
                $atr[$i] > 0
            ) {
                $candleRange = max(0.0000001, $curHigh - $curLow);
                $body = abs($curClose - $curOpen);
                $bodyRatio = $candleRange > 0 ? $body / $candleRange : 0;
                $upperWick = $curHigh - max($curClose, $curOpen);
                $lowerWick = min($curClose, $curOpen) - $curLow;
                $upperWickRatio = $upperWick / $candleRange;
                $lowerWickRatio = $lowerWick / $candleRange;

                $bullCandle = ($curClose > $curOpen) && ($bodyRatio >= 0.40) && ($upperWickRatio <= 0.30);
                $bearCandle = ($curClose < $curOpen) && ($bodyRatio >= 0.40) && ($lowerWickRatio <= 0.30);

                $previousHigh = max(array_slice($highs, $i - $structureLen, $structureLen));
                $previousLow = min(array_slice($lows, $i - $structureLen, $structureLen));

                $distToResPct = $previousHigh > 0 ? (($previousHigh - $curClose) / $previousHigh) * 100.0 : 999.0;
                $distToSupPct = $previousLow > 0 ? (($curClose - $previousLow) / $previousLow) * 100.0 : 999.0;

                $low1 = $lows[$i];
                $low2 = $lows[$i - 1];
                $low3 = $lows[$i - 2];
                $hasHigherLows = ($low1 >= $low2 * 0.999 && $low2 >= $low3 * 0.999);

                $high1 = $highs[$i];
                $high2 = $highs[$i - 1];
                $high3 = $highs[$i - 2];
                $hasLowerHighs = ($high1 <= $high2 * 1.001 && $high2 <= $high3 * 1.001);

                $volRatio = ($volSma[$i] > 0) ? round($curVol / $volSma[$i], 2) : 1.0;
                $atrPct = ($atr[$i] / $curClose) * 100.0;
                $trendBull = $emaFast[$i] >= $emaSlow[$i] && ($emaTrend[$i] === null || $curClose >= $emaTrend[$i] * 0.995);
                $trendBear = $emaFast[$i] <= $emaSlow[$i] && ($emaTrend[$i] === null || $curClose <= $emaTrend[$i] * 1.005);
                $above200Ema = $emaTrend[$i] === null || $curClose > (float) $emaTrend[$i];
                $below200Ema = $emaTrend[$i] === null || $curClose < (float) $emaTrend[$i];

                $histSide = null;
                $histSetupType = null;
                $histSetupLabel = null;
                $histScore = 0;
                $histRawSl = 0.0;

                // Long Setups (Only when bullish trend aligned or strong breakout with momentum)
                if ($rsi[$i] >= 46.0 && $rsi[$i] <= 68.0) {
                    // Setup 1: Confirmed Resistance Breakout
                    if ($curClose > ($previousHigh * (1.0 + $breakoutBuffer)) && $bullCandle && $volRatio >= 1.15 && $above200Ema) {
                        $histSide = 'BUY';
                        $histSetupType = 'BREAKOUT_CONFIRMED';
                        $histSetupLabel = 'RESISTANCE BREAKOUT CONFIRMED';
                        $histScore = 94;
                        $histRawSl = max($previousHigh * 0.995, $curLow * 0.998);
                    }
                    // Setup 2: 21-EMA Value Pullback Bounce
                    elseif ($trendBull && ($curLow <= $emaSlow[$i] * 1.003 && $curClose > $emaFast[$i]) && $bullCandle && $lowerWickRatio >= 0.25) {
                        $histSide = 'BUY';
                        $histSetupType = 'PULLBACK_VALUE';
                        $histSetupLabel = '21-EMA VALUE PULLBACK BOUNCE';
                        $histScore = 90;
                        $histRawSl = $curLow * 0.998;
                    }
                    // Setup 3: Pre-Breakout Ascending Coil
                    elseif ($trendBull && $distToResPct >= 0.10 && $distToResPct <= 0.85 && $curClose > $emaFast[$i] && $bullCandle && $upperWickRatio <= 0.25 && $volRatio >= 1.10) {
                        $histSide = 'BUY';
                        $histSetupType = 'PRE_BREAKOUT_COIL';
                        $histSetupLabel = 'PRE-BREAKOUT ASCENDING COIL';
                        $histScore = 88;
                        $histRawSl = min($curLow, $lows[$i - 1]) * 0.998;
                    }
                    // Setup 4: Wyckoff Spring Liquidity Reversal
                    elseif (($curLow < $previousLow || $lows[$i - 1] < $previousLow) && $curClose > $previousLow && $curClose > $curOpen && $lowerWickRatio >= 0.40 && $volRatio >= 1.10 && $curClose > $emaFast[$i] && $above200Ema) {
                        $histSide = 'BUY';
                        $histSetupType = 'WYCKOFF_SPRING';
                        $histSetupLabel = 'WYCKOFF SPRING REVERSAL';
                        $histScore = 95;
                        $histRawSl = min($curLow, $lows[$i - 1]) * 0.998;
                    }
                    // Setup 5: Breakout Retest Bounce
                    elseif ($i >= 2 && $closes[$i - 1] > $previousHigh && $curLow <= ($previousHigh * 1.003) && $curClose >= $previousHigh && $bullCandle && $above200Ema) {
                        $histSide = 'BUY';
                        $histSetupType = 'RETEST_ENTRY';
                        $histSetupLabel = 'BREAKOUT RETEST & BOUNCE';
                        $histScore = 86;
                        $histRawSl = $curLow * 0.998;
                    }
                }

                // Short Setups (Only when bearish trend aligned or strong breakdown with momentum)
                if ($histSide === null && $rsi[$i] <= 54.0 && $rsi[$i] >= 22.0) {
                    // Setup 1: Confirmed Support Breakdown
                    if ($curClose < ($previousLow * (1.0 - $breakoutBuffer)) && $bearCandle && $volRatio >= 1.15 && $below200Ema) {
                        $histSide = 'SELL';
                        $histSetupType = 'BREAKDOWN_CONFIRMED';
                        $histSetupLabel = 'SUPPORT BREAKDOWN CONFIRMED';
                        $histScore = 94;
                        $histRawSl = min($previousLow * 1.005, $curHigh * 1.002);
                    }
                    // Setup 2: 21-EMA Value Pullback Rejection
                    elseif ($trendBear && ($curHigh >= $emaSlow[$i] * 0.997 && $curClose < $emaFast[$i]) && $bearCandle && $upperWickRatio >= 0.25) {
                        $histSide = 'SELL';
                        $histSetupType = 'PULLBACK_VALUE';
                        $histSetupLabel = '21-EMA VALUE PULLBACK REJECTION';
                        $histScore = 90;
                        $histRawSl = $curHigh * 1.002;
                    }
                    // Setup 3: Pre-Breakdown Descending Coil
                    elseif ($trendBear && $distToSupPct >= 0.10 && $distToSupPct <= 0.85 && $curClose < $emaFast[$i] && $bearCandle && $lowerWickRatio <= 0.25 && $volRatio >= 1.10) {
                        $histSide = 'SELL';
                        $histSetupType = 'PRE_BREAKOUT_COIL';
                        $histSetupLabel = 'PRE-BREAKDOWN DESCENDING COIL';
                        $histScore = 88;
                        $histRawSl = max($curHigh, $highs[$i - 1]) * 1.002;
                    }
                    // Setup 4: Wyckoff Upthrust Liquidity Reversal
                    elseif (($curHigh > $previousHigh || $highs[$i - 1] > $previousHigh) && $curClose < $previousHigh && $curClose < $curOpen && $upperWickRatio >= 0.40 && $volRatio >= 1.10 && $curClose < $emaFast[$i] && $below200Ema) {
                        $histSide = 'SELL';
                        $histSetupType = 'WYCKOFF_UPTHRUST';
                        $histSetupLabel = 'WYCKOFF UPTHRUST REVERSAL';
                        $histScore = 95;
                        $histRawSl = max($curHigh, $highs[$i - 1]) * 1.002;
                    }
                    // Setup 5: Breakdown Retest Rejection
                    elseif ($i >= 2 && $closes[$i - 1] < $previousLow && $curHigh >= ($previousLow * 0.997) && $curClose <= $previousLow && $bearCandle && $below200Ema) {
                        $histSide = 'SELL';
                        $histSetupType = 'RETEST_ENTRY';
                        $histSetupLabel = 'BREAKDOWN RETEST & REJECTION';
                        $histScore = 86;
                        $histRawSl = $curHigh * 1.002;
                    }
                }

                if ($histSide !== null && $histScore >= $minScore) {
                    $activeCooldown = ($activePosition === $histSide) ? max(8, $cooldownBars) : max(4, $oppositeCooldownBars);
                    $priceMovedEnough = abs($curClose - ($lastSignalPrice ?? 0.0)) >= ($atr[$i] * 0.8);
                    $canTrade = ($barsSinceSignal >= $activeCooldown) && ($activePosition !== $histSide || $priceMovedEnough);

                    if ($canTrade) {
                        $minRisk = $curClose * 0.0075;
                        $maxRisk = $curClose * 0.0135;
                        $calcRisk = max($minRisk, min($maxRisk, abs($curClose - $histRawSl)));

                        $sl = $histSide === 'BUY' ? round($curClose - $calcRisk, 4) : round($curClose + $calcRisk, 4);
                        $tp1 = $histSide === 'BUY' ? round($curClose + ($calcRisk * 1.35), 4) : round($curClose - ($calcRisk * 1.35), 4);
                        $tp2 = $histSide === 'BUY' ? round($curClose + ($calcRisk * 2.80), 4) : round($curClose - ($calcRisk * 2.80), 4);
                        $tp3 = $histSide === 'BUY' ? round($curClose + ($calcRisk * 4.50), 4) : round($curClose - ($calcRisk * 4.50), 4);

                        $grade = $histScore >= (int) $c['grade_a'] ? 'A' : ($histScore >= (int) $c['grade_b'] ? 'B' : 'C');
                        $slPct = round(($calcRisk / $curClose) * 100, 2);
                        $tp2Pct = round(abs($tp2 - $curClose) / $curClose * 100, 2);
                        $rrRatio = $slPct > 0 ? '1 : '.round($tp2Pct / $slPct, 1) : '1 : 2.8';

                        $markers[] = [
                            'time' => $timeSec,
                            'position' => $histSide === 'BUY' ? 'belowBar' : 'aboveBar',
                            'color' => $histSide === 'BUY' ? '#10b981' : '#ef4444',
                            'shape' => $histSide === 'BUY' ? 'arrowUp' : 'arrowDown',
                            'text' => "SignalAlgo {$histSide} [{$grade}: {$histScore}]",
                            'size' => 2,
                            'side' => $histSide,
                            'score' => $histScore,
                            'grade' => $grade,
                            'setup_type' => $histSetupType,
                            'setup_label' => $histSetupLabel,
                            'entry' => round($curClose, 4),
                            'sl' => $sl,
                            'tp1' => $tp1,
                            'tp2' => $tp2,
                            'tp3' => $tp3,
                            'risk_reward' => $rrRatio,
                            'rsi' => round((float) $rsi[$i], 1),
                            'adx' => isset($adx[$i]) && $adx[$i] !== null ? round((float) $adx[$i], 1) : 0.0,
                            'atr_pct' => round($atrPct, 2),
                            'volume_ratio' => $volRatio,
                            'rs_ratio' => round($rsRatio, 4),
                        ];

                        $activePosition = $histSide;
                        $lastSignalPrice = $curClose;
                        $barsSinceSignal = 0;
                    }
                }
            }
        }

        return [
            'candles' => $chartCandles,
            'markers' => $markers,
            'ema9' => $ema9Series,
            'ema21' => $ema21Series,
            'ema200' => $ema200Series,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $htf
     * @return array{0: bool, 1: bool}
     */
    public function htfTrend(?array $htf, int $trendLen): array
    {
        if ($htf === null || empty($htf['closes'])) {
            return [true, true];
        }
        $closes = $htf['closes'];
        $n = count($closes);
        $idx = $n - 2;
        if ($idx < 21) {
            return [true, true];
        }

        $emaFast = Indicators::ema($closes, 9);
        $emaSlow = Indicators::ema($closes, 21);
        $ema50 = Indicators::ema($closes, min(50, $trendLen));

        $close = (float) $closes[$idx];
        $fast = $emaFast[$idx] !== null ? (float) $emaFast[$idx] : $close;
        $slow = $emaSlow[$idx] !== null ? (float) $emaSlow[$idx] : $close;
        $trend50 = $ema50[$idx] !== null ? (float) $ema50[$idx] : $close;

        // Dynamic HTF evaluation: responsive to momentum (9 vs 21) and structural baseline (50 EMA)
        $isBull = ($fast >= $slow * 0.999) || ($close >= $trend50 * 0.998);
        $isBear = ($fast <= $slow * 1.001) || ($close <= $trend50 * 1.002);

        return [$isBull, $isBear];
    }

    /**
     * @param  array<int, float>  $highs
     * @param  array<int, float>  $lows
     * @param  array<int, float|null>  $rsi
     * @return array{0: bool, 1: bool}
     */
    public function checkDivergence(array $highs, array $lows, array $rsi, int $i, int $lookback): array
    {
        $half = intdiv($lookback, 2);
        if (($i - $lookback + 1) < 0) {
            return [false, false];
        }

        [$recentHigh, $recentHighIdx] = $this->extremeWithIndex($highs, $i - $half + 1, $half, true);
        [$priorHigh, $priorHighIdx] = $this->extremeWithIndex($highs, $i - $lookback + 1, $half, true);
        $bearishDivergence = $recentHigh > $priorHigh
            && $rsi[$recentHighIdx] !== null && $rsi[$priorHighIdx] !== null
            && $rsi[$recentHighIdx] < $rsi[$priorHighIdx];

        [$recentLow, $recentLowIdx] = $this->extremeWithIndex($lows, $i - $half + 1, $half, false);
        [$priorLow, $priorLowIdx] = $this->extremeWithIndex($lows, $i - $lookback + 1, $half, false);
        $bullishDivergence = $recentLow < $priorLow
            && $rsi[$recentLowIdx] !== null && $rsi[$priorLowIdx] !== null
            && $rsi[$recentLowIdx] > $rsi[$priorLowIdx];

        return [$bearishDivergence, $bullishDivergence];
    }

    /**
     * @param  array<int, float>  $values
     * @return array{0: float, 1: int}
     */
    public function extremeWithIndex(array $values, int $start, int $length, bool $findMax): array
    {
        $bestVal = $findMax ? -INF : INF;
        $bestIdx = $start;
        for ($k = $start; $k < $start + $length; $k++) {
            if ($findMax ? $values[$k] > $bestVal : $values[$k] < $bestVal) {
                $bestVal = $values[$k];
                $bestIdx = $k;
            }
        }

        return [(float) $bestVal, $bestIdx];
    }

    /**
     * Evaluate for counter-trend exhaustion / mean-reversion reversal setups.
     *
     * @param  array<string, mixed>  $candles
     * @param  array<int, float|null>  $emaFast
     * @param  array<int, float|null>  $emaSlow
     * @param  array<int, float|null>  $emaTrend
     * @param  array<int, float|null>  $rsi
     * @param  array<int, float|null>  $atr
     * @param  array<int, float|null>  $volSma
     * @return array<string, mixed>|null
     */
    public function evaluateReversal(
        array $candles,
        int $i,
        array $emaFast,
        array $emaSlow,
        array $emaTrend,
        array $rsi,
        array $atr,
        array $volSma
    ): ?array {
        $c = $this->config;
        $closes = $candles['closes'] ?? [];
        $highs = $candles['highs'] ?? [];
        $lows = $candles['lows'] ?? [];
        $opens = $candles['opens'] ?? [];
        $volumes = $candles['volumes'] ?? [];
        $closeTimes = $candles['closeTimes'] ?? [];

        if ($i < 20 || ! isset($closes[$i], $highs[$i], $lows[$i], $opens[$i])) {
            return null;
        }

        $close = (float) $closes[$i];
        $open = (float) $opens[$i];
        $high = (float) $highs[$i];
        $low = (float) $lows[$i];
        $vol = (float) ($volumes[$i] ?? 0.0);

        $curRsi = $rsi[$i] !== null ? (float) $rsi[$i] : null;
        $prevRsi = ($i >= 1 && $rsi[$i - 1] !== null) ? (float) $rsi[$i - 1] : null;
        $prev2Rsi = ($i >= 2 && $rsi[$i - 2] !== null) ? (float) $rsi[$i - 2] : $prevRsi;

        $atrVal = $atr[$i] !== null ? (float) $atr[$i] : 0.0;
        $fastVal = $emaFast[$i] !== null ? (float) $emaFast[$i] : null;
        $slowVal = $emaSlow[$i] !== null ? (float) $emaSlow[$i] : null;
        $trendVal = $emaTrend[$i] !== null ? (float) $emaTrend[$i] : null;
        $volSmaVal = $volSma[$i] !== null ? (float) $volSma[$i] : 0.0;

        if ($atrVal <= 0 || $curRsi === null || $prevRsi === null || $fastVal === null || $slowVal === null) {
            return null;
        }

        $range = $high - $low;
        $body = abs($close - $open);
        $upperWick = $high - max($close, $open);
        $lowerWick = min($close, $open) - $low;

        $prevClose = (float) $closes[$i - 1];
        $prevOpen = (float) $opens[$i - 1];

        $reversalMinScore = (int) ($c['reversal_min_score'] ?? 75);
        $overboughtThreshold = (float) ($c['reversal_rsi_overbought'] ?? 64.0);
        $oversoldThreshold = (float) ($c['reversal_rsi_oversold'] ?? 36.0);

        // --- 1. TOP REVERSAL SHORT ---
        $rsiOverbought = ($curRsi >= $overboughtThreshold || $prevRsi >= 68.0 || ($prev2Rsi !== null && $prev2Rsi >= 70.0));
        $rsiRollDown = ($curRsi < $prevRsi);

        $isShootingStar = ($range > 0 && ($upperWick / $range) >= 0.40 && $close <= ($low + ($range * 0.55)));
        $isBearEngulf = ($close < $open && $prevClose > $prevOpen && $close <= $prevOpen && $open >= $prevClose);
        $isStrongRed = ($close < $open && $range > 0 && ($body / $range) >= 0.55);
        $bearCandle = ($isShootingStar || $isBearEngulf || $isStrongRed);

        $stretchedHigh = false;
        if ($trendVal !== null && $close > ($trendVal * 1.03)) {
            $stretchedHigh = true;
        }
        if ($close > ($slowVal + ($atrVal * 1.2))) {
            $stretchedHigh = true;
        }

        $structureLen = min(20, $i);
        $priorHigh = max(array_slice($highs, $i - $structureLen, $structureLen));
        $atResistance = ($high >= ($priorHigh * 0.99));

        // Professional Institutional Guard: Never short if price is above 200 EMA or EMA 9 > EMA 21
        if ($trendVal !== null && $close >= $trendVal) {
            return null;
        }
        if ($fastVal !== null && $slowVal !== null && $fastVal > $slowVal) {
            return null;
        }

        if ($rsiOverbought && $rsiRollDown && $bearCandle && $stretchedHigh) {
            $score = 45; // Baseline setup score
            if ($isShootingStar || $isBearEngulf) {
                $score += 15;
            }
            if ($atResistance) {
                $score += 15;
            }
            if ($volSmaVal > 0 && $vol > $volSmaVal) {
                $score += 10;
            }
            if ($trendVal !== null && $close > ($trendVal * 1.05)) {
                $score += 5; // Extra overextension bonus
            }

            $recentHighWick = max(array_slice($highs, max(0, $i - 5), 6));
            $sl = $recentHighWick + ($atrVal * 0.25);
            $risk = $sl - $close;

            if ($risk > 0 && $score >= $reversalMinScore) {
                $tp1 = $close - max($atrVal * (float) $c['tp1_mult'], $risk * (float) $c['min_rr']);
                $tp2 = $close - max($atrVal * (float) $c['tp2_mult'], $risk * ((float) $c['min_rr'] + 1.0));
                $tp3 = $close - max($atrVal * (float) $c['tp3_mult'], $risk * ((float) $c['min_rr'] + 2.0));
                $rewardToRisk = abs($tp1 - $close) / $risk;

                if ($rewardToRisk >= (float) $c['min_rr']) {
                    return $this->buildReversalPayload(
                        side: 'SELL',
                        score: $score,
                        entry: $close,
                        sl: $sl,
                        tp1: $tp1,
                        tp2: $tp2,
                        tp3: $tp3,
                        rewardToRisk: $rewardToRisk,
                        curRsi: $curRsi,
                        atrVal: $atrVal,
                        volSmaVal: $volSmaVal,
                        vol: $vol,
                        closeTime: (int) ($closeTimes[$i] ?? 0)
                    );
                }
            }
        }

        // --- 2. BOTTOM REVERSAL BUY ---
        $rsiOversold = ($curRsi <= $oversoldThreshold || $prevRsi <= 32.0 || ($prev2Rsi !== null && $prev2Rsi <= 30.0));
        $rsiCurlUp = ($curRsi > $prevRsi);

        $isHammer = ($range > 0 && ($lowerWick / $range) >= 0.40 && $close >= ($high - ($range * 0.55)));
        $isBullEngulf = ($close > $open && $prevClose < $prevOpen && $close >= $prevOpen && $open <= $prevClose);
        $isStrongGreen = ($close > $open && $range > 0 && ($body / $range) >= 0.55);
        $bullRejection = ($isHammer || $isBullEngulf || $isStrongGreen);

        $stretchedLow = false;
        if ($trendVal !== null && $close < ($trendVal * 0.97)) {
            $stretchedLow = true;
        }
        if ($close < ($slowVal - ($atrVal * 1.2))) {
            $stretchedLow = true;
        }

        $priorLow = min(array_slice($lows, $i - $structureLen, $structureLen));
        $atSupport = ($low <= ($priorLow * 1.01));

        // Professional Institutional Guard: Never buy if price is below 200 EMA or EMA 9 < EMA 21
        if ($trendVal !== null && $close <= $trendVal) {
            return null;
        }
        if ($fastVal !== null && $slowVal !== null && $fastVal < $slowVal) {
            return null;
        }

        if ($rsiOversold && $rsiCurlUp && $bullRejection && $stretchedLow) {
            $score = 45; // Baseline setup score
            if ($isHammer || $isBullEngulf) {
                $score += 15;
            }
            if ($atSupport) {
                $score += 15;
            }
            if ($volSmaVal > 0 && $vol > $volSmaVal) {
                $score += 10;
            }
            if ($trendVal !== null && $close < ($trendVal * 0.95)) {
                $score += 5; // Extra oversold extension bonus
            }

            $recentLowWick = min(array_slice($lows, max(0, $i - 5), 6));
            $sl = $recentLowWick - ($atrVal * 0.25);
            $risk = $close - $sl;

            if ($risk > 0 && $score >= $reversalMinScore) {
                $tp1 = $close + max($atrVal * (float) $c['tp1_mult'], $risk * (float) $c['min_rr']);
                $tp2 = $close + max($atrVal * (float) $c['tp2_mult'], $risk * ((float) $c['min_rr'] + 1.0));
                $tp3 = $close + max($atrVal * (float) $c['tp3_mult'], $risk * ((float) $c['min_rr'] + 2.0));
                $rewardToRisk = abs($tp1 - $close) / $risk;

                if ($rewardToRisk >= (float) $c['min_rr']) {
                    return $this->buildReversalPayload(
                        side: 'BUY',
                        score: $score,
                        entry: $close,
                        sl: $sl,
                        tp1: $tp1,
                        tp2: $tp2,
                        tp3: $tp3,
                        rewardToRisk: $rewardToRisk,
                        curRsi: $curRsi,
                        atrVal: $atrVal,
                        volSmaVal: $volSmaVal,
                        vol: $vol,
                        closeTime: (int) ($closeTimes[$i] ?? 0)
                    );
                }
            }
        }

        return null;
    }

    /**
     * Build standard signal payload for reversal setups.
     *
     * @return array<string, mixed>
     */
    protected function buildReversalPayload(
        string $side,
        int $score,
        float $entry,
        float $sl,
        float $tp1,
        float $tp2,
        float $tp3,
        float $rewardToRisk,
        float $curRsi,
        float $atrVal,
        float $volSmaVal,
        float $vol,
        int $closeTime
    ): array {
        $c = $this->config;
        $grade = $score >= (int) $c['grade_a'] ? 'A' : ($score >= (int) $c['grade_b'] ? 'B' : 'C');
        $atrPct = $entry > 0 ? round(($atrVal / $entry) * 100.0, 2) : 1.5;
        $volRatio = $volSmaVal > 0 ? round($vol / $volSmaVal, 2) : 1.0;

        $slDistancePct = $entry > 0 ? round(abs($entry - $sl) / $entry * 100.0, 2) : 0.0;
        $tp1Pct = $entry > 0 ? round(abs($tp1 - $entry) / $entry * 100.0, 2) : 0.0;
        $tp2Pct = $entry > 0 ? round(abs($tp2 - $entry) / $entry * 100.0, 2) : 0.0;
        $tp3Pct = $entry > 0 ? round(abs($tp3 - $entry) / $entry * 100.0, 2) : 0.0;
        $rrRatio = $slDistancePct > 0 ? round($tp2Pct / $slDistancePct, 1) : 2.5;

        $recLeverage = $atrPct > 3.0 ? '3x - 5x' : ($atrPct < 1.0 ? '8x - 12x' : '5x - 10x');
        $levMult = $atrPct > 3.0 ? 3 : ($atrPct < 1.0 ? 10 : 5);

        return [
            'side' => $side,
            'score' => $score,
            'grade' => $grade,
            'setup_type' => 'REVERSAL',
            'entry' => round($entry, 8),
            'sl' => round($sl, 8),
            'tp1' => round($tp1, 8),
            'tp2' => round($tp2, 8),
            'tp3' => round($tp3, 8),
            'risk_reward' => round($rewardToRisk, 2),
            'rsi' => round($curRsi, 2),
            'adx' => 0.0,
            'volume_ratio' => $volRatio,
            'atr_pct' => $atrPct,
            'candle_close_time' => $closeTime,
            'perpetual_options' => [
                'recommended_leverage' => $recLeverage,
                'margin_mode' => 'Isolated Margin',
                'order_type' => 'Limit / Market Entry (Reversal)',
                'risk_per_trade' => '1% - 2% Account Balance',
                'risk_reward' => "1 : {$rrRatio}",
                'sl_pct' => $slDistancePct,
                'tp1_pct' => $tp1Pct,
                'tp2_pct' => $tp2Pct,
                'tp3_pct' => $tp3Pct,
                'sl_leveraged_pct' => round($slDistancePct * $levMult, 1),
                'tp1_leveraged_pct' => round($tp1Pct * $levMult, 1),
                'tp2_leveraged_pct' => round($tp2Pct * $levMult, 1),
                'tp3_leveraged_pct' => round($tp3Pct * $levMult, 1),
                'leverage_multiplier' => $levMult,
                'liquidation_buffer' => '> 15% safety cushion',
            ],
        ];
    }
}
