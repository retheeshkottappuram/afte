<?php

namespace App\Console\Commands;

use App\Models\TradingAccount;
use Illuminate\Console\Command;

/**
 * Restart progress tracking from a new starting balance (after adding funds) without touching trade history.
 */
class SetBaselineCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'trade:set-baseline {amount : The new starting balance in USDT} {--mode=live : paper or live}';

    /**
     * @var string
     */
    protected $description = 'Set the starting balance used for progress and drawdown (e.g. after adding funds)';

    public function handle(): int
    {
        $amount = (float) $this->argument('amount');
        $mode = strtolower((string) $this->option('mode'));
        if ($amount <= 0 || ! in_array($mode, ['paper', 'live'], true)) {
            $this->error('Usage: trade:set-baseline 24 --mode=live (amount > 0, mode paper or live)');

            return self::FAILURE;
        }

        $account = TradingAccount::getForMode($mode);
        $account->setBaseline($amount);

        $this->info(sprintf('%s account: starting balance $%.2f, peak $%.2f. Trade history is unchanged.', ucfirst($mode), $amount, $account->peak_equity));

        return self::SUCCESS;
    }
}
