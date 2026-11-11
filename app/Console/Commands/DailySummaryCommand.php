<?php

namespace App\Console\Commands;

use App\Services\Notifications\TelegramNotifier;
use App\Services\Trading\TradingModeManager;
use Illuminate\Console\Command;

class DailySummaryCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'trade:daily-summary';

    /**
     * @var string
     */
    protected $description = 'Send the daily trading summary to Telegram';

    public function handle(TelegramNotifier $alerts, TradingModeManager $modeManager): int
    {
        $alerts->dailySummary($modeManager->activeMode());
        $this->info('Daily summary sent.');

        return self::SUCCESS;
    }
}
