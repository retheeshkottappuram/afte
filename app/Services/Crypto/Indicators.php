<?php

namespace App\Services\Crypto;

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
     * Exponential Moving Average (EMA) matching Pine Script's ta.ema().
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
            $val = (float) $data[$i];
            $currentEma = ($alpha * $val) + ((1.0 - $alpha) * $prevEma);
            $result[$i] = $currentEma;
            $prevEma = $currentEma;
        }

        return $result;
    }

    /**
     * Wilder's Running Moving Average (RMA) matching Pine Script's ta.rma().
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
            $val = (float) $data[$i];
            $currentRma = ($alpha * $val) + ((1.0 - $alpha) * $prevRma);
            $result[$i] = $currentRma;
            $prevRma = $currentRma;
        }

        return $result;
    }

    /**
     * Relative Strength Index (RSI) matching Pine Script's ta.rsi().
     * Uses Wilder's smoothing (RMA).
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
            $diff = (float) $closes[$i] - (float) $closes[$i - 1];
            if ($diff > 0) {
                $gains[$i] = $diff;
            } else {
                $losses[$i] = abs($diff);
            }
        }

        // First average gain/loss at index $length (SMA of indices 1..$length)
        $sumGain = 0.0;
        $sumLoss = 0.0;
        for ($i = 1; $i <= $length; $i++) {
            $sumGain += $gains[$i];
            $sumLoss += $losses[$i];
        }

        $avgGain = $sumGain / $length;
        $avgLoss = $sumLoss / $length;

        $result[$length] = self::calculateRsiValue($avgGain, $avgLoss);

        for ($i = $length + 1; $i < $count; $i++) {
            $avgGain = (($avgGain * ($length - 1)) + $gains[$i]) / $length;
            $avgLoss = (($avgLoss * ($length - 1)) + $losses[$i]) / $length;

            $result[$i] = self::calculateRsiValue($avgGain, $avgLoss);
        }

        return $result;
    }

    /**
     * Average True Range (ATR) matching Pine Script's ta.atr().
     * Uses Wilder's smoothing (RMA) on True Range.
     *
     * @param  array<int, float>  $highs
     * @param  array<int, float>  $lows
     * @param  array<int, float>  $closes
     * @return array<int, float|null>
     */
    public static function atr(array $highs, array $lows, array $closes, int $length = 14): array
    {
        $count = count($closes);
        $result = array_fill(0, $count, null);

        if ($length <= 0 || $count < $length) {
            return $result;
        }

        $tr = array_fill(0, $count, 0.0);
        $tr[0] = (float) $highs[0] - (float) $lows[0];

        for ($i = 1; $i < $count; $i++) {
            $hl = (float) $highs[$i] - (float) $lows[$i];
            $hc = abs((float) $highs[$i] - (float) $closes[$i - 1]);
            $lc = abs((float) $lows[$i] - (float) $closes[$i - 1]);
            $tr[$i] = max($hl, $hc, $lc);
        }

        return self::rma($tr, $length);
    }

    /**
     * Directional Movement Index (DMI/ADX) matching Pine Script's ta.dmi().
     *
     * @param  array<int, float>  $highs
     * @param  array<int, float>  $lows
     * @param  array<int, float>  $closes
     * @return array{plus_di: array<int, float|null>, minus_di: array<int, float|null>, adx: array<int, float|null>}
     */
    public static function dmi(array $highs, array $lows, array $closes, int $diLength = 14, int $adxLength = 14): array
    {
        $count = count($closes);
        $nullArray = array_fill(0, $count, null);

        $defaultReturn = [
            'plus_di' => $nullArray,
            'minus_di' => $nullArray,
            'adx' => $nullArray,
            0 => $nullArray,
            1 => $nullArray,
            2 => $nullArray,
        ];

        if ($diLength <= 0 || $adxLength <= 0 || $count < ($diLength + $adxLength)) {
            return $defaultReturn;
        }

        $tr = array_fill(0, $count, 0.0);
        $plusDm = array_fill(0, $count, 0.0);
        $minusDm = array_fill(0, $count, 0.0);

        $tr[0] = (float) $highs[0] - (float) $lows[0];

        for ($i = 1; $i < $count; $i++) {
            $upMove = (float) $highs[$i] - (float) $highs[$i - 1];
            $downMove = (float) $lows[$i - 1] - (float) $lows[$i];

            $plusDm[$i] = ($upMove > $downMove && $upMove > 0) ? $upMove : 0.0;
            $minusDm[$i] = ($downMove > $upMove && $downMove > 0) ? $downMove : 0.0;

            $hl = (float) $highs[$i] - (float) $lows[$i];
            $hc = abs((float) $highs[$i] - (float) $closes[$i - 1]);
            $lc = abs((float) $lows[$i] - (float) $closes[$i - 1]);
            $tr[$i] = max($hl, $hc, $lc);
        }

        $smoothedTr = self::rma($tr, $diLength);
        $smoothedPlusDm = self::rma($plusDm, $diLength);
        $smoothedMinusDm = self::rma($minusDm, $diLength);

        $plusDi = array_fill(0, $count, null);
        $minusDi = array_fill(0, $count, null);
        $dx = array_fill(0, $count, null);

        for ($i = $diLength - 1; $i < $count; $i++) {
            $str = (float) $smoothedTr[$i];
            if ($str > 0) {
                $pDi = (100.0 * (float) $smoothedPlusDm[$i]) / $str;
                $mDi = (100.0 * (float) $smoothedMinusDm[$i]) / $str;
            } else {
                $pDi = 0.0;
                $mDi = 0.0;
            }

            $plusDi[$i] = $pDi;
            $minusDi[$i] = $mDi;

            $diSum = $pDi + $mDi;
            $diDiff = abs($pDi - $mDi);

            $dx[$i] = ($diSum > 0) ? (100.0 * $diDiff / $diSum) : 0.0;
        }

        // ADX is RMA of DX with adxLength
        // Valid DX starts at index $diLength - 1.
        // First valid ADX is at index ($diLength - 1) + ($adxLength - 1) = $diLength + $adxLength - 2.
        $adx = array_fill(0, $count, null);
        $adxStart = $diLength + $adxLength - 2;

        if ($count > $adxStart) {
            $dxSum = 0.0;
            for ($i = $diLength - 1; $i <= $adxStart; $i++) {
                $dxSum += (float) $dx[$i];
            }
            $prevAdx = $dxSum / $adxLength;
            $adx[$adxStart] = $prevAdx;

            for ($i = $adxStart + 1; $i < $count; $i++) {
                $currentAdx = (($prevAdx * ($adxLength - 1)) + (float) $dx[$i]) / $adxLength;
                $adx[$i] = $currentAdx;
                $prevAdx = $currentAdx;
            }
        }

        return [
            'plus_di' => $plusDi,
            'minus_di' => $minusDi,
            'adx' => $adx,
            0 => $plusDi,
            1 => $minusDi,
            2 => $adx,
        ];
    }

    /**
     * Average Directional Index (ADX) with +DI and -DI.
     *
     * @param  array<int, float>  $highs
     * @param  array<int, float>  $lows
     * @param  array<int, float>  $closes
     * @return array{0: array<int, float|null>, 1: array<int, float|null>, 2: array<int, float|null>, adx: array<int, float|null>, plus_di: array<int, float|null>, minus_di: array<int, float|null>}
     */
    public static function adx(array $highs, array $lows, array $closes, int $length = 14): array
    {
        $dmi = self::dmi($highs, $lows, $closes, $length, $length);

        return [
            0 => $dmi['adx'],
            1 => $dmi['plus_di'],
            2 => $dmi['minus_di'],
            'adx' => $dmi['adx'],
            'plus_di' => $dmi['plus_di'],
            'minus_di' => $dmi['minus_di'],
        ];
    }

    /**
     * Bollinger Bands: Upper, Middle (SMA), Lower, and Width Percent.
     *
     * @param  array<int, float>  $closes
     * @return array{0: array<int, float|null>, 1: array<int, float|null>, 2: array<int, float|null>, 3: array<int, float|null>, upper: array<int, float|null>, middle: array<int, float|null>, lower: array<int, float|null>, width: array<int, float|null>}
     */
    public static function bollingerBands(array $closes, int $period = 20, float $mult = 2.0): array
    {
        $n = count($closes);
        $upper = array_fill(0, $n, null);
        $lower = array_fill(0, $n, null);
        $width = array_fill(0, $n, null);
        $mid = self::sma($closes, $period);
        $sd = self::stddev($closes, $period);

        for ($i = 0; $i < $n; $i++) {
            if ($mid[$i] === null || $sd[$i] === null) {
                continue;
            }
            $upper[$i] = $mid[$i] + ($mult * $sd[$i]);
            $lower[$i] = $mid[$i] - ($mult * $sd[$i]);
            $width[$i] = ($mid[$i] != 0.0) ? (($upper[$i] - $lower[$i]) / $mid[$i]) * 100.0 : 0.0;
        }

        return [
            0 => $upper,
            1 => $mid,
            2 => $lower,
            3 => $width,
            'upper' => $upper,
            'middle' => $mid,
            'lower' => $lower,
            'width' => $width,
        ];
    }

    /**
     * Rolling sample standard deviation.
     *
     * @param  array<int, float>  $values
     * @return array<int, float|null>
     */
    public static function stddev(array $values, int $period): array
    {
        $n = count($values);
        $out = array_fill(0, $n, null);

        if ($period <= 0 || $n < $period) {
            return $out;
        }

        for ($i = $period - 1; $i < $n; $i++) {
            $slice = array_slice($values, $i - $period + 1, $period);
            $mean = array_sum($slice) / $period;
            $variance = 0.0;
            foreach ($slice as $v) {
                $variance += ($v - $mean) ** 2;
            }
            $out[$i] = sqrt($variance / $period);
        }

        return $out;
    }

    /**
     * Bollinger Bands % Width: ((Upper - Lower) / Mid) * 100.
     *
     * @param  array<int, float>  $closes
     * @return array<int, float|null>
     */
    public static function bbWidthPercent(array $closes, int $period = 20, float $mult = 2.0): array
    {
        $n = count($closes);
        $out = array_fill(0, $n, null);
        $mid = self::sma($closes, $period);
        $sd = self::stddev($closes, $period);

        for ($i = 0; $i < $n; $i++) {
            if ($mid[$i] === null || $sd[$i] === null || $mid[$i] == 0.0) {
                continue;
            }
            $upper = $mid[$i] + ($mult * $sd[$i]);
            $lower = $mid[$i] - ($mult * $sd[$i]);
            $out[$i] = ($upper - $lower) / $mid[$i] * 100.0;
        }

        return $out;
    }

    /**
     * On-Balance Volume (OBV).
     *
     * @param  array<int, float>  $closes
     * @param  array<int, float>  $volumes
     * @return array<int, float>
     */
    public static function obv(array $closes, array $volumes): array
    {
        $n = count($closes);
        $out = array_fill(0, $n, 0.0);

        if ($n === 0) {
            return $out;
        }

        $out[0] = (float) ($volumes[0] ?? 0.0);

        for ($i = 1; $i < $n; $i++) {
            $c = (float) $closes[$i];
            $prev = (float) $closes[$i - 1];
            $v = (float) ($volumes[$i] ?? 0.0);

            if ($c > $prev) {
                $out[$i] = $out[$i - 1] + $v;
            } elseif ($c < $prev) {
                $out[$i] = $out[$i - 1] - $v;
            } else {
                $out[$i] = $out[$i - 1];
            }
        }

        return $out;
    }

    private static function calculateRsiValue(float $avgGain, float $avgLoss): float
    {
        if ($avgLoss <= 0.0) {
            return ($avgGain <= 0.0) ? 50.0 : 100.0;
        }

        $rs = $avgGain / $avgLoss;

        return 100.0 - (100.0 / (1.0 + $rs));
    }
}
