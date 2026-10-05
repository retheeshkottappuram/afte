<?php

namespace App\Services\Strategy;

/**
 * Opportunity Score (0-100): one transparent number to compare signals across the market.
 * It ranks by measured edge and current trade quality. It is not a profit promise.
 *
 *  - Measured edge   0-35  setup 90d expectancy (0R -> 0, +0.30R -> 35) x sample confidence (n/100)
 *  - Filters         0-20  all auto-trader filters pass -> 20, else share passed (shadow setups -> 0)
 *  - Confluence      0-20  5 per confluence, max 4
 *  - Entry valid     0-15  price within 0.3R of entry -> 15, fading to 0 at 1R; stopped / target hit -> 0
 *  - Freshness       0-10  signal on the last candle -> 10, 6 hours old -> 0
 *  - AI lift       -10-+10 only when the AI model is active
 */
class OpportunityScorer
{
    public const MAX_ENTRY_DRIFT_R = 0.3;

    public function __construct(
        protected SetupStats $stats,
        protected SignalScorer $scorer
    ) {}

    /**
     * @return array{score: int, label: string, breakdown: array<string, float>, edge_r: ?float, drift_r: ?float, entry_status: string}
     */
    public function score(Signal $signal, ?float $currentPrice = null, ?int $now = null): array
    {
        $now ??= now()->timestamp;
        $stats = $this->stats->forSetup($signal->setup, null, 90, $signal->interval);
        $edge = $stats['expectancy'];

        $edgePoints = $edge === null ? 0.0 : $this->clamp($edge / 0.30, 0, 1) * 35 * min(1.0, $stats['n'] / 100);

        $filters = $signal->filters;
        $passed = count(array_filter($filters, fn (array $f): bool => $f['pass']));
        $filterPoints = match (true) {
            $signal->isShadow => 0.0,
            $signal->isTradable() => 20.0,
            $filters === [] => 0.0,
            default => 20.0 * $passed / count($filters),
        };

        $confluencePoints = min(4, count($signal->confluences)) * 5.0;

        $drift = $this->driftR($signal, $currentPrice);
        $entryStatus = $this->entryStatus($drift);
        $entryPoints = $this->entryPoints($drift);

        $ageHours = max(0, $now - $signal->time) / 3600;
        $freshnessPoints = 10 * $this->clamp(1 - $ageHours / 6, 0, 1);

        $lift = $this->scorer->aiLift($signal);
        $aiPoints = $lift === null ? 0.0 : $this->clamp(($lift - 1) * 20, -10, 10);

        $breakdown = [
            'edge' => round($edgePoints, 1),
            'filters' => round($filterPoints, 1),
            'confluence' => round($confluencePoints, 1),
            'entry' => round($entryPoints, 1),
            'freshness' => round($freshnessPoints, 1),
            'ai' => round($aiPoints, 1),
        ];
        $score = (int) round($this->clamp(array_sum($breakdown), 0, 100));

        return [
            'score' => $score,
            'label' => self::label($score),
            'breakdown' => $breakdown,
            'edge_r' => $edge,
            'drift_r' => $drift !== null ? round($drift, 2) : null,
            'entry_status' => $entryStatus,
        ];
    }

    public static function label(int $score): string
    {
        return match (true) {
            $score >= 75 => 'Strong',
            $score >= 60 => 'Good',
            $score >= 45 => 'Fair',
            default => 'Weak',
        };
    }

    /**
     * Distance of the current price from the entry, in R (positive = in the trade's favour).
     */
    public function driftR(Signal $signal, ?float $currentPrice): ?float
    {
        $risk = abs($signal->entry - $signal->stopLoss);
        if ($currentPrice === null || $currentPrice <= 0 || $risk <= 0) {
            return null;
        }

        return ($currentPrice - $signal->entry) * ($signal->isLong() ? 1 : -1) / $risk;
    }

    /**
     * Points for how usable the entry still is: 15 within 0.3R, fading to 0 at 1R.
     */
    public function entryPoints(?float $drift): float
    {
        return match ($this->entryStatus($drift)) {
            'Enter now' => 15.0,
            'Price ran ahead' => 15.0 * $this->clamp(1 - (abs((float) $drift) - self::MAX_ENTRY_DRIFT_R) / (1 - self::MAX_ENTRY_DRIFT_R), 0, 1),
            default => 0.0,
        };
    }

    public function entryStatus(?float $drift): string
    {
        return match (true) {
            $drift === null => 'Enter now',
            $drift <= -1.0 => 'Stopped out',
            $drift >= (float) config('trading.exits.tp1_r', 1.5) => 'Target hit',
            abs($drift) <= self::MAX_ENTRY_DRIFT_R => 'Enter now',
            default => 'Price ran ahead',
        };
    }

    protected function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
