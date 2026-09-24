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
        ?array $microCandles = null
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

        // 3. Structural Resistance and Support Discovery
        $structureLen = 30;
        $lookbackHighs = array_slice($highs, max(0, $i - $structureLen), $structureLen);
        $lookbackLows = array_slice($lows, max(0, $i - $structureLen), $structureLen);
        $resistance = max($lookbackHighs);
        $support = min($lookbackLows);

        // Calculate distance percentages to breakout levels
        $distToResPct = $resistance > 0 ? round((($resistance - $currentClose) / $resistance) * 100, 2) : 999.0;
        $distToSuppPct = $support > 0 ? round((($currentClose - $support) / $support) * 100, 2) : 999.0;

        // Candle geometry
        $candleRange = $currentHigh - $currentLow;
        $body = abs($currentClose - $currentOpen);
        $bodyRatio = $candleRange > 0 ? $body / $candleRange : 0.0;
        $isBullCandle = ($currentClose > $currentOpen) && ($bodyRatio >= 0.45);
        $isBearCandle = ($currentClose < $currentOpen) && ($bodyRatio >= 0.45);

        // Volatility Squeeze Check (Bollinger Band Compression)
        $bbCompression = false;
        if (isset($bbWidth[$i]) && $bbWidth[$i] !== null) {
            $recentBbWidths = array_slice(array_filter($bbWidth), -25);
            if (! empty($recentBbWidths)) {
                $minWidth = min($recentBbWidths);
                $bbCompression = ($bbWidth[$i] <= $minWidth * 1.25);
            }
        }

        // ==========================================
        // SCENARIO 1: CONFIRMED RESISTANCE BREAKOUT
        // ==========================================
        $confirmedLong = (
            $currentClose > $resistance &&
            $isBullCandle &&
            $volRatio >= 1.15 &&
            $curRsi >= 52.0 &&
            $curRsi <= 76.0 &&
            $htf1Bull &&
            ($curEma200 === null || $currentClose > $curEma200)
        );

        if ($confirmedLong) {
            $entry = $currentClose;
            $sl = max($resistance - ($curAtr * 0.45), $currentLow - ($curAtr * 0.20));
            $risk = $entry - $sl;

            if ($risk > 0) {
                $tp1 = round($entry + max($curAtr * 1.6, $risk * 2.0), 4);
                $tp2 = round($entry + max($curAtr * 3.2, $risk * 3.5), 4);
                $tp3 = round($entry + max($curAtr * 5.0, $risk * 5.0), 4);

                $score = 82;
                $score += $volRatio >= 1.5 ? 8 : 4;
                $score += ($curRsi > $prevRsi) ? 4 : 0;
                $score += $bbCompression ? 6 : 0;
                $score = min(98, $score);
                $grade = $score >= 90 ? 'A' : 'B';

                return $this->formatBreakoutPayload(
                    type: 'BREAKOUT_CONFIRMED',
                    side: 'BUY',
                    score: $score,
                    grade: $grade,
                    breakoutLevel: $resistance,
                    distancePct: 0.0,
                    entry: $entry,
                    sl: $sl,
                    tp1: $tp1,
                    tp2: $tp2,
                    tp3: $tp3,
                    volRatio: $volRatio,
                    rsi: $curRsi,
                    adx: $curAdx,
                    atrPct: $atrPct,
                    setupLabel: 'RESISTANCE BREAKOUT CONFIRMED',
                    closeTimeMs: $closeTimeMs
                );
            }
        }

        // ==========================================
        // SCENARIO 2: CONFIRMED SUPPORT BREAKDOWN
        // ==========================================
        $confirmedShort = (
            $currentClose < $support &&
            $isBearCandle &&
            $volRatio >= 1.15 &&
            $curRsi <= 48.0 &&
            $curRsi >= 24.0 &&
            $htf1Bear &&
            ($curEma200 === null || $currentClose < $curEma200)
        );

        if ($confirmedShort) {
            $entry = $currentClose;
            $sl = min($support + ($curAtr * 0.45), $currentHigh + ($curAtr * 0.20));
            $risk = $sl - $entry;

            if ($risk > 0) {
                $tp1 = round($entry - max($curAtr * 1.6, $risk * 2.0), 4);
                $tp2 = round($entry - max($curAtr * 3.2, $risk * 3.5), 4);
                $tp3 = round($entry - max($curAtr * 5.0, $risk * 5.0), 4);

                $score = 82;
                $score += $volRatio >= 1.5 ? 8 : 4;
                $score += ($curRsi < $prevRsi) ? 4 : 0;
                $score += $bbCompression ? 6 : 0;
                $score = min(98, $score);
                $grade = $score >= 90 ? 'A' : 'B';

                return $this->formatBreakoutPayload(
                    type: 'BREAKOUT_CONFIRMED',
                    side: 'SELL',
                    score: $score,
                    grade: $grade,
                    breakoutLevel: $support,
                    distancePct: 0.0,
                    entry: $entry,
                    sl: $sl,
                    tp1: $tp1,
                    tp2: $tp2,
                    tp3: $tp3,
                    volRatio: $volRatio,
                    rsi: $curRsi,
                    adx: $curAdx,
                    atrPct: $atrPct,
                    setupLabel: 'SUPPORT BREAKDOWN CONFIRMED',
                    closeTimeMs: $closeTimeMs
                );
            }
        }

        // ==========================================
        // SCENARIO 3: BREAKOUT RETEST ENTRY
        // ==========================================
        // Price previously broke above resistance and current bar pulled back to retest resistance as support
        if ($i >= 2 && $closes[$i - 1] > $resistance && $currentLow <= ($resistance * 1.004) && $currentClose >= $resistance && $htf1Bull) {
            $entry = $currentClose;
            $sl = $resistance - ($curAtr * 0.50);
            $risk = $entry - $sl;

            if ($risk > 0) {
                $tp1 = round($entry + ($curAtr * 1.8), 4);
                $tp2 = round($entry + ($curAtr * 3.5), 4);
                $tp3 = round($entry + ($curAtr * 5.5), 4);

                return $this->formatBreakoutPayload(
                    type: 'RETEST_ENTRY',
                    side: 'BUY',
                    score: 86,
                    grade: 'B',
                    breakoutLevel: $resistance,
                    distancePct: 0.0,
                    entry: $entry,
                    sl: $sl,
                    tp1: $tp1,
                    tp2: $tp2,
                    tp3: $tp3,
                    volRatio: $volRatio,
                    rsi: $curRsi,
                    adx: $curAdx,
                    atrPct: $atrPct,
                    setupLabel: 'BREAKOUT RETEST & BOUNCE',
                    closeTimeMs: $closeTimeMs
                );
            }
        }

        // ==========================================
        // SCENARIO 4: PRE-BREAKOUT WATCH (WATCHLIST ALERT)
        // ==========================================
        // Price is approaching resistance within 0.20% - 1.20% with rising volume and bullish momentum
        if ($distToResPct >= 0.15 && $distToResPct <= 1.20 && $volRatio >= 0.95 && $curRsi >= 53.0 && $curRsi <= 68.0 && $htf1Bull) {
            $probScore = 75;
            $probScore += ($volRatio >= 1.25) ? 10 : 5;
            $probScore += ($curRsi > $prevRsi) ? 5 : 0;
            $probScore += ($curEma9 !== null && $curEma21 !== null && $curEma9 > $curEma21) ? 5 : 0;
            $probScore += $bbCompression ? 5 : 0;
            $probScore = min(95, $probScore);

            $entryEst = $resistance * 1.0015;
            $slEst = $resistance - ($curAtr * 0.60);
            $riskEst = $entryEst - $slEst;

            return $this->formatBreakoutPayload(
                type: 'BREAKOUT_WATCH',
                side: 'BUY',
                score: $probScore,
                grade: $probScore >= 85 ? 'A' : 'B',
                breakoutLevel: $resistance,
                distancePct: $distToResPct,
                entry: round($entryEst, 4),
                sl: round($slEst, 4),
                tp1: round($entryEst + ($curAtr * 1.8), 4),
                tp2: round($entryEst + ($curAtr * 3.5), 4),
                tp3: round($entryEst + ($curAtr * 5.5), 4),
                volRatio: $volRatio,
                rsi: $curRsi,
                adx: $curAdx,
                atrPct: $atrPct,
                setupLabel: 'RESISTANCE BREAKOUT WATCH',
                closeTimeMs: $closeTimeMs
            );
        }

        return null;
    }

    /**
     * Format breakout setup into standard typed array with perpetual trade options.
     */
    protected function formatBreakoutPayload(
        string $type,
        string $side,
        int $score,
        string $grade,
        float $breakoutLevel,
        float $distancePct,
        float $entry,
        float $sl,
        float $tp1,
        float $tp2,
        float $tp3,
        float $volRatio,
        float $rsi,
        float $adx,
        float $atrPct,
        string $setupLabel,
        int $closeTimeMs
    ): array {
        $slPct = $entry > 0 ? round(abs($entry - $sl) / $entry * 100, 2) : 1.5;
        $tp1Pct = $entry > 0 ? round(abs($tp1 - $entry) / $entry * 100, 2) : 2.5;
        $tp2Pct = $entry > 0 ? round(abs($tp2 - $entry) / $entry * 100, 2) : 4.5;
        $tp3Pct = $entry > 0 ? round(abs($tp3 - $entry) / $entry * 100, 2) : 7.0;
        $rrRatio = $slPct > 0 ? '1 : '.round($tp2Pct / $slPct, 1) : '1 : 2.5';

        $recLeverage = $atrPct > 3.0 ? '3x - 5x' : ($atrPct < 1.0 ? '8x - 12x' : '5x - 10x');
        $levMult = $atrPct > 3.0 ? 3 : ($atrPct < 1.0 ? 10 : 5);

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
