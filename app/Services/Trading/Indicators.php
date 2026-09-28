<?php

namespace App\Services\Trading;

class Indicators
{
    /**
     * Simple Moving Average (SMA).
     *
     * @param  array<int, float|null>  $data
     * @return array<int, float|null>
     */
    public static function sma(array $data, int $length): array
    {
        $count = count($data);
        $result = array_fill(0, $count, null);

        if ($length <= 0 || $count < $length) {
            return $result;
        }

        $sum = 0.0;
        for ($i = 0; $i < $length; $i++) {
            if ($data[$i] === null) {
                return $result;
            }
            $sum += (float) $data[$i];
        }

        $result[$length - 1] = $sum / $length;

        for ($i = $length; $i < $count; $i++) {
            if ($data[$i] === null) {
                $result[$i] = null;

                continue;
            }
            $sum += (float) $data[$i] - (float) $data[$i - $length];
            $result[$i] = $sum / $length;
        }

        return $result;
    }

    /**
     * Exponential Moving Average (EMA).
     *
     * @param  array<int, float|null>  $data
     * @return array<int, float|null>
     */
    public static function ema(array $data, int $length): array
    {
        $count = count($data);
        $result = array_fill(0, $count, null);

        if ($length <= 0 || $count < $length) {
            return $result;
        }

        $alpha = 2.0 / ($length + 1.0);

        // Seed with SMA
        $sum = 0.0;
        for ($i = 0; $i < $length; $i++) {
            if ($data[$i] === null) {
                return $result;
            }
            $sum += (float) $data[$i];
        }

        $prevEma = $sum / $length;
        $result[$length - 1] = $prevEma;

        for ($i = $length; $i < $count; $i++) {
            if ($data[$i] === null) {
                $result[$i] = null;

                continue;
            }
            $currentVal = (float) $data[$i];
            $prevEma = ($alpha * $currentVal) + ((1.0 - $alpha) * $prevEma);
            $result[$i] = $prevEma;
        }

        return $result;
    }

    /**
     * Relative Moving Average (RMA / Wilder's Smoothing).
     *
     * @param  array<int, float|null>  $data
     * @return array<int, float|null>
     */
    public static function rma(array $data, int $length): array
    {
        $count = count($data);
        $result = array_fill(0, $count, null);

        if ($length <= 0 || $count < $length) {
            return $result;
        }

        $alpha = 1.0 / $length;

        // Seed with SMA
        $sum = 0.0;
        for ($i = 0; $i < $length; $i++) {
            if ($data[$i] === null) {
                return $result;
            }
            $sum += (float) $data[$i];
        }

        $prevRma = $sum / $length;
        $result[$length - 1] = $prevRma;

        for ($i = $length; $i < $count; $i++) {
            if ($data[$i] === null) {
                $result[$i] = null;

                continue;
            }
            $currentVal = (float) $data[$i];
            $prevRma = ($alpha * $currentVal) + ((1.0 - $alpha) * $prevRma);
            $result[$i] = $prevRma;
        }

        return $result;
    }

    /**
     * True Range (TR).
     *
     * @param  array<int, float>  $highs
     * @param  array<int, float>  $lows
     * @param  array<int, float>  $closes
     * @return array<int, float|null>
     */
    public static function trueRange(array $highs, array $lows, array $closes): array
    {
        $count = count($highs);
        $tr = array_fill(0, $count, null);

        if ($count === 0) {
            return $tr;
        }

        $tr[0] = $highs[0] - $lows[0];

        for ($i = 1; $i < $count; $i++) {
            $prevClose = $closes[$i - 1];
            $tr1 = $highs[$i] - $lows[$i];
            $tr2 = abs($highs[$i] - $prevClose);
            $tr3 = abs($lows[$i] - $prevClose);
            $tr[$i] = max($tr1, $tr2, $tr3);
        }

        return $tr;
    }

    /**
     * Average True Range (ATR).
     *
     * @param  array<int, float>  $highs
     * @param  array<int, float>  $lows
     * @param  array<int, float>  $closes
     * @return array<int, float|null>
     */
    public static function atr(array $highs, array $lows, array $closes, int $length = 14): array
    {
        $tr = self::trueRange($highs, $lows, $closes);

        return self::rma($tr, $length);
    }

