<?php

namespace App\Console\Commands;

use App\Services\Strategy\MarketScanService;
use Illuminate\Console\Command;

/**
 * Runs the whole-market scan on demand (the trading engine also runs it after each candle close).
 * A manual run records and displays signals but never places trades.
 */
class MarketScanCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'crypto:scan {--interval=1h} {--force : Scan even if this candle was already scanned}';

    /**
     * @var string
     */
    protected $description = 'Scan the liquid futures universe with the strategy engine and store results for the dashboard';

    public function handle(MarketScanService $scanner): int
    {
        $interval = (string) $this->option('interval');

        if (! $this->option('force') && ! $scanner->isScanDue($interval)) {
            $this->line('Scan for the current candle already done. Use --force to rescan.');

            return self::SUCCESS;
        }

        $result = $scanner->runScan($interval, (bool) $this->option('force'));
        $this->info($result['message']);

        $rows = array_filter($result['rows'], fn (array $row): bool => $row['signal'] !== null);
        if ($rows !== []) {
            $this->table(['Symbol', 'Side', 'Setup', 'Grade', 'AI', 'Tradable'], array_map(fn (array $row): array => [
                $row['symbol'],
                $row['signal']['side'],
                $row['signal']['setup_label'],
                $row['signal']['grade'],
                $row['signal']['ai_probability'] !== null ? round($row['signal']['ai_probability'] * 100).'%' : '-',
                $row['signal']['tradable'] ? 'yes' : 'no: '.implode('; ', $row['signal']['failed_filters']),
            ], $rows));
        }

        return self::SUCCESS;
    }
}
