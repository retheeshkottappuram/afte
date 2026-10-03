<?php

namespace App\Services\Strategy;

use App\Models\CryptoSignal;
use Illuminate\Support\Facades\Cache;

/**
 * Measured track record of each setup, from resolved signals (live outcomes and backtest seeds).
 * This is what the scanner, chart and Telegram show instead of made-up scores.
 */
class SetupStats
{
    public const RESOLVED = ['TP1', 'TP2', 'SL', 'BE', 'EXPIRED'];

    /** Live samples needed before backtest seeds are no longer blended in. */
    public const LIVE_SAMPLE_TARGET = 30;

    /** Samples needed before a setup can be promoted or demoted. */
    public const DECISION_SAMPLES = 50;

    /**
     * Stats for one setup (optionally one side) over a window of days.
     *
     * @return array{setup: string, side: ?string, days: int, n: int, n_live: int, win_rate: ?float, expectancy: ?float, profit_factor: ?float, source: string}
     */
    public function forSetup(string $setup, ?string $side = null, int $days = 90): array
    {
        $key = 'setup-stats:'.$setup.':'.($side ?? 'all').':'.$days;

        return Cache::remember($key, 900, fn (): array => $this->compute($setup, $side, $days));
    }

    /**
     * Stats for every known setup, for both windows.
     *
     * @return array<string, array{d30: array<string, mixed>, d90: array<string, mixed>, active: bool, is_core: bool}>
     */
    public function all(): array
    {
        $out = [];
        foreach (array_keys(StrategyEngine::SETUP_LABELS) as $setup) {
            $out[$setup] = [
                'label' => StrategyEngine::SETUP_LABELS[$setup],
                'd30' => $this->forSetup($setup, null, 30),
                'd90' => $this->forSetup($setup, null, 90),
                'active' => $this->isActive($setup),
                'is_core' => in_array($setup, (array) config('trading.strategy.core_setups', []), true),
            ];
        }

        return $out;
    }

    /**
     * Core setups stay active while they keep a real edge (breakeven-after-fees only adds risk);
     * shadow setups activate once they prove a clearly positive one.
     */
    public function isActive(string $setup): bool
    {
        $stats = $this->forSetup($setup, null, 90);
        $isCore = in_array($setup, (array) config('trading.strategy.core_setups', []), true);

        if ($stats['n'] < self::DECISION_SAMPLES || $stats['expectancy'] === null) {
            return $isCore;
        }

        return $isCore ? $stats['expectancy'] >= self::minEdge() : $stats['expectancy'] > 0.15;
    }

    /**
     * A setup is "proven" when it has enough samples and a real edge per trade.
     */
    public function isProven(string $setup): bool
    {
        $stats = $this->forSetup($setup, null, 90);

        return $stats['n'] >= self::LIVE_SAMPLE_TARGET && ($stats['expectancy'] ?? -1) >= self::minEdge();
    }

    /**
     * Minimum average R per trade (after fees) for a setup to be traded.
     */
    public static function minEdge(): float
    {
        return (float) config('trading.strategy.min_setup_expectancy_r', 0.05);
    }

    public function flush(): void
    {
        foreach (array_keys(StrategyEngine::SETUP_LABELS) as $setup) {
            foreach ([null, 'LONG', 'SHORT'] as $side) {
                foreach ([30, 90] as $days) {
                    Cache::forget('setup-stats:'.$setup.':'.($side ?? 'all').':'.$days);
                }
            }
        }
    }

    /**
     * @return array{setup: string, side: ?string, days: int, n: int, n_live: int, win_rate: ?float, expectancy: ?float, profit_factor: ?float, source: string}
     */
    protected function compute(string $setup, ?string $side, int $days): array
    {
        // Only signals that passed every market filter: those are the trades the bot would actually take.
        $base = CryptoSignal::query()
            ->where('setup', $setup)
            ->where('passed_filters', true)
            ->whereIn('outcome', self::RESOLVED)
            ->whereNotNull('r_multiple');

        if ($side !== null) {
            $base->where('side', $side === 'LONG' ? 'BUY' : 'SELL');
        }

        $live = (clone $base)->where('source', '!=', 'backtest')
            ->where('sent_at', '>=', now()->subDays($days))
            ->pluck('r_multiple')
            ->map(fn ($r): float => (float) $r)
            ->all();

        $samples = $live;
        $source = 'live';

        if (count($live) < self::LIVE_SAMPLE_TARGET) {
            $backtest = (clone $base)->where('source', 'backtest')
                ->pluck('r_multiple')
                ->map(fn ($r): float => (float) $r)
                ->all();

            if ($backtest !== []) {
                $samples = array_merge($live, $backtest);
                $source = $live === [] ? 'backtest' : 'live+backtest';
            }
        }

        return array_merge(['setup' => $setup, 'side' => $side, 'days' => $days, 'n_live' => count($live), 'source' => $source], self::summarize($samples));
    }

    /**
     * @param  array<int, float>  $rMultiples
     * @return array{n: int, win_rate: ?float, expectancy: ?float, profit_factor: ?float}
     */
    public static function summarize(array $rMultiples): array
    {
        $n = count($rMultiples);
        if ($n === 0) {
            return ['n' => 0, 'win_rate' => null, 'expectancy' => null, 'profit_factor' => null];
        }

        $wins = array_filter($rMultiples, fn (float $r): bool => $r > 0);
        $grossWin = array_sum($wins);
        $grossLoss = abs(array_sum(array_filter($rMultiples, fn (float $r): bool => $r < 0)));

        return [
            'n' => $n,
            'win_rate' => round(count($wins) / $n * 100, 1),
            'expectancy' => round(array_sum($rMultiples) / $n, 3),
            'profit_factor' => $grossLoss > 0 ? round($grossWin / $grossLoss, 2) : ($grossWin > 0 ? 99.0 : null),
        ];
    }
}
