<?php

namespace App\Services\Strategy;

use App\Services\Crypto\Indicators;

/**
 * Replays the confirmed swing BUY/SELL flips on closed candles with the Swing SAR bot's own exits, so the chart can
 * show how each timeframe's signals actually did. Per bar (conservative): stop check first, then partials, then the
 * ladder / trail / ROE lock for the next bar; the opposite flip closes at its candle close. Fees: 0.05% per side.
 */
class SwingScorecard
{
    protected const FEE = 0.0005;

    public function __construct(protected StrategyEngine $engine) {}

    /**
     * @param  array<string, array<int, float|int>>  $c  Closed candles, oldest first
     * @return array{trades: int, win_pct: ?float, profit_factor: ?float, net_pct: float, avg_r: ?float, late_pct: ?float, mfe_pct: ?float, days: float}
     */
    public function score(array $c, int $warmup = 60): array
    {
        $n = count($c['closes'] ?? []);
        $empty = ['trades' => 0, 'win_pct' => null, 'profit_factor' => null, 'net_pct' => 0.0, 'avg_r' => null, 'late_pct' => null, 'mfe_pct' => null, 'days' => 0.0];
        if ($n <= $warmup + 2) {
            return $empty;
        }

        $cfg = (array) config('trading.swing_sar');
        $lev = max(1, (int) ($cfg['leverage'] ?? 5));
        $maxSl = (float) ($cfg['max_sl_pct'] ?? 2.0) / 100;
        $buffer = (float) ($cfg['stop_buffer_atr'] ?? 1.0);
        $st = StrategyEngine::supertrendOf($c);
        $atr = Indicators::atr($c['highs'], $c['lows'], $c['closes'], 14);

        $flips = [];
        $index = array_flip(array_map(fn ($ms): int => intdiv((int) $ms, 1000), $c['closeTimes']));
        foreach ($this->engine->swingSignals($c, $warmup, true) as $sig) {
            if (isset($index[$sig['time']])) {
                $flips[$index[$sig['time']]] = ['dir' => $sig['side'] === 'LONG' ? 1 : -1, 'confirmed' => (bool) $sig['confirmed']];
            }
        }

        $results = [];
        $pos = null;
        $close = function (int $q, float $px) use (&$pos, &$results): void {
            $pos['ret'] += $pos['left'] * $pos['dir'] * ($px - $pos['entry']) / $pos['entry'];
            $ret = $pos['ret'] - 2 * self::FEE;
            $results[] = ['ret' => $ret, 'r' => $ret / ($pos['risk'] / $pos['entry']), 'late' => $pos['late'], 'mfe' => ($pos['best'] - $pos['entry']) * $pos['dir'] / $pos['entry']];
            $pos = null;
        };

        for ($q = $warmup; $q < $n; $q++) {
            if ($pos !== null) {
                $d = $pos['dir'];
                $e = $pos['entry'];
                if ($d > 0 ? $c['lows'][$q] <= $pos['stop'] : $c['highs'][$q] >= $pos['stop']) {
                    $close($q, $d > 0 ? min($pos['stop'], (float) $c['opens'][$q]) : max($pos['stop'], (float) $c['opens'][$q]));
                } else {
                    $pos['best'] = $d > 0 ? max($pos['best'], (float) $c['highs'][$q]) : min($pos['best'], (float) $c['lows'][$q]);
                    $this->updateStop($pos, $c, $q, $st['line'][$q] ?? null, $cfg, $lev);
                }
            }

            $flip = $flips[$q] ?? null;
            if ($flip === null) {
                continue;
            }
            if ($pos !== null && $flip['dir'] !== $pos['dir']) {
                $close($q, (float) $c['closes'][$q]);
            }
            if ($pos === null && $flip['confirmed'] && ($st['line'][$q] ?? null) !== null && ($atr[$q] ?? null) !== null) {
                $d = $flip['dir'];
                $entry = (float) $c['closes'][$q];
                $stop = (float) $st['line'][$q] - $d * $buffer * (float) $atr[$q];
                $cap = $entry * (1 - $d * $maxSl);
                if ($d > 0 ? $stop < $cap : $stop > $cap) {
                    $stop = $cap;
                }
                // How far the signal came after the turn: entry vs the extreme of the last 5 candles.
                $from = max(0, $q - 4);
                $extreme = $d > 0 ? min(array_slice($c['lows'], $from, $q - $from + 1)) : max(array_slice($c['highs'], $from, $q - $from + 1));
                $pos = ['dir' => $d, 'entry' => $entry, 'stop' => $stop, 'risk' => abs($entry - $stop), 'atr' => (float) $atr[$q], 'best' => $entry,
                    'ret' => 0.0, 'left' => 1.0, 'done' => [], 'late' => ($entry - (float) $extreme) * $d / $entry];
            }
        }

        if ($results === []) {
            return ['days' => $this->days($c, $warmup)] + $empty;
        }

        $gain = array_sum(array_map(fn ($r) => max(0, $r['ret']), $results));
        $loss = -array_sum(array_map(fn ($r) => min(0, $r['ret']), $results));
        $count = count($results);
        $avg = fn (string $k): float => array_sum(array_column($results, $k)) / $count;

        return [
            'trades' => $count,
            'win_pct' => round(count(array_filter($results, fn ($r) => $r['ret'] > 0)) / $count * 100, 1),
            'profit_factor' => $loss > 0 ? round($gain / $loss, 2) : ($gain > 0 ? 99.99 : null), // 99.99 = no losing trade
            'net_pct' => round(array_sum(array_column($results, 'ret')) * 100 * $lev, 1),
            'avg_r' => round($avg('r'), 2),
            'late_pct' => round($avg('late') * 100, 2),
            'mfe_pct' => round($avg('mfe') * 100, 2),
            'days' => $this->days($c, $warmup),
        ];
    }

