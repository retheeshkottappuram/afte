<?php

namespace App\Console\Commands;

use App\Services\Trading\BacktestingEngine;
use Illuminate\Console\Command;

class BacktestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trade:backtest
                            {symbol=SOLUSDT : Trading pair to test}
                            {--interval=15m : Candlestick timeframe}
                            {--limit=600 : Number of historical candles}
                            {--balance=5.0 : Initial starting capital}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run historical backtesting simulation on Binance Futures historical data';

    /**
     * Execute the console command.
     */
    public function handle(BacktestingEngine $engine): int
    {
        $symbol = strtoupper((string) $this->argument('symbol'));
        $interval = (string) $this->option('interval');
        $limit = (int) $this->option('limit');
        $balance = (float) $this->option('balance');

        $this->info("⏳ Running Backtest on Binance Futures [{$symbol} - {$interval}] over {$limit} candles...");

        $results = $engine->run($symbol, $interval, $limit, $balance);

        $this->newLine();
        $this->info('====================================================');
        $this->info("        BACKTEST PERFORMANCE REPORT [{$symbol}]");
        $this->info('====================================================');

        $pnlSign = $results['net_profit'] >= 0 ? '+' : '';
        $color = $results['net_profit'] >= 0 ? 'info' : 'error';

        $this->$color("Initial Balance:    \${$results['initial_balance']}");
        $this->$color("Final Balance:      \${$results['final_balance']}");
        $this->$color("Net Profit:         {$pnlSign}\${$results['net_profit']} ({$pnlSign}{$results['net_profit_pct']}%)");
        $this->line("Total Trades:       {$results['total_trades']}");
        $this->line("Winning Trades:     {$results['wins']}");
        $this->line("Losing Trades:      {$results['losses']}");
        $this->line("Win Rate:           {$results['win_rate']}%");
        $this->line("Profit Factor:      {$results['profit_factor']}");
        $this->line("Max Drawdown:       {$results['max_drawdown_pct']}%");
        $this->info('====================================================');

        if (! empty($results['trades'])) {
            $this->newLine();
            $this->line('Recent simulated trades:');
            $table = [];
            foreach ($results['trades'] as $t) {
                $table[] = [
                    $t['time'],
                    $t['side'],
                    '$'.$t['entry_price'],
                    '$'.$t['exit_price'],
                    ($t['net_pnl'] >= 0 ? '+' : '').'$'.$t['net_pnl'],
                    $t['pnl_pct'].'%',
                    $t['exit_reason'],
                ];
            }

            $this->table(['Time', 'Side', 'Entry', 'Exit', 'PnL ($)', 'ROE (%)', 'Exit Reason'], $table);
        }

        return self::SUCCESS;
    }
}
