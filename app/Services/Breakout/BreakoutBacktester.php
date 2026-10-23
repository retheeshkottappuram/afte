<?php

namespace App\Services\Breakout;

use App\Services\Crypto\Indicators;

/**
 * Chronological replay of EBD v1 on historical 5m candles through the SAME SignalLifecycleService the live
 * scanner uses. At candle i only candles 0..i are used (indicator series are causal, ranges come from the
 * detector at the previous close, 1h/15m context is the last candle closed by then).
 *
 * Limits of 5m OHLC (stated in every report):
 *  - Early triggers are PRICE-ONLY: the 1m volume pace at the crossing cannot be rebuilt from 5m candles,
 *    so the pace filter is assumed met and the early score floor is lowered by the 20 pace points it cannot
 *    see (this over-counts early signals compared with live).
 *  - The crossing moment inside a candle is unknown: the fill is the trigger (or the open on a gap), and on
 *    the entry candle only the stop is checked (a stop touch there counts as a loss, targets are not credited).
 *  - When a stop and a target fall in the same later candle, the stop is assumed first.
 *  - Spread is the configured default (no historical order book); funding uses Binance's historical rates.
 */
class BreakoutBacktester
{
    /** @var array<string, int> why EBD signals were not simulated as trades */
    public array $skips = [];

    public function __construct(
        protected EbdConfig $config,
        protected IndicatorCalculator $indicators,
        protected ConsolidationDetector $detector,
        protected SignalLifecycleService $lifecycle,
    ) {}

    /**
     * @param  array<int, float>  $funding  fundingTime ms => rate
     * @return array{trades: array<int, array<string, mixed>>, setups: array<int, array<string, mixed>>, ambiguous: int, tick_rejects: array<string, int>}
     */
    public function runSymbol(string $symbol, Candles $c, ?Candles $m15, ?Candles $h1, array $funding, int $fromMs, int $toMs): array
    {
        $n = $c->count();
        $s = $this->indicators->series($c);
        $ctx = $this->contextSeries($c, $m15, $h1, $funding);
        $warm = $this->config->int('compression.bbw_lookback') + $this->config->int('indicators.bb_period') + 2;
        $setup = null;
        $trades = [];
        $setups = [];
        $ambiguous = 0;
        $tickRejects = [];
        $busy = ['early' => -1, 'confirmed' => -1, 'retest' => -1];
        $events = [];

        for ($i = $warm; $i < $n; $i++) {
            if ($c->closeTimes[$i] > $toMs) {
                break;
            }
            $endedNow = false;
            $live = $c->openTimes[$i] >= $fromMs;

            // Stage B inside candle i (price only), before the candle closes.
            if ($setup !== null && $setup['state'] === SignalLifecycleService::COMPRESSION && $c->openTimes[$i] > $setup['compressed_ms']) {
                $up = $c->highs[$i] > $setup['long_trigger'];
                $down = $c->lows[$i] < $setup['short_trigger'];
                if ($up && $down) {
                    $ambiguous++;
                } elseif ($up || $down) {
                    // First price strictly beyond the trigger (or the open, on a gap through it).
                    $price = $up ? max($c->opens[$i], $setup['long_trigger'] * (1 + 1e-7)) : min($c->opens[$i], $setup['short_trigger'] * (1 - 1e-7));
                    [$setup, $new] = $this->lifecycle->onTick($setup, [
                        'price' => $price,
                        'now_ms' => $c->openTimes[$i],
                        'bar_open_ms' => $c->openTimes[$i],
                        'bar_ms' => $c->intervalMs,
                        'pace' => $this->config->float('early.min_volume_pace'), // not observable in 5m history: assumed met
                        'spread_bps' => null,
                        'funding_rate' => $ctx[$i - 1]['funding_rate'],
                        'forming' => ['open' => $c->opens[$i], 'high' => max($c->opens[$i], $price), 'low' => min($c->opens[$i], $price)],
                        'structure' => ['lows' => [$c->lows[$i - 2], $c->lows[$i - 1]], 'highs' => [$c->highs[$i - 2], $c->highs[$i - 1]]],
                        'rsi' => $s['rsi'][$i - 1],
                        'rsi_prev' => $s['rsi'][$i - 2],
                        'ema_fast' => $s['ema_fast'][$i - 1],
                        'ema_slow' => $s['ema_slow'][$i - 1],
                        'context' => $ctx[$i - 1],
                        // Volume pace (up to 20 of the 25 volume points) is unobservable here: lower the floor by that much.
                        'score_floor' => $this->config->float('score.watchlist') - 20,
                    ]);
                    if ($new === [] && $live) {
                        $why = (string) preg_replace('/[\d.]+/', 'N', (string) ($setup['last_check'] ?? 'no event'));
                        $tickRejects[$why] = ($tickRejects[$why] ?? 0) + 1;
                    }
                    foreach ($new as $e) {
                        $events[] = $e + ['index' => $i];
                        if ($live) {
                            $this->collect($trades, $busy, $e, 'early', $i, $c, $symbol, $funding, intrabar: true);
                        }
                    }
                }
            }

            // Stages B-F on the close of candle i.
            if ($setup !== null) {
                [$setup, $new] = $this->lifecycle->onClosedBar($setup, $c, $s, $i, $ctx[$i]);
                foreach ($new as $e) {
                    $events[] = $e + ['index' => $i];
                    if ($live && $e['type'] === 'CONFIRMED') {
                        $this->collect($trades, $busy, $e, 'confirmed', $i, $c, $symbol, $funding, intrabar: false);
                    }
                    if ($live && $e['type'] === 'RETEST') {
                        $this->collect($trades, $busy, $e, 'retest', $i, $c, $symbol, $funding, intrabar: false);
                    }
                }
                if (SignalLifecycleService::isTerminal($setup['state'])) {
                    if ($live) {
                        $setups[] = $this->setupStats($setup, $events, $c);
                    }
                    $setup = null;
                    $events = [];
                    $endedNow = true;
                }
            }

            // Stage A after candle i closed (not on the candle a previous setup ended, as live).
            if ($setup === null && ! $endedNow) {
                $found = $this->detector->detect($c, $s, $i);
                if ($found['ok']) {
                    $setup = $this->lifecycle->open($symbol, $found['range']);
                }
            }
        }

        return ['trades' => $trades, 'setups' => $setups, 'ambiguous' => $ambiguous, 'tick_rejects' => $tickRejects];
    }