    /**
     * Partials, ladder, ATR trail, SuperTrend line, ROE profit lock and swing trail, as in DynamicTradeManager::manageSwingSar.
     *
     * @param  array<string, mixed>  $pos
     * @param  array<string, mixed>  $cfg
     */
    protected function updateStop(array &$pos, array $c, int $q, ?float $line, array $cfg, int $lev): void
    {
        $d = $pos['dir'];
        $e = $pos['entry'];
        $r = $pos['risk'];
        $bestR = $r > 0 ? ($pos['best'] - $e) * $d / $r : 0.0;

        foreach ((array) ($cfg['partials'] ?? []) as $i => [$atR, $share]) {
            if (! isset($pos['done'][$i]) && $bestR >= $atR && $pos['left'] > $share + 1e-9) {
                $pos['ret'] += $share * $atR * $r / $e; // booked at +atR R, a gain on either side
                $pos['left'] -= $share;
                $pos['done'][$i] = true;
            }
        }

        $candidates = [];
        if ($line !== null && ($cfg['trail'] ?? true)) {
            $candidates[] = $line - $d * (float) ($cfg['stop_buffer_atr'] ?? 1.0) * $pos['atr'];
        }
        foreach ((array) ($cfg['ladder'] ?? []) as [$atR, $stopR]) {
            if ($bestR >= $atR) {
                $candidates[] = (float) $stopR == 0.0 ? $e * (1 + $d * 2 * self::FEE) : $e + $d * $stopR * $r;
            }
        }
        $trailFrom = (float) ($cfg['trail_from_r'] ?? 1.5);
        if ($trailFrom > 0 && $bestR >= $trailFrom) {
            $candidates[] = $pos['best'] - $d * (float) ($cfg['trail_atr'] ?? 1.5) * $pos['atr'];
        }
        $roe = ($pos['best'] - $e) * $d / $e * 100 * $lev;
        $keep = null;
        foreach ((array) ($cfg['profit_lock'] ?? []) as [$atRoe, $share]) {
            if ($roe >= $atRoe) {
                $keep = max((float) $keep, (float) $share);
            }
        }
        if ($keep !== null) {
            $candidates[] = $e * (1 + $d * 2 * self::FEE);
            $candidates[] = $e + $d * ($pos['best'] - $e) * $keep;
            $swingFrom = (float) ($cfg['swing_trail_from_roe'] ?? 10);
            if ($swingFrom > 0 && $roe >= $swingFrom && $q > 0) {
                $candidates[] = ($d > 0 ? min($c['lows'][$q], $c['lows'][$q - 1]) : max($c['highs'][$q], $c['highs'][$q - 1])) - $d * 0.1 * $pos['atr'];
            }
        }

        foreach ($candidates as $s) {
            if ($d > 0 ? $s > $pos['stop'] && $s < $c['closes'][$q] : $s < $pos['stop'] && $s > $c['closes'][$q]) {
                $pos['stop'] = $s;
            }
        }
    }

    protected function days(array $c, int $warmup): float
    {
        $n = count($c['closeTimes']);

        return round(((int) $c['closeTimes'][$n - 1] - (int) $c['closeTimes'][min($warmup, $n - 1)]) / 86400000, 1);
    }
}
