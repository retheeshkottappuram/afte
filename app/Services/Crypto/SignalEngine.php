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
            'adx_min' => 18.0,

            // Volume
            'vol_len' => 20,
            'vol_mult' => 1.15,
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
            'tp1_mult' => 1.5,
            'tp2_mult' => 3.0,
            'tp3_mult' => 4.5,
            'use_structure_sl' => true,
            'min_rr' => 1.5,

            // Thresholds (Institutional High-Confluence Tunables)
            'minimum_score' => 80,
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
    public function evaluate(array $candles, ?array $htf1 = null, ?array $htf2 = null): ?array
    {
        return $this->evaluateDetailed($candles, $htf1, $htf2)['signal'];
    }

    /**
     * Evaluate candle data and return both signal and diagnostics metrics.
     *
     * @param  array<string, mixed>  $candles
     * @param  array<string, mixed>|null  $htf1
     * @param  array<string, mixed>|null  $htf2
     * @return array{signal: array<string, mixed>|null, diagnostics: array<string, mixed>}
     */
    public function evaluateDetailed(array $candles, ?array $htf1 = null, ?array $htf2 = null): array
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
        $dmiRes = Indicators::dmi($highs, $lows, $closes, (int) $c['adx_len'], (int) $c['adx_len']);
        $plusDI = $dmiRes['plus_di'] ?? $dmiRes[0];
        $minusDI = $dmiRes['minus_di'] ?? $dmiRes[1];
        $adx = $dmiRes['adx'] ?? $dmiRes[2];

        if (
            $emaFast[$i] === null ||
            $emaSlow[$i] === null ||
            $emaTrend[$i] === null ||
            $rsi[$i] === null ||
            $atr[$i] === null ||
            $volSma[$i] === null ||
            $adx[$i] === null ||
            $bbWidth[$i] === null
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
            $emaTrend[$idx] !== null &&
            $emaFast[$idx] > $emaSlow[$idx] &&
            $closes[$idx] > $emaTrend[$idx];

        $trendBearAt = fn (int $idx): bool => $idx >= 0 &&
            $emaFast[$idx] !== null &&
            $emaSlow[$idx] !== null &&
            $emaTrend[$idx] !== null &&
            $emaFast[$idx] < $emaSlow[$idx] &&
            $closes[$idx] < $emaTrend[$idx];

        $trendBull = $trendBullAt($i);
        $trendBear = $trendBearAt($i);

        $persistBull = true;
        $persistBear = true;
        for ($p = 0; $p < (int) $c['persistence_bars']; $p++) {
            $persistBull = $persistBull && $trendBullAt($i - $p);
            $persistBear = $persistBear && $trendBearAt($i - $p);
        }

        [$htf1Bull, $htf1Bear] = $this->htfTrend($htf1, (int) $c['trend_len']);
        [$htf2Bull, $htf2Bear] = $this->htfTrend($htf2, (int) $c['trend_len']);

        $htf1OK = ! $c['use_htf1'] || $htf1 === null || $htf1Bull;
        $htf1OKBear = ! $c['use_htf1'] || $htf1 === null || $htf1Bear;
        $htf2OK = ! $c['use_htf2'] || $htf2 === null || $htf2Bull;
        $htf2OKBear = ! $c['use_htf2'] || $htf2 === null || $htf2Bear;

        $adxBull = $adx[$i] >= $c['adx_min'] && $plusDI[$i] > $minusDI[$i];
        $adxBear = $adx[$i] >= $c['adx_min'] && $minusDI[$i] > $plusDI[$i];

        $rsiBull = $rsi[$i] > $c['rsi_long_min'];
        $rsiBear = $rsi[$i] < $c['rsi_short_max'];
        $rsiMomentumBull = $rsi[$i - 1] !== null && $rsi[$i] > $rsi[$i - 1];
        $rsiMomentumBear = $rsi[$i - 1] !== null && $rsi[$i] < $rsi[$i - 1];

        $volumeOK = $volSma[$i] !== null && $volSma[$i] > 0 && $volumes[$i] > ($volSma[$i] * (float) $c['vol_mult']);

        $obvLb = (int) $c['obv_lookback'];
        $obvBull = ($i - $obvLb) >= 0 && $obv[$i] > $obv[$i - $obvLb];
        $obvBear = ($i - $obvLb) >= 0 && $obv[$i] < $obv[$i - $obvLb];

        $candleRange = $high - $low;
        $body = abs($close - $open);
        $bodyRatio = $candleRange > 0 ? $body / $candleRange : 0;
        $strongBull = $close > $open && $bodyRatio >= 0.60;
        $strongBear = $close < $open && $bodyRatio >= 0.60;
        $bullEngulf = $close > $open && $closes[$i - 1] < $opens[$i - 1]
            && $close >= $opens[$i - 1] && $open <= $closes[$i - 1];
        $bearEngulf = $close < $open && $closes[$i - 1] > $opens[$i - 1]
            && $close <= $opens[$i - 1] && $open >= $closes[$i - 1];
        $bullCandle = $strongBull || $bullEngulf;
        $bearCandle = $strongBear || $bearEngulf;

        $structureLen = (int) $c['structure_len'];
        $previousHigh = max(array_slice($highs, $i - $structureLen, $structureLen));
        $previousLow = min(array_slice($lows, $i - $structureLen, $structureLen));
        $breakoutBuffer = (float) $c['breakout_buffer_pct'] / 100.0;
        $breakoutLong = $close > ($previousHigh * (1.0 + $breakoutBuffer));
        $breakoutShort = $close < ($previousLow * (1.0 - $breakoutBuffer));
        $higherHigh = $high > $highs[$i - 1] && $highs[$i - 1] > $highs[$i - 2];
        $higherLow = $low > $lows[$i - 1];
        $lowerLow = $low < $lows[$i - 1] && $lows[$i - 1] < $lows[$i - 2];
        $lowerHigh = $high < $highs[$i - 1];
        $structureBull = $breakoutLong || ($higherHigh && $higherLow);
        $structureBear = $breakoutShort || ($lowerLow && $lowerHigh);

        $atrPct = $close > 0 ? ($atr[$i] / $close * 100.0) : 0.0;
        $volatilityOK = $atrPct >= (float) $c['min_atr_pct'] && $atrPct <= (float) $c['max_atr_pct'];

        $bbLb = (int) $c['bb_expansion_lookback'];
        $volatilityExpanding = ($i - $bbLb) >= 0 && $bbWidth[$i - $bbLb] !== null
            && $bbWidth[$i] > $bbWidth[$i - $bbLb];

        $distanceFromEMA = $atr[$i] > 0 ? abs($close - (float) $emaFast[$i]) / $atr[$i] : 999.0;
        $notOverextendedLong = $close >= (float) $emaSlow[$i] && $distanceFromEMA < 2.8;
        $notOverextendedShort = $close <= (float) $emaSlow[$i] && $distanceFromEMA < 2.8;

        [$bearishDivergence, $bullishDivergence] = $this->checkDivergence($highs, $lows, $rsi, $i, (int) $c['divergence_lookback']);

        $volRatio = ($volSma[$i] !== null && $volSma[$i] > 0) ? round($volumes[$i] / $volSma[$i], 2) : 1.0;
        $volumeScore = $volRatio >= (float) $c['vol_mult'] ? 8 : ($volRatio >= 0.85 ? 5 : 0);

        $longScore = 0;
        $longScore += ($trendBull && $htf1OK && $htf2OK) ? 20 : 0;
        $longScore += $adxBull ? 15 : 0;
        $longScore += ($rsiBull ? 7 : 0) + ($rsiMomentumBull ? 3 : 0);
        $longScore += $volumeScore;
        $longScore += $obvBull ? 7 : 0;
        $longScore += $bullCandle ? 10 : 0;
        $longScore += $structureBull ? 10 : 0;
        $longScore += $volatilityOK ? 5 : 0;
        $longScore += $volatilityExpanding ? 5 : 0;
        $longScore += $persistBull ? 10 : 0;

        $shortScore = 0;
        $shortScore += ($trendBear && $htf1OKBear && $htf2OKBear) ? 20 : 0;
        $shortScore += $adxBear ? 15 : 0;
        $shortScore += ($rsiBear ? 7 : 0) + ($rsiMomentumBear ? 3 : 0);
        $shortScore += $volumeScore;
        $shortScore += $obvBear ? 7 : 0;
        $shortScore += $bearCandle ? 10 : 0;
        $shortScore += $structureBear ? 10 : 0;
        $shortScore += $volatilityOK ? 5 : 0;
        $shortScore += $volatilityExpanding ? 5 : 0;
        $shortScore += $persistBear ? 10 : 0;

        $minScore = (int) $c['minimum_score'];
        // Professional Institutional Gate: Must meet min score, follow macro trend, and align with HTF
        $longQualifies = $longScore >= $minScore && $trendBull && $htf1OK && $notOverextendedLong && ! $bearishDivergence;
        $shortQualifies = $shortScore >= $minScore && $trendBear && $htf1OKBear && $notOverextendedShort && ! $bullishDivergence;

        $volRatio = ($volSma[$i] !== null && $volSma[$i] > 0) ? round($volumes[$i] / $volSma[$i], 2) : 1.0;

        $diagnostics = [
            'buy_score' => $longScore,
            'sell_score' => $shortScore,
            'minimum_score' => $minScore,
            'rsi' => round((float) $rsi[$i], 2),
            'adx' => round((float) $adx[$i], 2),
            'volume_ratio' => $volRatio,
            'atr_pct' => round($atrPct, 2),
            'close' => round($close, 4),
            'rejection' => null,
        ];

        if (! $longQualifies && ! $shortQualifies) {
            if ((bool) ($c['enable_reversals'] ?? false)) {
                $revSignal = $this->evaluateReversal(
                    $candles,
                    $i,
                    $emaFast,
                    $emaSlow,
                    $emaTrend,
                    $rsi,
                    $atr,
                    $volSma
                );
                if ($revSignal !== null) {
                    return [
                        'signal' => $revSignal,
                        'diagnostics' => $diagnostics,
                    ];
                }
            }

            if ($longScore >= $minScore && ! $notOverextendedLong) {
                $diagnostics['rejection'] = 'Price overextended from Fast EMA (> 2.5 ATR)';
            } elseif ($longScore >= $minScore && $bearishDivergence) {
                $diagnostics['rejection'] = 'Bearish RSI divergence detected';
            } elseif ($shortScore >= $minScore && ! $notOverextendedShort) {
                $diagnostics['rejection'] = 'Price overextended from Fast EMA (> 2.5 ATR)';
            } elseif ($shortScore >= $minScore && $bullishDivergence) {
                $diagnostics['rejection'] = 'Bullish RSI divergence detected';
            }

            return [
                'signal' => null,
                'diagnostics' => $diagnostics,
            ];
        }

        $side = $longQualifies && (! $shortQualifies || $longScore >= $shortScore) ? 'BUY' : 'SELL';
        $score = $side === 'BUY' ? $longScore : $shortScore;
        $entry = $close;
        $atrVal = (float) $atr[$i];

        if ($side === 'BUY') {
            $structureSL = $previousLow - ($atrVal * 0.25);
            $atrSL = $close - ($atrVal * (float) $c['sl_mult']);
            $sl = (bool) $c['use_structure_sl'] ? min($atrSL, $structureSL) : $atrSL;
            $risk = $entry - $sl;
            $tp1 = $entry + max($atrVal * (float) $c['tp1_mult'], $risk * (float) $c['min_rr']);
            $tp2 = $entry + max($atrVal * (float) $c['tp2_mult'], $risk * ((float) $c['min_rr'] + 1.0));
            $tp3 = $entry + max($atrVal * (float) $c['tp3_mult'], $risk * ((float) $c['min_rr'] + 2.0));
        } else {
            $structureSL = $previousHigh + ($atrVal * 0.25);
            $atrSL = $close + ($atrVal * (float) $c['sl_mult']);
            $sl = (bool) $c['use_structure_sl'] ? max($atrSL, $structureSL) : $atrSL;
            $risk = $sl - $entry;
            $tp1 = $entry - max($atrVal * (float) $c['tp1_mult'], $risk * (float) $c['min_rr']);
            $tp2 = $entry - max($atrVal * (float) $c['tp2_mult'], $risk * ((float) $c['min_rr'] + 1.0));
            $tp3 = $entry - max($atrVal * (float) $c['tp3_mult'], $risk * ((float) $c['min_rr'] + 2.0));
        }

        if ($risk <= 0) {
            $diagnostics['rejection'] = 'Invalid stop-loss calculation (risk <= 0)';

            return [
                'signal' => null,
                'diagnostics' => $diagnostics,
            ];
        }

        $rewardToRisk = abs($tp1 - $entry) / $risk;
        if ($rewardToRisk < (float) $c['min_rr']) {
            $diagnostics['rejection'] = "Reward-to-risk ratio ({$rewardToRisk}) below minimum threshold ({$c['min_rr']})";

            return [
                'signal' => null,
                'diagnostics' => $diagnostics,
            ];
        }

        $grade = $score >= (int) $c['grade_a'] ? 'A' : ($score >= (int) $c['grade_b'] ? 'B' : 'C');

        $slDistancePct = $entry > 0 ? round(abs($entry - $sl) / $entry * 100.0, 2) : 0.0;
        $tp1Pct = $entry > 0 ? round(abs($tp1 - $entry) / $entry * 100.0, 2) : 0.0;
        $tp2Pct = $entry > 0 ? round(abs($tp2 - $entry) / $entry * 100.0, 2) : 0.0;
        $tp3Pct = $entry > 0 ? round(abs($tp3 - $entry) / $entry * 100.0, 2) : 0.0;
        $rrRatio = $slDistancePct > 0 ? round($tp2Pct / $slDistancePct, 1) : 2.5;

        $recLeverage = '5x - 10x';
        $levMult = 5;
        if ($atrPct > 3.0) {
            $recLeverage = '3x - 5x';
            $levMult = 3;
        } elseif ($atrPct < 1.0) {
            $recLeverage = '8x - 12x';
            $levMult = 10;
        }

        $perpetualOptions = [
            'recommended_leverage' => $recLeverage,
            'margin_mode' => 'Isolated Margin',
            'order_type' => 'Limit / Market Entry',
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
        ];

        $signal = [
            'side' => $side,
            'score' => $score,
            'grade' => $grade,
            'entry' => round($entry, 8),
            'sl' => round($sl, 8),
            'tp1' => round($tp1, 8),
            'tp2' => round($tp2, 8),
            'tp3' => round($tp3, 8),
            'risk_reward' => round($rewardToRisk, 2),
            'rsi' => round((float) $rsi[$i], 2),
            'adx' => round((float) $adx[$i], 2),
            'volume_ratio' => $volRatio,
            'atr_pct' => round($atrPct, 2),
            'candle_close_time' => $closeTimes[$i] ?? 0,
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
     * @return array{
     *     candles: array<int, array{time: int, open: float, high: float, low: float, close: float, volume: float}>,
     *     markers: array<int, array<string, mixed>>,
     *     ema9: array<int, array{time: int, value: float}>,
     *     ema21: array<int, array{time: int, value: float}>,
     *     ema200: array<int, array{time: int, value: float}>
     * }
     */
    public function evaluateHistory(array $candles, ?array $htf1 = null, ?array $htf2 = null, int $lookback = 140): array
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
        $obv = Indicators::obv($closes, $volumes);
        $bbWidth = Indicators::bbWidthPercent($closes, $bbLen, $bbMult);
        $dmiRes = Indicators::dmi($highs, $lows, $closes, $adxLen, $adxLen);
        $plusDI = $dmiRes['plus_di'] ?? $dmiRes[0];
        $minusDI = $dmiRes['minus_di'] ?? $dmiRes[1];
        $adx = $dmiRes['adx'] ?? $dmiRes[2];

        [$htf1Bull, $htf1Bear] = $this->htfTrend($htf1, $trendLen);
        [$htf2Bull, $htf2Bear] = $this->htfTrend($htf2, $trendLen);

        $htf1OK = ! $c['use_htf1'] || $htf1 === null || $htf1Bull;
        $htf1OKBear = ! $c['use_htf1'] || $htf1 === null || $htf1Bear;
        $htf2OK = ! $c['use_htf2'] || $htf2 === null || $htf2Bull;
        $htf2OKBear = ! $c['use_htf2'] || $htf2 === null || $htf2Bear;

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

        $trendBullAt = fn (int $idx): bool => $idx >= 0 &&
            $emaFast[$idx] !== null &&
            $emaSlow[$idx] !== null &&
            $emaTrend[$idx] !== null &&
            $emaFast[$idx] > $emaSlow[$idx] &&
            $closes[$idx] > $emaTrend[$idx];

        $trendBearAt = fn (int $idx): bool => $idx >= 0 &&
            $emaFast[$idx] !== null &&
            $emaSlow[$idx] !== null &&
            $emaTrend[$idx] !== null &&
            $emaFast[$idx] < $emaSlow[$idx] &&
            $closes[$idx] < $emaTrend[$idx];

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
                $i >= (int) $c['structure_len'] + 2 &&
                $emaFast[$i] !== null &&
                $emaSlow[$i] !== null &&
                $emaTrend[$i] !== null &&
                $rsi[$i] !== null &&
                $atr[$i] !== null &&
                $volSma[$i] !== null &&
                $adx[$i] !== null &&
                $bbWidth[$i] !== null &&
                $curClose > 0 &&
                $atr[$i] > 0
            ) {
                $trendBull = $trendBullAt($i);
                $trendBear = $trendBearAt($i);

                $persistBull = true;
                $persistBear = true;
                for ($p = 0; $p < (int) $c['persistence_bars']; $p++) {
                    $persistBull = $persistBull && $trendBullAt($i - $p);
                    $persistBear = $persistBear && $trendBearAt($i - $p);
                }

                $adxBull = $adx[$i] >= $c['adx_min'] && $plusDI[$i] > $minusDI[$i];
                $adxBear = $adx[$i] >= $c['adx_min'] && $minusDI[$i] > $plusDI[$i];

                $rsiBull = $rsi[$i] > $c['rsi_long_min'];
                $rsiBear = $rsi[$i] < $c['rsi_short_max'];
                $rsiMomentumBull = $rsi[$i - 1] !== null && $rsi[$i] > $rsi[$i - 1];
                $rsiMomentumBear = $rsi[$i - 1] !== null && $rsi[$i] < $rsi[$i - 1];

                $volRatio = ($volSma[$i] !== null && $volSma[$i] > 0) ? round($curVol / $volSma[$i], 2) : 1.0;
                $volumeScore = $volRatio >= (float) $c['vol_mult'] ? 8 : ($volRatio >= 0.85 ? 5 : 0);

                $obvLb = (int) $c['obv_lookback'];
                $obvBull = ($i - $obvLb) >= 0 && $obv[$i] > $obv[$i - $obvLb];
                $obvBear = ($i - $obvLb) >= 0 && $obv[$i] < $obv[$i - $obvLb];

                $candleRange = $curHigh - $curLow;
                $body = abs($curClose - $curOpen);
                $bodyRatio = $candleRange > 0 ? $body / $candleRange : 0;
                $strongBull = $curClose > $curOpen && $bodyRatio >= 0.60;
                $strongBear = $curClose < $curOpen && $bodyRatio >= 0.60;
                $bullEngulf = $curClose > $curOpen && $closes[$i - 1] < $opens[$i - 1]
                    && $curClose >= $opens[$i - 1] && $curOpen <= $closes[$i - 1];
                $bearEngulf = $curClose < $curOpen && $closes[$i - 1] > $opens[$i - 1]
                    && $curClose <= $opens[$i - 1] && $curOpen >= $closes[$i - 1];
                $bullCandle = $strongBull || $bullEngulf;
                $bearCandle = $strongBear || $bearEngulf;

                $structureLen = (int) $c['structure_len'];
                $previousHigh = max(array_slice($highs, $i - $structureLen, $structureLen));
                $previousLow = min(array_slice($lows, $i - $structureLen, $structureLen));
                $breakoutBuffer = (float) $c['breakout_buffer_pct'] / 100.0;
                $breakoutLong = $curClose > ($previousHigh * (1.0 + $breakoutBuffer));
                $breakoutShort = $curClose < ($previousLow * (1.0 - $breakoutBuffer));
                $higherHigh = $curHigh > $highs[$i - 1] && $highs[$i - 1] > $highs[$i - 2];
                $higherLow = $curLow > $lows[$i - 1];
                $lowerLow = $curLow < $lows[$i - 1] && $lows[$i - 1] < $lows[$i - 2];
                $lowerHigh = $curHigh < $highs[$i - 1];
                $structureBull = $breakoutLong || ($higherHigh && $higherLow);
                $structureBear = $breakoutShort || ($lowerLow && $lowerHigh);

                $atrPct = ($atr[$i] / $curClose) * 100.0;
                $volatilityOK = $atrPct >= (float) $c['min_atr_pct'] && $atrPct <= (float) $c['max_atr_pct'];

                $bbLb = (int) $c['bb_expansion_lookback'];
                $volatilityExpanding = ($i - $bbLb) >= 0 && $bbWidth[$i - $bbLb] !== null
                    && $bbWidth[$i] > $bbWidth[$i - $bbLb];

                $distanceFromEMA = $atr[$i] > 0 ? abs($curClose - (float) $emaFast[$i]) / $atr[$i] : 999.0;
                $notOverextendedLong = $curClose >= (float) $emaSlow[$i] && $distanceFromEMA < 2.8;
                $notOverextendedShort = $curClose <= (float) $emaSlow[$i] && $distanceFromEMA < 2.8;

                [$bearishDivergence, $bullishDivergence] = $this->checkDivergence($highs, $lows, $rsi, $i, (int) $c['divergence_lookback']);

                $longScore = 0;
                $longScore += ($trendBull && $htf1OK && $htf2OK) ? 20 : 0;
                $longScore += $adxBull ? 15 : 0;
                $longScore += ($rsiBull ? 7 : 0) + ($rsiMomentumBull ? 3 : 0);
                $longScore += $volumeScore;
                $longScore += $obvBull ? 7 : 0;
                $longScore += $bullCandle ? 10 : 0;
                $longScore += $structureBull ? 10 : 0;
                $longScore += $volatilityOK ? 5 : 0;
                $longScore += $volatilityExpanding ? 5 : 0;
                $longScore += $persistBull ? 10 : 0;

                $shortScore = 0;
                $shortScore += ($trendBear && $htf1OKBear && $htf2OKBear) ? 20 : 0;
                $shortScore += $adxBear ? 15 : 0;
                $shortScore += ($rsiBear ? 7 : 0) + ($rsiMomentumBear ? 3 : 0);
                $shortScore += $volumeScore;
                $shortScore += $obvBear ? 7 : 0;
                $shortScore += $bearCandle ? 10 : 0;
                $shortScore += $structureBear ? 10 : 0;
                $shortScore += $volatilityOK ? 5 : 0;
                $shortScore += $volatilityExpanding ? 5 : 0;
                $shortScore += $persistBear ? 10 : 0;

                // Professional Institutional Gate: Must meet min score, follow macro trend, and align with HTF
                $longQualifies = $longScore >= $minScore && $trendBullAt($i) && $htf1OK && $notOverextendedLong && ! $bearishDivergence;
                $shortQualifies = $shortScore >= $minScore && $trendBearAt($i) && $htf1OKBear && $notOverextendedShort && ! $bullishDivergence;

                $canLong = $activePosition === 'BUY' ? ($barsSinceSignal >= $cooldownBars) : ($barsSinceSignal >= $oppositeCooldownBars);
                $canShort = $activePosition === 'SELL' ? ($barsSinceSignal >= $cooldownBars) : ($barsSinceSignal >= $oppositeCooldownBars);

                if ($longQualifies && $canLong) {
                    $structureSL = $previousLow - ($atr[$i] * 0.25);
                    $atrSL = $curClose - ($atr[$i] * (float) $c['sl_mult']);
                    $sl = (bool) $c['use_structure_sl'] ? min($atrSL, $structureSL) : $atrSL;
                    $risk = $curClose - $sl;
                    if ($risk > 0) {
                        $tp1 = $curClose + max($atr[$i] * (float) $c['tp1_mult'], $risk * (float) $c['min_rr']);
                        $tp2 = $curClose + max($atr[$i] * (float) $c['tp2_mult'], $risk * ((float) $c['min_rr'] + 1.0));
                        $tp3 = $curClose + max($atr[$i] * (float) $c['tp3_mult'], $risk * ((float) $c['min_rr'] + 2.0));
                        $grade = $longScore >= (int) $c['grade_a'] ? 'A' : ($longScore >= (int) $c['grade_b'] ? 'B' : 'C');

                        $markers[] = [
                            'time' => $timeSec,
                            'position' => 'belowBar',
                            'color' => '#10b981',
                            'shape' => 'arrowUp',
                            'text' => "SignalAlgo BUY [{$grade}: {$longScore}]",
                            'size' => 2,
                            'side' => 'BUY',
                            'score' => $longScore,
                            'grade' => $grade,
                            'setup_type' => 'TREND',
                            'entry' => round($curClose, 4),
                            'sl' => round($sl, 4),
                            'tp1' => round($tp1, 4),
                            'tp2' => round($tp2, 4),
                            'tp3' => round($tp3, 4),
                            'rsi' => round((float) $rsi[$i], 1),
                            'adx' => round((float) $adx[$i], 1),
                            'atr_pct' => round($atrPct, 2),
                            'volume_ratio' => $volSma[$i] > 0 ? round($curVol / $volSma[$i], 2) : 1.0,
                        ];
                        $activePosition = 'BUY';
                        $barsSinceSignal = 0;
                    }
                } elseif ($shortQualifies && $canShort) {
                    $structureSL = $previousHigh + ($atr[$i] * 0.25);
                    $atrSL = $curClose + ($atr[$i] * (float) $c['sl_mult']);
                    $sl = (bool) $c['use_structure_sl'] ? max($atrSL, $structureSL) : $atrSL;
                    $risk = $sl - $curClose;
                    if ($risk > 0) {
                        $tp1 = $curClose - max($atr[$i] * (float) $c['tp1_mult'], $risk * (float) $c['min_rr']);
                        $tp2 = $curClose - max($atr[$i] * (float) $c['tp2_mult'], $risk * ((float) $c['min_rr'] + 1.0));
                        $tp3 = $curClose - max($atr[$i] * (float) $c['tp3_mult'], $risk * ((float) $c['min_rr'] + 2.0));
                        $grade = $shortScore >= (int) $c['grade_a'] ? 'A' : ($shortScore >= (int) $c['grade_b'] ? 'B' : 'C');

                        $markers[] = [
                            'time' => $timeSec,
                            'position' => 'aboveBar',
                            'color' => '#ef4444',
                            'shape' => 'arrowDown',
                            'text' => "SignalAlgo SELL [{$grade}: {$shortScore}]",
                            'size' => 2,
                            'side' => 'SELL',
                            'score' => $shortScore,
                            'grade' => $grade,
                            'setup_type' => 'TREND',
                            'entry' => round($curClose, 4),
                            'sl' => round($sl, 4),
                            'tp1' => round($tp1, 4),
                            'tp2' => round($tp2, 4),
                            'tp3' => round($tp3, 4),
                            'rsi' => round((float) $rsi[$i], 1),
                            'adx' => round((float) $adx[$i], 1),
                            'atr_pct' => round($atrPct, 2),
                            'volume_ratio' => $volSma[$i] > 0 ? round($curVol / $volSma[$i], 2) : 1.0,
                        ];
                        $activePosition = 'SELL';
                        $barsSinceSignal = 0;
                    }
                } elseif ((bool) ($c['enable_reversals'] ?? false)) {
                    $rev = $this->evaluateReversal(
                        $candles,
                        $i,
                        $emaFast,
                        $emaSlow,
                        $emaTrend,
                        $rsi,
                        $atr,
                        $volSma
                    );
                    if ($rev !== null) {
                        $canRev = $activePosition === $rev['side'] ? ($barsSinceSignal >= $cooldownBars) : ($barsSinceSignal >= $oppositeCooldownBars);
                        if ($canRev) {
                            $markers[] = [
                                'time' => $timeSec,
                                'position' => $rev['side'] === 'BUY' ? 'belowBar' : 'aboveBar',
                                'color' => $rev['side'] === 'BUY' ? '#10b981' : '#ef4444',
                                'shape' => $rev['side'] === 'BUY' ? 'arrowUp' : 'arrowDown',
                                'text' => "SignalAlgo {$rev['side']} [REV {$rev['grade']}: {$rev['score']}]",
                                'size' => 2,
                                'side' => $rev['side'],
                                'score' => $rev['score'],
                                'grade' => $rev['grade'],
                                'setup_type' => 'REVERSAL',
                                'entry' => round($curClose, 4),
                                'sl' => $rev['sl'],
                                'tp1' => $rev['tp1'],
                                'tp2' => $rev['tp2'],
                                'tp3' => $rev['tp3'],
                                'rsi' => round((float) $rsi[$i], 1),
                                'adx' => isset($adx[$i]) && $adx[$i] !== null ? round((float) $adx[$i], 1) : 0.0,
                                'atr_pct' => round($atrPct, 2),
                                'volume_ratio' => $volSma[$i] > 0 ? round($curVol / $volSma[$i], 2) : 1.0,
                            ];
                            $activePosition = $rev['side'];
                            $barsSinceSignal = 0;
                        }
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
        if ($idx < $trendLen) {
            return [true, true];
        }
        $ema = Indicators::ema($closes, $trendLen);
        if ($ema[$idx] === null) {
            return [true, true];
        }

        return [(float) $closes[$idx] > (float) $ema[$idx], (float) $closes[$idx] < (float) $ema[$idx]];
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
