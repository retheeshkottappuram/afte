<?php

namespace App\Services\Strategy;

use App\Services\Crypto\Indicators;

/**
 * S/R reversal signals: a swing trade where price sweeps a support / resistance zone and turns with a reversal
 * candle pattern, in line with the higher-timeframe trend (user's request, 2026-10-21). On closed candle k:
 *
 *  1. Zones: swing pivots (3 candles each side) of the last 300 candles, clustered within zone_atr ATR; 2+ touches.
 *     Higher-timeframe pivots count double. A zone below the close is support, above is resistance.
 *  2. Trend (higher timeframe): UP = close > EMA50 > EMA200 and SuperTrend up, DOWN mirrored, else RANGE.
 *  3. Setup: candle k or k-1 sweeps the zone (wick beyond it by at most sweep_atr ATR, close back inside) and a
 *     reversal pattern prints: hammer / shooting star, engulfing, or morning / evening star.
 *  4. Confirmation: RSI divergence with the previous swing, volume >= volume_ratio x average, or the candle after the
 *     sweep closing beyond the sweep candle. At least min_confirmations.
 *  5. With the trend (or in a range) = BUY / SELL; against it only with an RSI divergence = BUY? / SELL?.
 *  6. Stop beyond the sweep wick + 0.2 ATR; target = the next opposite zone; reward/risk must be >= min_rr.
 *
 * Nothing here can make every trade a winner; the walk-forward test (bt_sr.php) decides whether the bot may trade it.
 */
class ReversalEngine
{
    protected const PIVOT = 3;

    protected ExitPlan $exitPlan;

    public function __construct(?ExitPlan $exitPlan = null)
    {
        $this->exitPlan = $exitPlan ?? ExitPlan::fromConfig();
    }

