<?php

namespace App\Console\Commands;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Binance\BinanceFuturesClient;
use App\Services\Trading\DynamicTradeManager;
use Illuminate\Console\Command;

class KillSwitchCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trade:kill
                            {--mode=paper : Mode to apply kill switch (paper, live, all)}
                            {--resume : Resume trading by deactivating kill switch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Activate emergency kill switch: halts bot and immediately closes all open positions';

    /**
     * Execute the console command.
     */
    public function handle(DynamicTradeManager $tradeManager, BinanceFuturesClient $client): int
    {
        $mode = (string) $this->option('mode');
        $resume = (bool) $this->option('resume');

        $modes = $mode === 'all' ? ['paper', 'live'] : [$mode];

        foreach ($modes as $m) {
            $account = TradingAccount::getForMode($m);
            $account->kill_switch = ! $resume;
            if ($resume) {
                $account->peak_equity = $account->balance;
                $account->pause_reason = null;
            }
            $account->save();

            if ($resume) {
                $this->info('✅ Kill Switch DEACTIVATED for ['.strtoupper($m).']. Trading resumed.');
            } else {
                $this->error('🚨 EMERGENCY KILL SWITCH ACTIVATED for ['.strtoupper($m).']!');

                // Close all open positions immediately at market
                $openTrades = Trade::where('mode', $m)
                    ->where('status', 'OPEN')
                    ->get();

                foreach ($openTrades as $trade) {
                    $this->warn("Closing position {$trade->symbol} ({$trade->side})...");
                    try {
                        $price = (float) $client->getMarkPrice($trade->symbol) ?: (float) $trade->entry_price;
                    } catch (\Throwable) {
                        $price = (float) $trade->entry_price;
                    }
                    $result = $tradeManager->closeTrade($trade, $price, 'KILL_SWITCH');
                    if ($result['status'] !== 'closed') {
                        $this->error("  NOT confirmed closed: {$result['message']} Close it manually on Binance.");
                    }
                }

                $this->line('All open positions terminated for ['.strtoupper($m).'].');
            }
        }

        return self::SUCCESS;
    }
}
