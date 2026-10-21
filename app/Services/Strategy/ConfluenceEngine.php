<?php

namespace App\Services\Strategy;

use App\Services\Crypto\Indicators;

/**
 * SignalAlgo PRO V3: a BUY / SELL is printed on a closed candle when trend, higher timeframe, momentum, volume,
 * breakout structure and candle quality agree, scored out of 100 (user's strategy, 2026-10-21):
 *
 *   EMA trend 20 | higher timeframe 15 | ADX/DMI + RSI 15 | relative volume 15 | breakout + structure 20 | candle + retest 15
 *
 * Score >= v3.min_score prints BUY / SELL (confirmed); >= v3.unconfirmed_score prints BUY? / SELL?. A label prints on
 * the candle where the score crosses a threshold. Rejected (score 0): RSI overextended, price stretched far from
 * EMA21, stop too tight or too wide, the next swing level too close for the reward, or the other side also scoring high.
 * Stop: the last 5 candles' extreme -/+ 0.2 ATR, kept between 1 and 2.5 ATR. The score is confluence, not a win chance.
 */
class ConfluenceEngine
{
    protected const PIVOT = 3;

    protected ExitPlan $exitPlan;

    public function __construct(?ExitPlan $exitPlan = null)
    {
        $this->exitPlan = $exitPlan ?? ExitPlan::fromConfig();
    }

    /**
     * @param  array<string, array<int, float|int>>  $c  Closed candles, oldest first
     * @param  array<string, array<int, float|int>>|null  $htf  Closed higher-timeframe candles (1h for 15m), or null
     * @return array<int, array<string, mixed>> Same keys as StrategyEngine::swingSignalAt plus score and breakdown
     */
    public function signals(array $c, ?array $htf = null, int $from = 0, bool $includeUnconfirmed = false): array
    {
        $n = count($c['closes'] ?? []);
        if ($n < 60) {
            return [];
        }

        $cfg = (array) config('trading.swing_sar.v3', []);
        $min = (float) ($cfg['min_score'] ?? 80);
        $minQ = (float) ($cfg['unconfirmed_score'] ?? 70);
        $s = $this->series($c, $htf);

        $out = [];
        $prev = [1 => 0.0, -1 => 0.0];
        $start = max(30, min($from, $n - 1) - 5);
        $conflict = (float) ($cfg['conflict_score'] ?? 60);
        for ($k = $start; $k < $n; $k++) {
            $eval = [1 => $this->evaluate($k, 1, $c, $s, $cfg), -1 => $this->evaluate($k, -1, $c, $s, $cfg)];
            foreach ([1, -1] as $d) {
                $now = $eval[$d];
                // Both sides scoring high = no clear direction.
                $score = $now['ok'] && $eval[-$d]['score'] < $conflict ? $now['score'] : 0.0;
                $crossed = ($score >= $minQ && $prev[$d] < $minQ) || ($score >= $min && $prev[$d] < $min);
                $prev[$d] = $score;
                if ($k < $from || ! $crossed) {
                    continue;
                }
                $confirmed = $score >= $min;
                if (! $confirmed && ! $includeUnconfirmed) {
                    continue;
                }
                $out[] = $this->signal($k, $d, $c, $s, $now, $confirmed);
            }
        }

        return $out;
    }

    /**
     * The score of one closed candle for one side, with its breakdown (for the chart inspector and tests).
     *
     * @return array{ok: bool, score: float, breakdown: array<string, float>, reject: ?string, sl: ?float}
     */
    public function scoreAt(array $c, ?array $htf, int $k, string $side): array
    {
        return $this->evaluate($k, $side === 'LONG' ? 1 : -1, $c, $this->series($c, $htf), (array) config('trading.swing_sar.v3', []));
    }

