<?php

namespace App\Services\Strategy;

use App\Services\Crypto\CandleSanitizer;
use App\Services\Crypto\Indicators;

/**
 * Builds the "SignalAlgo Pro" chart overlays: candles, EMA lines, EMA21-50 trend cloud,
 * ATR trend ribbon (SuperTrend style), swing support/resistance zones, the squeeze breakout box,
 * signal labels and their measured outcomes.
 */
class ChartOverlayBuilder
{
    public function __construct(protected TradeSimulator $simulator) {}

    /**
     * @param  array<string, array<int, float|int>>  $candles  Raw base candles (the last one may still be forming)
     * @param  array<int, Signal>  $signals
     * @return array<string, mixed>
     */
    public function build(array $candles, array $signals, string $interval, int $bars = 300): array
    {
        $count = count($candles['closes']);
        $start = max(0, $count - $bars);
        $time = fn (int $i): int => intdiv((int) $candles['closeTimes'][$i], 1000);

        $chartCandles = [];
        for ($i = $start; $i < $count; $i++) {
            $chartCandles[] = [
                'time' => $time($i),
                'open' => (float) $candles['opens'][$i],
                'high' => (float) $candles['highs'][$i],
                'low' => (float) $candles['lows'][$i],
                'close' => (float) $candles['closes'][$i],
                'volume' => (float) $candles['volumes'][$i],
            ];
        }

        $series = fn (array $values): array => $this->toSeries($values, $start, $count, $time);

        $closes = $candles['closes'];
        // Outcomes use closed candles only, so a label never changes while a candle is still forming.
        $outcomes = $this->outcomes($signals, CandleSanitizer::onlyClosedCandles($candles), $interval, $time($start));

        return [
            'candles' => $chartCandles,
            'ema9' => $series(Indicators::ema($closes, 9)),
            'ema21' => $series(Indicators::ema($closes, 21)),
            'ema50' => $series(Indicators::ema($closes, 50)),
            'ema200' => $series(Indicators::ema($closes, 200)),
            'trend_ribbon' => $this->trendRibbon($candles, $start, $time),
            'levels' => $this->swingLevels($candles, $start),
            'breakout_box' => $this->breakoutBox($candles),
            'markers' => $outcomes['markers'],
            'signal_history' => $outcomes['history'],
            'stats_strip' => $outcomes['strip'],
        ];
    }

