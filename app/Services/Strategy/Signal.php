<?php

namespace App\Services\Strategy;

/**
 * One strategy signal on a closed candle. The same object feeds the auto-trader,
 * the market scanner, chart markers, Telegram alerts and the backtester.
 */
final class Signal
{
    /**
     * @param  array<string, array{pass: bool, detail: string}>  $filters
     * @param  array<int, string>  $confluences
     * @param  array<string, float|int|string>  $features
     * @param  array<string, float|int|string|null>  $indicators
     * @param  array<int, string>  $aiReasons
     */
    public function __construct(
        public readonly string $symbol,
        public readonly string $interval,
        public readonly string $side,
        public readonly string $setup,
        public readonly string $setupLabel,
        public readonly int $time,
        public readonly float $entry,
        public readonly float $stopLoss,
        public readonly float $tp1,
        public readonly float $tp2,
        public readonly float $tp3,
        public readonly float $atr,
        public readonly bool $isShadow,
        public readonly array $filters,
        public readonly array $confluences,
        public readonly array $features,
        public readonly array $indicators,
        public readonly ?string $grade = null,
        public readonly ?float $aiProbability = null,
        public readonly array $aiReasons = [],
    ) {}

    public function isLong(): bool
    {
        return $this->side === 'LONG';
    }

    public function orderSide(): string
    {
        return $this->isLong() ? 'BUY' : 'SELL';
    }

    public function slPct(): float
    {
        return $this->entry > 0 ? abs($this->entry - $this->stopLoss) / $this->entry * 100 : 0.0;
    }

    public function riskReward(): float
    {
        $risk = abs($this->entry - $this->stopLoss);

        return $risk > 0 ? abs($this->tp2 - $this->entry) / $risk : 0.0;
    }

    /**
     * All market filters passed and the setup is a core (non-shadow) setup.
     */
    public function isTradable(): bool
    {
        return ! $this->isShadow && $this->failedFilters() === [];
    }

    /**
     * @return array<string, string>
     */
    public function failedFilters(): array
    {
        $failed = [];
        foreach ($this->filters as $name => $result) {
            if (! $result['pass']) {
                $failed[$name] = $result['detail'];
            }
        }

        return $failed;
    }

    /**
     * Number of grade stars (1-5) for chart labels.
     */
    public function stars(): int
    {
        return match ($this->grade) {
            'A' => count($this->confluences) >= 4 ? 5 : 4,
            'B' => 3,
            'C' => 2,
            default => 1,
        };
    }

    /**
     * @param  array<int, string>  $aiReasons
     */
    public function withScore(string $grade, ?float $aiProbability, array $aiReasons = []): self
    {
        return new self(
            $this->symbol, $this->interval, $this->side, $this->setup, $this->setupLabel, $this->time,
            $this->entry, $this->stopLoss, $this->tp1, $this->tp2, $this->tp3, $this->atr, $this->isShadow,
            $this->filters, $this->confluences, $this->features, $this->indicators,
            $grade, $aiProbability, $aiReasons,
        );
    }

    /**
     * Copy with an extra (or replaced) filter result.
     */
    public function withFilter(string $name, bool $pass, string $detail): self
    {
        $filters = $this->filters;
        $filters[$name] = ['pass' => $pass, 'detail' => $detail];

        return new self(
            $this->symbol, $this->interval, $this->side, $this->setup, $this->setupLabel, $this->time,
            $this->entry, $this->stopLoss, $this->tp1, $this->tp2, $this->tp3, $this->atr, $this->isShadow,
            $filters, $this->confluences, $this->features, $this->indicators,
            $this->grade, $this->aiProbability, $this->aiReasons,
        );
    }

    /**
     * Payload for OrderExecutor::executeSignal().
     *
     * @return array<string, mixed>
     */
    public function toOrderPayload(): array
    {
        return [
            'symbol' => $this->symbol,
            'interval' => $this->interval,
            'direction' => $this->side,
            'side' => $this->orderSide(),
            'setup' => $this->setup,
            'setup_label' => $this->setupLabel,
            'price' => $this->entry,
            'initial_sl' => $this->stopLoss,
            'tp1' => $this->tp1,
            'tp2' => $this->tp2,
            'grade' => $this->grade ?? 'C',
            'score' => (int) round(($this->aiProbability ?? 0) * 100),
            'ai_probability' => $this->aiProbability,
            'indicators' => array_merge($this->indicators, ['atr' => $this->atr]),
            'marker_time' => $this->time,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'interval' => $this->interval,
            'side' => $this->side,
            'order_side' => $this->orderSide(),
            'setup' => $this->setup,
            'setup_label' => $this->setupLabel,
            'time' => $this->time,
            'entry' => $this->entry,
            'sl' => $this->stopLoss,
            'tp1' => $this->tp1,
            'tp2' => $this->tp2,
            'tp3' => $this->tp3,
            'atr' => $this->atr,
            'sl_pct' => round($this->slPct(), 3),
            'risk_reward' => round($this->riskReward(), 2),
            'is_shadow' => $this->isShadow,
            'tradable' => $this->isTradable(),
            'filters' => $this->filters,
            'failed_filters' => $this->failedFilters(),
            'confluences' => $this->confluences,
            'indicators' => $this->indicators,
            'grade' => $this->grade,
            'stars' => $this->stars(),
            'ai_probability' => $this->aiProbability,
            'ai_reasons' => $this->aiReasons,
        ];
    }
}