    /**
     * Relative Strength Index (RSI).
     *
     * @param  array<int, float>  $closes
     * @return array<int, float|null>
     */
    public static function rsi(array $closes, int $length = 14): array
    {
        $count = count($closes);
        $result = array_fill(0, $count, null);

        if ($length <= 0 || $count <= $length) {
            return $result;
        }

        $gains = array_fill(0, $count, 0.0);
        $losses = array_fill(0, $count, 0.0);

        for ($i = 1; $i < $count; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            if ($change > 0) {
                $gains[$i] = $change;
            } else {
                $losses[$i] = abs($change);
            }
        }

        $avgGain = self::rma($gains, $length);
        $avgLoss = self::rma($losses, $length);

        for ($i = $length; $i < $count; $i++) {
            if ($avgGain[$i] === null || $avgLoss[$i] === null) {
                continue;
            }

            if ($avgLoss[$i] == 0.0) {
                $result[$i] = 100.0;
            } elseif ($avgGain[$i] == 0.0) {
                $result[$i] = 0.0;
            } else {
                $rs = $avgGain[$i] / $avgLoss[$i];
                $result[$i] = 100.0 - (100.0 / (1.0 + $rs));
            }
        }

        return $result;
    }

    /**
     * Bollinger Bands (Upper, Middle, Lower, Bandwidth).
     *
     * @param  array<int, float>  $closes
     * @return array{
     *     upper: array<int, float|null>,
     *     middle: array<int, float|null>,
     *     lower: array<int, float|null>,
     *     bandwidth: array<int, float|null>
     * }
     */
    public static function bollingerBands(array $closes, int $length = 20, float $multiplier = 2.0): array
    {
        $count = count($closes);
        $middle = self::sma($closes, $length);
        $upper = array_fill(0, $count, null);
        $lower = array_fill(0, $count, null);
        $bandwidth = array_fill(0, $count, null);

        for ($i = $length - 1; $i < $count; $i++) {
            if ($middle[$i] === null) {
                continue;
            }

            $sumSqDiff = 0.0;
            for ($j = $i - $length + 1; $j <= $i; $j++) {
                $diff = $closes[$j] - $middle[$i];
                $sumSqDiff += $diff * $diff;
            }
            $stdDev = sqrt($sumSqDiff / $length);

            $upper[$i] = $middle[$i] + ($multiplier * $stdDev);
            $lower[$i] = $middle[$i] - ($multiplier * $stdDev);
            if ($middle[$i] != 0.0) {
                $bandwidth[$i] = (($upper[$i] - $lower[$i]) / $middle[$i]) * 100.0;
            }
        }

        return [
            'upper' => $upper,
            'middle' => $middle,
            'lower' => $lower,
            'bandwidth' => $bandwidth,
        ];
    }

    /**
     * Average Directional Index (ADX) & Directional Movement Index (DMI).
     *
     * @param  array<int, float>  $highs
     * @param  array<int, float>  $lows
     * @param  array<int, float>  $closes
     * @return array{
     *     adx: array<int, float|null>,
     *     plusDi: array<int, float|null>,
     *     minusDi: array<int, float|null>
     * }
     */
    public static function adx(array $highs, array $lows, array $closes, int $length = 14): array
    {
        $count = count($highs);
        $adx = array_fill(0, $count, null);
        $plusDi = array_fill(0, $count, null);
        $minusDi = array_fill(0, $count, null);

        if ($count <= $length * 2) {
            return ['adx' => $adx, 'plusDi' => $plusDi, 'minusDi' => $minusDi];
        }

        $tr = self::trueRange($highs, $lows, $closes);
        $plusDm = array_fill(0, $count, 0.0);
        $minusDm = array_fill(0, $count, 0.0);

        for ($i = 1; $i < $count; $i++) {
            $upMove = $highs[$i] - $highs[$i - 1];
            $downMove = $lows[$i - 1] - $lows[$i];

            if ($upMove > $downMove && $upMove > 0) {
                $plusDm[$i] = $upMove;
            }
            if ($downMove > $upMove && $downMove > 0) {
                $minusDm[$i] = $downMove;
            }
        }

        $smoothTr = self::rma($tr, $length);
        $smoothPlusDm = self::rma($plusDm, $length);
        $smoothMinusDm = self::rma($minusDm, $length);

        $dx = array_fill(0, $count, null);

        for ($i = $length - 1; $i < $count; $i++) {
            if ($smoothTr[$i] === null || $smoothTr[$i] == 0.0) {
                continue;
            }

            $pDi = ($smoothPlusDm[$i] / $smoothTr[$i]) * 100.0;
            $mDi = ($smoothMinusDm[$i] / $smoothTr[$i]) * 100.0;

            $plusDi[$i] = $pDi;
            $minusDi[$i] = $mDi;

            $sumDi = $pDi + $mDi;
            if ($sumDi > 0) {
                $dx[$i] = (abs($pDi - $mDi) / $sumDi) * 100.0;
            } else {
                $dx[$i] = 0.0;
            }
        }

        $adxStart = ($length * 2) - 2;

        if ($count > $adxStart) {
            $dxSum = 0.0;
            for ($i = $length - 1; $i <= $adxStart; $i++) {
                $dxSum += (float) ($dx[$i] ?? 0.0);
            }
            $prevAdx = $dxSum / $length;
            $adx[$adxStart] = $prevAdx;

            for ($i = $adxStart + 1; $i < $count; $i++) {
                $currentAdx = (($prevAdx * ($length - 1)) + (float) ($dx[$i] ?? 0.0)) / $length;
                $adx[$i] = $currentAdx;
                $prevAdx = $currentAdx;
            }
        }

        return [
            'adx' => $adx,
            'plusDi' => $plusDi,
            'minusDi' => $minusDi,
        ];
    }