    /**
     * @param  array<string, array<int, float|int>>  $c  Closed candles, oldest first
     * @param  array<string, array<int, float|int>>|null  $htf  Closed higher-timeframe candles, or null (trend = RANGE)
     * @return array<int, array<string, mixed>> Swing-signal keys plus pattern, zone, touches, trend, rr, source = 'sr'
     */
    public function signals(array $c, ?array $htf = null, int $from = 0, bool $includeUnconfirmed = false): array
    {
        $n = count($c['closes'] ?? []);
        if ($n < 60) {
            return [];
        }

        $cfg = (array) config('trading.swing_sar.sr', []) + [
            'zone_atr' => 0.5, 'min_touches' => 2, 'sweep_atr' => 1.0, 'volume_ratio' => 1.3,
            'min_confirmations' => 1, 'min_rr' => 2.0, 'counter_trend' => true, 'lookback' => 300,
        ];
        $s = [
            'atr' => Indicators::atr($c['highs'], $c['lows'], $c['closes'], 14),
            'rsi' => Indicators::rsi($c['closes'], 14),
            'vol_sma' => Indicators::sma($c['volumes'], 20),
        ];
        $pivots = $this->pivots($c['highs'], $c['lows']);
        $points = $this->points($pivots, $htf);
        $trend = $this->trendMap($c['closeTimes'], $htf);

        $out = [];
        $last = [1 => -100, -1 => -100];
        for ($k = max(50, $from); $k < $n; $k++) {
            $atr = (float) ($s['atr'][$k] ?? 0);
            if ($atr <= 0 || $s['rsi'][$k] === null) {
                continue;
            }
            $zones = null;
            foreach ([1, -1] as $d) {
                if ($k - $last[$d] < 5) {
                    continue; // one signal per side per 5 candles
                }
                if ($this->pattern($k, $d, $c) === null && $this->pattern($k - 1, $d, $c) === null) {
                    continue; // no reversal candle: skip the zone work
                }
                $zones ??= $this->zones($k, $c, $points, $atr, $cfg);
                $signal = $this->setupAt($k, $d, $c, $s, $pivots, $zones, $trend[$k], $atr, $cfg);
                if ($signal === null || (! $signal['confirmed'] && ! $includeUnconfirmed)) {
                    continue;
                }
                $last[$d] = $k;
                $out[] = $signal;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function setupAt(int $k, int $d, array $c, array $s, array $pivots, array $zones, string $trend, float $atr, array $cfg): ?array
    {
        $close = (float) $c['closes'][$k];

        // Sweep of a zone on candle k or k-1: the wick goes through it, the close comes back.
        $sweep = null;
        foreach ([$k, $k - 1] as $j) {
            $wick = $d > 0 ? (float) $c['lows'][$j] : (float) $c['highs'][$j];
            $jClose = (float) $c['closes'][$j];
            foreach ($zones as $z) {
                $edge = $d > 0 ? $z['lo'] : $z['hi'];
                $beyond = ($edge - $wick) * $d;
                if ($beyond > 0 && $beyond <= (float) $cfg['sweep_atr'] * $atr && ($jClose - $edge) * $d > 0
                    && ($z['price'] - $close) * $d < 1.5 * $atr) {
                    $sweep = ['j' => $j, 'zone' => $z, 'wick' => $wick];
                    break 2;
                }
            }
        }
        if ($sweep === null) {
            return null;
        }

        // Reversal pattern on this candle, or on the sweep candle just before it.
        $pattern = $this->pattern($k, $d, $c) ?? ($sweep['j'] === $k - 1 ? $this->pattern($k - 1, $d, $c) : null);
        if ($pattern === null) {
            return null;
        }

        // Confirmations
        $j = $sweep['j'];
        $divergence = $this->divergence($j, $d, $c, $s['rsi'], $pivots);
        $volSma = (float) ($s['vol_sma'][$j - 1] ?? 0);
        $volume = $volSma > 0 && max((float) $c['volumes'][$j], (float) $c['volumes'][$k]) >= (float) $cfg['volume_ratio'] * $volSma;
        $follow = $j === $k - 1 && ($close - ($d > 0 ? (float) $c['highs'][$j] : (float) $c['lows'][$j])) * $d > 0;
        $confirmations = array_keys(array_filter(['RSI divergence' => $divergence, 'volume' => $volume, 'follow-through' => $follow]));
        if (count($confirmations) < (int) $cfg['min_confirmations']) {
            return null;
        }

        $withTrend = $trend === 'RANGE' || $trend === ($d > 0 ? 'UP' : 'DOWN');
        if (! $withTrend && (! $cfg['counter_trend'] || ! $divergence)) {
            return null;
        }

        // Risk: stop past the sweep wick, target the next opposite zone.
        $extreme = $d > 0 ? min($sweep['wick'], (float) $c['lows'][$k]) : max($sweep['wick'], (float) $c['highs'][$k]);
        $sl = $extreme - $d * 0.2 * $atr;
        $risk = ($close - $sl) * $d;
        $slPct = $risk / $close * 100;
        if ($risk <= 0 || $slPct < 0.3 || $slPct > 3.0) {
            return null;
        }
        $target = null;
        foreach ($zones as $z) {
            $level = $d > 0 ? $z['lo'] : $z['hi'];
            if (($level - $close) * $d > 0.5 * $atr && ($target === null || ($level - $target) * $d < 0)) {
                $target = $level;
            }
        }
        if ($target === null) {
            return null;
        }
        $rr = ($target - $close) * $d / $risk;
        if ($rr < (float) $cfg['min_rr']) {
            return null;
        }

        $side = $d > 0 ? 'LONG' : 'SHORT';
        $targets = $this->exitPlan->targets($side, $close, $sl);
        $z = $sweep['zone'];
        $volSmaK = (float) ($s['vol_sma'][$k - 1] ?? 0);

        return [
            'side' => $side, 'entry' => $close, 'sl' => $sl, 'tp1' => $targets['tp1'], 'tp2' => $target, 'tp3' => $target + $d * $risk,
            'atr' => $atr,
            'reason' => sprintf('%s after a sweep of %s %s (%d touches), %s, %s trend, RR %.1f',
                $pattern, $d > 0 ? 'support' : 'resistance', $this->fmt($z['price']), $z['touches'],
                $confirmations === [] ? 'no extra confirmation' : implode(' + ', $confirmations), $trend, $rr),
            'rsi' => round((float) $s['rsi'][$k], 1),
            'volume_ratio' => $volSmaK > 0 ? round((float) $c['volumes'][$k] / $volSmaK, 2) : 0.0,
            'time' => intdiv((int) $c['closeTimes'][$k], 1000),
            'confirmed' => $withTrend,
            'sl_pct' => round($slPct, 2),
            'pattern' => $pattern, 'zone' => $z['price'], 'touches' => $z['touches'], 'trend' => $trend, 'rr' => round($rr, 2),
            'source' => 'sr',
        ];
    }

    /**
     * Reversal candle pattern ending on candle k in direction d, or null.
     */
    protected function pattern(int $k, int $d, array $c): ?string
    {
        $o = (float) $c['opens'][$k];
        $cl = (float) $c['closes'][$k];
        $h = (float) $c['highs'][$k];
        $l = (float) $c['lows'][$k];
        $range = max($h - $l, 1e-12);
        $body = abs($cl - $o);

        // Hammer (long) / shooting star (short): long rejection wick, small opposite wick, close in the trade's half.
        $rejection = $d > 0 ? min($o, $cl) - $l : $h - max($o, $cl);
        $other = $d > 0 ? $h - max($o, $cl) : min($o, $cl) - $l;
        if ($rejection >= 2 * max($body, 0.1 * $range) && $other <= 0.35 * $range && ($d > 0 ? $cl - $l : $h - $cl) >= 0.5 * $range) {
            return $d > 0 ? 'Hammer' : 'Shooting star';
        }

        // Engulfing: this candle's body covers the previous opposite candle's body.
        $po = (float) $c['opens'][$k - 1];
        $pc = (float) $c['closes'][$k - 1];
        if (($cl - $o) * $d > 0 && ($pc - $po) * $d < 0 && ($cl - $po) * $d >= 0 && ($o - $pc) * $d <= 0 && $body > abs($pc - $po)) {
            return $d > 0 ? 'Bullish engulfing' : 'Bearish engulfing';
        }

        // Morning / evening star: big opposite candle, small middle candle, then a close past the first one's midpoint.
        $ao = (float) $c['opens'][$k - 2];
        $ac = (float) $c['closes'][$k - 2];
        $aRange = max((float) $c['highs'][$k - 2] - (float) $c['lows'][$k - 2], 1e-12);
        $mRange = max((float) $c['highs'][$k - 1] - (float) $c['lows'][$k - 1], 1e-12);
        if (($ac - $ao) * $d < 0 && abs($ac - $ao) >= 0.5 * $aRange && abs($pc - $po) <= 0.35 * $mRange
            && ($cl - $o) * $d > 0 && ($cl - ($ao + $ac) / 2) * $d > 0) {
            return $d > 0 ? 'Morning star' : 'Evening star';
        }

        return null;
    }

    /**
     * Regular RSI divergence at the sweep candle j: a lower low (long) with a higher RSI than the previous swing low.
     */
    protected function divergence(int $j, int $d, array $c, array $rsi, array $pivots): bool
    {
        $list = $d > 0 ? $pivots['lows'] : $pivots['highs'];
        $price = $d > 0 ? (float) $c['lows'][$j] : (float) $c['highs'][$j];
        foreach (array_reverse($list, true) as $at => [$known, $level]) {
            if ($known > $j || $at >= $j - 3) {
                continue;
            }
            if ($at < $j - 60) {
                break;
            }
            if ($rsi[$at] === null || $rsi[$j] === null) {
                return false;
            }

            return ($level - $price) * $d > 0 && ((float) $rsi[$j] - (float) $rsi[$at]) * $d > 0;
        }

        return false;
    }

    /**
     * Swing pivots: [index => [known at index, price]] for highs and lows (strictly beyond the left side, at least
     * equal to the right).
     *
     * @return array{highs: array<int, array{0: int, 1: float}>, lows: array<int, array{0: int, 1: float}>}
     */
    protected function pivots(array $highs, array $lows): array
    {
        $n = count($highs);
        $p = self::PIVOT;
        $out = ['highs' => [], 'lows' => []];
        for ($j = $p; $j < $n - $p; $j++) {
            $hi = $lo = true;
            for ($i = $j - $p; $i <= $j + $p && ($hi || $lo); $i++) {
                if ($i === $j) {
                    continue;
                }
                $hi = $hi && ($i < $j ? $highs[$j] > $highs[$i] : $highs[$j] >= $highs[$i]);
                $lo = $lo && ($i < $j ? $lows[$j] < $lows[$i] : $lows[$j] <= $lows[$i]);
            }
            if ($hi) {
                $out['highs'][$j] = [$j + $p, (float) $highs[$j]];
            }
            if ($lo) {
                $out['lows'][$j] = [$j + $p, (float) $lows[$j]];
            }
        }

        return $out;
    }

    /**
     * The support / resistance zones at the last closed candle (for the chart).
     *
     * @return array<int, array{price: float, lo: float, hi: float, touches: int}>
     */
    public function zonesAt(array $c, ?array $htf = null): array
    {
        $n = count($c['closes'] ?? []);
        if ($n < 60) {
            return [];
        }
        $atr = Indicators::atr($c['highs'], $c['lows'], $c['closes'], 14)[$n - 1] ?? null;
        if ($atr === null) {
            return [];
        }
        $cfg = (array) config('trading.swing_sar.sr', []) + ['zone_atr' => 0.5, 'min_touches' => 2, 'lookback' => 300];

        return $this->zones($n - 1, $c, $this->points($this->pivots($c['highs'], $c['lows']), $htf), (float) $atr, $cfg);
    }

    /**
     * Pivot prices as one list sorted by the time they became known: [known time ms, start time ms, price, weight].
     * Higher-timeframe pivots weigh 2.
     *
     * @return array<int, array{0: int, 1: int, 2: float, 3: int}>
     */
    protected function points(array $pivots, ?array $htf): array
    {
        $points = [];
        foreach (['highs', 'lows'] as $kind) {
            foreach ($pivots[$kind] as $at => [$known, $price]) {
                $points[] = [$known, $at, $price, 1, 'base'];
            }
        }
        if ($htf !== null && count($htf['closes'] ?? []) > 10) {
            foreach ($this->pivots($htf['highs'], $htf['lows']) as $kind => $list) {
                foreach ($list as $at => [$known, $price]) {
                    $points[] = [(int) $htf['closeTimes'][$known], (int) $htf['closeTimes'][$at], $price, 2, 'htf'];
                }
            }
        }

        return $points;
    }

    /**
     * Support / resistance zones known at candle k: pivots of the last `lookback` candles (base pivots by index,
     * higher-timeframe ones by time) clustered within zone_atr ATR, keeping zones with at least min_touches.
     *
     * @return array<int, array{price: float, lo: float, hi: float, touches: int}>
     */
    protected function zones(int $k, array $c, array $points, float $atr, array $cfg): array
    {
        $from = max(0, $k - (int) $cfg['lookback']);
        $time = (int) $c['closeTimes'][$k];
        $start = (int) $c['closeTimes'][$from];
        $in = [];
        foreach ($points as [$known, $at, $price, $weight, $kind]) {
            $ok = $kind === 'base' ? $known <= $k && $at >= $from : $known <= $time && $at >= $start;
            if ($ok) {
                $in[] = [$price, $weight];
            }
        }
        if ($in === []) {
            return [];
        }

        usort($in, fn ($a, $b) => $a[0] <=> $b[0]);
        $tol = (float) $cfg['zone_atr'] * $atr;
        $zones = [];
        $cluster = [$in[0]];
        $flush = function (array $cluster) use (&$zones, $cfg): void {
            $touches = array_sum(array_column($cluster, 1));
            if ($touches >= (int) $cfg['min_touches']) {
                $prices = array_column($cluster, 0);
                $zones[] = ['price' => array_sum($prices) / count($prices), 'lo' => min($prices), 'hi' => max($prices), 'touches' => $touches];
            }
        };
        $count = count($in);
        for ($i = 1; $i < $count; $i++) {
            if ($in[$i][0] - $cluster[count($cluster) - 1][0] <= $tol) {
                $cluster[] = $in[$i];
            } else {
                $flush($cluster);
                $cluster = [$in[$i]];
            }
        }
        $flush($cluster);

        return $zones;
    }

    /**
     * Higher-timeframe trend per base candle: UP, DOWN or RANGE (RANGE when there is no higher timeframe).
     *
     * @return array<int, string>
     */
    protected function trendMap(array $closeTimes, ?array $htf): array
    {
        $out = array_fill(0, count($closeTimes), 'RANGE');
        if ($htf === null || count($htf['closes'] ?? []) < 60) {
            return $out;
        }
        $ema50 = Indicators::ema($htf['closes'], 50);
        $ema200 = Indicators::ema($htf['closes'], 200);
        $st = Indicators::supertrend($htf['highs'], $htf['lows'], $htf['closes'], 10, 3.0);
        $m = count($htf['closes']);
        $j = -1;
        foreach ($closeTimes as $k => $t) {
            while ($j + 1 < $m && (int) $htf['closeTimes'][$j + 1] <= (int) $t) {
                $j++;
            }
            if ($j < 0 || $ema50[$j] === null) {
                continue;
            }
            $close = (float) $htf['closes'][$j];
            $long = $ema200[$j] ?? $ema50[$j];
            $dir = $st['dir'][$j] ?? 0;
            if ($close > $ema50[$j] && $ema50[$j] >= $long && $dir === 1) {
                $out[$k] = 'UP';
            } elseif ($close < $ema50[$j] && $ema50[$j] <= $long && $dir === -1) {
                $out[$k] = 'DOWN';
            }
        }

        return $out;
    }

    protected function fmt(float $price): string
    {
        return $price >= 1 ? rtrim(rtrim(number_format($price, 4, '.', ''), '0'), '.') : (string) round($price, 6);
    }
}
