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

        // Volatility Squeeze / Expansion Detection
        $bbExpanding = false;
        if (isset($bb['upper'][$i], $bb['lower'][$i], $bb['upper'][$i - 2], $bb['lower'][$i - 2])) {
            $prevWidth = $bb['upper'][$i - 2] - $bb['lower'][$i - 2];
            $curWidth = $bb['upper'][$i] - $bb['lower'][$i];
            $bbExpanding = $curWidth > $prevWidth * 1.05;
        }

        // ==========================================
        // 5. Test LONG (BUY) Breakout Setup
        // ==========================================
        $longScore = 0;
        $isCleanBreakoutLong = ($currentClose > $swingHigh && $isBullCandle);
        $isRetestLong = ($closes[$i - 1] > $swingHigh && $currentLow <= $swingHigh * 1.004 && $currentClose >= $swingHigh && $currentClose >= $currentOpen);

        if ($isCleanBreakoutLong) {
            $longScore += 30; // Verified resistance breakout with solid body close
        } elseif ($isRetestLong) {
            $longScore += 28; // Breakout retest & support confirmation
        } elseif ($currentClose > $valEma9 && $closes[$i - 1] <= $valEma9 && $isBullCandle) {
            $longScore += 18; // Clean momentum cross
        }

        if ($valEma9 > $valEma21) {
            $longScore += 15;
        }
        if ($valEma200 && $currentClose > $valEma200) {
            $longScore += 10;
        }
        if ($volRatio >= 1.40) {
            $longScore += 20; // High institutional volume expansion
        } elseif ($volRatio >= 1.15) {
            $longScore += 12;
        }
        if ($valRsi >= 52.0 && $valRsi <= 72.0) {
            $longScore += 15; // RSI momentum sweet-spot
        }
        if ($htf1Bullish) {
            $longScore += 15; // Higher timeframe trend alignment
        }
        if ($bbExpanding) {
            $longScore += 8;  // Volatility expansion out of squeeze
        }

        // ==========================================
        // 6. Test SHORT (SELL) Breakdown Setup
        // ==========================================
        $shortScore = 0;
        $isCleanBreakdownShort = ($currentClose < $swingLow && $isBearCandle);
        $isRetestShort = ($closes[$i - 1] < $swingLow && $currentHigh >= $swingLow * 0.996 && $currentClose <= $swingLow && $currentClose <= $currentOpen);

        if ($isCleanBreakdownShort) {
            $shortScore += 30; // Verified support breakdown with solid body close
        } elseif ($isRetestShort) {
            $shortScore += 28; // Breakdown retest & resistance confirmation
        } elseif ($currentClose < $valEma9 && $closes[$i - 1] >= $valEma9 && $isBearCandle) {
            $shortScore += 18; // Clean downward momentum cross
        }

        if ($valEma9 < $valEma21) {
            $shortScore += 15;
        }
        if ($valEma200 && $currentClose < $valEma200) {
            $shortScore += 10;
        }
        if ($volRatio >= 1.40) {
            $shortScore += 20; // High institutional volume expansion
        } elseif ($volRatio >= 1.15) {
            $shortScore += 12;
        }
        if ($valRsi <= 48.0 && $valRsi >= 28.0) {
            $shortScore += 15; // RSI downward momentum
        }
        if ($htf1Bearish) {
            $shortScore += 15; // Higher timeframe trend alignment
        }
        if ($bbExpanding) {
            $shortScore += 8;  // Volatility expansion out of squeeze
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

        // Calculate Dynamic SL & TP Targets
        $entryPrice = $currentClose;
        if ($direction === 'LONG') {
            // Correct SL: Structural swing LOW (minimum of recent lows) or ATR buffer
            $recentLowsSlice = array_slice($lows, max(0, $i - 10), 10);
            $structuralSl = ! empty($recentLowsSlice) ? min($recentLowsSlice) : ($entryPrice - (1.5 * $valAtr));
            $atrSl = $entryPrice - (1.5 * $valAtr);
            $initialSl = min($structuralSl, $atrSl);

            // Safety bounds: SL distance between 0.8% and 2.5%
            $slDist = $entryPrice - $initialSl;
            $minDist = $entryPrice * 0.008;
            $maxDist = $entryPrice * 0.025;
            $slDist = max($minDist, min($maxDist, $slDist));

            $initialSl = round($entryPrice - $slDist, 6);
            $tp1 = round($entryPrice + ($slDist * 1.5), 6); // R:R 1:1.5
            $tp2 = round($entryPrice + ($slDist * 3.0), 6); // R:R 1:3.0
        } else {
            // Correct SHORT SL: Structural swing HIGH (maximum of recent highs) or ATR buffer
            $recentHighsSlice = array_slice($highs, max(0, $i - 10), 10);
            $structuralSl = ! empty($recentHighsSlice) ? max($recentHighsSlice) : ($entryPrice + (1.5 * $valAtr));
            $atrSl = $entryPrice + (1.5 * $valAtr);
            $initialSl = max($structuralSl, $atrSl);

            $slDist = $initialSl - $entryPrice;
            $minDist = $entryPrice * 0.008;
            $maxDist = $entryPrice * 0.025;
            $slDist = max($minDist, min($maxDist, $slDist));

            $initialSl = round($entryPrice + $slDist, 6);
            $tp1 = round($entryPrice - ($slDist * 1.5), 6);
            $tp2 = round($entryPrice - ($slDist * 3.0), 6);
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