    /**
     * @return array<string, mixed>
     */
    protected function series(array $c, ?array $htf): array
    {
        $dmi = Indicators::dmi($c['highs'], $c['lows'], $c['closes'], 14, 14);

        return [
            'ema9' => Indicators::ema($c['closes'], 9),
            'ema21' => Indicators::ema($c['closes'], 21),
            'ema200' => Indicators::ema($c['closes'], 200),
            'rsi' => Indicators::rsi($c['closes'], 14),
            'atr' => Indicators::atr($c['highs'], $c['lows'], $c['closes'], 14),
            'adx' => $dmi['adx'],
            'pdi' => $dmi['plus_di'],
            'mdi' => $dmi['minus_di'],
            'vol_sma' => Indicators::sma($c['volumes'], 20),
            'htf' => $this->htfMap($c['closeTimes'], $htf),
            'pivots' => $this->pivots($c),
        ];
    }

    /**
     * For each base candle: the last higher-timeframe candle closed by then (close, EMA50, EMA200), or null.
     *
     * @return array<int, array{close: float, ema50: ?float, ema200: ?float}|null>
     */
    protected function htfMap(array $closeTimes, ?array $htf): array
    {
        $out = array_fill(0, count($closeTimes), null);
        if ($htf === null || count($htf['closes'] ?? []) < 50) {
            return $out;
        }
        $ema50 = Indicators::ema($htf['closes'], 50);
        $ema200 = Indicators::ema($htf['closes'], 200);
        $j = -1;
        $m = count($htf['closes']);
        foreach ($closeTimes as $k => $t) {
            while ($j + 1 < $m && (int) $htf['closeTimes'][$j + 1] <= (int) $t) {
                $j++;
            }
            if ($j >= 0) {
                $out[$k] = ['close' => (float) $htf['closes'][$j], 'ema50' => $ema50[$j], 'ema200' => $ema200[$j]];
            }
        }

        return $out;
    }

    /**
     * Swing highs / lows known at each candle (a pivot of PIVOT candles each side is known PIVOT candles later).
     *
     * @return array{high: array<int, ?float>, low: array<int, ?float>, highs: array<int, array<int, float>>, lows: array<int, array<int, float>>}
     */
    protected function pivots(array $c): array
    {
        $n = count($c['closes']);
        $p = self::PIVOT;
        $isHigh = $isLow = [];
        for ($j = $p; $j < $n - $p; $j++) {
            $hi = $lo = true;
            for ($i = $j - $p; $i <= $j + $p; $i++) {
                if ($i === $j) {
                    continue;
                }
                // Strictly beyond the left side, at least equal to the right (a flat double top still counts once).
                $hi = $hi && ($i < $j ? $c['highs'][$j] > $c['highs'][$i] : $c['highs'][$j] >= $c['highs'][$i]);
                $lo = $lo && ($i < $j ? $c['lows'][$j] < $c['lows'][$i] : $c['lows'][$j] <= $c['lows'][$i]);
            }
            if ($hi) {
                $isHigh[$j + $p] = (float) $c['highs'][$j];
            }
            if ($lo) {
                $isLow[$j + $p] = (float) $c['lows'][$j];
            }
        }

        $high = $low = array_fill(0, $n, null);
        $highs = $lows = array_fill(0, $n, []);
        $lastHigh = $lastLow = null;
        $recentHighs = $recentLows = [];
        for ($k = 0; $k < $n; $k++) {
            if (isset($isHigh[$k])) {
                $lastHigh = $isHigh[$k];
                $recentHighs = array_slice([...$recentHighs, $isHigh[$k]], -8);
            }
            if (isset($isLow[$k])) {
                $lastLow = $isLow[$k];
                $recentLows = array_slice([...$recentLows, $isLow[$k]], -8);
            }
            $high[$k] = $lastHigh;
            $low[$k] = $lastLow;
            $highs[$k] = $recentHighs;
            $lows[$k] = $recentLows;
        }

        return ['high' => $high, 'low' => $low, 'highs' => $highs, 'lows' => $lows];
    }

