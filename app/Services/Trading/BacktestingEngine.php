<?php

namespace App\Services\Trading;

use App\Models\CryptoSignal;
use App\Services\Crypto\BinanceClient;
use App\Services\Strategy\MarketScanService;
use App\Services\Strategy\SetupStats;
use App\Services\Strategy\Signal;
use App\Services\Strategy\SignalLedger;
use App\Services\Strategy\StrategyEngine;
use App\Services\Strategy\SymbolAnalyzer;
use App\Services\Strategy\TradeSimulator;
use Throwable;

/**
 * Backtests the exact live strategy: StrategyEngine signals (non-repainting, bar by bar)
 * managed by the shared ExitPlan via TradeSimulator, with fees and slippage, SL-first intrabar.
 * Portfolio results apply the live risk rules (2% per trade, position limits by equity).
 */
class BacktestingEngine
{
    public const SLIPPAGE = 0.0003;

    public function __construct(
        protected BinanceClient $market,
        protected StrategyEngine $engine,
        protected TradeSimulator $simulator,
        protected RiskManager $riskManager,
        protected SignalLedger $ledger,
        protected SetupStats $stats
    ) {}

    /**
     * Single-symbol backtest used by the dashboard.
     *
     * @return array<string, mixed>
     */
    public function run(string $symbol, string $interval = '1h', int $limit = 720, float $initialBalance = 5.0): array
    {
        $barSeconds = MarketScanService::barSeconds($interval);
        $endMs = intdiv(time(), $barSeconds) * $barSeconds * 1000;
        $startMs = $endMs - max(200, $limit) * $barSeconds * 1000;

        return $this->runPortfolio([strtoupper($symbol)], $interval, $startMs, $endMs, $initialBalance);
    }

    /**
     * @param  array<int, string>  $symbols
     * @return array<string, mixed>
     */
    public function runPortfolio(array $symbols, string $interval, int $startMs, int $endMs, float $initialBalance = 5.0, bool $seedStats = false, ?callable $progress = null, ?array $portfolioSetups = null): array
    {
        $regimeInterval = SymbolAnalyzer::regimeInterval($interval);
        $barSeconds = MarketScanService::barSeconds($interval);
        $warmupMs = 300 * $barSeconds * 1000;
        $regimeBarSeconds = ['1h' => 3600, '4h' => 14400, '1d' => 86400][$regimeInterval] ?? 14400;
        $regimeWarmupMs = 300 * $regimeBarSeconds * 1000;

        $btcBase = $this->market->klinesRange('BTCUSDT', $interval, $startMs - $warmupMs, $endMs);
        $btcRegime = $this->market->klinesRange('BTCUSDT', $regimeInterval, $startMs - $regimeWarmupMs, $endMs);

        $trades = [];
        $errors = [];

        foreach ($symbols as $symbol) {
            try {
                $base = $this->market->klinesRange($symbol, $interval, $startMs - $warmupMs, $endMs);
                $regime = $this->market->klinesRange($symbol, $regimeInterval, $startMs - $regimeWarmupMs, $endMs);
                $symbolTrades = $this->backtestSymbol($symbol, $interval, $base, $regime, $symbol === 'BTCUSDT' ? null : $btcBase, $btcRegime, $startMs, $endMs);

                if ($seedStats) {
                    $this->seed($symbol, $interval, $symbolTrades);
                }

                $trades = array_merge($trades, $symbolTrades);
                if ($progress !== null) {
                    $progress($symbol, count($symbolTrades));
                }
            } catch (Throwable $e) {
                $errors[$symbol] = $e->getMessage();
            }
        }

        if ($seedStats) {
            $this->stats->flush();
        }

        usort($trades, fn (array $a, array $b): int => $a['entry_time'] <=> $b['entry_time']);

        // The portfolio and Monte Carlo only include the setups the live system would trade.
        $portfolioSetups ??= (array) config('trading.strategy.core_setups', []);
        $portfolioTrades = array_values(array_filter($trades, fn (array $t): bool => in_array($t['setup'], $portfolioSetups, true)));

        return array_merge(
            $this->portfolio($portfolioTrades, $initialBalance),
            [
                'symbols' => $symbols,
                'interval' => $interval,
                'from' => date('Y-m-d', intdiv($startMs, 1000)),
                'to' => date('Y-m-d', intdiv($endMs, 1000)),
                'setups' => $this->perSetup($trades),
                'portfolio_setups' => $portfolioSetups,
                'monte_carlo' => $this->monteCarlo(array_column(array_filter($portfolioTrades, fn (array $t): bool => $t['tradable']), 'r_multiple'), $initialBalance),
                'errors' => $errors,
            ]
        );
    }

