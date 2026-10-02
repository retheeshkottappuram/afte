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
        ?array $btcCandles = null,
        ?int $referenceTimeMs = null
    ): ?array {
        // Enforce STRICT closed candle evaluation - zero forming candle leakage
        $cleanBase = CandleSanitizer::onlyClosedCandles($baseCandles, $referenceTimeMs, true);
        $closes = $cleanBase['closes'] ?? [];
        $highs = $cleanBase['highs'] ?? [];
        $lows = $cleanBase['lows'] ?? [];
        $opens = $cleanBase['opens'] ?? [];
        $volumes = $cleanBase['volumes'] ?? [];
        $closeTimes = $cleanBase['closeTimes'] ?? [];
        $count = count($closes);

        if ($count < 55) {
            return null;
        }

        // Index of the latest closed candle
        $i = $count - 1;
        $currentClose = $closes[$i];
        $currentHigh = $highs[$i];
        $currentLow = $lows[$i];
        $currentOpen = $opens[$i];
        $currentVol = $volumes[$i];
        $closeTimeMs = $closeTimes[$i] ?? (int) (microtime(true) * 1000);

        // 1. Indicators calculation strictly on CLOSED base timeframe candles
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

        // 2. Higher Timeframe Trend Alignment on CLOSED candles (> 1 Hour Strategy Confluence)
        $htf1Bull = true;
        $htf1Bear = true;
        $htfSummary = '1H Neutral';
        if ($htf1Candles && ! empty($htf1Candles['closes'])) {
            $cleanHtf1 = CandleSanitizer::onlyClosedCandles($htf1Candles, $referenceTimeMs, true);
            $htfCloses = $cleanHtf1['closes'];
            $htfIdx = count($htfCloses) - 1;
            if ($htfIdx >= 20) {
                $htfEma9 = Indicators::ema($htfCloses, 9);
                $htfEma21 = Indicators::ema($htfCloses, 21);
                $htfEma50 = Indicators::ema($htfCloses, min(50, $htfIdx));
                $htfRsi = Indicators::rsi($htfCloses, 14);
                $htfClose = $htfCloses[$htfIdx];
                $h9 = $htfEma9[$htfIdx] ?? $htfClose;
                $h21 = $htfEma21[$htfIdx] ?? $htfClose;
                $h50 = $htfEma50[$htfIdx] ?? $htfClose;
                $hRsi = (float) ($htfRsi[$htfIdx] ?? 50.0);

                // STRICT: 1H strategy confluence - no longing against downtrend, no shorting against expansion
                $htf1Bull = ($htfClose >= $h21 * 0.996) && ($h9 >= $h21 * 0.998 || $htfClose >= $h50 * 0.996) && ($hRsi >= 42.0);
                $htf1Bear = ($htfClose <= $h21 * 1.004) && ($h9 <= $h21 * 1.002 || $htfClose <= $h50 * 1.004) && ($hRsi <= 58.0);
                $htfSummary = $htf1Bull ? '1H Bullish Expansion' : ($htf1Bear ? '1H Bearish Trend' : '1H Range');
            }
        }

        // 3. Relative Strength vs Bitcoin on CLOSED candles
        $rsRatio = 1.0;
        if ($btcCandles && ! empty($btcCandles['closes'])) {
            $cleanBtc = CandleSanitizer::onlyClosedCandles($btcCandles, $referenceTimeMs, true);
            $rsRatio = Indicators::relativeStrength($closes, $cleanBtc['closes'], 24, true);
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
                rsRatio: $rsRatio,
                support: $support,
                resistance: $resistance
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
                rsRatio: $rsRatio,
                support: $support,
                resistance: $resistance
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
                rsRatio: $rsRatio,
                support: $support,
                resistance: $resistance
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
                rsRatio: $rsRatio,
                support: $support,
                resistance: $resistance
            );
        }

        // ==========================================
        // SCENARIO 5A: MASSIVE 1-DAY BREAKOUT INCEPTION (High Volume + Squeeze Expansion)
        // ==========================================
        $massiveLong = (
            $currentClose > $resistance &&
            $isBullCandle &&
            $volRatio >= 1.60 &&
            ($isCompressed || $curAdx >= 22.0) &&
            $curRsi >= 54.0 &&
            $curRsi <= 76.0 &&
            $rsRatio >= 0.995 &&
            $htf1Bull &&
            ($curEma200 === null || $currentClose > $curEma200)
        );

        if ($massiveLong) {
            $entry = $currentClose;
            $rawSl = max($resistance * 0.992, $currentLow * 0.995);

            return $this->formatBreakoutPayload(
                type: 'MASSIVE_BREAKOUT_INCEPTION',
                side: 'BUY',
                score: 98,
                grade: 'A+',
                breakoutLevel: $resistance,
                distancePct: 0.0,
                entry: $entry,
                rawSl: $rawSl,
                volRatio: $volRatio,
                rsi: $curRsi,
                adx: $curAdx,
                atrPct: $atrPct,
                setupLabel: '🚀 MASSIVE 1-DAY BREAKOUT INCEPTION',
                closeTimeMs: $closeTimeMs,
                rsRatio: $rsRatio,
                support: $support,
                resistance: $resistance
            );
        }

        // ==========================================
        // SCENARIO 5B: MASSIVE 1-DAY BREAKDOWN INCEPTION (High Volume + Squeeze Expansion)
        // ==========================================
        $massiveShort = (
            $currentClose < $support &&
            $isBearCandle &&
            $volRatio >= 1.60 &&
            ($isCompressed || $curAdx >= 22.0) &&
            $curRsi <= 46.0 &&
            $curRsi >= 24.0 &&
            $rsRatio <= 1.005 &&
            $htf1Bear &&
            ($curEma200 === null || $currentClose < $curEma200)
        );

        if ($massiveShort) {
            $entry = $currentClose;
            $rawSl = min($support * 1.008, $currentHigh * 1.005);

            return $this->formatBreakoutPayload(
                type: 'MASSIVE_BREAKDOWN_INCEPTION',
                side: 'SELL',
                score: 98,
                grade: 'A+',
                breakoutLevel: $support,
                distancePct: 0.0,
                entry: $entry,
                rawSl: $rawSl,
                volRatio: $volRatio,
                rsi: $curRsi,
                adx: $curAdx,
                atrPct: $atrPct,
                setupLabel: '📉 MASSIVE 1-DAY BREAKDOWN INCEPTION',
                closeTimeMs: $closeTimeMs,
                rsRatio: $rsRatio,
                support: $support,
                resistance: $resistance
            );
        }

        // ==========================================
        // SCENARIO 5C: CONFIRMED RESISTANCE BREAKOUT
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
                rsRatio: $rsRatio,
                support: $support,
                resistance: $resistance
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
                rsRatio: $rsRatio,
                support: $support,
                resistance: $resistance
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
                rsRatio: $rsRatio,
                support: $support,
                resistance: $resistance
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
                rsRatio: $rsRatio,
                support: $support,
                resistance: $resistance
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
        float $rsRatio = 1.0,
        float $support = 0.0,
        float $resistance = 0.0
    ): array {
        $supportLevel = $support > 0 ? $support : ($side === 'BUY' ? $entry * 0.985 : $entry * 0.975);
        $resistanceLevel = $resistance > 0 ? $resistance : ($side === 'BUY' ? $entry * 1.025 : $entry * 1.015);

        // Classify Trade Horizon: Day Trade vs Swing Trade
        $isSwingSetup = in_array($type, ['MASSIVE_BREAKOUT_INCEPTION', 'MASSIVE_BREAKDOWN_INCEPTION', 'WYCKOFF_SPRING', 'WYCKOFF_UPTHRUST'], true);
        $tradeType = $isSwingSetup ? 'SWING TRADE' : 'DAY TRADE';
        $tradeHorizon = $isSwingSetup ? '1 – 2 Days (Multi-Day)' : 'Intraday (4h – 12h)';
        $usefulLeverage = $isSwingSetup ? '3x – 7x' : '5x – 10x';

        // Support & Resistance based SL & TP Calculation
        if ($side === 'BUY') {
            // SL placed just below structural support
            $targetSl = min($rawSl, $supportLevel * 0.997);
            $rawRisk = abs($entry - $targetSl);
            $risk = max($entry * 0.008, min($entry * 0.035, $rawRisk));
            $sl = round($entry - $risk, 6);

            // TP1 near immediate resistance or at least 1.5R
            $tp1Dist = max($risk * 1.5, abs($resistanceLevel - $entry));
            if ($entry < $resistanceLevel) {
                $tp1 = round(min($resistanceLevel, $entry + $tp1Dist), 6);
            } else {
                $tp1 = round($entry + max($risk * 1.6, $entry * 0.025), 6);
            }

            // TP2: Structural expansion target
            $tp2 = round($entry + max($risk * 3.0, ($tp1 - $entry) * 2.0), 6);
            $tp3 = round($entry + max($risk * 5.0, ($tp2 - $entry) * 1.8), 6);
        } else {
            // SL placed just above structural resistance
            $targetSl = max($rawSl, $resistanceLevel * 1.003);
            $rawRisk = abs($targetSl - $entry);
            $risk = max($entry * 0.008, min($entry * 0.035, $rawRisk));
            $sl = round($entry + $risk, 6);

            // TP1 near immediate support or at least 1.5R
            $tp1Dist = max($risk * 1.5, abs($entry - $supportLevel));
            if ($entry > $supportLevel) {
                $tp1 = round(max($supportLevel, $entry - $tp1Dist), 6);
            } else {
                $tp1 = round($entry - max($risk * 1.6, $entry * 0.025), 6);
            }

            // TP2: Structural breakdown target
            $tp2 = round($entry - max($risk * 3.0, ($entry - $tp1) * 2.0), 6);
            $tp3 = round($entry - max($risk * 5.0, ($entry - $tp2) * 1.8), 6);
        }

        $slPct = $entry > 0 ? round(($risk / $entry) * 100, 2) : 1.5;
        $tp1Pct = $entry > 0 ? round(abs($tp1 - $entry) / $entry * 100, 2) : 2.5;
        $tp2Pct = $entry > 0 ? round(abs($tp2 - $entry) / $entry * 100, 2) : 5.0;
        $tp3Pct = $entry > 0 ? round(abs($tp3 - $entry) / $entry * 100, 2) : 8.0;
        $rrRatio = $slPct > 0 ? '1 : '.round($tp2Pct / $slPct, 1) : '1 : 2.8';

        $recLeverage = $usefulLeverage;
        $levParts = explode('x', $usefulLeverage);
        $levMult = isset($levParts[0]) ? (int) trim($levParts[0]) : 5;

        $dollarSim = [
            'capital' => 100,
            'leverage' => $levMult,
            'position_size' => 100 * $levMult,
            'tp1_profit' => round(100 * ($tp1Pct / 100.0) * $levMult, 2),
            'tp1_roe_pct' => round($tp1Pct * $levMult, 1),
            'tp2_profit' => round(100 * ($tp2Pct / 100.0) * $levMult, 2),
            'tp2_roe_pct' => round($tp2Pct * $levMult, 1),
            'tp3_profit' => round(100 * ($tp3Pct / 100.0) * $levMult, 2),
            'tp3_roe_pct' => round($tp3Pct * $levMult, 1),
            'sl_loss' => round(100 * ($slPct / 100.0) * $levMult, 2),
            'sl_roe_pct' => round($slPct * $levMult, 1),
        ];

        // Detailed institutional market reasoning
        $dirText = $side === 'BUY' ? 'BULLISH' : 'BEARISH';
        $reasoning = [
            'market_structure' => "Identified {$dirText} {$setupLabel}. Immediate Resistance: \${$resistanceLevel}, Immediate Support: \${$supportLevel}.",
            'volume_ignition' => "Volume ratio is {$volRatio}x 20-period average, confirming institutional participation and early momentum expansion.",
            'trend_momentum' => "RSI is at {$rsi} with ADX at {$adx}, indicating strong trend inception without being overextended.",
            'relative_strength' => "Relative Strength ratio against BTC is {$rsRatio}, demonstrating sector leadership and independent price velocity.",
            'execution_strategy' => "Suggested for {$tradeType} ({$tradeHorizon}) using {$usefulLeverage} leverage. Stop Loss placed structurally beyond key levels at \${$sl} (-{$slPct}%), with TP1 at \${$tp1} (+{$tp1Pct}%) and TP2 at \${$tp2} (+{$tp2Pct}%).",
        ];

        return [
            'type' => $type,
            'side' => $side,
            'score' => $score,
            'grade' => $grade,
            'breakout_level' => round($breakoutLevel, 4),
            'support' => round($supportLevel, 6),
            'resistance' => round($resistanceLevel, 6),
            'distance_pct' => $distancePct,
            'entry' => round($entry, 6),
            'sl' => round($sl, 6),
            'tp1' => round($tp1, 6),
            'tp2' => round($tp2, 6),
            'tp3' => round($tp3, 6),
            'sl_pct' => $slPct,
            'tp1_pct' => $tp1Pct,
            'tp2_pct' => $tp2Pct,
            'tp3_pct' => $tp3Pct,
            'risk_reward' => $rrRatio,
            'trade_type' => $tradeType,
            'trade_horizon' => $tradeHorizon,
            'recommended_leverage' => $usefulLeverage,
            'volume_ratio' => $volRatio,
            'rsi' => round($rsi, 1),
            'adx' => round($adx, 1),
            'atr_pct' => $atrPct,
            'setup_label' => $setupLabel,
            'candle_close_time' => $closeTimeMs,
            'rs_ratio' => round($rsRatio, 4),
            'dollar_sim' => $dollarSim,
            'detailed_reasoning' => $reasoning,
            'perpetual_options' => [
                'recommended_leverage' => $usefulLeverage,
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