    /**
     * Turns an EARLY / CONFIRMED / RETEST event with valid levels into a simulated trade (one open trade per
     * symbol and variant). Early fills at the observed price inside candle i; confirmed and retest fill at the
     * next candle's open.
     */
    protected function collect(array &$trades, array &$busy, array $e, string $variant, int $i, Candles $c, string $symbol, array $funding, bool $intrabar): void
    {
        $levels = $e['levels'] ?? null;
        $bucket = $e['score']['bucket'] ?? null;
        $skip = match (true) {
            $levels === null => 'no levels',
            ! $levels['ok'] => (string) preg_replace('/[\d.]+/', 'N', (string) $levels['reason']),
            $variant !== 'early' && ! ($e['entry_valid'] ?? false) => 'score bucket '.$bucket,
            default => null,
        };
        $entryIndex = $intrabar ? $i : $i + 1;
        if ($skip === null && ($entryIndex >= $c->count() || $entryIndex <= $busy[$variant])) {
            $skip = 'already in a trade';
        }
        $long = $e['direction'] === 'LONG';
        $entry = $intrabar ? ($levels['entry'] ?? 0) : ($c->opens[$entryIndex] ?? 0);
        if ($skip === null && ($long ? ($entry <= $levels['sl'] || $entry >= $levels['tp1']) : ($entry >= $levels['sl'] || $entry <= $levels['tp1']))) {
            $skip = 'gapped past the stop or TP1 before the fill';
        }
        if ($skip !== null) {
            $this->skips["{$variant}: {$skip}"] = ($this->skips["{$variant}: {$skip}"] ?? 0) + 1;

            return;
        }

        $trade = $this->simulate($e['direction'], $entry, $levels['sl'], $levels['tp1'], $levels['tp2'], $entryIndex, $intrabar, $c, $funding);
        $busy[$variant] = $trade['exit_index'];
        $trades[] = $trade + [
            'symbol' => $symbol,
            'variant' => $variant,
            'direction' => $e['direction'],
            'score' => $e['score']['score'] ?? null,
            'bucket' => $bucket ?? 'n/a',
            'h1_aligned' => ($e['score']['components']['higher_tf'] ?? 0) >= 3,
            'entry_time' => $c->openTimes[$entryIndex],
            'extension_atr' => $e['extension_atr'] ?? ($e['metrics']['extension_atr'] ?? null),
        ];
    }

