<?php

namespace App\Console\Commands;

use App\Models\EquitySnapshot;
use App\Models\Trade;
use App\Models\TradingAccount;
use App\Models\TradingSignal;
use Illuminate\Console\Command;

class ResetPaperCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trade:reset-paper {--balance=5.0 : Initial seed capital to reset to}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset paper trading account back to seed capital ($5.00) and clear paper history';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $seed = (float) $this->option('balance');

        $account = TradingAccount::getForMode('paper');
        $account->initial_balance = $seed;
        $account->balance = $seed;
        $account->equity = $seed;
        $account->peak_equity = $seed;
        $account->total_trades = 0;
        $account->winning_trades = 0;
        $account->losing_trades = 0;
        $account->consecutive_losses = 0;
        $account->consecutive_wins = 0;
        $account->paused_until = null;
        $account->kill_switch = false;
        $account->save();

        Trade::where('mode', 'paper')->delete();
        EquitySnapshot::where('mode', 'paper')->delete();
        TradingSignal::truncate();

        EquitySnapshot::create([
            'mode' => 'paper',
            'balance' => $seed,
            'equity' => $seed,
            'open_positions' => 0,
        ]);

        $this->info("✅ Paper account successfully reset to \${$seed} seed capital.");

        return self::SUCCESS;
    }
}
