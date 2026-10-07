<?php

namespace App\Services\Strategy;

/**
 * Grades signals from measured setup statistics plus confluence, attaches the AI probability,
 * and decides whether the auto-trader may take a signal.
 *
 *  A: setup 90d expectancy > +0.25R (n >= 30) and >= 3 confluences
 *  B: setup expectancy > 0 (n >= 30) and >= 1 confluence; or an unproven setup with >= 1 confluence
 *  C: everything else (shown on the scanner, never auto-traded or alerted by default)
 */
class SignalScorer
{
    public function __construct(
        protected SetupStats $stats,
        protected SignalModel $model
    ) {}

    public function score(Signal $signal): Signal
    {
        $stats = $this->stats->forSetup($signal->setup, null, 90, $signal->interval);
        $proven = $stats['n'] >= SetupStats::LIVE_SAMPLE_TARGET;
        $expectancy = $stats['expectancy'];
        $confluenceCount = count($signal->confluences);

        $grade = match (true) {
            $proven && $expectancy > 0.25 && $confluenceCount >= 3 => 'A',
            $proven && $expectancy > 0 && $confluenceCount >= 1 => 'B',
            ! $proven && $confluenceCount >= 1 => 'B',
            default => 'C',
        };

        $prediction = $this->model->predict($signal->features);

        return $signal->withScore($grade, $prediction['probability'] ?? null, $prediction['reasons'] ?? []);
    }

    /**
     * AI edge: the model's win probability relative to the average signal it was trained on
     * (1.0 = average, 1.2 = 20% more likely than average to end profitable). Null when untrained.
     */
    public function aiLift(Signal $signal): ?float
    {
        $baseRate = (float) ($this->model->metrics()['base_rate'] ?? 0);
        if ($signal->aiProbability === null || $baseRate <= 0) {
            return null;
        }

        return round($signal->aiProbability / $baseRate, 3);
    }

    /**
     * Decide whether the auto-trader may take this signal.
     *
     * @return array{allowed: bool, reason: string}
     */
    public function autoTradeDecision(Signal $signal, string $mode): array
    {
        if ($signal->isShadow && ! $this->stats->isActive($signal->setup, $signal->interval)) {
            return ['allowed' => false, 'reason' => 'Shadow setup: tracked, not traded until it proves an edge.'];
        }

        if (! $signal->isTradable()) {
            return ['allowed' => false, 'reason' => 'Filters failed: '.implode('; ', $signal->failedFilters())];
        }

        if (! in_array($signal->interval, self::tradeIntervals(), true)) {
            return ['allowed' => false, 'reason' => "{$signal->interval} signals are alerts-only: this timeframe has not passed its backtest for auto-trading."];
        }

        // User's decision (2026-10-07): trade every confirmed early breakout, even with a negative measured edge.
        // The 3-loss cooldown, daily loss cap and drawdown kill switch still apply.
        $earlyExempt = self::ignoresMinEdge($signal->setup);

        // Two-sided breakouts are always alerted; trading them all lost in the 12-month test (PF 0.70-1.07),
        // so unless switched on, only the trend + box-bias + top-10 subset (PF 1.39-1.58) is traded.
        if ($signal->setup === 'EARLY_BREAKOUT' && ! config('trading.strategy.early_breakout.trade_all_breakouts', true) && ! ($signal->features['proven_subset'] ?? true)) {
            return ['allowed' => false, 'reason' => 'Alert only: breakout against the 4h trend / box lean or outside the top 10 coins (this group lost in the 12-month test).'];
        }
        if (! $earlyExempt && ! $this->stats->isActive($signal->setup, $signal->interval)) {
            return ['allowed' => false, 'reason' => sprintf('%s is paused: measured edge is below %+.2fR per trade.', $signal->setupLabel, SetupStats::minEdge())];
        }

        $grades = (array) config('trading.strategy.auto_trade_grades', ['A', 'B']);
        // Grade C on a proven setup means a negative measured edge, which early breakouts trade anyway (above).
        if (! in_array($signal->grade, $grades, true) && ! ($earlyExempt && $signal->confluences !== [])) {
            return ['allowed' => false, 'reason' => "Grade {$signal->grade} is below the auto-trade threshold."];
        }

        // The model's probabilities are relative to how often signals win at all, so the gate is relative too:
        // skip signals the model rates clearly below an average signal.
        $minLift = (float) config('trading.strategy.min_ai_lift', 0.85);
        $lift = $this->aiLift($signal);
        if ($lift !== null && $lift < $minLift) {
            return ['allowed' => false, 'reason' => sprintf('AI rates this %d%% likely to profit, below the average signal (lift %.2f < %.2f).', round($signal->aiProbability * 100), $lift, $minLift)];
        }

        // Early breakouts trade live by the user's decision; their losing-streak pause and min-edge rule still apply.
        if ($mode === 'live' && $signal->setup !== 'EARLY_BREAKOUT' && config('trading.strategy.live_requires_proven_setup', true) && ! $this->stats->isProven($signal->setup, $signal->interval)) {
            return ['allowed' => false, 'reason' => "{$signal->setupLabel} is not yet proven (needs ".SetupStats::LIVE_SAMPLE_TARGET.' resolved signals with positive expectancy) for live trading.'];
        }

        return ['allowed' => true, 'reason' => 'Signal approved.'];
    }

    /**
     * Setups the auto-trader takes regardless of their measured edge (early breakouts, by the user's choice).
     */
    public static function ignoresMinEdge(string $setup): bool
    {
        return $setup === 'EARLY_BREAKOUT' && (bool) config('trading.strategy.early_breakout.ignore_min_edge', true);
    }

    /**
     * Timeframes the auto-trader may enter on (others are scanned for alerts only).
     *
     * @return array<int, string>
     */
    public static function tradeIntervals(): array
    {
        return (array) config('trading.strategy.trade_intervals', [config('trading.strategy.base_interval', '1h')]);
    }
}