    /**
     * Long/short trade with TP1 (half) then breakeven stop, TP2 (rest), time stop after tracking_bars.
     * Costs: taker fee and slippage on every fill, half the default spread per fill, historical funding.
     *
     * @return array<string, mixed>
     */
    public function simulate(string $direction, float $signalEntry, float $sl, float $tp1, float $tp2, int $entryIndex, bool $intrabar, Candles $c, array $funding): array
    {
        $sign = $direction === 'LONG' ? 1 : -1;
        $fee = $this->config->float('costs.taker_fee');
        $slip = $this->config->float('costs.slippage') + $this->config->float('costs.default_spread_bps') / 20_000;
        $entry = $signalEntry * (1 + $sign * $slip);
        $risk = abs($entry - $sl);
        $remaining = 1.0;
        $pnl = -$fee * $entry;
        $stop = $sl;
        $tp1Hit = false;
        $moveToBe = false;
        $last = min($c->count() - 1, $entryIndex + $this->config->int('tracking_bars'));
        $exitIndex = $last;
        $outcome = 'TIME';

        $fill = function (float $price, float $fraction) use (&$pnl, &$remaining, $sign, $slip, $fee, $entry): void {
            $exit = $price * (1 - $sign * $slip);
            $pnl += $fraction * ($sign * ($exit - $entry) - $fee * $exit);
            $remaining -= $fraction;
        };

        for ($j = $entryIndex; $j <= $last; $j++) {
            if ($moveToBe) {
                $stop = $entry;
                $moveToBe = false;
            }
            $adverse = $sign > 0 ? $c->lows[$j] <= $stop : $c->highs[$j] >= $stop;
            if ($adverse) {
                $fill($stop, $remaining);
                $exitIndex = $j;
                $outcome = $tp1Hit ? 'TP1_BE' : 'SL';
                break;
            }
            if ($intrabar && $j === $entryIndex) {
                continue; // crossing time unknown: no target credit on the entry candle
            }
            if (! $tp1Hit && ($sign > 0 ? $c->highs[$j] >= $tp1 : $c->lows[$j] <= $tp1)) {
                $fill($tp1, 0.5);
                $tp1Hit = true;
                $moveToBe = true;
            }
            if ($tp1Hit && ($sign > 0 ? $c->highs[$j] >= $tp2 : $c->lows[$j] <= $tp2)) {
                $fill($tp2, $remaining);
                $exitIndex = $j;
                $outcome = 'TP2';
                break;
            }
        }
        if ($remaining > 1e-9) {
            $fill($c->closes[$exitIndex], $remaining);
        }

        $entryTime = $c->openTimes[$entryIndex];
        $exitTime = $c->closeTimes[$exitIndex];
        $fundingCost = 0.0;
        foreach ($funding as $t => $rate) {
            if ($t > $entryTime && $t <= $exitTime) {
                $fundingCost += $sign * $rate * $entry;
            }
        }
        $pnl -= $fundingCost;

        return [
            'entry' => $entry, 'sl' => $sl, 'tp1' => $tp1, 'tp2' => $tp2, 'risk' => $risk,
            'r' => $risk > 0 ? $pnl / $risk : 0.0,
            'cost_r' => $risk > 0 ? (2 * $fee * $entry + 2 * $slip * $entry + $fundingCost) / $risk : 0.0,
            'outcome' => $outcome,
            'exit_index' => $exitIndex,
            'exit_time' => $exitTime,
            'hold_minutes' => intdiv($exitTime - $entryTime + 1, 60_000),
        ];
    }

    /**
     * Per-setup detection quality: extension at the first detection, and the largest favourable move from the
     * broken boundary in the next tracking window (to judge "caught before most of the move").
     */
    protected function setupStats(array $setup, array $events, Candles $c): array
    {
        $types = array_column($events, 'type');
        $first = null;
        foreach ($events as $e) {
            if (in_array($e['type'], ['EARLY', 'CONFIRMED', 'EXTENDED'], true)) {
                $first = $e;
                break;
            }
        }
        $move = null;
        $detectExt = null;
        if ($first !== null && $setup['direction'] !== null) {
            $long = $setup['direction'] === 'LONG';
            $boundary = $long ? $setup['resistance'] : $setup['support'];
            $to = min($c->count() - 1, $first['index'] + $this->config->int('tracking_bars'));
            $best = $long ? max(array_slice($c->highs, $first['index'], $to - $first['index'] + 1)) : min(array_slice($c->lows, $first['index'], $to - $first['index'] + 1));
            $move = ($long ? $best - $boundary : $boundary - $best) / $setup['atr'];
            $detectExt = (float) ($first['extension_atr'] ?? 0);
        }

        return [
            'state' => $setup['state'],
            'direction' => $setup['direction'],
            'early' => in_array('EARLY', $types, true),
            'confirmed' => in_array('CONFIRMED', $types, true),
            'extended' => in_array('EXTENDED', $types, true),
            'failed' => $setup['state'] === SignalLifecycleService::FAILED,
            'first_type' => $first['type'] ?? null,
            'detect_extension_atr' => $detectExt,
            'move_atr' => $move,
        ];
    }

