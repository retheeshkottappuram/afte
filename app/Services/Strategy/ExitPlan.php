<?php

namespace App\Services\Strategy;

/**
 * Pure exit rules shared by live trade management, paper simulation and the backtester,
 * so all three behave identically.
 *
 * Rules (R = initial risk = |entry - initial SL|):
 *  - Stop-loss is checked first (conservative when a bar touches both SL and a target).
 *  - At +1R the stop moves to breakeven plus round-trip fees.
 *  - At TP1 (1.5R) half the position is booked and the stop moves to +0.5R.
 *  - At TP2 (3R) the stop is locked at no worse than TP1.
 *  - After TP1 the runner trails at 1.5x ATR, updated on candle closes only.
 *  - Time stop: not yet +0.5R after 12h closes the trade; 48h is the hard max hold.
 */
class ExitPlan
{
    /**
     * @param  array{tp1_r: float, tp2_r: float, tp1_close_ratio: float, breakeven_at_r: float, after_tp1_lock_r: float, trail_atr_mult: float, time_stop_hours: float, time_stop_min_r: float, max_hold_hours: float, fee_rate: float}  $config
     */
    public function __construct(protected array $config) {}

    public static function fromConfig(): self
    {
        return new self(array_merge([
            'tp1_r' => 1.5,
            'tp2_r' => 3.0,
            'tp1_close_ratio' => 0.5,
            'breakeven_at_r' => 1.0,
            'after_tp1_lock_r' => 0.5,
            'trail_atr_mult' => 1.5,
            'time_stop_hours' => 12,
            'time_stop_min_r' => 0.5,
            'max_hold_hours' => 48,
            'fee_rate' => 0.0005,
        ], (array) config('trading.exits', [])));
    }

    public function tp1CloseRatio(): float
    {
        return (float) $this->config['tp1_close_ratio'];
    }

    public function feeRate(): float
    {
        return (float) $this->config['fee_rate'];
    }

    /**
     * Compute take-profit targets from entry and stop.
     *
     * @return array{risk: float, tp1: float, tp2: float, tp3: float}
     */
    public function targets(string $side, float $entry, float $stopLoss): array
    {
        $risk = abs($entry - $stopLoss);
        $direction = $this->direction($side);

        return [
            'risk' => $risk,
            'tp1' => $entry + $direction * $risk * (float) $this->config['tp1_r'],
            'tp2' => $entry + $direction * $risk * (float) $this->config['tp2_r'],
            'tp3' => $entry + $direction * $risk * ((float) $this->config['tp2_r'] + 1.5),
        ];
    }

    /**
     * Evaluate one price update (a live tick, or one OHLC bar in a backtest).
     *
     * @param  array{side: string, entry: float, initial_sl: float, current_sl: float, tp1: float, tp2: float, tp1_hit: bool, be_locked: bool, highest: ?float, lowest: ?float, opened_at: int}  $state
     * @return array{
     *     state: array{side: string, entry: float, initial_sl: float, current_sl: float, tp1: float, tp2: float, tp1_hit: bool, be_locked: bool, highest: ?float, lowest: ?float, opened_at: int},
     *     events: array<int, array{type: string, price: float, reason?: string, ratio?: float}>
     * }
     */
    public function evaluate(array $state, float $high, float $low, float $close, int $nowTs, ?float $atr = null, bool $isBarClose = false): array
    {
        $events = [];
        $direction = $this->direction($state['side']);
        $isLong = $direction > 0;
        $risk = abs($state['entry'] - $state['initial_sl']);

        if ($risk <= 0) {
            return ['state' => $state, 'events' => $events];
        }

        // 1. Stop-loss first (conservative intrabar ordering)
        $stopHit = $isLong ? $low <= $state['current_sl'] : $high >= $state['current_sl'];
        if ($stopHit) {
            $events[] = ['type' => 'close', 'price' => $state['current_sl'], 'reason' => $this->stopReason($state)];

            return ['state' => $state, 'events' => $events];
        }

        $state['highest'] = max($state['highest'] ?? $high, $high);
        $state['lowest'] = min($state['lowest'] ?? $low, $low);
        $bestPrice = $isLong ? $state['highest'] : $state['lowest'];
        $bestR = ($bestPrice - $state['entry']) * $direction / $risk;

        // 2. Breakeven + fees at +1R
        if (! $state['be_locked'] && $bestR >= (float) $this->config['breakeven_at_r']) {
            $feeBuffer = $state['entry'] * $this->feeRate() * 2;
            $state = $this->raiseStop($state, $state['entry'] + $direction * $feeBuffer, $events);
            $state['be_locked'] = true;
        }

        // 3. TP1: book partial, lock +0.5R
        $tp1Reached = $isLong ? $high >= $state['tp1'] : $low <= $state['tp1'];
        if (! $state['tp1_hit'] && $tp1Reached) {
            $events[] = ['type' => 'partial', 'price' => $state['tp1'], 'ratio' => $this->tp1CloseRatio(), 'reason' => 'TP1'];
            $state['tp1_hit'] = true;
            $state['be_locked'] = true;
            $state = $this->raiseStop($state, $state['entry'] + $direction * $risk * (float) $this->config['after_tp1_lock_r'], $events);
        }

        // 4. TP2: lock the stop at no worse than TP1
        $tp2Reached = $isLong ? $high >= $state['tp2'] : $low <= $state['tp2'];
        if ($state['tp1_hit'] && $tp2Reached) {
            $state = $this->raiseStop($state, $state['tp1'], $events);
        }

        // 5. ATR trail on the runner, candle closes only
        if ($state['tp1_hit'] && $isBarClose && $atr !== null && $atr > 0) {
            $trailDistance = $atr * (float) $this->config['trail_atr_mult'];
            $state = $this->raiseStop($state, $bestPrice - $direction * $trailDistance, $events);
        }

        // 6. Time stops
        $ageHours = max(0, $nowTs - $state['opened_at']) / 3600;
        $currentR = ($close - $state['entry']) * $direction / $risk;

        if ($ageHours >= (float) $this->config['max_hold_hours']) {
            $events[] = ['type' => 'close', 'price' => $close, 'reason' => 'MAX_HOLD_TIME'];
        } elseif (! $state['tp1_hit'] && $ageHours >= (float) $this->config['time_stop_hours'] && $currentR < (float) $this->config['time_stop_min_r']) {
            $events[] = ['type' => 'close', 'price' => $close, 'reason' => 'TIME_STOP'];
        }

        return ['state' => $state, 'events' => $events];
    }

    /**
     * Move the stop only in the profitable direction.
     *
     * @param  array<string, mixed>  $state
     * @param  array<int, array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    protected function raiseStop(array $state, float $candidate, array &$events): array
    {
        $isLong = $this->direction($state['side']) > 0;
        $improves = $isLong ? $candidate > $state['current_sl'] : $candidate < $state['current_sl'];

        if ($improves) {
            $state['current_sl'] = $candidate;
            $events[] = ['type' => 'move_sl', 'price' => $candidate];
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function stopReason(array $state): string
    {
        if ($state['tp1_hit']) {
            return 'TRAILING_STOP';
        }

        return $state['be_locked'] ? 'BREAKEVEN_STOP' : 'STOP_LOSS';
    }

    protected function direction(string $side): int
    {
        return in_array(strtoupper($side), ['LONG', 'BUY'], true) ? 1 : -1;
    }
}