    /**
     * @param  array<string, mixed>  $s
     * @param  array<string, mixed>  $cfg
     * @return array{ok: bool, score: float, breakdown: array<string, float>, reject: ?string, sl: ?float}
     */
    protected function evaluate(int $k, int $d, array $c, array $s, array $cfg): array
    {
        $none = ['ok' => false, 'score' => 0.0, 'breakdown' => [], 'reject' => 'warm-up', 'sl' => null];
        $atr = (float) ($s['atr'][$k] ?? 0);
        if ($k < 30 || $atr <= 0 || $s['ema21'][$k] === null || $s['rsi'][$k] === null) {
            return $none;
        }

        $close = (float) $c['closes'][$k];
        $open = (float) $c['opens'][$k];
        $high = (float) $c['highs'][$k];
        $low = (float) $c['lows'][$k];
        $rsi = (float) $s['rsi'][$k];
        $b = [];

        // 1. EMA trend (20)
        $ema200 = $s['ema200'][$k];
        $ema200Before = $s['ema200'][$k - 10] ?? null;
        $b['trend'] = (($s['ema9'][$k] - $s['ema21'][$k]) * $d > 0 ? 8 : 0)
            + ($ema200 !== null && ($close - $ema200) * $d > 0 ? 6 : 0)
            + ($ema200 !== null && $ema200Before !== null && ($ema200 - $ema200Before) * $d > 0 ? 6 : 0);

        // 2. Higher timeframe (15)
        $h = $s['htf'][$k];
        $b['htf'] = $h === null || $h['ema50'] === null ? 0
            : (($h['close'] - $h['ema50']) * $d > 0 ? 7 : 0) + ($h['ema200'] !== null && ($h['ema50'] - $h['ema200']) * $d > 0 ? 8 : 0);

        // 3. ADX / DMI and momentum (15)
        $rsiOk = $d > 0 ? $rsi >= 50 && $rsi <= 70 : $rsi >= 30 && $rsi <= 50;
        $b['momentum'] = ((float) ($s['adx'][$k] ?? 0) >= 20 ? 5 : 0)
            + ((((float) ($s['pdi'][$k] ?? 0)) - ((float) ($s['mdi'][$k] ?? 0))) * $d > 0 ? 5 : 0)
            + ($rsiOk ? 5 : 0);

        // 4. Relative volume (15)
        $volSma = (float) ($s['vol_sma'][$k - 1] ?? 0);
        $ratio = $volSma > 0 ? (float) $c['volumes'][$k] / $volSma : 0.0;
        $b['volume'] = $ratio >= 2.0 ? 15 : ($ratio >= 1.5 ? 12 : ($ratio >= 1.2 ? 8 : 0));

        // 5. Breakout of the prior range (12) and break of the last swing high / low (8)
        $look = (int) ($cfg['breakout_lookback'] ?? 20);
        $broke = null;
        $level = null;
        for ($j = $k; $j >= max($look, $k - 4); $j--) {
            $range = $d > 0 ? max(array_slice($c['highs'], $j - $look, $look)) : min(array_slice($c['lows'], $j - $look, $look));
            if (((float) $c['closes'][$j] - $range) * $d > 0) {
                $broke = $j;
                $level = (float) $range;
                break;
            }
        }
        $swing = $d > 0 ? $s['pivots']['high'][$k] : $s['pivots']['low'][$k];
        $b['breakout'] = ($broke !== null ? 12 : 0) + ($swing !== null && ($close - $swing) * $d > 0 ? 8 : 0);

        // 6. Candle quality (8) and retest of the broken level (7)
        $span = max($high - $low, 1e-12);
        $strong = ($close - $open) * $d > 0 && abs($close - $open) / $span >= 0.6
            && ($d > 0 ? ($close - $low) / $span >= 0.75 : ($high - $close) / $span >= 0.75);
        $retest = false;
        if ($broke !== null && $broke < $k) {
            for ($i = $broke + 1; $i <= $k; $i++) {
                $touch = $d > 0 ? (float) $c['lows'][$i] <= $level + 0.25 * $atr : (float) $c['highs'][$i] >= $level - 0.25 * $atr;
                if ($touch && ((float) $c['closes'][$i] - $level) * $d > 0) {
                    $retest = true;
                    break;
                }
            }
        }
        $b['candle'] = ($strong ? 8 : 0) + ($retest ? 7 : 0);

        $score = (float) array_sum($b);

        // Stop: the last 5 candles' extreme -/+ 0.2 ATR, kept between 1 and 2.5 ATR from the close.
        $extreme = $d > 0 ? min(array_slice($c['lows'], $k - 4, 5)) : max(array_slice($c['highs'], $k - 4, 5));
        $dist = min(max(($close - ((float) $extreme - $d * 0.2 * $atr)) * $d, 1.0 * $atr), 2.5 * $atr);
        $sl = $close - $d * $dist;
        $slPct = $dist / $close * 100;

        $reject = match (true) {
            $d > 0 && $rsi > (float) ($cfg['max_rsi'] ?? 75) => sprintf('RSI %.0f overbought', $rsi),
            $d < 0 && $rsi < 100 - (float) ($cfg['max_rsi'] ?? 75) => sprintf('RSI %.0f oversold', $rsi),
            ($close - (float) $s['ema21'][$k]) * $d > (float) ($cfg['max_extension_atr'] ?? 2.0) * $atr => 'too far from EMA21 (chasing)',
            $slPct < 0.3 || $slPct > 3.0 => sprintf('stop %.2f%% outside 0.3-3%%', $slPct),
            ! $this->roomToRun($k, $d, $close, $dist, $s, (float) ($cfg['min_rr'] ?? 2.0)) => 'next swing level is closer than '.((float) ($cfg['min_rr'] ?? 2.0)).'R',
            default => null,
        };

        return ['ok' => $reject === null, 'score' => $score, 'breakdown' => $b, 'reject' => $reject, 'sl' => $sl];
    }

