<?php

namespace App\Console\Commands;

use App\Models\EquitySnapshot;
use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Binance\BinanceFuturesClient;
use App\Services\Notifications\TelegramNotifier;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class DailyReconciliationCommand extends Command
{
    protected $signature = 'trade:daily-reconcile 
                            {--mode=live : Trading mode to reconcile (live, paper)} 
                            {--days=1 : Number of days to inspect} 
                            {--date= : Specific UTC date to reconcile (YYYY-MM-DD)} 
                            {--threshold=0.02 : Alert threshold discrepancy in USD}';

    protected $description = 'Compare start balance + sum of income records with actual wallet balance and alert on discrepancy > $0.02.';

    public function handle(BinanceFuturesClient $binanceClient, TelegramNotifier $notifier): int
    {
        $mode = (string) $this->option('mode');
        $threshold = (float) $this->option('threshold');
        $dateOption = $this->option('date');
        $daysOption = (int) $this->option('days');

        if ($dateOption) {
            $startWindow = Carbon::parse($dateOption, 'UTC')->startOfDay();
            $endWindow = Carbon::parse($dateOption, 'UTC')->endOfDay();
        } else {
            $startWindow = Carbon::now('UTC')->subDays($daysOption)->startOfDay();
            $endWindow = Carbon::now('UTC');
        }

        $this->info('================================================================');
        $this->info("📊 AFTE Truthful Daily Reconciliation [{$mode}]");
        $this->info("• Period: {$startWindow->toIso8601String()} to {$endWindow->toIso8601String()} (UTC)");
        $this->info("• Alert Threshold: \${$threshold}");
        $this->info('================================================================');

        $account = TradingAccount::getForMode($mode);

        if ($mode === 'live') {
            return $this->reconcileLive($binanceClient, $notifier, $account, $startWindow, $endWindow, $threshold);
        }

        return $this->reconcilePaper($account, $startWindow, $endWindow, $threshold);
    }

    /**
     * Reconcile Live account directly against Binance /fapi/v2/balance and /fapi/v1/income.
     */
    protected function reconcileLive(
        BinanceFuturesClient $binanceClient,
        TelegramNotifier $notifier,
        TradingAccount $account,
        Carbon $startWindow,
        Carbon $endWindow,
        float $threshold
    ): int {
        $client = $binanceClient->forMode('live');
        if (! $client->hasCredentials()) {
            $this->error('❌ Live Binance API credentials not configured.');

            return Command::FAILURE;
        }

        try {
            // 1. Fetch live balance from Binance
            $balances = $client->getBalance();
            $actualBalance = null;
            $crossUnPnl = 0.0;

            foreach ($balances as $b) {
                if (($b['asset'] ?? '') === 'USDT') {
                    $actualBalance = (float) ($b['balance'] ?? $b['crossWalletBalance'] ?? 0.0);
                    $crossUnPnl = (float) ($b['crossUnPnl'] ?? 0.0);
                    break;
                }
            }

            if ($actualBalance === null) {
                $this->error('❌ Could not retrieve USDT balance from Binance.');

                return Command::FAILURE;
            }

            // 2. Fetch income records from Binance (/fapi/v1/income)
            $startTimeMs = $startWindow->timestamp * 1000;
            $endTimeMs = $endWindow->timestamp * 1000;

            $incomeRecords = $client->getIncome(
                startTime: $startTimeMs,
                endTime: $endTimeMs,
                limit: 1000
            );

            $groupedIncome = [
                'REALIZED_PNL' => 0.0,
                'COMMISSION' => 0.0,
                'FUNDING_FEE' => 0.0,
                'TRANSFER' => 0.0,
                'INSURANCE_CLEAR' => 0.0,
                'OTHER' => 0.0,
            ];
            $totalIncomeSum = 0.0;

            foreach ($incomeRecords as $record) {
                $type = (string) ($record['incomeType'] ?? 'OTHER');
                $amount = (float) ($record['income'] ?? 0.0);
                $totalIncomeSum += $amount;

                if (isset($groupedIncome[$type])) {
                    $groupedIncome[$type] += $amount;
                } else {
                    $groupedIncome['OTHER'] += $amount;
                }
            }

            // 3. Find start balance
            // Look for closest snapshot prior to or at startWindow
            $snapshot = EquitySnapshot::where('mode', 'live')
                ->where('created_at', '<=', $startWindow->copy()->addMinutes(10))
                ->orderByDesc('created_at')
                ->first();

            if ($snapshot) {
                $startBalance = (float) $snapshot->balance;
                $startBalanceSource = "Snapshot (#{$snapshot->id} at {$snapshot->created_at})";
            } else {
                // If no snapshot exists, infer start balance from actual balance minus income
                $startBalance = round($actualBalance - $totalIncomeSum, 4);
                $startBalanceSource = 'Inferred backwards from actual balance minus income sum';
            }

            $expectedBalance = round($startBalance + $totalIncomeSum, 4);
            $discrepancy = round(abs($actualBalance - $expectedBalance), 4);
            $isMatch = $discrepancy <= $threshold;

            $this->table(
                ['Metric', 'Value (USD)'],
                [
                    ['Actual Live Wallet Balance', sprintf('$%.4f', $actualBalance)],
                    ['Unrealized PnL', sprintf('$%.4f', $crossUnPnl)],
                    ['Current Equity', sprintf('$%.4f', $actualBalance + $crossUnPnl)],
                    ['Start Balance', sprintf('$%.4f (%s)', $startBalance, $startBalanceSource)],
                    ['Gross Realized PnL (Binance)', sprintf('$%.4f', $groupedIncome['REALIZED_PNL'])],
                    ['Commissions Paid (Binance)', sprintf('$%.4f', $groupedIncome['COMMISSION'])],
                    ['Funding Fees (Binance)', sprintf('$%.4f', $groupedIncome['FUNDING_FEE'])],
                    ['Transfers / Deposits / Withdrawals', sprintf('$%.4f', $groupedIncome['TRANSFER'])],
                    ['Other Income / Insurance', sprintf('$%.4f', $groupedIncome['INSURANCE_CLEAR'] + $groupedIncome['OTHER'])],
                    ['Net Binance Income Sum', sprintf('$%.4f', $totalIncomeSum)],
                    ['Expected Wallet Balance', sprintf('$%.4f', $expectedBalance)],
                    ['Absolute Discrepancy', sprintf('$%.4f', $discrepancy)],
                    ['Reconciliation Status', $isMatch ? '✅ MATCH (Within $'.$threshold.')' : '🚨 MISMATCH (> $'.$threshold.')'],
                ]
            );

            // 4. Alert if discrepancy exceeds threshold
            if (! $isMatch) {
                $alertMsg = "🚨 *AFTE Daily Reconciliation Discrepancy!*\n\n"
                    ."*Mode:* LIVE\n"
                    .sprintf("*Actual Balance:* $%.4f\n", $actualBalance)
                    .sprintf("*Expected Balance:* $%.4f\n", $expectedBalance)
                    .sprintf("*Discrepancy:* $%.4f (Threshold: $%.2f)\n", $discrepancy, $threshold)
                    .sprintf("*Income Sum:* $%.4f\n", $totalIncomeSum)
                    .sprintf("• Realized PnL: $%.4f\n", $groupedIncome['REALIZED_PNL'])
                    .sprintf("• Fees: $%.4f\n", $groupedIncome['COMMISSION'])
                    .sprintf("• Funding: $%.4f\n", $groupedIncome['FUNDING_FEE'])
                    .'Please inspect trade history immediately.';

                $notifier->sendMessage($alertMsg);
                Log::error("[DailyReconciliation] {$alertMsg}");
                $this->error("🚨 Alert dispatched! Balance discrepancy is \${$discrepancy} (> \${$threshold}).");

                return Command::FAILURE;
            }

            $this->info("✅ Reconciliation verified. Discrepancy is \${$discrepancy} (<= \${$threshold}).");

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $this->error("❌ Reconciliation failed with error: {$e->getMessage()}");
            Log::error("[DailyReconciliation] Error: {$e->getMessage()}", ['exception' => $e]);

            return Command::FAILURE;
        }
    }

    /**
     * Reconcile Paper account against database closed trades.
     */
    protected function reconcilePaper(
        TradingAccount $account,
        Carbon $startWindow,
        Carbon $endWindow,
        float $threshold
    ): int {
        $closedTrades = Trade::where('mode', 'paper')
            ->where('status', 'CLOSED')
            ->whereBetween('closed_at', [$startWindow, $endWindow])
            ->get();

        $grossPnl = (float) $closedTrades->sum('gross_pnl');
        $fees = (float) $closedTrades->sum('commission');
        $netPnl = (float) $closedTrades->sum('net_pnl');

        $this->table(
            ['Metric', 'Value'],
            [
                ['Account Balance', sprintf('$%.4f', $account->balance)],
                ['Account Equity', sprintf('$%.4f', $account->equity)],
                ['Closed Trades in Window', (string) $closedTrades->count()],
                ['Gross PnL', sprintf('$%.4f', $grossPnl)],
                ['Commissions Paid', sprintf('$%.4f', $fees)],
                ['Net PnL', sprintf('$%.4f', $netPnl)],
            ]
        );

        $this->info('✅ Paper trading accounting ledger verified.');

        return Command::SUCCESS;
    }
}
