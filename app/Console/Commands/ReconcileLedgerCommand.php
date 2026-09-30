<?php

namespace App\Console\Commands;

use App\Models\Trade;
use App\Services\Trading\TradeReconciler;
use Illuminate\Console\Command;
use Throwable;

class ReconcileLedgerCommand extends Command
{
    protected $signature = 'trade:reconcile-ledger {--mode=live : Mode to reconcile (live, paper)} {--force : Reconcile all closed trades even if already reconciled}';

    protected $description = 'Reconcile existing closed trades against true Binance fills and income records.';

    public function handle(TradeReconciler $reconciler): int
    {
        $mode = (string) $this->option('mode');
        $force = (bool) $this->option('force');

        $query = Trade::where('mode', $mode)->where('status', 'CLOSED');

        if (! $force) {
            $query->where(function ($q): void {
                $q->whereNull('gross_pnl')
                    ->orWhere('gross_pnl', 0.0)
                    ->orWhereNull('commission')
                    ->orWhere('commission', 0.0)
                    ->orWhereIn('exit_reason', ['EXCHANGE_CLOSED', 'EXCHANGE_OR_MANUAL_CLOSE']);
            });
        }

        $trades = $query->orderBy('opened_at')->get();
        $this->info("Found {$trades->count()} closed trades in {$mode} mode to reconcile.");

        $reconciledCount = 0;
        $failedCount = 0;

        $progressBar = $this->output->createProgressBar($trades->count());
        $progressBar->start();

        foreach ($trades as $trade) {
            try {
                $reconciler->reconcileClosedTrade($trade, $trade->binance_exit_order_id, $trade->exit_reason);
                $reconciledCount++;
            } catch (Throwable $e) {
                $failedCount++;
                $this->newLine();
                $this->warn("Skipped #{$trade->id} ({$trade->symbol}): {$e->getMessage()}");
            }
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info('================================================================');
        $this->info("Reconciliation Complete: {$reconciledCount} reconciled, {$failedCount} skipped.");
        $this->info('================================================================');

        return Command::SUCCESS;
    }
}
