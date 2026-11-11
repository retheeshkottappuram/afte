<?php

namespace App\Console\Commands;

use App\Models\EquitySnapshot;
use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Notifications\TelegramNotifier;
use App\Services\Trading\DynamicTradeManager;
use App\Services\Trading\ExchangePositionSync;
use App\Services\Trading\NearBreakoutTrader;
use App\Services\Trading\TradingDaemonManager;
use App\Services\Trading\TradingModeManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cron-driven trading engine for shared hosting (no nohup / supervisor).
 *
 * The scheduler starts it every minute. It holds a lock (overlapping runs exit at once),
 * then for ~50 seconds: manages every open trade every 5s (the NEAR trail runs on the mark price) and,
 * 30s after each 1h close, runs the NEAR breakout strategy on that candle. Then it writes a heartbeat
 * and exits. Positions are protected by exchange-side stops between runs.
 */
class TradingDaemonCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'trade:engine
                            {--once : Run a single management/scan pass and exit}
                            {--seconds= : Override the work window in seconds}';

    /**
     * @var array<int, string>
     */
    protected $aliases = ['trade:daemon'];

    /**
     * @var string
     */
    protected $description = 'Cron-driven trading engine: manage open trades and run the NEAR 1h breakout strategy';

    public function handle(
        TradingModeManager $modeManager,
        DynamicTradeManager $tradeManager,
        ExchangePositionSync $exchangeSync,
        NearBreakoutTrader $near
    ): int {
        @set_time_limit(120);

        $lock = Cache::lock('trade-engine', (int) config('trading.engine.lock_seconds', 70));
        if (! $lock->get()) {
            $this->line('Another engine cycle is running. Exiting.');

            return self::SUCCESS;
        }

        $started = microtime(true);
        $window = (int) ($this->option('seconds') ?? config('trading.engine.cycle_seconds', 50));
        $manageEvery = max(2, (int) config('trading.engine.manage_every_seconds', 5));
        $stats = $this->initialStats($modeManager);

        try {
            do {
                $passStarted = microtime(true);
                $mode = $modeManager->activeMode();
                $account = TradingAccount::getForMode($mode);
                $pausedReason = $this->pausedReason($modeManager, $account, $mode);

                // 1. Live wallet balance (every ~30s)
                if (Cache::add('engine:live-sync', true, 30) && ($mode === 'live' || Trade::where('mode', 'live')->where('status', 'OPEN')->exists())) {
                    $exchangeSync->syncLiveAccountAndPositions(TradingAccount::getForMode('live'));
                }

                // 2. Manage every open trade in both modes (a mode switch never orphans a position)
                foreach (Trade::where('status', 'OPEN')->get() as $trade) {
                    $result = $tradeManager->manageTrade($trade);
                    $stats['managed']++;
                    if ($result['status'] === 'closed') {
                        $stats['closed']++;
                        $this->log("CLOSED {$result['message']}");
                    } elseif ($result['status'] === 'error') {
                        $this->recordError($stats, $result['message']);
                    }
                }

                // 3. NEAR breakout: from 30s after each 1h close, once a minute until that candle is handled (so an
                //    API error is retried). The EMA exit always runs; new entries follow pause / stop / breaker state.
                if ((NearBreakoutTrader::enabled() || NearBreakoutTrader::hasOpenTrades()) && time() % 3600 >= 30 && Cache::add('engine:near:'.intdiv(time(), 60), true, 120)) {
                    try {
                        $entriesAllowed = NearBreakoutTrader::enabled() && $pausedReason === null && $account->fresh()->canTrade();
                        $row = $near->run($mode, $entriesAllowed);
                        if ($row['action'] !== 'none') {
                            $stats['last_scan_at'] = now()->toIso8601String();
                            $stats['last_scan_summary'] = "NEAR 1h: {$row['message']}";
                            $this->log("[{$mode}] NEAR {$row['action']}: {$row['message']}");
                        }
                        if ($row['action'] === 'opened') {
                            $stats['opened']++;
                        }
                    } catch (Throwable $e) {
                        $this->recordError($stats, 'NEAR breakout: '.$e->getMessage());
                        Log::warning("[TradeEngine] NEAR breakout: {$e->getMessage()}");
                    }
                }

                // 4. Equity snapshot every 5 minutes
                if (Cache::add('engine:snapshot:'.intdiv(time(), 300), true, 600)) {
                    $account->refresh();
                    EquitySnapshot::create([
                        'mode' => $mode,
                        'balance' => $account->balance,
                        'equity' => $account->equity ?: $account->balance,
                        'open_positions' => Trade::where('mode', $mode)->where('status', 'OPEN')->count(),
                    ]);
                }

                $modeManager->recordHeartbeat([
                    'mode' => $mode,
                    'paused_reason' => $pausedReason ?? (! $account->is_running ? 'Auto-trading is stopped (open trades are still managed).' : null),
                    'open_trades' => Trade::where('status', 'OPEN')->count(),
                    'cycle_ms' => (int) round((microtime(true) - $passStarted) * 1000),
                    'next_scan_at' => now()->setTimestamp((intdiv(time(), 3600) + 1) * 3600 + 30)->toIso8601String(),
                ] + $stats);

                if ($this->option('once')) {
                    break;
                }

                $sleepFor = $manageEvery - (microtime(true) - $passStarted);
                if ($sleepFor > 0 && (microtime(true) - $started + $sleepFor) < $window) {
                    usleep((int) ($sleepFor * 1_000_000));
                }
            } while ((microtime(true) - $started) < $window);
        } catch (Throwable $e) {
            $this->recordError($stats, $e->getMessage());
            Log::error("[TradeEngine] {$e->getMessage()}", ['exception' => $e]);
            $this->log('ERROR '.$e->getMessage());
            $modeManager->recordHeartbeat(['mode' => $modeManager->activeMode()] + $stats);
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    /**
     * Per-run counters, keeping the last scan and last error from earlier runs (the scan runs once per candle).
     *
     * @return array<string, mixed>
     */
    protected function initialStats(TradingModeManager $modeManager): array
    {
        $previous = $modeManager->heartbeat()['details'];

        return [
            'managed' => 0,
            'closed' => 0,
            'opened' => 0,
            'scans' => 0,
            'last_scan_at' => $previous['last_scan_at'] ?? null,
            'last_scan_summary' => $previous['last_scan_summary'] ?? null,
            'last_error' => $previous['last_error'] ?? null,
            'last_error_at' => $previous['last_error_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    protected function recordError(array &$stats, string $message): void
    {
        $stats['last_error'] = mb_substr($message, 0, 300);
        $stats['last_error_at'] = now()->toIso8601String();
    }

    /**
     * Why new entries are blocked at the engine level (null when they are allowed).
     */
    protected function pausedReason(TradingModeManager $modeManager, TradingAccount $account, string $mode): ?string
    {
        if ($mode === 'live') {
            $readiness = $modeManager->liveReadiness();
            if (! $readiness['ready']) {
                if (Cache::add('engine:live-not-ready-alert', true, 3600)) {
                    app(TelegramNotifier::class)->notifyRiskEvent('live', 'Live mode selected but not usable', $readiness['reason'].' No new trades will open until this is fixed.');
                }

                return 'Live mode unavailable: '.$readiness['reason'];
            }
        }

        if ($account->kill_switch) {
            return 'Kill switch is active.';
        }

        return null;
    }

    protected function log(string $line): void
    {
        $this->line($line);
        TradingDaemonManager::appendLog($line);
    }
}
