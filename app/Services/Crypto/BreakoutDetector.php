<?php

namespace App\Services\Crypto;

class BreakoutDetector
{
    /**
     * Analyze candles for Pre-Breakout Watch, Confirmed Breakout, or Retest entries.
     *
     * @param  array{
     *     opens: array<int, float>,
     *     highs: array<int, float>,
     *     lows: array<int, float>,
     *     closes: array<int, float>,
     *     volumes: array<int, float>,
     *     closeTimes: array<int, int>
     * }  $baseCandles  15m candles
     * @param  array|null  $htf1Candles  1h candles
     * @param  array|null  $htf2Candles  4h candles
     * @param  array|null  $microCandles  5m candles (for micro confirmation)
     * @return array{
     *     type: string, // 'BREAKOUT_WATCH', 'BREAKOUT_CONFIRMED', 'RETEST_ENTRY', 'NONE'
     *     side: string, // 'BUY' or 'SELL'
     *     score: int,
     *     grade: string,
     *     breakout_level: float,
     *     distance_pct: float,
     *     entry: float,
     *     sl: float,
     *     tp1: float,
     *     tp2: float,
     *     tp3: float,
     *     risk_reward: string,
     *     volume_ratio: float,
     *     rsi: float,
     *     adx: float,
     *     atr_pct: float,
     *     setup_label: string,
     *     candle_close_time: int,
     *     perpetual_options: array<string, mixed>
     * }|null
     */
    public function evaluate(
        array $baseCandles,
        ?array $htf1Candles = null,
        ?array $htf2Candles = null,
        ?array $microCandles = null,
        ?array $btcCandles = null
    ): ?array {
        $closes = $baseCandles['closes'] ?? [];
        $highs = $baseCandles['highs'] ?? [];
        $lows = $baseCandles['lows'] ?? [];
        $opens = $baseCandles['opens'] ?? [];
        $volumes = $baseCandles['volumes'] ?? [];
        $closeTimes = $baseCandles['closeTimes'] ?? [];
        $count = count($closes);

        if ($count < 55) {
            return null;
        }

        $i = $count - 2; // Last closed candle
        $currentClose = $closes[$i];
        $currentHigh = $highs[$i];
        $currentLow = $lows[$i];
        $currentOpen = $opens[$i];
        $currentVol = $volumes[$i];
        $closeTimeMs = $closeTimes[$i];

        // 1. Indicators calculation on base timeframe
        $ema9 = Indicators::ema($closes, 9);
        $ema21 = Indicators::ema($closes, 21);
        $ema200 = Indicators::ema($closes, 200);
        $rsi = Indicators::rsi($closes, 14);
        $atr = Indicators::atr($highs, $lows, $closes, 14);
        $volSma = Indicators::sma($volumes, 20);
        [$adx, $plusDI, $minusDI] = Indicators::adx($highs, $lows, $closes, 14);
        [$bbUpper, $bbMiddle, $bbLower, $bbWidth] = Indicators::bollingerBands($closes, 20, 2.0);
        $ttm = Indicators::ttmSqueeze($highs, $lows, $closes, 20);

        $curEma9 = $ema9[$i] ?? null;
        $curEma21 = $ema21[$i] ?? null;
        $curEma200 = $ema200[$i] ?? null;
        $curRsi = (float) ($rsi[$i] ?? 50.0);
        $prevRsi = (float) ($rsi[$i - 1] ?? $curRsi);
        $curAtr = (float) ($atr[$i] ?? ($currentClose * 0.015));
        $atrPct = $currentClose > 0 ? round(($curAtr / $currentClose) * 100, 2) : 1.5;
        $curVolSma = (float) ($volSma[$i] ?? 1.0);
        $volRatio = $curVolSma > 0 ? round($currentVol / $curVolSma, 2) : 1.0;
        $curAdx = (float) ($adx[$i] ?? 20.0);

        // 2. Higher Timeframe Trend Alignment
        $htf1Bull = true;
        $htf1Bear = true;
        if ($htf1Candles && ! empty($htf1Candles['closes'])) {
            $htfCloses = $htf1Candles['closes'];
            $htfIdx = count($htfCloses) - 2;
            if ($htfIdx >= 0) {
                $htfEma21 = Indicators::ema($htfCloses, 21);
                $htfEma200 = Indicators::ema($htfCloses, 200);
                $htfClose = $htfCloses[$htfIdx];
                $h200 = $htfEma200[$htfIdx] ?? null;
                $h21 = $htfEma21[$htfIdx] ?? null;

                $htf1Bull = ($h200 === null || $htfClose > $h200) && ($h21 === null || $htfClose >= $h21 * 0.995);
                $htf1Bear = ($h200 === null || $htfClose < $h200) && ($h21 === null || $htfClose <= $h21 * 1.005);
            }
        }

        // 3. Relative Strength vs Bitcoin
        $rsRatio = 1.0;
        if ($btcCandles && ! empty($btcCandles['closes'])) {
            $rsRatio = Indicators::relativeStrength($closes, $btcCandles['closes'], 24);
        }

        // 4. Structural Resistance and Support Discovery
        $structureLen = 30;
        $lookbackHighs = array_slice($highs, max(0, $i - $structureLen), $structureLen);
        $lookbackLows = array_slice($lows, max(0, $i - $structureLen), $structureLen);
        $resistance = max($lookbackHighs);
        $support = min($lookbackLows);

        // Distance percentages to breakout levels
        $distToResPct = $resistance > 0 ? round((($resistance - $currentClose) / $resistance) * 100, 2) : 999.0;
        $distToSuppPct = $support > 0 ? round((($currentClose - $support) / $support) * 100, 2) : 999.0;

        // Candle geometry & wick hygiene
        $candleRange = max(0.0000001, $currentHigh - $currentLow);
        $body = abs($currentClose - $currentOpen);
        $bodyRatio = $candleRange > 0 ? $body / $candleRange : 0.0;
        $upperWick = $currentHigh - max($currentClose, $currentOpen);
        $lowerWick = min($currentClose, $currentOpen) - $currentLow;
        $upperWickRatio = $upperWick / $candleRange;
        $lowerWickRatio = $lowerWick / $candleRange;

        $isBullCandle = ($currentClose > $currentOpen) && ($bodyRatio >= 0.40) && ($upperWickRatio <= 0.30);
        $isBearCandle = ($currentClose < $currentOpen) && ($bodyRatio >= 0.40) && ($lowerWickRatio <= 0.30);

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

        // Local structure swings
        $low1 = $lows[$i];
        $low2 = $lows[$i - 1];
        $low3 = $lows[$i - 2];
        $hasHigherLows = ($low1 >= $low2 * 0.999 && $low2 >= $low3 * 0.999);

        $high1 = $highs[$i];
        $high2 = $highs[$i - 1];
        $high3 = $highs[$i - 2];
        $hasLowerHighs = ($high1 <= $high2 * 1.001 && $high2 <= $high3 * 1.001);

        // ==========================================
        // SCENARIO 1: PRE-BREAKOUT ASCENDING COIL SQUEEZE (Enter BEFORE Breakout)
        // ==========================================
        if ($distToResPct >= 0.12 && $distToResPct <= 1.30 && ($hasHigherLows || $currentClose > $curEma9) && $isBullCandle && $upperWickRatio <= 0.28 && $rsRatio >= 0.995 && $htf1Bull) {
            $entry = $currentClose;
            $rawSl = min($low1, $low2) * 0.998;
            $score = 92;
            $score += $isCompressed ? 4 : 0;
            $score += $volRatio >= 1.2 ? 2 : 0;

            return $this->formatBreakoutPayload(
                type: 'PRE_BREAKOUT_COIL',
                side: 'BUY',
                score: min(98, $score),
                grade: 'A',
                breakoutLevel: $resistance,
                distancePct: $distToResPct,
                entry: $entry,
                rawSl: $rawSl,
                volRatio: $volRatio,
                rsi: $curRsi,
                adx: $curAdx,
                atrPct: $atrPct,
                setupLabel: 'PRE-BREAKOUT ASCENDING COIL',
                closeTimeMs: $closeTimeMs,
                rsRatio: $rsRatio
            );
        }

        // ==========================================
        // SCENARIO 2: PRE-BREAKDOWN DESCENDING COIL SQUEEZE (Enter BEFORE Breakdown)
        // ==========================================
        if ($distToSuppPct >= 0.12 && $distToSuppPct <= 1.30 && ($hasLowerHighs || $currentClose < $curEma9) && $isBearCandle && $lowerWickRatio <= 0.28 && $rsRatio <= 1.005 && $htf1Bear) {
            $entry = $currentClose;
            $rawSl = max($high1, $high2) * 1.002;
            $score = 92;
            $score += $isCompressed ? 4 : 0;
            $score += $volRatio >= 1.2 ? 2 : 0;

            return $this->formatBreakoutPayload(
                type: 'PRE_BREAKOUT_COIL',
                side: 'SELL',
                score: min(98, $score),
                grade: 'A',
                breakoutLevel: $support,
                distancePct: $distToSuppPct,
                entry: $entry,
                rawSl: $rawSl,
                volRatio: $volRatio,
                rsi: $curRsi,
                adx: $curAdx,
                atrPct: $atrPct,
                setupLabel: 'PRE-BREAKDOWN DESCENDING COIL',
                closeTimeMs: $closeTimeMs,
                rsRatio: $rsRatio
            );
        }

        // ==========================================
        // SCENARIO 3: WYCKOFF SPRING LIQUIDITY REVERSAL
        // ==========================================
        if (($currentLow < $support || $lows[$i - 1] < $support) && $currentClose > $support && $currentClose > $currentOpen && $lowerWickRatio >= 0.35 && $rsRatio >= 0.995 && ! $htf1Bear) {
            $entry = $currentClose;
            $rawSl = min($currentLow, $lows[$i - 1]) * 0.998;

            return $this->formatBreakoutPayload(
                type: 'WYCKOFF_SPRING',
                side: 'BUY',
                score: 95,
                grade: 'A+',
                breakoutLevel: $support,
                distancePct: 0.0,
                entry: $entry,
                rawSl: $rawSl,
                volRatio: $volRatio,
                rsi: $curRsi,
                adx: $curAdx,
                atrPct: $atrPct,
                setupLabel: 'WYCKOFF SPRING REVERSAL',
                closeTimeMs: $closeTimeMs,
                rsRatio: $rsRatio
            );
        }

        // ==========================================
        // SCENARIO 4: WYCKOFF UPTHRUST LIQUIDITY REVERSAL
        // ==========================================
        if (($currentHigh > $resistance || $highs[$i - 1] > $resistance) && $currentClose < $resistance && $currentClose < $currentOpen && $upperWickRatio >= 0.35 && $rsRatio <= 1.005 && ! $htf1Bull) {
            $entry = $currentClose;
            $rawSl = max($currentHigh, $highs[$i - 1]) * 1.002;

            return $this->formatBreakoutPayload(
                type: 'WYCKOFF_UPTHRUST',
                side: 'SELL',
                score: 95,
                grade: 'A+',
                breakoutLevel: $resistance,
                distancePct: 0.0,
                entry: $entry,
                rawSl: $rawSl,
                volRatio: $volRatio,
                rsi: $curRsi,
                adx: $curAdx,
                atrPct: $atrPct,
                setupLabel: 'WYCKOFF UPTHRUST REVERSAL',
                closeTimeMs: $closeTimeMs,
                rsRatio: $rsRatio
            );
        }

        // ==========================================
        // SCENARIO 5: CONFIRMED RESISTANCE BREAKOUT
        // ==========================================
        $confirmedLong = (
            $currentClose > $resistance &&
            $isBullCandle &&
            $volRatio >= 1.25 &&
            $curRsi >= 50.0 &&
            $curRsi <= 74.0 &&
            $rsRatio >= 0.995 &&
            $htf1Bull &&
            ($curEma200 === null || $currentClose > $curEma200)
        );

        if ($confirmedLong) {
            $entry = $currentClose;
            $rawSl = max($resistance * 0.995, $currentLow * 0.998);

            return $this->formatBreakoutPayload(
                type: 'BREAKOUT_CONFIRMED',
                side: 'BUY',
                score: 88,
                grade: 'A',
                breakoutLevel: $resistance,
                distancePct: 0.0,
                entry: $entry,
                rawSl: $rawSl,
                volRatio: $volRatio,
                rsi: $curRsi,
                adx: $curAdx,
                atrPct: $atrPct,
                setupLabel: 'RESISTANCE BREAKOUT CONFIRMED',
                closeTimeMs: $closeTimeMs,
                rsRatio: $rsRatio
            );
        }

        // ==========================================
        // SCENARIO 6: CONFIRMED SUPPORT BREAKDOWN
        // ==========================================
        $confirmedShort = (
            $currentClose < $support &&
            $isBearCandle &&
            $volRatio >= 1.25 &&
            $curRsi <= 50.0 &&
            $curRsi >= 26.0 &&
            $rsRatio <= 1.005 &&
            $htf1Bear &&
            ($curEma200 === null || $currentClose < $curEma200)
        );

        if ($confirmedShort) {
            $entry = $currentClose;
            $rawSl = min($support * 1.005, $currentHigh * 1.002);

            return $this->formatBreakoutPayload(
                type: 'BREAKOUT_CONFIRMED',
                side: 'SELL',
                score: 88,
                grade: 'A',
                breakoutLevel: $support,
                distancePct: 0.0,
                entry: $entry,
                rawSl: $rawSl,
                volRatio: $volRatio,
                rsi: $curRsi,
                adx: $curAdx,
                atrPct: $atrPct,
                setupLabel: 'SUPPORT BREAKDOWN CONFIRMED',
                closeTimeMs: $closeTimeMs,
                rsRatio: $rsRatio
            );
        }

        // ==========================================
        // SCENARIO 7: BREAKOUT RETEST ENTRY
        // ==========================================
        if ($i >= 2 && $closes[$i - 1] > $resistance && $currentLow <= ($resistance * 1.004) && $currentClose >= $resistance && $htf1Bull && $volRatio >= 1.10 && $rsRatio >= 0.995 && ($curEma200 === null || $currentClose > $curEma200) && $upperWickRatio <= 0.30) {
            $entry = $currentClose;
            $rawSl = $currentLow * 0.998;

            return $this->formatBreakoutPayload(
                type: 'RETEST_ENTRY',
                side: 'BUY',
                score: 86,
                grade: 'B',
                breakoutLevel: $resistance,
                distancePct: 0.0,
                entry: $entry,
                rawSl: $rawSl,
                volRatio: $volRatio,
                rsi: $curRsi,
                adx: $curAdx,
                atrPct: $atrPct,
                setupLabel: 'BREAKOUT RETEST & BOUNCE',
                closeTimeMs: $closeTimeMs,
                rsRatio: $rsRatio
            );
        }

        // ==========================================
        // SCENARIO 8: PRE-BREAKOUT WATCH (WATCHLIST ALERT)
        // ==========================================
        if ($distToResPct >= 0.15 && $distToResPct <= 1.25 && $volRatio >= 1.10 && $curRsi >= 52.0 && $curRsi <= 68.0 && $rsRatio >= 0.995 && $htf1Bull && ($curEma200 === null || $currentClose > $curEma200) && $upperWickRatio <= 0.30) {
            $probScore = 80;
            $probScore += ($volRatio >= 1.30) ? 8 : 4;
            $probScore += ($curRsi > $prevRsi) ? 4 : 0;
            $probScore += $isCompressed ? 5 : 0;
            $probScore = min(96, $probScore);

            $entryEst = $resistance * 1.0015;
            $rawSlEst = $resistance * 0.991;

            return $this->formatBreakoutPayload(
                type: 'BREAKOUT_WATCH',
                side: 'BUY',
                score: $probScore,
                grade: $probScore >= 90 ? 'A' : 'B',
                breakoutLevel: $resistance,
                distancePct: $distToResPct,
                entry: round($entryEst, 4),
                rawSl: $rawSlEst,
                volRatio: $volRatio,
                rsi: $curRsi,
                adx: $curAdx,
                atrPct: $atrPct,
                setupLabel: 'RESISTANCE BREAKOUT WATCH',
                closeTimeMs: $closeTimeMs,
                rsRatio: $rsRatio
            );
        }

        return null;
    }

