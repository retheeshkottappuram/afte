<?php

namespace App\Console\Commands;

use App\Services\Strategy\MarketScanService;
use App\Services\Strategy\SymbolAnalyzer;
use App\Services\Trading\BacktestingEngine;
use Illuminate\Console\Command;

class BacktestCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'trade:backtest
                            {symbols?* : Symbols to test (default: top liquid universe)}
                            {--months=12 : History length in months}
                            {--interval=1h : Base timeframe}
                            {--top=20 : Number of top-volume symbols when none are given}
                            {--balance=5.0 : Starting capital for the portfolio simulation}
                            {--seed : Store results as backtest signals so setup stats and the AI model have data from day one}
                            {--setups= : Comma-separated setups included in the portfolio simulation (default: core setups)}
                            {--intrabar : Squeeze breakouts enter when price trades through the level (minute watcher) instead of at the candle close}';

    /**
     * @var string
     */
    protected $description = 'Backtest the live strategy (same engine, exits, fees and risk rules) on Binance history';

    public function handle(BacktestingEngine $engine, SymbolAnalyzer $analyzer): int
    {
        $interval = (string) $this->option('interval');
        $symbols = array_map('strtoupper', (array) $this->argument('symbols'));

        if ($symbols === []) {
            $symbols = array_keys($analyzer->universe((int) $this->option('top')));
        }

        if ($symbols === []) {
            $this->error('No symbols to test (could not load the futures universe).');

            return self::FAILURE;
        }

        $barSeconds = MarketScanService::barSeconds($interval);
        $endMs = intdiv(time(), $barSeconds) * $barSeconds * 1000;
        $startMs = $endMs - (int) $this->option('months') * 30 * 86400 * 1000;

        $this->info(sprintf('Backtesting %d symbols on %s from %s to %s...', count($symbols), $interval, date('Y-m-d', intdiv($startMs, 1000)), date('Y-m-d', intdiv($endMs, 1000))));

        $result = $engine->runPortfolio(
            $symbols,
            $interval,
            $startMs,
            $endMs,
            (float) $this->option('balance'),
            (bool) $this->option('seed'),
            fn (string $symbol, int $count) => $this->line("  {$symbol}: {$count} signals"),
            $this->option('setups') ? array_map('trim', explode(',', strtoupper((string) $this->option('setups')))) : null,
            (bool) $this->option('intrabar')
        );

        $this->newLine();
        $this->info('Per setup (fees and slippage included, R = initial risk)');
        $this->table(['Setup', 'Type', 'Trades', 'Win %', 'Avg R', 'Profit factor'], array_map(fn (array $s): array => [
            $s['label'],
            $s['shadow'] ? 'shadow' : 'core',
            $s['n'],
            $s['win_rate'] ?? '-',
            $s['expectancy'] ?? '-',
            $s['profit_factor'] ?? '-',
        ], $result['setups']));

        $this->info("Portfolio simulation from \${$result['initial_balance']} (live risk and position limits) using: ".implode(', ', $result['portfolio_setups']));
        $this->table(['Metric', 'Value'], [
            ['Trades taken', $result['total_trades']],
            ['Win rate', $result['win_rate'].'%'],
            ['Expectancy', $result['expectancy_r'].'R per trade'],
            ['Profit factor', $result['profit_factor']],
            ['Final balance', '$'.$result['final_balance'].' ('.$result['net_profit_pct'].'%)'],
            ['Max drawdown', $result['max_drawdown_pct'].'%'],
            ['Longest losing streak', $result['longest_losing_streak']],
        ]);

        $mc = $result['monte_carlo'];
        if ($mc['runs'] > 0) {
            $this->info("Monte Carlo ({$mc['runs']} bootstrapped years of the same trade count)");
            $this->table(['Metric', 'Value'], [
                ['Chance of falling below $4 (cannot trade)', $mc['ruin_probability'].'%'],
                ['Chance of doubling', $mc['double_probability'].'%'],
                ['Median final balance', '$'.$mc['median_final']],
                ['10th / 90th percentile', '$'.$mc['p10_final'].' / $'.$mc['p90_final']],
            ]);
        }

        $gate = $result['total_trades'] >= 200 && $result['profit_factor'] >= 1.3;
        $this->{$gate ? 'info' : 'warn'}($gate
            ? 'GO-LIVE GATE (backtest part): PASSED. Next: 2+ weeks of paper trading.'
            : 'GO-LIVE GATE (backtest part): NOT PASSED (needs >= 200 trades and profit factor >= 1.3). Keep trading on paper.');

        foreach ($result['errors'] as $symbol => $error) {
            $this->warn("{$symbol}: {$error}");
        }

        return self::SUCCESS;
    }
}
