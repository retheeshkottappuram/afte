<?php

namespace App\Services\Strategy;

/**
 * Replays a signal through the ExitPlan on candles that follow it.
 * Used by the signal outcome tracker and the backtester so measured results
 * reflect exactly how the bot manages trades (fees included, SL-first intrabar).
 */
class TradeSimulator
{
    public function __construct(protected ?ExitPlan $exitPlan = null)
    {
        $this->exitPlan ??= ExitPlan::fromConfig();
    }

    /**
     * @param  array{side: string, entry: float, sl: float, tp1: float, tp2: float, atr: float, time: int}  $signal  time = signal candle close (unix seconds)
     * @param  array{opens: array<int, float>, highs: array<int, float>, lows: array<int, float>, closes: array<int, float>, closeTimes: array<int, int>}  $candles  Candles after the signal (any timeframe)
     * @return array{closed: bool, outcome: string, r_multiple: float, mfe_r: float, mae_r: float, exit_reason: ?string, exit_time: ?int, exit_price: ?float}
     */
    public function run(array $signal, array $candles, int $trailBarSeconds = 3600, float $slippage = 0.0): array
    {
        $direction = strtoupper($signal['side']) === 'LONG' ? 1 : -1;
        $entry = $signal['entry'] * (1 + $direction * $slippage);
        $risk = abs($signal['entry'] - $signal['sl']);
        $feeRate = $this->exitPlan->feeRate();
        $tp1Ratio = $this->exitPlan->tp1CloseRatio();

        $state = [
            'side' => $direction > 0 ? 'LONG' : 'SHORT',
            'entry' => $entry,
            'initial_sl' => $entry - $direction * $risk,
            'current_sl' => $entry - $direction * $risk,
            'tp1' => $signal['tp1'],
            'tp2' => $signal['tp2'],
            'tp1_hit' => false,
            'be_locked' => false,
            'highest' => $entry,
            'lowest' => $entry,
            'opened_at' => (int) $signal['time'],
        ];

        $result = ['closed' => false, 'outcome' => 'OPEN', 'r_multiple' => 0.0, 'mfe_r' => 0.0, 'mae_r' => 0.0, 'exit_reason' => null, 'exit_time' => null, 'exit_price' => null];
        if ($risk <= 0) {
            return $result;
        }

        $realizedR = 0.0;
        $remaining = 1.0;
        $tp2Reached = false;
        $lastTrailBar = intdiv((int) $signal['time'], $trailBarSeconds);
        $count = count($candles['closes'] ?? []);

        for ($k = 0; $k < $count; $k++) {
            $closeTime = intdiv((int) $candles['closeTimes'][$k], 1000);
            if ($closeTime <= (int) $signal['time']) {
                continue;
            }

            $high = (float) $candles['highs'][$k];
            $low = (float) $candles['lows'][$k];
            $close = (float) $candles['closes'][$k];
            $bar = intdiv($closeTime, $trailBarSeconds);
            $isBarClose = $bar !== $lastTrailBar;
            $lastTrailBar = $bar;

            $result['mfe_r'] = max($result['mfe_r'], (($direction > 0 ? $high : $low) - $entry) * $direction / $risk);
            $result['mae_r'] = min($result['mae_r'], (($direction > 0 ? $low : $high) - $entry) * $direction / $risk);

            $step = $this->exitPlan->evaluate($state, $high, $low, $close, $closeTime, (float) $signal['atr'], $isBarClose);
            $state = $step['state'];

            if (($direction > 0 ? $high >= $state['tp2'] : $low <= $state['tp2']) && $state['tp1_hit']) {
                $tp2Reached = true;
            }

            foreach ($step['events'] as $event) {
                if ($event['type'] === 'partial') {
                    $realizedR += $tp1Ratio * (($event['price'] - $entry) * $direction / $risk);
                    $remaining -= $tp1Ratio;
                }

                if ($event['type'] === 'close') {
                    $realizedR += $remaining * (($event['price'] - $entry) * $direction / $risk);
                    $result['closed'] = true;
                    $result['exit_reason'] = $event['reason'];
                    $result['exit_time'] = $closeTime;
                    $result['exit_price'] = (float) $event['price'];
                    break 2;
                }
            }
        }

        if (! $result['closed']) {
            $lastClose = $count > 0 ? (float) $candles['closes'][$count - 1] : $entry;
            $realizedR += $remaining * (($lastClose - $entry) * $direction / $risk);
        }

        // Round-trip fees expressed in R
        $feeR = ($entry * $feeRate * 2) / $risk;
        $result['r_multiple'] = round($realizedR - $feeR, 3);
        $result['mfe_r'] = round($result['mfe_r'], 3);
        $result['mae_r'] = round($result['mae_r'], 3);

        $result['outcome'] = match (true) {
            ! $result['closed'] => 'OPEN',
            $tp2Reached => 'TP2',
            $state['tp1_hit'] => 'TP1',
            $result['exit_reason'] === 'BREAKEVEN_STOP' => 'BE',
            in_array($result['exit_reason'], ['TIME_STOP', 'MAX_HOLD_TIME'], true) => 'EXPIRED',
            default => 'SL',
        };

        return $result;
    }
}
