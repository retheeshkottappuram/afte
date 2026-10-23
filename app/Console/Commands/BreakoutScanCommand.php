<?php

namespace App\Console\Commands;

use App\Services\Breakout\BreakoutStrategyService;
use App\Services\Breakout\EbdConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * EBD v1 scanner, run every minute by the scheduler (routes/console.php). Signals and alerts only:
 * it never places orders. A cache lock stops two runs from overlapping.
 */
class BreakoutScanCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'breakout:scan
        {--symbols= : Comma-separated symbols instead of the whole universe}
        {--force-bar : Run the closed-candle pass now even if this 5m candle was already processed}
        {--dry-run : Evaluate and print only: nothing is stored or sent}';

    /**
     * @var string
     */
    protected $description = 'Early Breakout Detection (EBD v1): 5m compression, live early alerts and closed-candle confirmation';

    public function handle(EbdConfig $config): int
    {
        if (! BreakoutStrategyService::enabled()) {
            if ($this->getOutput()->isVerbose()) {
                $this->line('EBD is disabled (breakout.enabled / trading.strategy.breakout_engine).');
            }

            return self::SUCCESS;
        }

        try {
            $config->validate();
        } catch (InvalidArgumentException $e) {
            Log::error($e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $lock = Cache::lock('ebd-scan', $config->int('scan.lock_seconds'));
        if (! $lock->get()) {
            $this->line('Another breakout:scan is still running; skipped.');

            return self::SUCCESS;
        }

        try {
            $symbols = $this->option('symbols') ? array_filter(array_map('trim', explode(',', (string) $this->option('symbols')))) : null;
            $metrics = app(BreakoutStrategyService::class)->run(
                (int) floor(microtime(true) * 1000),
                (bool) $this->option('force-bar') || (bool) $this->option('dry-run'),
                $symbols,
                (bool) $this->option('dry-run'),
            );
        } finally {
            $lock->release();
        }

        $this->info(sprintf('EBD: %d symbols, %d evaluated, %d rejected, %d armed, %d events, %d sent, %d duplicates prevented, %.1fs%s',
            $metrics['symbols'], $metrics['evaluated'], $metrics['rejected_count'], $metrics['armed'], count($metrics['events']),
            $metrics['notified'], $metrics['duplicates_prevented'], $metrics['duration_s'], $metrics['bar_pass'] ? ' (closed-candle pass)' : ''));
        foreach ($metrics['events'] as $event) {
            $this->line('  '.$event);
        }
        if ($this->getOutput()->isVerbose()) {
            foreach ($metrics['rejected'] as $symbol => $why) {
                $this->line("  skip {$symbol}: {$why}");
            }
        }
        foreach ($metrics['errors'] as $error) {
            $this->warn('  '.$error);
        }

        return self::SUCCESS;
    }
}