    /**
     * No earlier swing high (long) / low (short) between the entry and min_rr R away.
     */
    protected function roomToRun(int $k, int $d, float $close, float $risk, array $s, float $minRr): bool
    {
        foreach ($d > 0 ? $s['pivots']['highs'][$k] : $s['pivots']['lows'][$k] as $level) {
            $gap = ($level - $close) * $d;
            if ($gap > 0 && $gap < $minRr * $risk) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $now  From evaluate()
     * @return array<string, mixed>
     */
    protected function signal(int $k, int $d, array $c, array $s, array $now, bool $confirmed): array
    {
        $side = $d > 0 ? 'LONG' : 'SHORT';
        $close = (float) $c['closes'][$k];
        $sl = (float) $now['sl'];
        $targets = $this->exitPlan->targets($side, $close, $sl);
        $volSma = (float) ($s['vol_sma'][$k - 1] ?? 0);
        $b = $now['breakdown'];

        return [
            'side' => $side, 'entry' => $close, 'sl' => $sl, 'tp1' => $targets['tp1'], 'tp2' => $targets['tp2'], 'tp3' => $targets['tp3'],
            'atr' => (float) $s['atr'][$k],
            'reason' => sprintf('V3 score %d/100: trend %d, HTF %d, momentum %d, volume %d, breakout %d, candle %d',
                $now['score'], $b['trend'], $b['htf'], $b['momentum'], $b['volume'], $b['breakout'], $b['candle']),
            'rsi' => round((float) $s['rsi'][$k], 1),
            'volume_ratio' => $volSma > 0 ? round((float) $c['volumes'][$k] / $volSma, 2) : 0.0,
            'time' => intdiv((int) $c['closeTimes'][$k], 1000),
            'confirmed' => $confirmed,
            'sl_pct' => round(abs($close - $sl) / $close * 100, 2),
            'score' => (int) $now['score'],
            'breakdown' => $b,
            'source' => 'v3',
        ];
    }
}
