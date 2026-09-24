<?php

namespace App\Console\Commands;

use App\Models\CryptoSignal;
use App\Services\Crypto\BinanceClient;
use App\Services\Crypto\BreakoutDetector;
use App\Services\Crypto\MarketScanner;
use App\Services\Crypto\SignalEngine;
use App\Services\Crypto\SignalRecorder;
use App\Services\Crypto\TelegramNotifier;
use App\Services\Crypto\TimingGuard;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class WatchCryptoSignals extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crypto:watch-signals
                            {--interval= : Comma-separated timeframe intervals to monitor (e.g. 15m, 1h)}
                            {--sleep=25 : Seconds to sleep between monitoring cycles (default: 25)}
                            {--symbols= : Custom comma-separated symbols to monitor}
                            {--all : Dynamically scan the entire active Binance USDT Perpetual market}
                            {--once : Run only a single scanning cycle and exit}
                            {--dry-run : Run without sending Telegram alerts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run a continuous 24/7 background sentinel daemon to monitor coins and dispatch Telegram alerts on candle close.';

    /**
     * Execute the console command.
     */
    public function handle(
        BinanceClient $binanceClient,
        TimingGuard $timingGuard,
        BreakoutDetector $breakoutDetector
    ): int {
        // Fortify PHP execution environment for continuous 24/7 background operation
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '512M');
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        // Disable query log to prevent memory leaks in continuous loops
        try {
            DB::connection()->disableQueryLog();
        } catch (Throwable) {
        }

        $dryRun = (bool) $this->option('dry-run');
        $runOnce = (bool) $this->option('once');
        $scanAll = (bool) $this->option('all') || (bool) config('crypto.all_symbols', true);
        $sleepSeconds = max(10, (int) ($this->option('sleep') ?: 25));
        $rawIntervals = (string) ($this->option('interval') ?: config('crypto.interval', '15m'));
        $intervals = array_values(array_filter(array_map('trim', explode(',', $rawIntervals))));

        $rawSymbols = $this->option('symbols');
        if ($rawSymbols) {
            $symbols = array_values(array_filter(array_map('trim', explode(',', strtoupper((string) $rawSymbols)))));
            $scanAll = false;
        } else {
            // Default core featured coins
            $defaultFeatured = MarketScanner::CORE_PAIRS;
            $configured = (array) config('crypto.symbols', []);
            $cleanConfigured = array_values(array_filter($configured, fn ($s) => strtoupper($s) !== 'ALL'));
            $symbols = array_values(array_unique(array_merge($defaultFeatured, $cleanConfigured)));
        }

        $indicatorConfig = (array) config('crypto.indicators', []);
        $signalEngine = new SignalEngine($indicatorConfig);
        $marketScanner = new MarketScanner($binanceClient, $signalEngine, $breakoutDetector, $timingGuard);
        $telegramNotifier = new TelegramNotifier(
            (string) config('crypto.telegram.bot_token', ''),
            (string) config('crypto.telegram.chat_id', '')
        );
        $marketLabel = $binanceClient->getMarketLabel();

        $this->info('╔════════════════════════════════════════════════════════════════════════════════════╗');
        $this->info('║  ⚡ SignalAlgo PRO™ 24/7 Automated Signal Watcher Daemon Active                     ║');
        $this->info('╚════════════════════════════════════════════════════════════════════════════════════╝');
        $this->line("• Market: <fg=cyan>{$marketLabel}</>");
        $this->line('• Mode: '.($scanAll ? '<fg=magenta>[DYNAMIC WHOLE-MARKET SCANNING ACTIVE]</>' : '<fg=yellow>[FIXED SYMBOLS SCANNING]</>'));
        $this->line('• Monitored Timeframes: <fg=yellow>'.implode(', ', $intervals).'</>');
        $this->line('• Monitored Coins: <fg=green>'.($scanAll ? 'All Active Binance Futures Perpetual Pairs ($5M+ Vol)' : implode(', ', $symbols)).'</>');
        $this->line("• Loop Sleep Interval: <fg=yellow>{$sleepSeconds}s</>");
        $this->line('• Telegram Alerts: '.($dryRun ? '<fg=yellow>[DRY RUN - No Alerts]</>' : '<fg=green>[LIVE TELEGRAM ALERTING ENABLED]</>'));
        $this->line('• Process ID (PID): <fg=cyan>'.getmypid().'</>');
        $this->newLine();

        $existingStats = (array) Cache::get('crypto:daemon:stats', []);
        $loopCount = $runOnce ? (int) ($existingStats['loop_count'] ?? 0) : 0;
        $totalAlertsSent = $runOnce ? (int) ($existingStats['total_alerts_sent'] ?? 0) : 0;
        $startTime = now();

        // Clear any previous stop request if starting continuous loop
        if (! $runOnce) {
            Cache::forget('crypto:daemon:stop');
            $stopFile = storage_path('framework/stop-sentinel');
            if (file_exists($stopFile)) {
                @unlink($stopFile);
            }
        }

        // Record startup in Cache
        Cache::put('crypto:daemon:pid', getmypid(), 3600);
        Cache::put('crypto:daemon:started_at', $startTime->toIso8601String(), 86400);

        while (true) {
            $loopCount++;
            $loopStart = microtime(true);

            // Check if stop was requested via web dashboard or stop file
            $stopFile = storage_path('framework/stop-sentinel');
            if (! $runOnce && (Cache::pull('crypto:daemon:stop') || file_exists($stopFile))) {
                $this->warn('⚠️ Stop signal received from dashboard. Shutting down daemon gracefully...');
                @unlink($stopFile);
                break;
            }

            // Database connection resilience: reconnect if MySQL dropped connection during sleep
            try {
                DB::connection()->getPdo();
            } catch (Throwable $dbEx) {
                $this->warn("⚠️ Database connection interrupted ({$dbEx->getMessage()}). Reconnecting...");
                try {
                    DB::purge();
                    DB::reconnect();
                    DB::connection()->disableQueryLog();
                } catch (Throwable) {
                }
            }

            // Dynamically refresh monitored symbols and scan mode on every cycle so dashboard edits take effect immediately
            if (! $rawSymbols) {
                $symbols = MarketScanner::getMonitoredSymbols();
                $scanMode = MarketScanner::getScanMode();
                $scanAll = ($scanMode !== 'monitored_only') && ((bool) $this->option('all') || (bool) config('crypto.all_symbols', true) || $scanMode === 'whole_market' || $scanMode === 'both');
            } else {
                $scanMode = 'fixed_symbols';
            }

            $currentStatus = $runOnce ? 'ACTIVE (CRON)' : 'RUNNING';

            $modeLabel = match ($scanMode) {
                'monitored_only' => 'Monitored Coins ('.count($symbols).')',
                'whole_market' => 'Whole Market Perpetuals',
                default => 'Monitored ('.count($symbols).') + Whole Market Breakouts',
            };

            // Update Heartbeat in Cache
            Cache::put('crypto:daemon:heartbeat', now()->timestamp, 180);
            Cache::put('crypto:daemon:status', $currentStatus, 180);
            Cache::put('crypto:daemon:stats', [
                'pid' => getmypid(),
                'status' => $currentStatus,
                'loop_count' => $loopCount,
                'total_alerts_sent' => $totalAlertsSent,
                'monitored_coins' => count($symbols),
                'scan_mode' => $scanMode,
                'scan_mode_label' => $modeLabel,
                'symbols' => $symbols,
                'intervals' => $intervals,
                'started_at' => $startTime->toIso8601String(),
                'last_loop_at' => now()->toIso8601String(),
                'last_loop_duration_ms' => 0,
            ], 180);

            $this->line('<fg=gray>['.now()->format('H:i:s')."] Cycle #{$loopCount} starting ({$modeLabel})...</>");

            // =========================================================================
            // PIPELINE 1: DYNAMIC WHOLE-MARKET OPPORTUNITY SCANNER (BREAKOUTS & TRENDS)
            // =========================================================================
            if ($scanAll && $scanMode !== 'monitored_only') {
                $minVol = (float) config('crypto.min_24h_volume', 5000000.0);
                $marketSignals = $marketScanner->scanMarket(
                    minQuoteVolume24h: $minVol,
                    maxCandidateSymbols: 35,
                    baseInterval: $intervals[0] ?? '15m'
                );

                foreach ($marketSignals as $sig) {
                    $sym = $sig['symbol'];
                    $side = $sig['side'];
                    $sigType = $sig['type'];
                    $timeSec = (int) floor(($sig['candle_close_time'] ?? now()->timestamp * 1000) / 1000);
                    $timingStatus = $sig['timing_status'] ?? 'VALID';
                    $interval = $intervals[0] ?? '15m';

                    // A. PRE-BREAKOUT WATCH ALERT
                    if ($sigType === 'BREAKOUT_WATCH') {
                        $watchKey = "crypto:breakout-watch:sent:{$sym}:{$side}";
                        if (! Cache::has($watchKey) && ! $dryRun && $telegramNotifier->isConfigured()) {
                            $msg = CheckCryptoSignals::formatTelegramMessage($sym, $interval, $sig, $marketLabel);
                            $sendRes = $telegramNotifier->sendWithDetails($msg);
                            if ($sendRes['success']) {
                                Cache::put($watchKey, true, now()->addMinutes(45)); // 45m dedup
                                $totalAlertsSent++;
                                $this->info("  🟡 [BREAKOUT WATCH SENT] {$sym} {$side} (Score: {$sig['score']}, Dist: {$sig['distance_pct']}%) in {$sendRes['latency_ms']}ms");
                            }
                        }

                        continue;
                    }

                    // B. CONFIRMED BREAKOUT OR TREND CONTINUATION
                    $dedupKey = "crypto-signal:telegram-sent:{$sym}:{$interval}:{$timeSec}:{$side}";
                    if (! Cache::has($dedupKey)) {
                        // If price moved too far, send missed entry warning or suppress
                        if ($timingStatus === 'MISSED' && ! $dryRun && $telegramNotifier->isConfigured()) {
                            $missedKey = "crypto:entry-missed:sent:{$sym}:{$timeSec}";
                            if (! Cache::has($missedKey)) {
                                $msg = CheckCryptoSignals::formatTelegramMessage($sym, $interval, $sig, $marketLabel);
                                $sendRes = $telegramNotifier->sendWithDetails($msg);
                                if ($sendRes['success']) {
                                    Cache::put($missedKey, true, now()->addMinutes(30));
                                    Cache::put($dedupKey, true, now()->addDays(7));
                                    $this->warn("  ⚠️ [ENTRY MISSED ALERT SENT] {$sym} {$side} ({$sig['timing_reason']})");
                                }
                            }
                        } elseif ($timingStatus === 'VALID' && ! $dryRun && $telegramNotifier->isConfigured()) {
                            $msg = CheckCryptoSignals::formatTelegramMessage($sym, $interval, $sig, $marketLabel);
                            $sendRes = $telegramNotifier->sendWithDetails($msg);

                            if ($sendRes['success']) {
                                $totalAlertsSent++;
                                Cache::put($dedupKey, true, now()->addDays(7));
                                Cache::put("crypto-signal:{$sym}:{$interval}:{$side}", true, now()->addMinutes(35));

                                // Persist signal record in database
                                try {
                                    CryptoSignal::create([
                                        'symbol' => $sym,
                                        'market' => $marketLabel,
                                        'interval' => $interval,
                                        'side' => $side,
                                        'setup_type' => $sigType,
                                        'score' => (int) $sig['score'],
                                        'grade' => (string) ($sig['grade'] ?? 'B'),
                                        'entry_price' => (float) $sig['entry'],
                                        'stop_loss' => (float) $sig['sl'],
                                        'take_profit_1' => (float) $sig['tp1'],
                                        'take_profit_2' => (float) $sig['tp2'],
                                        'take_profit_3' => (float) $sig['tp3'],
                                        'risk_reward' => (string) ($sig['risk_reward'] ?? '1 : 2.5'),
                                        'rsi' => (float) ($sig['rsi'] ?? 50),
                                        'adx' => (float) ($sig['adx'] ?? 20),
                                        'volume_ratio' => (float) ($sig['volume_ratio'] ?? 1.0),
                                        'atr_pct' => (float) ($sig['atr_pct'] ?? 1.5),
                                        'leverage' => $sig['perpetual_options']['recommended_leverage'] ?? '5x - 10x',
                                        'margin_mode' => 'Isolated Margin',
                                        'source' => 'market_scanner_247',
                                        'telegram_sent' => true,
                                        'candle_close_time' => Carbon::createFromTimestampMs($sig['candle_close_time'] ?? now()->timestamp * 1000),
                                        'sent_at' => now(),
                                    ]);
                                } catch (Throwable $dbErr) {
                                    Log::warning("Failed to persist signal record: {$dbErr->getMessage()}");
                                }

                                $this->info("  🚀 [TELEGRAM ALERT SENT] {$sym} {$interval} {$side} ({$sigType} Grade {$sig['grade']}: {$sig['score']}/100) in {$sendRes['latency_ms']}ms!");
                            }
                        }
                    }
                }
            }

            // =========================================================================
            // PIPELINE 2: MONITORED COINS CANDLE CLOSE SYNCHRONIZATION & TELEGRAM ALERTING
            // =========================================================================
            if ($scanMode !== 'whole_market' && ! empty($symbols)) {
                foreach ($intervals as $interval) {
                    $interval = strtolower($interval);
                    $htf1 = CheckCryptoSignals::resolveHtf1Interval($interval);
                    $htf2 = CheckCryptoSignals::resolveHtf2Interval($interval);
                    $useHtf = (bool) ($indicatorConfig['use_htf1'] ?? true);

                    foreach ($symbols as $symbol) {
                        try {
                            $multiKlines = $binanceClient->fetchMultiTimeframeKlines(
                                $symbol,
                                $interval,
                                $useHtf ? $htf1 : null,
                                $useHtf ? $htf2 : null,
                                320,
                                260
                            );

                            $baseCandles = $multiKlines['base'];
                            $htf1Candles = $multiKlines['htf1'];
                            $htf2Candles = $multiKlines['htf2'];

                            if (empty($baseCandles['closes'] ?? [])) {
                                continue;
                            }

                            $history = $signalEngine->evaluateHistory($baseCandles, $htf1Candles, $htf2Candles, 140);
                            $markers = $history['markers'] ?? [];

                            if (! empty($markers)) {
                                $syncResult = SignalRecorder::syncMarkers(
                                    $symbol,
                                    $interval,
                                    $markers,
                                    $marketLabel,
                                    dispatchTelegram: ! $dryRun
                                );

                                if ($syncResult['telegram_sent']) {
                                    $totalAlertsSent++;
                                    $this->info("  🚀 [TELEGRAM ALERT SENT] {$symbol} {$interval} Candle Close Signal Dispatched!");
                                }
                            }
                        } catch (Throwable $e) {
                            Log::debug("WatchCryptoSignals marker sync error for {$symbol}: {$e->getMessage()}");
                        }
                    }
                }
            }

            $loopDurationMs = round((microtime(true) - $loopStart) * 1000, 2);

            // Update stats with loop duration
            Cache::put('crypto:daemon:stats', [
                'pid' => getmypid(),
                'status' => $currentStatus,
                'loop_count' => $loopCount,
                'total_alerts_sent' => $totalAlertsSent,
                'monitored_coins' => count($symbols),
                'scan_mode' => $scanMode,
                'scan_mode_label' => $modeLabel,
                'symbols' => $symbols,
                'intervals' => $intervals,
                'started_at' => $startTime->toIso8601String(),
                'last_loop_at' => now()->toIso8601String(),
                'last_loop_duration_ms' => $loopDurationMs,
            ], 180);

            $this->line('<fg=gray>['.now()->format('H:i:s')."] Cycle #{$loopCount} complete in {$loopDurationMs}ms (Total Alerts: {$totalAlertsSent}). Sleeping {$sleepSeconds}s...</>");
            $this->newLine();

            if ($runOnce) {
                Cache::put('crypto:daemon:heartbeat', now()->timestamp, 180);
                Cache::put('crypto:daemon:status', 'ACTIVE (CRON)', 180);
                $this->info('Completed single-cycle run (--once). Exiting.');

                return Command::SUCCESS;
            }

            // Force PHP garbage collection to keep memory usage minimal
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            sleep($sleepSeconds);
        }

        Cache::put('crypto:daemon:status', 'STOPPED', 3600);
        $this->info("Watcher Daemon stopped. Total cycles: {$loopCount} | Total alerts dispatched: {$totalAlertsSent}");

        return Command::SUCCESS;
    }
}
