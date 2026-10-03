<?php

namespace App\Console\Commands;

use App\Services\Notifications\SignalAlerts;
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

    public function handle(SignalAlerts $alerts, TradingModeManager $modeManager): int
    {
        $alerts->dailySummary($modeManager->activeMode());
        $this->info('Daily summary sent.');

        return self::SUCCESS;
    }
}