    /**
     * Keltner Channels.
     *
     * @param  array<int, float>  $highs
     * @param  array<int, float>  $lows
     * @param  array<int, float>  $closes
     * @return array{
     *     upper: array<int, float|null>,
     *     middle: array<int, float|null>,
     *     lower: array<int, float|null>
     * }
     */
    public static function keltnerChannels(array $highs, array $lows, array $closes, int $length = 20, float $multiplier = 2.0): array
    {
        $count = count($closes);
        $middle = self::ema($closes, $length);
        $atr = self::atr($highs, $lows, $closes, 14);

        $upper = array_fill(0, $count, null);
        $lower = array_fill(0, $count, null);

        for ($i = 0; $i < $count; $i++) {
            if ($middle[$i] !== null && $atr[$i] !== null) {
                $upper[$i] = $middle[$i] + ($multiplier * $atr[$i]);
                $lower[$i] = $middle[$i] - ($multiplier * $atr[$i]);
            }
        }

        return [
            'upper' => $upper,
            'middle' => $middle,
            'lower' => $lower,
        ];
    }

    /**
     * TTM Squeeze Detection (Bollinger Bands inside Keltner Channel).
     *
     * @param  array<int, float>  $highs
     * @param  array<int, float>  $lows
     * @param  array<int, float>  $closes
     * @return array{
     *     squeeze_on: array<int, bool>,
     *     fired_bullish: array<int, bool>,
     *     fired_bearish: array<int, bool>,
     *     momentum: array<int, float|null>
     * }
     */
    public static function ttmSqueeze(array $highs, array $lows, array $closes, int $length = 20): array
    {
        $count = count($closes);
        $bb = self::bollingerBands($closes, $length, 2.0);
        $kc = self::keltnerChannels($highs, $lows, $closes, $length, 2.0);

        $squeezeOn = array_fill(0, $count, false);
        $firedBull = array_fill(0, $count, false);
        $firedBear = array_fill(0, $count, false);
        $momentum = array_fill(0, $count, null);

        for ($i = $length; $i < $count; $i++) {
            $bbUp = $bb['upper'][$i] ?? null;
            $bbLow = $bb['lower'][$i] ?? null;
            $kcUp = $kc['upper'][$i] ?? null;
            $kcLow = $kc['lower'][$i] ?? null;

            if ($bbUp !== null && $bbLow !== null && $kcUp !== null && $kcLow !== null) {
                // Squeeze is ON when BB is inside KC
                $isSqueeze = ($bbUp <= $kcUp) && ($bbLow >= $kcLow);
                $squeezeOn[$i] = $isSqueeze;

                // Squeeze firing: previous bar was in squeeze, current bar broke out
                $prevSqueeze = $squeezeOn[$i - 1] ?? false;
                if ($prevSqueeze && ! $isSqueeze) {
                    if ($closes[$i] > ($kc['middle'][$i] ?? $closes[$i])) {
                        $firedBull[$i] = true;
                    } else {
                        $firedBear[$i] = true;
                    }
                }
            }
        }

        return [
            'squeeze_on' => $squeezeOn,
            'fired_bullish' => $firedBull,
            'fired_bearish' => $firedBear,
            'momentum' => $momentum,
        ];
    }

    /**
     * Compute Relative Strength ratio vs benchmark (e.g. BTC).
     *
     * @param  array<int, float>  $coinCloses
     * @param  array<int, float>  $benchCloses
     */
    public static function relativeStrength(array $coinCloses, array $benchCloses, int $period = 24): float
    {
        $cCount = count($coinCloses);
        $bCount = count($benchCloses);

        if ($cCount <= $period || $bCount <= $period) {
            return 1.0;
        }

        $cCur = $coinCloses[$cCount - 2];
        $cPast = $coinCloses[$cCount - 2 - $period];

        $bCur = $benchCloses[$bCount - 2];
        $bPast = $benchCloses[$bCount - 2 - $period];

        if ($cPast <= 0 || $bPast <= 0 || $bCur <= 0) {
            return 1.0;
        }

        $coinPerf = $cCur / $cPast;
        $benchPerf = $bCur / $bPast;

        return $benchPerf > 0 ? round($coinPerf / $benchPerf, 4) : 1.0;
    }
}
