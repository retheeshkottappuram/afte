<?php

namespace App\Services\Breakout;

use App\Services\Crypto\Indicators;

/**
 * EBD indicator series. Every value at index i uses candles 0..i only (causal), and the
 * baselines (volume mean, bandwidth percentile) use the candles BEFORE i, never i itself.
 * Missing warm-up stays null; it is never replaced with 0.
 */
class IndicatorCalculator
{
    public function __construct(protected EbdConfig $config) {}

    /**
     * @return array{atr: array<int, ?float>, natr: array<int, ?float>, ema_fast: array<int, ?float>, ema_slow: array<int, ?float>,
     *     rsi: array<int, ?float>, bbw: array<int, ?float>, bbw_pct: array<int, ?float>, vavg: array<int, ?float>, rvol: array<int, ?float>}
     */
    public function series(Candles $c): array
    {
        $atr = Indicators::atr($c->highs, $c->lows, $c->closes, $this->config->int('indicators.atr_period'));
        $bbw = $this->bandwidth($c->closes, $this->config->int('indicators.bb_period'), $this->config->float('indicators.bb_mult'));
        $vavg = self::priorMean($c->volumes, $this->config->int('indicators.volume_baseline'));

        $natr = [];
        $rvol = [];
        foreach ($c->closes as $i => $close) {
            $natr[$i] = $atr[$i] !== null && $close > 0 ? $atr[$i] / $close : null;
            $rvol[$i] = ($vavg[$i] ?? 0) > 0 ? $c->volumes[$i] / $vavg[$i] : null;
        }

        return [
            'atr' => $atr,
            'natr' => $natr,
            'ema_fast' => Indicators::ema($c->closes, $this->config->int('indicators.ema_fast')),
            'ema_slow' => Indicators::ema($c->closes, $this->config->int('indicators.ema_slow')),
            'rsi' => Indicators::rsi($c->closes, $this->config->int('indicators.rsi_period')),
            'bbw' => $bbw,
            'bbw_pct' => self::priorPercentile($bbw, $this->config->int('compression.bbw_lookback')),
            'vavg' => $vavg,
            'rvol' => $rvol,
        ];
    }

    /**
     * Bollinger bandwidth (Upper - Lower) / MA20 as a fraction (4 sigma / MA for 2-sigma bands).
     *
     * @param  array<int, float>  $closes
     * @return array<int, ?float>
     */
    public function bandwidth(array $closes, int $period, float $mult): array
    {
        $bands = Indicators::bollingerBands($closes, $period, $mult);
        $out = [];
        foreach ($closes as $i => $_) {
            $mid = $bands['middle'][$i];
            $out[$i] = $mid !== null && $mid > 0 && $bands['upper'][$i] !== null
                ? ($bands['upper'][$i] - $bands['lower'][$i]) / $mid
                : null;
        }

        return $out;
    }

    /**
     * Mean of the $n values before i (excludes i): VAvg_t = (1/n) * sum V_{t-1..t-n}.
     *
     * @param  array<int, float>  $values
     * @return array<int, ?float>
     */
    public static function priorMean(array $values, int $n): array
    {
        $out = [];
        $sum = 0.0;
        foreach ($values as $i => $v) {
            $out[$i] = $i >= $n ? $sum / $n : null;
            $sum += $v;
            if ($i >= $n) {
                $sum -= $values[$i - $n];
            }
        }

        return $out;
    }

    /**
     * Percentile rank (0-100) of value i among the $lookback values before it. 0 = lowest ever seen.
     * Null until $lookback non-null prior values exist.
     *
     * @param  array<int, ?float>  $values
     * @return array<int, ?float>
     */
    public static function priorPercentile(array $values, int $lookback): array
    {
        $out = [];
        foreach ($values as $i => $v) {
            $out[$i] = null;
            if ($v === null || $i < $lookback) {
                continue;
            }
            $below = 0;
            $valid = true;
            for ($j = $i - $lookback; $j < $i; $j++) {
                if ($values[$j] === null) {
                    $valid = false;
                    break;
                }
                if ($values[$j] < $v) {
                    $below++;
                }
            }
            $out[$i] = $valid ? (float) $below / $lookback * 100 : null;
        }

        return $out;
    }

    /** Close location in the trade direction: (C-L)/(H-L) for longs, (H-C)/(H-L) for shorts. Null on a zero-range candle. */
    public static function closeLocation(float $high, float $low, float $close, string $direction): ?float
    {
        $range = $high - $low;
        if ($range <= 0) {
            return null;
        }

        return $direction === 'LONG' ? ($close - $low) / $range : ($high - $close) / $range;
    }

    public static function bodyAtr(float $open, float $close, ?float $atr): ?float
    {
        return $atr !== null && $atr > 0 ? abs($close - $open) / $atr : null;
    }

    /**
     * Time-normalised volume pace of a forming candle: volume of its closed 1m candles divided by the
     * median volume of the same first k minutes of the previous comparable candles.
     * Null when no 1m candle of the bucket has closed yet or the baseline is too thin / zero
     * (the volume condition is then unavailable, not satisfied).
     *
     * @return array{pace: float, minutes: int, samples: int}|null
     */
    public static function volumePace(Candles $m1, int $barOpenMs, int $barMs, int $nowMs, int $maxSamples = 20, int $minSamples = 10): ?array
    {
        $minuteMs = 60_000;
        $perBar = intdiv($barMs, $minuteMs);
        $byOpen = [];
        foreach ($m1->openTimes as $i => $t) {
            if ($m1->closeTimes[$i] <= $nowMs) {
                $byOpen[$t] = $m1->volumes[$i];
            }
        }

        $k = 0;
        $current = 0.0;
        for ($m = 0; $m < $perBar; $m++) {
            $t = $barOpenMs + $m * $minuteMs;
            if (! array_key_exists($t, $byOpen)) {
                break;
            }
            $current += $byOpen[$t];
            $k++;
        }
        if ($k === 0) {
            return null;
        }

        $history = [];
        for ($b = 1; $b <= $maxSamples; $b++) {
            $start = $barOpenMs - $b * $barMs;
            $sum = 0.0;
            for ($m = 0; $m < $k; $m++) {
                $t = $start + $m * $minuteMs;
                if (! array_key_exists($t, $byOpen)) {
                    continue 2;
                }
                $sum += $byOpen[$t];
            }
            $history[] = $sum;
        }
        if (count($history) < $minSamples) {
            return null;
        }
        sort($history);
        $mid = intdiv(count($history), 2);
        $median = count($history) % 2 ? $history[$mid] : ($history[$mid - 1] + $history[$mid]) / 2;

        return $median > 0 ? ['pace' => $current / $median, 'minutes' => $k, 'samples' => count($history)] : null;
    }
}
