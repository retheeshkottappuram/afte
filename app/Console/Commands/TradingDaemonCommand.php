<?php

namespace App\Console\Commands;

use App\Models\EquitySnapshot;
use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\AI\SignalValidator;
use App\Services\Trading\DynamicTradeManager;
use App\Services\Trading\ExchangePositionSync;
use App\Services\Trading\MarketEngine;
use App\Services\Trading\OrderExecutor;
use App\Services\Trading\SignalEngine;
use App\Services\Trading\TradingDaemonManager;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TradingDaemonCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trade:daemon
                            {--mode= : Override mode (paper, live)}
                            {--interval=2 : Seconds between position management cycles}
                            {--scan-interval=25 : Seconds between market scanner cycles}
                            {--start : Activate auto-trading state}
                            {--once : Run a single loop iteration and exit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Autonomous 24/7 trading daemon: continuous position management & breakout scanner';

    /**
     * Execute the console command.
     */
    public function handle(
        MarketEngine $marketEngine,
        SignalEngine $signalEngine,
        SignalValidator $validator,
        DynamicTradeManager $tradeManager,
        OrderExecutor $executor,
        ExchangePositionSync $exchangeSync
    ): int {
        // Fortify PHP execution environment for continuous 24/7 background operation
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '512M');
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        try {
            DB::connection()->disableQueryLog();
        } catch (Throwable) {
            // Ignore if connection not ready
        }

        $mode = (string) ($this->option('mode') ?: config('trading.mode', 'live'));

        if ($mode === 'live' && ! config('trading.allow_live_trading', true)) {
            $this->error('🛑 LIVE trading is strictly disabled in this environment (ALLOW_LIVE_TRADING is false).');
            $this->line('To prevent order collisions with your live production server, use --mode=paper or --mode=shadow in local development.');
            $this->line('If this is your live server, set ALLOW_LIVE_TRADING=true in your .env file or set TRADING_MODE=live.');

            return self::FAILURE;
        }

        $interval = max(1, (int) $this->option('interval'));
        $scanInterval = max(10, (int) $this->option('scan-interval'));
        $runOnce = (bool) $this->option('once');
        $daemonStartTimestamp = time();

        $account = TradingAccount::getForMode($mode);

        if ($this->option('start')) {
            // Explicit --start voids any previous stop signal
            Cache::forget(TradingDaemonManager::CACHE_STOP_KEY);
            @unlink(storage_path('framework/stop-trading-daemon'));
            $account->update(['is_running' => true]);
            $account->refresh();
        }

        $pid = getmypid() ?: 0;
        $startedAt = now()->toIso8601String();
        $statusStr = $account->is_running ? 'ACTIVE' : 'PAUSED';

        $this->logInfo("🤖 AFTE 24/7 Autonomous Daemon online [Mode: {$mode}, PID: {$pid}, Status: {$statusStr}]");
        $this->logLine("Capital: \${$account->balance} | Target: \$500.00 | Management Loop: {$interval}s | Scanner Loop: {$scanInterval}s");

        $lastScanTime = 0;
        $lastSnapshotTime = 0;
        $existingStats = (array) Cache::get(TradingDaemonManager::CACHE_STATS_KEY, []);
        $loopCount = $runOnce ? ((int) ($existingStats['loop_count'] ?? 0)) : 0;
        $totalManagedCount = $runOnce ? ((int) ($existingStats['managed_positions_count'] ?? 0)) : 0;
        $totalClosedCount = $runOnce ? ((int) ($existingStats['closed_trades_count'] ?? 0)) : 0;
        $totalScannedCount = $runOnce ? ((int) ($existingStats['signals_scanned_count'] ?? 0)) : 0;
        $totalOpenedCount = $runOnce ? ((int) ($existingStats['orders_opened_count'] ?? 0)) : 0;

        while (true) {
            $loopCount++;

            try {
                // Check and reconnect database connection if it dropped (e.g. MySQL server has gone away overnight)
                try {
                    DB::connection()->getPdo();
                } catch (Throwable) {
                    try {
                        DB::purge();
                        DB::reconnect();
                    } catch (Throwable $dbEx) {
                        $this->logWarn("Database reconnecting... ({$dbEx->getMessage()})");
                        sleep(2);

                        continue;
                    }
                }

                // 0. Check stop signals (Runtime manual stops triggered via Web Dashboard / Artisan)
                $stopFile = storage_path('framework/stop-trading-daemon');
                $hasFreshStopFile = file_exists($stopFile) && ((int) @filemtime($stopFile) >= $daemonStartTimestamp);

                if ($loopCount > 1 || ! $this->option('start')) {
                    if (Cache::has(TradingDaemonManager::CACHE_STOP_KEY) || $hasFreshStopFile) {
                        $this->logInfo('🛑 Stop signal received. Gracefully exiting 24/7 trading daemon.');
                        Cache::forget(TradingDaemonManager::CACHE_STOP_KEY);
                        @unlink($stopFile);
                        Cache::put(TradingDaemonManager::CACHE_STATUS_KEY, 'STOPPED', 120);
                        break;
                    }
                }

                try {
                    $account->refresh();
                } catch (Throwable) {
                    $account = TradingAccount::getForMode($mode);
                }

                // Write live heartbeat and diagnostics to cache for web UI
                Cache::put(TradingDaemonManager::CACHE_HEARTBEAT_KEY, time(), 120);
                Cache::put(TradingDaemonManager::CACHE_PID_KEY, $pid, 120);
                Cache::put(TradingDaemonManager::CACHE_STATUS_KEY, $account->is_running ? 'RUNNING' : 'PAUSED', 120);
                Cache::put(TradingDaemonManager::CACHE_STATS_KEY, [
                    'pid' => $pid,
                    'mode' => $mode,
                    'started_at' => $startedAt,
                    'last_tick_at' => now()->toIso8601String(),
                    'loop_count' => $loopCount,
                    'managed_positions_count' => $totalManagedCount,
                    'closed_trades_count' => $totalClosedCount,
                    'signals_scanned_count' => $totalScannedCount,
                    'orders_opened_count' => $totalOpenedCount,
                    'last_scan_at' => $lastScanTime > 0 ? Carbon::createFromTimestamp($lastScanTime)->toIso8601String() : null,
                    'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                ], 120);

                if ($account->kill_switch) {
                    $this->logError('Emergency Kill Switch is ACTIVE. Daemon pausing execution.');
                    if ($runOnce) {
                        break;
                    }
                    sleep(10);

                    continue;
                }

                // 1. Live Exchange Synchronization (Reconciles open/closed positions and balances with Binance)
                if ($mode === 'live') {
                    try {
                        $exchangeSync->syncLiveAccountAndPositions($account, $mode);
                        $account->refresh();
                    } catch (Throwable $syncEx) {
                        Log::warning("[TradingDaemon] Live exchange sync notice: {$syncEx->getMessage()}");
                    }
                }

                // 2. High-Frequency Active Position Management Loop
                try {
                    $openTrades = Trade::where('mode', $mode)
                        ->where('status', 'OPEN')
                        ->get();

                    foreach ($openTrades as $trade) {
                        try {
                            $result = $tradeManager->manageTrade($trade);
                            $totalManagedCount++;
                            if (($result['status'] ?? '') === 'closed') {
                                $totalClosedCount++;
                                $this->logWarn("Position Closed: {$trade->symbol} ({$result['message']})");
                                // Free slot opened: immediately trigger fresh market scan!
                                $lastScanTime = 0;
                            }
                        } catch (Throwable $tradeEx) {
                            Log::warning("[TradingDaemon] Trade management error on {$trade->symbol}: {$tradeEx->getMessage()}");
                        }
                    }
                } catch (Throwable $e) {
                    Log::warning("[TradingDaemon] Position management error: {$e->getMessage()}");
                }

                // 2. Scheduled Market Scanner Cycle
                $now = time();
                if ($now - $lastScanTime >= $scanInterval || $runOnce) {
                    $lastScanTime = $now;

                    if ($account->canTrade()) {
                        $btcTrend = $marketEngine->getBtcMarketTrend();
                        $btcBase = $marketEngine->getBtcBaseKlines();
                        $this->logLine('['.date('H:i:s')."] Macro BTC Trend: {$btcTrend['trend']} (\${$btcTrend['btc_price']}) | Scanning setups...");
                        try {
                            $symbols = $marketEngine->getScannableSymbols();
                            $candidates = array_slice($symbols, 0, 20);

                            foreach ($candidates as $sym) {
                                $totalScannedCount++;
                                try {
                                    $klines = $marketEngine->getMultiTimeframeKlines($sym);
                                    $eval = $signalEngine->evaluate($sym, $klines['base'], $klines['htf1'], $klines['htf2'], $btcBase);

                                    if ($eval !== null && $eval['score'] >= 80) {
                                        // Macro Market Trend Filter Gate (Permits high-strength decoupled altcoin leaders)
                                        $rsRatio = (float) ($eval['indicators']['rs_ratio'] ?? 1.0);
                                        $isDecoupledLeader = $eval['score'] >= 90 || $rsRatio >= 1.04;

                                        if ($eval['direction'] === 'LONG' && ! $btcTrend['allow_long'] && ! $isDecoupledLeader) {
                                            $this->logLine("Skipped {$sym} LONG: Counter-trend to Bearish BTC macro (RS: {$rsRatio}).");

                                            continue;
                                        }
                                        if ($eval['direction'] === 'SHORT' && ! $btcTrend['allow_short'] && ! $isDecoupledLeader) {
                                            $this->logLine("Skipped {$sym} SHORT: Counter-trend to Bullish BTC macro (RS: {$rsRatio}).");

                                            continue;
                                        }

                                        $ai = $validator->validate($eval, $klines['base']);

                                        if ($ai['approved']) {
                                            $this->logInfo("⚡ High-Confluence Setup on {$sym} ({$eval['direction']}) - Score: {$eval['score']}/100");
                                            $execResult = $executor->executeSignal($eval, $ai, $mode);

                                            if (($execResult['status'] ?? '') === 'opened') {
                                                $totalOpenedCount++;
                                                $this->logInfo("✅ Order Executed: {$execResult['message']}");
                                                break; // Executed 1 trade for this scanning cycle; multi-trade capacity will continue on subsequent cycles based on available free margin
                                            } else {
                                                $this->logLine("Execution Notice: {$execResult['message']}");
                                            }
                                        }
                                    }
                                } catch (Throwable $symEx) {
                                    // Ignore transient per-symbol errors
                                }
                            }

                            if ($totalOpenedCount === 0) {
                                $this->logLine('['.date('H:i:s')."] Scan complete: {$totalScannedCount} pairs checked. No setups met score >= 80 entry criteria on this candle.");
                            }
                        } catch (Throwable $scanEx) {
                            Log::error("[TradingDaemon] Scanner cycle error: {$scanEx->getMessage()}");
                        }
                    } else {
                        if (! $account->is_running) {
                            $this->logLine('['.date('H:i:s').'] Auto-trading is PAUSED via terminal. Waiting for start command...');
                        } elseif ($account->paused_until !== null && $account->paused_until->isFuture()) {
                            $this->logWarn('['.date('H:i:s')."] Circuit breaker cooldown active until {$account->paused_until->format('H:i:s')}");
                        }
                    }
                }

                // 3. Periodic Equity Snapshot (every 5 minutes)
                if ($now - $lastSnapshotTime >= 300) {
                    $lastSnapshotTime = $now;
                    try {
                        $openCount = Trade::where('mode', $mode)->where('status', 'OPEN')->count();
                        EquitySnapshot::create([
                            'mode' => $mode,
                            'balance' => $account->balance,
                            'equity' => $account->balance,
                            'open_positions' => $openCount,
                        ]);
                    } catch (Throwable) {
                        // Ignore snapshot failure
                    }
                }
            } catch (Throwable $loopError) {
                $this->logError("Daemon loop fault recovered: {$loopError->getMessage()}");
                if (str_contains(strtolower($loopError->getMessage()), 'gone away') || str_contains(strtolower($loopError->getMessage()), 'lost connection')) {
                    try {
                        DB::purge();
                        DB::reconnect();
                    } catch (Throwable) {
                        // Reconnection will retry on next cycle
                    }
                }
                sleep(2);
            }

            // 4. Memory Cleanup
            if ($loopCount % 25 === 0) {
                gc_collect_cycles();
            }

            if ($runOnce) {
                break;
            }

            sleep($interval);
        }

        return self::SUCCESS;
    }

    protected function logInfo(string $message): void
    {
        $this->info($message);
        Log::channel('single')->info("[TradingDaemon] {$message}");
    }

    protected function logWarn(string $message): void
    {
        $this->warn($message);
        Log::channel('single')->warning("[TradingDaemon] {$message}");
    }

    protected function logError(string $message): void
    {
        $this->error($message);
        Log::channel('single')->error("[TradingDaemon] {$message}");
    }

    protected function logLine(string $message): void
    {
        $this->line($message);
    }
}