    /**
     * Generate signals bar by bar and simulate each one on the following candles.
     *
     * @param  array<string, array<int, float|int>>  $base
     * @param  array<string, array<int, float|int>>  $regime
     * @param  array<string, array<int, float|int>>|null  $btcBase
     * @param  array<string, array<int, float|int>>|null  $btcRegime
     * @return array<int, array<string, mixed>>
     */
    public function backtestSymbol(string $symbol, string $interval, array $base, array $regime, ?array $btcBase, ?array $btcRegime, int $startMs, int $endMs): array
    {
        $bars = count($base['closes']);
        if ($bars < 320) {
            return [];
        }

        $analysis = $this->engine->analyze($base, $regime, $btcBase, $btcRegime, [
            'symbol' => $symbol,
            'interval' => $interval,
            'lookback' => $bars,
            'now_ms' => $endMs,
        ]);

        $trades = [];
        $busyUntil = 0;

        foreach ($analysis['signals'] as $signal) {
            /** @var Signal $signal */
            if ($signal->time * 1000 < $startMs) {
                continue;
            }

            $result = $this->simulator->run([
                'side' => $signal->side,
                'entry' => $signal->entry,
                'sl' => $signal->stopLoss,
                'tp1' => $signal->tp1,
                'tp2' => $signal->tp2,
                'atr' => $signal->atr,
                'time' => $signal->time,
            ], $base, MarketScanService::barSeconds($interval), self::SLIPPAGE);

            if (! $result['closed']) {
                continue;
            }

            // One position per symbol at a time, as in live trading.
            $tradable = $signal->isTradable() && $signal->time >= $busyUntil;
            if ($tradable) {
                $busyUntil = (int) $result['exit_time'];
            }

            $trades[] = [
                'symbol' => $symbol,
                'side' => $signal->side,
                'setup' => $signal->setup,
                'is_shadow' => $signal->isShadow,
                'tradable' => $tradable,
                'entry_time' => $signal->time,
                'exit_time' => $result['exit_time'],
                'r_multiple' => $result['r_multiple'],
                'outcome' => $result['outcome'],
                'mfe_r' => $result['mfe_r'],
                'mae_r' => $result['mae_r'],
                'sl_pct' => $signal->slPct(),
                'signal' => $signal,
            ];
        }

        return $trades;
    }