    /**
     * Format breakout setup into standard typed array with tight asymmetric perpetual trade options.
     */
    protected function formatBreakoutPayload(
        string $type,
        string $side,
        int $score,
        string $grade,
        float $breakoutLevel,
        float $distancePct,
        float $entry,
        float $rawSl,
        float $volRatio,
        float $rsi,
        float $adx,
        float $atrPct,
        string $setupLabel,
        int $closeTimeMs,
        float $rsRatio = 1.0
    ): array {
        // Enforce strict structural risk bounds (Min 0.75%, Max 1.35%)
        $rawSlDist = abs($entry - $rawSl);
        $minSlDist = $entry * 0.0075;
        $maxSlDist = $entry * 0.0135;
        $risk = max($minSlDist, min($maxSlDist, $rawSlDist));

        $sl = $side === 'BUY' ? round($entry - $risk, 6) : round($entry + $risk, 6);
        $tp1 = $side === 'BUY' ? round($entry + ($risk * 1.35), 6) : round($entry - ($risk * 1.35), 6); // 1:1.35 R:R (+15% ROE)
        $tp2 = $side === 'BUY' ? round($entry + ($risk * 2.80), 6) : round($entry - ($risk * 2.80), 6); // 1:2.80 R:R (+30% ROE)
        $tp3 = $side === 'BUY' ? round($entry + ($risk * 4.50), 6) : round($entry - ($risk * 4.50), 6); // 1:4.50 R:R (+50% ROE)

        $slPct = $entry > 0 ? round(($risk / $entry) * 100, 2) : 1.0;
        $tp1Pct = $entry > 0 ? round(abs($tp1 - $entry) / $entry * 100, 2) : 1.5;
        $tp2Pct = $entry > 0 ? round(abs($tp2 - $entry) / $entry * 100, 2) : 3.0;
        $tp3Pct = $entry > 0 ? round(abs($tp3 - $entry) / $entry * 100, 2) : 4.8;
        $rrRatio = $slPct > 0 ? '1 : '.round($tp2Pct / $slPct, 1) : '1 : 2.8';

        $recLeverage = '5x - 10x';
        $levMult = 10;

        return [
            'type' => $type,
            'side' => $side,
            'score' => $score,
            'grade' => $grade,
            'breakout_level' => round($breakoutLevel, 4),
            'distance_pct' => $distancePct,
            'entry' => round($entry, 4),
            'sl' => round($sl, 4),
            'tp1' => round($tp1, 4),
            'tp2' => round($tp2, 4),
            'tp3' => round($tp3, 4),
            'risk_reward' => $rrRatio,
            'volume_ratio' => $volRatio,
            'rsi' => round($rsi, 1),
            'adx' => round($adx, 1),
            'atr_pct' => $atrPct,
            'setup_label' => $setupLabel,
            'candle_close_time' => $closeTimeMs,
            'rs_ratio' => round($rsRatio, 4),
            'perpetual_options' => [
                'recommended_leverage' => $recLeverage,
                'margin_mode' => 'Isolated Margin',
                'order_type' => 'Limit / Market Entry',
                'risk_per_trade' => '1.0% - 2.0% Account Balance',
                'risk_reward' => $rrRatio,
                'sl_pct' => $slPct,
                'tp1_pct' => $tp1Pct,
                'tp2_pct' => $tp2Pct,
                'tp3_pct' => $tp3Pct,
                'sl_leveraged_pct' => round($slPct * $levMult, 1),
                'tp1_leveraged_pct' => round($tp1Pct * $levMult, 1),
                'tp2_leveraged_pct' => round($tp2Pct * $levMult, 1),
                'tp3_leveraged_pct' => round($tp3Pct * $levMult, 1),
                'leverage_multiplier' => $levMult,
            ],
        ];
    }
}
