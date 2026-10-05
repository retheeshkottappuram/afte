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
        $stats = $this->stats->forSetup($signal->setup, null, 90);
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
        if ($signal->isShadow && ! $this->stats->isActive($signal->setup)) {
            return ['allowed' => false, 'reason' => 'Shadow setup: tracked, not traded until it proves an edge.'];
        }

        if (! $signal->isTradable()) {
            return ['allowed' => false, 'reason' => 'Filters failed: '.implode('; ', $signal->failedFilters())];
        }

        if (! $this->stats->isActive($signal->setup)) {
            return ['allowed' => false, 'reason' => sprintf('%s is paused: measured edge is below %+.2fR per trade.', $signal->setupLabel, SetupStats::minEdge())];
        }

        $grades = (array) config('trading.strategy.auto_trade_grades', ['A', 'B']);
        if (! in_array($signal->grade, $grades, true)) {
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
        if ($mode === 'live' && $signal->setup !== 'EARLY_BREAKOUT' && config('trading.strategy.live_requires_proven_setup', true) && ! $this->stats->isProven($signal->setup)) {
            return ['allowed' => false, 'reason' => "{$signal->setupLabel} is not yet proven (needs ".SetupStats::LIVE_SAMPLE_TARGET.' resolved signals with positive expectancy) for live trading.'];
        }

        return ['allowed' => true, 'reason' => 'Signal approved.'];
    }
}