    /**
     * Compound tradable trades in time order with the live risk rules.
     *
     * @param  array<int, array<string, mixed>>  $trades
     * @return array<string, mixed>
     */
    protected function portfolio(array $trades, float $initialBalance): array
    {
        $riskPct = (float) config('trading.sizing.risk_per_trade_pct', 2.0) / 100;
        $equity = $initialBalance;
        $peak = $initialBalance;
        $maxDrawdown = 0.0;
        $open = [];
        $taken = [];
        $longestLosing = 0;
        $losingRun = 0;

        foreach ($trades as $trade) {
            if (! $trade['tradable'] || $trade['is_shadow']) {
                continue;
            }

            // Settle positions that closed before this entry
            foreach ($open as $key => $position) {
                if ($position['exit_time'] <= $trade['entry_time']) {
                    $equity += $position['pnl'];
                    $peak = max($peak, $equity);
                    $maxDrawdown = max($maxDrawdown, $peak > 0 ? ($peak - $equity) / $peak * 100 : 0);
                    unset($open[$key]);
                }
            }

            if (count($open) >= $this->riskManager->maxPositions($equity) || $equity <= 0) {
                continue;
            }

            // Small accounts: the exchange minimum order can force more than 2% risk; mirror RiskManager's 3% cap.
            $minRiskUsd = 5.05 * $trade['sl_pct'] / 100;
            $riskUsd = max($equity * $riskPct, $minRiskUsd);
            if ($riskUsd > $equity * 0.03 && $riskUsd > $equity * $riskPct) {
                continue;
            }

            $pnl = $riskUsd * $trade['r_multiple'];
            $open[] = ['exit_time' => $trade['exit_time'], 'pnl' => $pnl];
            $taken[] = $trade['r_multiple'];

            $losingRun = $trade['r_multiple'] < 0 ? $losingRun + 1 : 0;
            $longestLosing = max($longestLosing, $losingRun);
        }

        foreach ($open as $position) {
            $equity += $position['pnl'];
            $peak = max($peak, $equity);
            $maxDrawdown = max($maxDrawdown, $peak > 0 ? ($peak - $equity) / $peak * 100 : 0);
        }

        $summary = SetupStats::summarize($taken);
        $wins = count(array_filter($taken, fn (float $r): bool => $r > 0));

        return [
            'initial_balance' => round($initialBalance, 2),
            'final_balance' => round($equity, 2),
            'net_profit' => round($equity - $initialBalance, 2),
            'net_profit_pct' => $initialBalance > 0 ? round(($equity - $initialBalance) / $initialBalance * 100, 2) : 0.0,
            'total_trades' => count($taken),
            'wins' => $wins,
            'losses' => count($taken) - $wins,
            'win_rate' => $summary['win_rate'] ?? 0.0,
            'expectancy_r' => $summary['expectancy'] ?? 0.0,
            'profit_factor' => $summary['profit_factor'] ?? 0.0,
            'max_drawdown_pct' => round($maxDrawdown, 2),
            'longest_losing_streak' => $longestLosing,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $trades
     * @return array<string, array<string, mixed>>
     */
    protected function perSetup(array $trades): array
    {
        $out = [];
        foreach (array_keys(StrategyEngine::SETUP_LABELS) as $setup) {
            $rows = array_filter($trades, fn (array $t): bool => $t['setup'] === $setup);
            $filtered = array_filter($rows, fn (array $t): bool => $t['signal']->isTradable() || $t['is_shadow']);
            $out[$setup] = array_merge(
                ['label' => StrategyEngine::SETUP_LABELS[$setup], 'shadow' => ! in_array($setup, (array) config('trading.strategy.core_setups'), true)],
                SetupStats::summarize(array_column($filtered, 'r_multiple')),
                ['all_signals' => SetupStats::summarize(array_column($rows, 'r_multiple'))]
            );
        }

        return $out;
    }

    /**
     * Bootstrap the trade sequence many times to estimate the range of outcomes from a small start.
     *
     * @param  array<int, float>  $rMultiples
     * @return array{runs: int, ruin_probability: ?float, median_final: ?float, p10_final: ?float, p90_final: ?float, double_probability: ?float}
     */
    protected function monteCarlo(array $rMultiples, float $initialBalance, int $runs = 1000): array
    {
        $rMultiples = array_values($rMultiples);
        if (count($rMultiples) < 10) {
            return ['runs' => 0, 'ruin_probability' => null, 'median_final' => null, 'p10_final' => null, 'p90_final' => null, 'double_probability' => null];
        }

        $riskPct = (float) config('trading.sizing.risk_per_trade_pct', 2.0) / 100;
        $ruinFloor = 4.0; // below ~$4 the exchange minimum order breaches the 3% risk cap
        $finals = [];
        $ruined = 0;
        $doubled = 0;

        $count = count($rMultiples);

        for ($run = 0; $run < $runs; $run++) {
            // Bootstrap: resample the year's trades with replacement (shuffling alone cannot change a
            // fixed-fraction result, because multiplication is order-independent).
            $sequence = [];
            for ($k = 0; $k < $count; $k++) {
                $sequence[] = $rMultiples[mt_rand(0, $count - 1)];
            }
            $equity = $initialBalance;

            foreach ($sequence as $r) {
                $equity += max($equity * $riskPct, 0.05) * $r;
                if ($equity < $ruinFloor) {
                    $ruined++;
                    break;
                }
            }

            $finals[] = $equity;
            $doubled += (int) ($equity >= 2 * $initialBalance);
        }

        sort($finals);

        return [
            'runs' => $runs,
            'ruin_probability' => round($ruined / $runs * 100, 1),
            'median_final' => round($finals[intdiv($runs, 2)], 2),
            'p10_final' => round($finals[intdiv($runs, 10)], 2),
            'p90_final' => round($finals[intdiv($runs * 9, 10)], 2),
            'double_probability' => round($doubled / $runs * 100, 1),
        ];
    }

    /**
     * Store backtest signals and outcomes so setup stats and the AI model have data from day one.
     *
     * @param  array<int, array<string, mixed>>  $trades
     */
    protected function seed(string $symbol, string $interval, array $trades): void
    {
        CryptoSignal::query()->where('symbol', $symbol)->where('interval', $interval)->where('source', 'backtest')->delete();

        foreach ($trades as $trade) {
            /** @var Signal $signal */
            $signal = $trade['signal'];
            $record = $this->ledger->record($signal->withScore('B', null), 'backtest');

            if ($record->source === 'backtest') {
                $record->update([
                    'outcome' => $trade['outcome'],
                    'r_multiple' => $trade['r_multiple'],
                    'mfe_r' => $trade['mfe_r'],
                    'mae_r' => $trade['mae_r'],
                    'resolved_at' => now(),
                ]);
            }
        }
    }
}