    /**
     * Simulate every signal in view with the live exit plan and turn the results into chart markers.
     *
     * @param  array<int, Signal>  $signals
     * @param  array<string, array<int, float|int>>  $candles
     * @return array{markers: array<int, array<string, mixed>>, history: array<int, array<string, mixed>>, strip: array<string, mixed>}
     */
    protected function outcomes(array $signals, array $candles, string $interval, int $firstVisibleTime): array
    {
        $markers = [];
        $history = [];
        $closedR = [];
        $barSeconds = MarketScanService::barSeconds($interval);

        foreach ($signals as $signal) {
            if ($signal->time < $firstVisibleTime) {
                continue;
            }

            $result = $this->simulator->run([
                'side' => $signal->side,
                'entry' => $signal->entry,
                'sl' => $signal->stopLoss,
                'tp1' => $signal->tp1,
                'tp2' => $signal->tp2,
                'atr' => $signal->atr,
                'time' => $signal->time,
            ], $candles, $barSeconds);

            // Short, high-contrast labels; the page shows or hides each `kind` with toggles.
            $word = $signal->isLong() ? 'BUY' : 'SELL';
            $position = $signal->isLong() ? 'belowBar' : 'aboveBar';
            $isOpen = ! $result['closed'];

            if ($signal->isShadow) {
                $markers[] = ['time' => $signal->time, 'position' => $position, 'shape' => 'circle', 'color' => '#64748b', 'text' => '', 'size' => 1, 'kind' => 'shadow', 'signal_time' => $signal->time];
            } elseif (! $signal->isTradable()) {
                $markers[] = ['time' => $signal->time, 'position' => $position, 'shape' => $signal->isLong() ? 'arrowUp' : 'arrowDown', 'color' => '#94a3b8', 'text' => $word.' (filtered)', 'size' => 1, 'kind' => 'filtered', 'signal_time' => $signal->time];
            } else {
                $markers[] = [
                    'time' => $signal->time,
                    'position' => $position,
                    'shape' => $signal->isLong() ? 'arrowUp' : 'arrowDown',
                    'color' => $signal->isLong() ? '#22c55e' : '#f43f5e',
                    'text' => $isOpen ? "{$word} {$signal->grade} · OPEN" : "{$word} {$signal->grade}",
                    'size' => $isOpen ? 3 : 2,
                    'kind' => $isOpen ? 'active' : 'signal',
                    'signal_time' => $signal->time,
                ];
            }

            if ($result['closed'] && ! $signal->isShadow) {
                $markers[] = [
                    'time' => $this->alignToBar((int) $result['exit_time'], $candles),
                    'position' => $signal->isLong() ? 'aboveBar' : 'belowBar',
                    'shape' => 'circle',
                    'color' => $result['r_multiple'] > 0 ? '#22c55e' : ($result['r_multiple'] < -0.1 ? '#f43f5e' : '#94a3b8'),
                    'text' => sprintf('%+.1fR', $result['r_multiple']),
                    'size' => 1,
                    'kind' => 'result',
                    'signal_time' => $signal->time,
                ];

                if ($signal->isTradable()) {
                    $closedR[] = $result['r_multiple'];
                }
            }

            $history[] = array_merge($signal->toArray(), [
                'outcome' => $result['closed'] ? $result['outcome'] : 'OPEN',
                'r_multiple' => $result['closed'] ? $result['r_multiple'] : null,
                'unrealized_r' => $result['closed'] ? null : $result['r_multiple'],
                'exit_time' => $result['exit_time'],
            ]);
        }

        usort($markers, fn (array $a, array $b): int => $a['time'] <=> $b['time']);
        $summary = SetupStats::summarize($closedR);

        return [
            'markers' => $markers,
            'history' => $history,
            'strip' => [
                'signals' => count($history),
                'closed' => $summary['n'],
                'win_rate' => $summary['win_rate'],
                'avg_r' => $summary['expectancy'],
                'profit_factor' => $summary['profit_factor'],
            ],
        ];
    }

    /**
     * ATR trailing trend line (ATR 10 x 3), split into up / down segments for colouring.
     *
     * @param  array<string, array<int, float|int>>  $c
     * @return array<int, array{time: int, value: float, trend: string}>
     */
    protected function trendRibbon(array $c, int $start, callable $time): array
    {
        $atr = Indicators::atr($c['highs'], $c['lows'], $c['closes'], 10);
        $count = count($c['closes']);
        $out = [];
        $upper = null;
        $lower = null;
        $trend = 1;

        for ($i = 1; $i < $count; $i++) {
            if ($atr[$i] === null) {
                continue;
            }

            $mid = ($c['highs'][$i] + $c['lows'][$i]) / 2;
            $basicUpper = $mid + 3 * $atr[$i];
            $basicLower = $mid - 3 * $atr[$i];
            $prevClose = $c['closes'][$i - 1];

            $upper = ($upper === null || $basicUpper < $upper || $prevClose > $upper) ? $basicUpper : $upper;
            $lower = ($lower === null || $basicLower > $lower || $prevClose < $lower) ? $basicLower : $lower;

            if ($trend === 1 && $c['closes'][$i] < $lower) {
                $trend = -1;
            } elseif ($trend === -1 && $c['closes'][$i] > $upper) {
                $trend = 1;
            }

            if ($i >= $start) {
                $out[] = ['time' => $time($i), 'value' => $trend === 1 ? $lower : $upper, 'trend' => $trend === 1 ? 'up' : 'down'];
            }
        }

        return $out;
    }