    /**
     * Context known at the close of each 5m candle: last closed 1h EMA21 trend, last closed 15m bandwidth
     * percentile, rolling 24h quote volume, latest funding rate.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function contextSeries(Candles $c, ?Candles $m15, ?Candles $h1, array $funding): array
    {
        $out = [];
        $h1Ema = $h1 ? Indicators::ema($h1->closes, $this->config->int('context.h1_ema')) : [];
        $m15Pct = $m15 ? IndicatorCalculator::priorPercentile(
            $this->indicators->bandwidth($m15->closes, $this->config->int('indicators.bb_period'), $this->config->float('indicators.bb_mult')),
            $this->config->int('compression.bbw_lookback'),
        ) : [];
        $slope = $this->config->int('context.h1_slope_bars');
        $hi = -1;
        $mi = -1;
        $fundingTimes = array_keys($funding);
        $fi = -1;
        $vol = 0.0;

        foreach ($c->closeTimes as $i => $t) {
            while ($h1 && $hi + 1 < $h1->count() && $h1->closeTimes[$hi + 1] <= $t) {
                $hi++;
            }
            while ($m15 && $mi + 1 < $m15->count() && $m15->closeTimes[$mi + 1] <= $t) {
                $mi++;
            }
            while ($fi + 1 < count($fundingTimes) && $fundingTimes[$fi + 1] <= $t) {
                $fi++;
            }
            $vol += $c->volumes[$i] - ($i >= 288 ? $c->volumes[$i - 288] : 0);

            $h1Row = null;
            if ($hi >= $slope && ($h1Ema[$hi] ?? null) !== null && ($h1Ema[$hi - $slope] ?? null) !== null) {
                $h1Row = ['close' => $h1->closes[$hi], 'ema' => $h1Ema[$hi], 'ema_slope' => $h1Ema[$hi] - $h1Ema[$hi - $slope]];
            }
            $pct = $mi >= 0 ? ($m15Pct[$mi] ?? null) : null;
            $out[$i] = [
                'spread_bps' => null,
                'funding_rate' => $fi >= 0 ? $funding[$fundingTimes[$fi]] : null,
                'quote_volume_24h' => $i >= 287 && $c->quoteVolume ? $vol : null,
                'h1' => $h1Row,
                'm15_compressed' => $pct === null ? null : $pct <= $this->config->float('context.m15_bbw_percentile'),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $trades
     * @return array<string, float|int|null>
     */
    public static function summarize(array $trades, float $riskFraction = 0.01, float $startBalance = 24.0): array
    {
        $n = count($trades);
        if ($n === 0) {
            return ['trades' => 0, 'win_rate' => null, 'avg_r' => null, 'net_r' => 0.0, 'profit_factor' => null, 'max_dd_r' => 0.0,
                'avg_hold_min' => null, 'avg_cost_r' => null, 'final_balance' => $startBalance, 'max_dd_pct' => 0.0];
        }
        usort($trades, fn (array $a, array $b): int => $a['exit_time'] <=> $b['exit_time']);
        $rs = array_column($trades, 'r');
        $gains = array_sum(array_filter($rs, fn (float $r): bool => $r > 0));
        $losses = -array_sum(array_filter($rs, fn (float $r): bool => $r < 0));
        $cum = 0.0;
        $peak = 0.0;
        $dd = 0.0;
        $balance = $startBalance;
        $peakBal = $startBalance;
        $ddPct = 0.0;
        foreach ($rs as $r) {
            $cum += $r;
            $peak = max($peak, $cum);
            $dd = max($dd, $peak - $cum);
            $balance *= 1 + $riskFraction * $r;
            $peakBal = max($peakBal, $balance);
            $ddPct = max($ddPct, ($peakBal - $balance) / $peakBal * 100);
        }

        return [
            'trades' => $n,
            'win_rate' => round(count(array_filter($rs, fn (float $r): bool => $r > 0)) / $n * 100, 1),
            'avg_r' => round(array_sum($rs) / $n, 3),
            'net_r' => round(array_sum($rs), 2),
            'profit_factor' => $losses > 0 ? round($gains / $losses, 2) : null,
            'max_dd_r' => round($dd, 2),
            'avg_hold_min' => (int) round(array_sum(array_column($trades, 'hold_minutes')) / $n),
            'avg_cost_r' => round(array_sum(array_column($trades, 'cost_r')) / $n, 3),
            'final_balance' => round($balance, 2),
            'max_dd_pct' => round($ddPct, 1),
        ];
    }
}
