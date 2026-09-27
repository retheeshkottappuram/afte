<?php

namespace App\Services\Trading;

class SignalEngine
{
    /**
     * Evaluate candles for trading setup.
     *
     * @param  array{opens: float[], highs: float[], lows: float[], closes: float[], volumes: float[], closeTimes: int[]}  $base
     * @param  array{opens: float[], highs: float[], lows: float[], closes: float[], volumes: float[], closeTimes: int[]}|null  $htf1
     * @param  array{opens: float[], highs: float[], lows: float[], closes: float[], volumes: float[], closeTimes: int[]}|null  $htf2
     * @return array<string, mixed>|null
     */
    public function evaluate(string $symbol, array $base, ?array $htf1 = null, ?array $htf2 = null): ?array
    {
        $closes = $base['closes'];
        $highs = $base['highs'];
        $lows = $base['lows'];
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
        $currentVolume = $volumes[$i];

        // 1. Indicators on Base
        $ema9 = Indicators::ema($closes, 9);
        $ema21 = Indicators::ema($closes, 21);
        $ema200 = Indicators::ema($closes, 200);
        $atr = Indicators::atr($highs, $lows, $closes, 14);
        $rsi = Indicators::rsi($closes, 14);
        $volSma20 = Indicators::sma($volumes, 20);
        $bb = Indicators::bollingerBands($closes, 20, 2.0);
        $adxData = Indicators::adx($highs, $lows, $closes, 14);

        $valEma9 = $ema9[$i] ?? null;
        $valEma21 = $ema21[$i] ?? null;
        $valEma200 = $ema200[$i] ?? ($ema21[$i] ?? null);
        $valAtr = $atr[$i] ?? ($currentClose * 0.015);
        $valRsi = $rsi[$i] ?? 50.0;
        $valVolSma = $volSma20[$i] ?? 1.0;
        $valAdx = $adxData['adx'][$i] ?? 20.0;
        $valPlusDi = $adxData['plusDi'][$i] ?? 20.0;
        $valMinusDi = $adxData['minusDi'][$i] ?? 20.0;

        if ($valEma9 === null || $valEma21 === null || $valAtr <= 0) {
            return null;
        }

        // 2. Market Structure: 20-period swing high and swing low (excluding current candle)
        $lookback = 20;
        $recentHighs = array_slice($highs, max(0, $i - $lookback), $lookback);
        $recentLows = array_slice($lows, max(0, $i - $lookback), $lookback);
        $swingHigh = ! empty($recentHighs) ? max($recentHighs) : $currentHigh;
        $swingLow = ! empty($recentLows) ? min($recentLows) : $currentLow;

        // 3. HTF Trend Evaluation
        $htf1Bullish = true;
        $htf1Bearish = true;
        if ($htf1 && count($htf1['closes']) >= 30) {
            $htfCloses = $htf1['closes'];
            $htfIdx = count($htfCloses) - 2;
            $htfEma21 = Indicators::ema($htfCloses, 21);
            $htfEma50 = Indicators::ema($htfCloses, 50);

            if (($htfEma21[$htfIdx] ?? null) !== null && ($htfEma50[$htfIdx] ?? null) !== null) {
                $htf1Bullish = $htfEma21[$htfIdx] >= $htfEma50[$htfIdx];
                $htf1Bearish = $htfEma21[$htfIdx] <= $htfEma50[$htfIdx];
            }
        }

        $volRatio = $valVolSma > 0 ? round($currentVolume / $valVolSma, 2) : 1.0;

        // 4. Candle Geometry & Price Action Filter
        $currentOpen = $base['opens'][$i] ?? $currentClose;
        $candleRange = max(0.0000001, $currentHigh - $currentLow);
        $body = abs($currentClose - $currentOpen);
        $bodyRatio = $body / $candleRange;
        $upperWick = $currentHigh - max($currentOpen, $currentClose);
        $lowerWick = min($currentOpen, $currentClose) - $currentLow;
        $upperWickPct = ($upperWick / $candleRange) * 100.0;
        $lowerWickPct = ($lowerWick / $candleRange) * 100.0;

        $isBullCandle = ($currentClose > $currentOpen) && ($bodyRatio >= 0.38) && ($upperWickPct <= 38.0);
        $isBearCandle = ($currentClose < $currentOpen) && ($bodyRatio >= 0.38) && ($lowerWickPct <= 38.0);

        $ema50 = Indicators::ema($closes, 50);
        $valEma50 = $ema50[$i] ?? ($ema21[$i] ?? null);

        // Volatility Squeeze / Expansion Detection
        $bbExpanding = false;
        if (isset($bb['upper'][$i], $bb['lower'][$i], $bb['upper'][$i - 2], $bb['lower'][$i - 2])) {
            $prevWidth = $bb['upper'][$i - 2] - $bb['lower'][$i - 2];
            $curWidth = $bb['upper'][$i - 2] ? ($bb['upper'][$i] - $bb['lower'][$i]) : 0;
            $bbExpanding = $curWidth > $prevWidth * 1.05;
        }

        // ==========================================
        // 5. Test LONG (BUY) Setup
        // ==========================================
        $longScore = 0;

        // Pattern 1: Pure Structure Breakout (New 20-period High with solid body)
        $isCleanBreakoutLong = ($currentClose > $swingHigh && $isBullCandle && $bodyRatio >= 0.45 && $upperWickPct <= 30.0);

        // Pattern 2: Breakout Retest (Prior bar broke out, current bar tested former resistance as support)
        $isRetestLong = ($closes[$i - 1] > $swingHigh && $currentLow <= $swingHigh * 1.003 && $currentClose >= $swingHigh && $isBullCandle);

        // Pattern 3: Trend Continuation Pullback into Value Zone (Dip to 21 EMA in established uptrend with rejection hammer)
        $isPullbackBounceLong = ($valEma9 > $valEma21
            && ($valEma50 === null || $valEma21 >= $valEma50)
            && $currentLow <= ($valEma21 * 1.003)
            && $currentClose > $valEma9
            && $isBullCandle
            && $lowerWickPct >= 28.0);

        if ($isCleanBreakoutLong) {
            $longScore += 35; // Major structure breakout
        } elseif ($isRetestLong) {
            $longScore += 32; // Verified breakout retest
        } elseif ($isPullbackBounceLong) {
            $longScore += 30; // Institutional value pullback bounce
        }

        // Only proceed if one of the 3 validated institutional patterns occurred
        if ($longScore > 0) {
            // Trend alignment on base timeframe
            if ($valEma9 > $valEma21 && ($valEma50 === null || $valEma21 > $valEma50)) {
                $longScore += 15;
            }
            if ($valEma200 && $currentClose > $valEma200) {
                $longScore += 10;
            } elseif ($valEma200 && $currentClose < $valEma200) {
                $longScore -= 20; // Severe penalty: Counter-trend below 200 EMA
            }

            // Volume validation
            if ($volRatio >= 1.35) {
                $longScore += 20; // Institutional surge
            } elseif ($volRatio >= 1.05) {
                $longScore += 10;
            } elseif ($volRatio < 0.85) {
                $longScore -= 20; // Low volume trap penalty
            }

            // Momentum & ADX
            if ($valRsi >= 50.0 && $valRsi <= 68.0) {
                $longScore += 15; // Optimal momentum
            } elseif ($valRsi > 72.0) {
                $longScore -= 20; // Overbought exhaustion penalty
            }

            if ($valAdx >= 22.0) {
                $longScore += 10;
            } elseif ($valAdx < 17.0) {
                $longScore -= 15; // Choppy market penalty
            }

            // Higher timeframe gate
            if ($htf1Bullish) {
                $longScore += 15;
            } else {
                $longScore -= 30; // Severe penalty: Fighting HTF trend
            }

            if ($bbExpanding) {
                $longScore += 5;
            }
        }

        // ==========================================
        // 6. Test SHORT (SELL) Setup
        // ==========================================
        $shortScore = 0;

        // Pattern 1: Pure Structure Breakdown (New 20-period Low with solid body)
        $isCleanBreakdownShort = ($currentClose < $swingLow && $isBearCandle && $bodyRatio >= 0.45 && $lowerWickPct <= 30.0);

        // Pattern 2: Breakdown Retest (Prior bar broke down, current bar tested former support as resistance)
        $isRetestShort = ($closes[$i - 1] < $swingLow && $currentHigh >= $swingLow * 0.997 && $currentClose <= $swingLow && $isBearCandle);

        // Pattern 3: Trend Continuation Pullback into Value Zone (Rally to 21 EMA in established downtrend with rejection star)
        $isPullbackRejectionShort = ($valEma9 < $valEma21
            && ($valEma50 === null || $valEma21 <= $valEma50)
            && $currentHigh >= ($valEma21 * 0.997)
            && $currentClose < $valEma9
            && $isBearCandle
            && $upperWickPct >= 28.0);

        if ($isCleanBreakdownShort) {
            $shortScore += 35; // Major structure breakdown
        } elseif ($isRetestShort) {
            $shortScore += 32; // Verified breakdown retest
        } elseif ($isPullbackRejectionShort) {
            $shortScore += 30; // Institutional value pullback rejection
        }

        // Only proceed if one of the 3 validated institutional patterns occurred
        if ($shortScore > 0) {
            // Trend alignment on base timeframe
            if ($valEma9 < $valEma21 && ($valEma50 === null || $valEma21 < $valEma50)) {
                $shortScore += 15;
            }
            if ($valEma200 && $currentClose < $valEma200) {
                $shortScore += 10;
            } elseif ($valEma200 && $currentClose > $valEma200) {
                $shortScore -= 20; // Severe penalty: Counter-trend above 200 EMA
            }

            // Volume validation
            if ($volRatio >= 1.35) {
                $shortScore += 20; // Institutional surge
            } elseif ($volRatio >= 1.05) {
                $shortScore += 10;
            } elseif ($volRatio < 0.85) {
                $shortScore -= 20; // Low volume trap penalty
            }

            // Momentum & ADX
            if ($valRsi <= 50.0 && $valRsi >= 32.0) {
                $shortScore += 15; // Optimal downward momentum
            } elseif ($valRsi < 28.0) {
                $shortScore -= 20; // Oversold exhaustion penalty
            }

            if ($valAdx >= 22.0) {
                $shortScore += 10;
            } elseif ($valAdx < 17.0) {
                $shortScore -= 15; // Choppy market penalty
            }

            // Higher timeframe gate
            if ($htf1Bearish) {
                $shortScore += 15;
            } else {
                $shortScore -= 30; // Severe penalty: Fighting HTF trend
            }

            if ($bbExpanding) {
                $shortScore += 5;
            }
        }

        $direction = null;
        $score = 0;

        if ($longScore >= 80 && $longScore > $shortScore && $valPlusDi >= $valMinusDi) {
            $direction = 'LONG';
            $score = min(100, $longScore);
        } elseif ($shortScore >= 80 && $shortScore > $longScore && $valMinusDi >= $valPlusDi) {
            $direction = 'SHORT';
            $score = min(100, $shortScore);
        }

        if ($direction === null) {
            return null;
        }

        // Calculate Dynamic Structure SL & Asymmetric TP Targets
        $entryPrice = $currentClose;
        if ($direction === 'LONG') {
            // Structural SL: Lowest low of the last 10 candles minus 0.15% cushion, bounded by 1.0% to 2.2%
            $recentLowsSlice = array_slice($lows, max(0, $i - 10), 10);
            $structuralLow = ! empty($recentLowsSlice) ? min($recentLowsSlice) : ($entryPrice - (1.5 * $valAtr));
            $rawSl = $structuralLow * 0.9985;

            $slDist = max($entryPrice * 0.010, min($entryPrice * 0.022, $entryPrice - $rawSl));
            $initialSl = round($entryPrice - $slDist, 6);
            $tp1 = round($entryPrice + ($slDist * 1.5), 6); // 1:1.5 R:R
            $tp2 = round($entryPrice + ($slDist * 3.0), 6); // 1:3.0 R:R
        } else {
            // Structural SL: Highest high of the last 10 candles plus 0.15% cushion, bounded by 1.0% to 2.2%
            $recentHighsSlice = array_slice($highs, max(0, $i - 10), 10);
            $structuralHigh = ! empty($recentHighsSlice) ? max($recentHighsSlice) : ($entryPrice + (1.5 * $valAtr));
            $rawSl = $structuralHigh * 1.0015;

            $slDist = max($entryPrice * 0.010, min($entryPrice * 0.022, $rawSl - $entryPrice));
            $initialSl = round($entryPrice + $slDist, 6);
            $tp1 = round($entryPrice - ($slDist * 1.5), 6); // 1:1.5 R:R
            $tp2 = round($entryPrice - ($slDist * 3.0), 6); // 1:3.0 R:R
        }

        $grade = $score >= 88 ? 'A+' : ($score >= 84 ? 'A' : 'B');

        return [
            'symbol' => $symbol,
            'direction' => $direction,
            'score' => $score,
            'grade' => $grade,
            'price' => $entryPrice,
            'initial_sl' => $initialSl,
            'tp1' => $tp1,
            'tp2' => $tp2,
            'indicators' => [
                'rsi' => round($valRsi, 2),
                'adx' => round($valAdx, 2),
                'volume_ratio' => $volRatio,
                'atr' => round($valAtr, 4),
                'ema9' => round($valEma9, 4),
                'ema21' => round($valEma21, 4),
                'ema200' => $valEma200 ? round($valEma200, 4) : null,
                'htf1_aligned' => $direction === 'LONG' ? $htf1Bullish : $htf1Bearish,
            ],
        ];
    }
}