    /**
     * Recent swing highs / lows (5-bar pivots) as support / resistance zones.
     *
     * @param  array<string, array<int, float|int>>  $c
     * @return array<int, array{price: float, type: string, touches: int}>
     */
    protected function swingLevels(array $c, int $start): array
    {
        $count = count($c['closes']);
        $last = (float) $c['closes'][$count - 1];
        $pivots = [];

        for ($i = max($start, 5); $i < $count - 5; $i++) {
            $windowHighs = array_slice($c['highs'], $i - 5, 11);
            $windowLows = array_slice($c['lows'], $i - 5, 11);
            if ($c['highs'][$i] >= max($windowHighs)) {
                $pivots[] = (float) $c['highs'][$i];
            }
            if ($c['lows'][$i] <= min($windowLows)) {
                $pivots[] = (float) $c['lows'][$i];
            }
        }

        // Merge pivots within 0.4% of each other into zones, keep the most-touched near price.
        sort($pivots);
        $zones = [];
        foreach ($pivots as $price) {
            $lastZone = end($zones);
            if ($lastZone !== false && abs($price - $lastZone['price']) / $lastZone['price'] < 0.004) {
                $zones[key($zones)]['price'] = ($lastZone['price'] * $lastZone['touches'] + $price) / ($lastZone['touches'] + 1);
                $zones[key($zones)]['touches']++;
            } else {
                $zones[] = ['price' => $price, 'touches' => 1];
            }
        }

        $zones = array_map(fn (array $z): array => $z + ['type' => $z['price'] >= $last ? 'resistance' : 'support'], $zones);
        usort($zones, fn (array $a, array $b): int => abs($a['price'] - $last) <=> abs($b['price'] - $last));

        return array_slice($zones, 0, 6);
    }

    /**
     * The 20-bar range when volatility is squeezed (bottom 20% of BB width over 100 bars).
     *
     * @param  array<string, array<int, float|int>>  $c
     * @return array{high: float, low: float, from: int, to: int}|null
     */
    protected function breakoutBox(array $c): ?array
    {
        $count = count($c['closes']);
        $i = $count - 2; // last closed bar
        if ($i < 105) {
            return null;
        }

        $bbw = Indicators::bbWidthPercent($c['closes'], 20, 2.0);
        $history = array_values(array_filter(array_slice($bbw, $i - 100, 100), fn ($v): bool => $v !== null));
        if ($bbw[$i] === null || $history === []) {
            return null;
        }

        $percentile = count(array_filter($history, fn (float $v): bool => $v < $bbw[$i])) / count($history);
        if ($percentile > 0.2) {
            return null;
        }

        return [
            'high' => (float) max(array_slice($c['highs'], $i - 19, 20)),
            'low' => (float) min(array_slice($c['lows'], $i - 19, 20)),
            'from' => intdiv((int) $c['closeTimes'][$i - 19], 1000),
            'to' => intdiv((int) $c['closeTimes'][$count - 1], 1000),
        ];
    }

    /**
     * @param  array<int, float|null>  $values
     * @return array<int, array{time: int, value: float}>
     */
    protected function toSeries(array $values, int $start, int $count, callable $time): array
    {
        $out = [];
        for ($i = $start; $i < $count; $i++) {
            if ($values[$i] !== null) {
                $out[] = ['time' => $time($i), 'value' => (float) $values[$i]];
            }
        }

        return $out;
    }

    /**
     * Snap a timestamp to the close time of the chart candle containing it.
     *
     * @param  array<string, array<int, float|int>>  $c
     */
    protected function alignToBar(int $timestamp, array $c): int
    {
        foreach ($c['closeTimes'] as $closeTime) {
            $closeSec = intdiv((int) $closeTime, 1000);
            if ($closeSec >= $timestamp) {
                return $closeSec;
            }
        }

        return intdiv((int) end($c['closeTimes']), 1000);
    }
}
