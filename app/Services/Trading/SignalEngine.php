<?php

namespace App\Services\Trading;

class SignalEngine
{
    /**
     * Evaluate candles for institutional pre-breakout and high-probability trading setups.
     *
     * @param  array{opens: float[], highs: float[], lows: float[], closes: float[], volumes: float[], closeTimes: int[]}  $base
     * @param  array{opens: float[], highs: float[], lows: float[], closes: float[], volumes: float[], closeTimes: int[]}|null  $htf1
     * @param  array{opens: float[], highs: float[], lows: float[], closes: float[], volumes: float[], closeTimes: int[]}|null  $htf2
     * @param  array{opens: float[], highs: float[], lows: float[], closes: float[], volumes: float[], closeTimes: int[]}|null  $btcBase
     * @return array<string, mixed>|null
     */
    public function evaluate(
        string $symbol,
        array $base,
        ?array $htf1 = null,
        ?array $htf2 = null,
        ?array $btcBase = null
    ): ?array {
        $closes = $base['closes'];
        $highs = $base['highs'];
        $lows = $base['lows'];
        $opens = $base['opens'] ?? $closes;
        $volumes = $base['volumes'];
        $count = count($closes);

        if ($count < 60) {
            return null;
        }

        // Index of the last fully confirmed closed candle
        $i = $count - 2;
        $currentClose = $closes[$i];
        $currentHigh = $highs[$i];
        $currentLow = $lows[$i];
        $currentOpen = $opens[$i];
        $currentVolume = $volumes[$i];

        // 1. Core Technical Indicators on Base (15m)
        $ema9 = Indicators::ema($closes, 9);
        $ema21 = Indicators::ema($closes, 21);
        $ema50 = Indicators::ema($closes, 50);
        $ema200 = Indicators::ema($closes, 200);
        $atr = Indicators::atr($highs, $lows, $closes, 14);
        $rsi = Indicators::rsi($closes, 14);
        $volSma20 = Indicators::sma($volumes, 20);
        $bb = Indicators::bollingerBands($closes, 20, 2.0);
        $adxData = Indicators::adx($highs, $lows, $closes, 14);

        $valEma9 = $ema9[$i] ?? null;
        $valEma21 = $ema21[$i] ?? null;
        $valEma50 = $ema50[$i] ?? null;
        $valEma200 = $ema200[$i] ?? null;
        $valAtr = $atr[$i] ?? ($currentClose * 0.015);
        $valRsi = $rsi[$i] ?? 50.0;
        $valVolSma = $volSma20[$i] ?? 1.0;
        $valAdx = $adxData['adx'][$i] ?? 20.0;
        $valPlusDi = $adxData['plusDi'][$i] ?? 20.0;
        $valMinusDi = $adxData['minusDi'][$i] ?? 20.0;

        if ($valEma9 === null || $valEma21 === null || $valAtr <= 0) {
            return null;
        }

        // 2. TTM Squeeze & Energy Compression Detection
        $kc = Indicators::keltnerChannels($highs, $lows, $closes, 20, 2.0);
        $bbUp = $bb['upper'][$i] ?? ($valEma21 + (2.0 * $valAtr));
        $bbLow = $bb['lower'][$i] ?? ($valEma21 - (2.0 * $valAtr));
        $kcUp = $kc['upper'][$i] ?? ($valEma21 + (2.0 * $valAtr));
        $kcLow = $kc['lower'][$i] ?? ($valEma21 - (2.0 * $valAtr));

        // Squeeze is active when BB is within or compressed near Keltner Channel
        $isSqueeze = ($bbUp <= $kcUp * 1.002) && ($bbLow >= $kcLow * 0.998);

        // Or Bandwidth is in lowest 25% of recent 30 bars
        $recentWidths = array_slice($bb['bandwidth'], max(0, $i - 30), 30);
        $minWidth = ! empty($recentWidths) ? min($recentWidths) : 3.0;
        $curWidth = $bb['bandwidth'][$i] ?? 5.0;
        $isCompressed = $isSqueeze || ($curWidth <= $minWidth * 1.25);

        // 3. Higher Timeframe (1h) Trend Evaluation
        $htf1Bullish = true;
        $htf1Bearish = true;
        if ($htf1 && count($htf1['closes']) >= 30) {
            $htfCloses = $htf1['closes'];
            $htfIdx = count($htfCloses) - 2;
            $htfC = $htfCloses[$htfIdx];
            $htfEma9 = Indicators::ema($htfCloses, 9)[$htfIdx] ?? $htfC;
            $htfEma21 = Indicators::ema($htfCloses, 21)[$htfIdx] ?? $htfC;
            $htfEma50 = Indicators::ema($htfCloses, 50)[$htfIdx] ?? $htfC;

            // HTF Bullish: 1h price above 21 EMA OR 1h 9 EMA >= 21 EMA OR 21 EMA >= 50 EMA
            $htf1Bullish = ($htfC >= $htfEma21 * 0.998) || ($htfEma9 >= $htfEma21) || ($htfEma21 >= $htfEma50);
            // HTF Bearish: 1h price below 21 EMA OR 1h 9 EMA <= 21 EMA OR 21 EMA <= 50 EMA
            $htf1Bearish = ($htfC <= $htfEma21 * 1.002) || ($htfEma9 <= $htfEma21) || ($htfEma21 <= $htfEma50);
        }

        // 4. Relative Strength vs BTC (over last 24 bars = 6 hours)
        $rsRatio = 1.0;
        if ($btcBase && count($btcBase['closes']) >= 26) {
            $rsRatio = Indicators::relativeStrength($closes, $btcBase['closes'], 24);
        }

        // 5. Market Structure: 20-period swing high and swing low (excluding current candle)
        $lookback = 20;
        $recentHighs = array_slice($highs, max(0, $i - $lookback), $lookback);
        $recentLows = array_slice($lows, max(0, $i - $lookback), $lookback);
        $swingHigh = ! empty($recentHighs) ? max($recentHighs) : $currentHigh;
        $swingLow = ! empty($recentLows) ? min($recentLows) : $currentLow;

        $distToResPct = (($swingHigh - $currentClose) / $swingHigh) * 100.0;
        $distToSupPct = (($currentClose - $swingLow) / $currentClose) * 100.0;

        // 6. Candle Geometry & Volume Validation
        $candleRange = max(0.0000001, $currentHigh - $currentLow);
        $body = abs($currentClose - $currentOpen);
        $bodyRatio = $body / $candleRange;
        $upperWick = $currentHigh - max($currentOpen, $currentClose);
        $lowerWick = min($currentOpen, $currentClose) - $currentLow;
        $upperWickPct = ($upperWick / $candleRange) * 100.0;
        $lowerWickPct = ($lowerWick / $candleRange) * 100.0;
        $volRatio = $valVolSma > 0 ? round($currentVolume / $valVolSma, 2) : 1.0;

        // Consecutive Higher Lows & Lower Highs (Ascending / Descending Triangles)
        $low1 = $lows[$i];
        $low2 = $lows[$i - 1];
        $low3 = $lows[$i - 2];
        $hasHigherLows = ($low1 >= $low2 * 0.999 && $low2 >= $low3 * 0.999);

        $high1 = $highs[$i];
        $high2 = $highs[$i - 1];
        $high3 = $highs[$i - 2];
        $hasLowerHighs = ($high1 <= $high2 * 1.001 && $high2 <= $high3 * 1.001);

        // Trend validation on base timeframe
        $isBaseUptrend = ($currentClose > $valEma21) && ($valEma9 >= $valEma21 * 0.999);
        $isBaseDowntrend = ($currentClose < $valEma21) && ($valEma9 <= $valEma21 * 1.001);

        $direction = null;
        $setup = null;
        $score = 0;
        $slPrice = 0.0;

        // ==========================================
        // 7. TEST HIGH-PROBABILITY BULLISH (LONG) SETUPS
        // ==========================================
        $longScore = 0;

        // Setup 1: Clean Structure Breakout (New 20-period High expansion)
        $isCleanBreakoutLong = ($currentClose > $swingHigh)
            && $isBaseUptrend
            && $htf1Bullish
            && ($currentClose > $currentOpen)
            && ($upperWickPct <= 35.0)
            && ($valRsi >= 50.0 && $valRsi <= 74.0)
            && ($volRatio >= 1.05);

        // Setup 2: Wyckoff Spring Liquidity Reversal (Trap breakout shorts and pump through highs!)
        $isSpringLong = ($currentLow < $swingLow || $lows[$i - 1] < $swingLow)
            && ($currentClose > $swingLow)
            && ($currentClose > $currentOpen)
            && ($lowerWickPct >= 35.0)
            && ! $htf1Bearish
            && ($valRsi >= 38.0 && $valRsi <= 64.0)
            && ($volRatio >= 1.10);

        // Setup 3: Pre-Breakout Ascending Coiling Squeeze (Enter BEFORE the breakout explodes!)
        $isPreBreakoutLong = $isBaseUptrend
            && $htf1Bullish
            && ($distToResPct >= 0.05 && $distToResPct <= 1.80)
            && ($hasHigherLows || $currentClose > $valEma9)
            && ($currentClose > $currentOpen)
            && ($upperWickPct <= 35.0)
            && ($valRsi >= 48.0 && $valRsi <= 68.0)
            && ($volRatio >= 0.85);

        // Setup 4: Institutional 21-EMA Value Pullback Bounce (Dip buying in strong trend)
        $isPullbackBounceLong = $isBaseUptrend
            && $htf1Bullish
            && ($currentLow <= $valEma21 * 1.004 && $currentClose > $valEma21)
            && ($currentClose > $currentOpen)
            && ($lowerWickPct >= 25.0)
            && ($valRsi >= 46.0 && $valRsi <= 66.0);

        if ($isSpringLong) {
            $direction = 'LONG';
            $setup = 'WYCKOFF_SPRING_REVERSAL';
            $longScore = 95;
            $slPrice = min($currentLow, $lows[$i - 1]) * 0.998;
        } elseif ($isCleanBreakoutLong) {
            $direction = 'LONG';
            $setup = 'CLEAN_STRUCTURE_BREAKOUT';
            $longScore = 92;
            $slPrice = max($swingLow, min($currentLow, $lows[$i - 1])) * 0.9985;
        } elseif ($isPreBreakoutLong) {
            $direction = 'LONG';
            $setup = 'PRE_BREAKOUT_ASCENDING_COIL';
            $longScore = 88;
            $slPrice = min($low1, $low2) * 0.998;
        } elseif ($isPullbackBounceLong) {
            $direction = 'LONG';
            $setup = 'INSTITUTIONAL_PULLBACK_BOUNCE';
            $longScore = 86;
            $slPrice = $currentLow * 0.998;
        }

        // ==========================================
        // 8. TEST HIGH-PROBABILITY BEARISH (SHORT) SETUPS
        // ==========================================
        $shortScore = 0;

        // Setup 1: Clean Structure Breakdown (New 20-period Low expansion)
        $isCleanBreakdownShort = ($currentClose < $swingLow)
            && $isBaseDowntrend
            && $htf1Bearish
            && ($currentClose < $currentOpen)
            && ($lowerWickPct <= 35.0)
            && ($valRsi <= 50.0 && $valRsi >= 26.0)
            && ($volRatio >= 1.05);

        // Setup 2: Wyckoff Upthrust Liquidity Reversal (Trap breakout longs and dump through lows!)
        $isUpthrustShort = ($currentHigh > $swingHigh || $highs[$i - 1] > $swingHigh)
            && ($currentClose < $swingHigh)
            && ($currentClose < $currentOpen)
            && ($upperWickPct >= 35.0)
            && ! $htf1Bullish
            && ($valRsi <= 64.0 && $valRsi >= 36.0)
            && ($volRatio >= 1.10);

        // Setup 3: Pre-Breakdown Descending Coiling Squeeze (Enter BEFORE the dump flushes!)
        $isPreBreakdownShort = $isBaseDowntrend
            && $htf1Bearish
            && ($distToSupPct >= 0.05 && $distToSupPct <= 1.80)
            && ($hasLowerHighs || $currentClose < $valEma9)
            && ($currentClose < $currentOpen)
            && ($lowerWickPct <= 35.0)
            && ($valRsi <= 52.0 && $valRsi >= 32.0)
            && ($volRatio >= 0.85);

        // Setup 4: Institutional 21-EMA Value Pullback Rejection (Rally shorting in strong downtrend)
        $isPullbackRejectShort = $isBaseDowntrend
            && $htf1Bearish
            && ($currentHigh >= $valEma21 * 0.996 && $currentClose < $valEma21)
            && ($currentClose < $currentOpen)
            && ($upperWickPct >= 25.0)
            && ($valRsi <= 54.0 && $valRsi >= 34.0);

        if ($isUpthrustShort) {
            $direction = 'SHORT';
            $setup = 'WYCKOFF_UPTHRUST_REVERSAL';
            $shortScore = 95;
            $slPrice = max($currentHigh, $highs[$i - 1]) * 1.002;
        } elseif ($isCleanBreakdownShort) {
            $direction = 'SHORT';
            $setup = 'CLEAN_STRUCTURE_BREAKDOWN';
            $shortScore = 92;
            $slPrice = min($swingHigh, max($currentHigh, $highs[$i - 1])) * 1.0015;
        } elseif ($isPreBreakdownShort) {
            $direction = 'SHORT';
            $setup = 'PRE_BREAKDOWN_DESCENDING_COIL';
            $shortScore = 88;
            $slPrice = max($high1, $high2) * 1.002;
        } elseif ($isPullbackRejectShort) {
            $direction = 'SHORT';
            $setup = 'INSTITUTIONAL_PULLBACK_REJECTION';
            $shortScore = 86;
            $slPrice = $currentHigh * 1.002;
        }

        // Confluence scoring resolution
        if ($direction === 'LONG') {
            $score = $longScore;
            if ($isCompressed) {
                $score += 3;
            }
            if ($volRatio >= 1.4) {
                $score += 2;
            }
        } elseif ($direction === 'SHORT') {
            $score = $shortScore;
            if ($isCompressed) {
                $score += 3;
            }
            if ($volRatio >= 1.4) {
                $score += 2;
            }
        } else {
            return null;
        }

        if ($score < 80) {
            return null;
        }

        // ==========================================
        // 9. DYNAMIC STRUCTURAL SL & ASYMMETRIC TP CALCULATION
        // ==========================================
        $entryPrice = $currentClose;
        $rawSlDist = abs($entryPrice - $slPrice);

        // Enforce strict risk bounds: Min 0.65%, Max 1.25% (Scalp risk boundary)
        $minSlDist = $entryPrice * 0.0065;
        $maxSlDist = $entryPrice * 0.0125;
        $slDist = max($minSlDist, min($maxSlDist, $rawSlDist));

        // Asymmetric compounding targets with true positive mathematical expectancy:
        // TP1: Solid cash harvest (1:1.35 R:R, ~1.00% - 1.50% price move, +10.0% to +15.0% ROE at 10x)
        // TP2: Major trend expansion (1:2.65 R:R, ~2.00% - 3.20% price move, +20.0% to +32.0% ROE)
        if ($direction === 'LONG') {
            $initialSl = round($entryPrice - $slDist, 6);
            $tp1 = round($entryPrice + ($slDist * 1.35), 6); // 35% profit harvest
            $tp2 = round($entryPrice + ($slDist * 2.65), 6); // 30% trend harvest
        } else {
            $initialSl = round($entryPrice + $slDist, 6);
            $tp1 = round($entryPrice - ($slDist * 1.35), 6);
            $tp2 = round($entryPrice - ($slDist * 2.65), 6);
        }

        $slPct = round(($slDist / $entryPrice) * 100, 2);
        $grade = $score >= 92 ? 'A+' : ($score >= 88 ? 'A' : 'B');

        return [
            'symbol' => $symbol,
            'direction' => $direction,
            'setup' => $setup,
            'score' => min(100, $score),
            'grade' => $grade,
            'price' => $entryPrice,
            'initial_sl' => $initialSl,
            'tp1' => $tp1,
            'tp2' => $tp2,
            'sl_pct' => $slPct,
            'indicators' => [
                'rsi' => round($valRsi, 2),
                'adx' => round($valAdx, 2),
                'volume_ratio' => $volRatio,
                'atr' => round($valAtr, 4),
                'ema9' => round($valEma9, 4),
                'ema21' => round($valEma21, 4),
                'ema50' => $valEma50 ? round($valEma50, 4) : null,
                'ema200' => $valEma200 ? round($valEma200, 4) : null,
                'htf1_aligned' => $direction === 'LONG' ? $htf1Bullish : $htf1Bearish,
                'rs_ratio' => round($rsRatio, 4),
                'is_compressed' => $isCompressed,
            ],
        ];
    }
}
