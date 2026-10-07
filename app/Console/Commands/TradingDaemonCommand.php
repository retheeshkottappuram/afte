<?php

namespace App\Console\Commands;

use App\Models\CryptoSignal;
use App\Models\EquitySnapshot;
use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Notifications\SignalAlerts;
use App\Services\Strategy\BreakoutWatcher;
use App\Services\Strategy\MarketScanService;
use App\Services\Strategy\SetupStats;
use App\Services\Strategy\Signal;
use App\Services\Trading\DynamicTradeManager;
use App\Services\Trading\ExchangePositionSync;
use App\Services\Trading\SignalAlgoTrader;
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
 * then for ~50 seconds: manages every open trade every 5s, scans the market right after
 * each candle close, and once a minute checks squeeze coins for an intrabar breakout.
 * Then it writes a heartbeat and exits. Positions are protected by exchange-side stops between runs.
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
    protected $description = 'Cron-driven trading engine: manage open trades, scan the market each candle close, act on signals';

    public function handle(
        TradingModeManager $modeManager,
        DynamicTradeManager $tradeManager,
        ExchangePositionSync $exchangeSync,
        MarketScanService $scanner,
        SignalAlgoTrader $trader,
        SignalAlerts $alerts,
        BreakoutWatcher $watcher
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
        $interval = (string) config('trading.strategy.base_interval', '1h');
        $stats = $this->initialStats($modeManager, $scanner);

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

                // 3. Market scan right after each candle close of every scanned timeframe (one scan per pass,
                //    so open trades keep being managed in between). A crash never stops trade management.
                foreach (MarketScanService::scanIntervals() as $scanInterval) {
                    if (! $scanner->isScanDue($scanInterval)) {
                        continue;
                    }

                    try {
                        $scan = $scanner->runScan($scanInterval);
                        if ($scan['scanned']) {
                            $stats['scans']++;
                            $stats['scans_by_interval'][$scanInterval] = ['at' => now()->toIso8601String(), 'summary' => $scan['message']];
                            if ($scanInterval === $interval) {
                                $stats['last_scan_at'] = now()->toIso8601String();
                                $stats['last_scan_summary'] = $scan['message'];
                            }
                            $stats['last_error'] = null;
                            $stats['last_error_at'] = null;
                            $this->log("[{$mode}] ".($scanInterval === $interval ? '' : "{$scanInterval}: ").$scan['message']);
                            $this->actOnSignals($scan['fresh'], $mode, $account, $pausedReason, $trader, $scanner, $alerts, $stats, $scanInterval);
                            if ($scanInterval === $interval && BreakoutWatcher::alertsEnabled()) {
                                $alerts->breakoutWatchAlert((array) ($scan['watches'] ?? []));
                            }
                        }
                    } catch (Throwable $e) {
                        $retry = $scanner->releaseScan($scanInterval);
                        $this->recordError($stats, "Scan {$scanInterval} failed: ".$e->getMessage());
                        Log::error("[TradeEngine] Scan {$scanInterval} failed: {$e->getMessage()}", ['exception' => $e]);
                        $this->log("ERROR {$scanInterval} scan failed".($retry ? ' (retrying next minute)' : ' (gave up for this candle)').': '.$e->getMessage());
                    }

                    break;
                }

                // 3b. Intrabar breakout watcher, once a minute
                if (BreakoutWatcher::enabled() && Cache::add('engine:breakout-watch:'.intdiv(time(), 60), true, 120)) {
                    try {
                        foreach ($watcher->check() as $breakout) {
                            $signal = $breakout['signal'];
                            $status = null;

                            if (BreakoutWatcher::tradingEnabled()) {
                                $entriesAllowed = $pausedReason === null && $account->fresh()->canTrade();
                                $decision = $trader->processFreshSignals([$breakout], $mode, $entriesAllowed)[0] ?? null;
                                $status = $entriesAllowed ? ($decision['message'] ?? null) : 'skipped: '.($pausedReason ?? $this->accountBlockReason($account->fresh()));
                                if (($decision['status'] ?? '') === 'taken') {
                                    $stats['opened']++;
                                    $this->log("OPENED {$signal->symbol} {$signal->side} Early Breakout");
                                }
                            }

                            $this->log(sprintf('[%s] BREAKOUT %s %s at %s (level %s, volume %.1fx pace)%s', strtoupper($mode), $signal->symbol, $signal->side, $signal->entry, $breakout['level'], $breakout['volume_pace'], $status ? ' · '.preg_replace('/^\[\w+\] /', '', $status) : ''));
                            if (BreakoutWatcher::alertsEnabled()) {
                                $alerts->breakoutNowAlert($signal, $breakout['level'], $breakout['volume_pace'], $status);
                            }
                        }
                    } catch (Throwable $e) {
                        $this->recordError($stats, 'Breakout watcher: '.$e->getMessage());
                        Log::warning("[TradeEngine] Breakout watcher: {$e->getMessage()}");
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
                    'watching' => BreakoutWatcher::enabled() ? $watcher->watchingCoins() : 0,
                    'next_scan_at' => $this->nextScanAt(MarketScanService::scanIntervals()),
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
     * Let the auto-trader act on fresh signals, then log, mark and announce each decision.
     *
     * @param  array<int, array{signal: Signal, record: CryptoSignal}>  $fresh
     * @param  array<string, mixed>  $stats
     */
    protected function actOnSignals(array $fresh, string $mode, TradingAccount $account, ?string $pausedReason, SignalAlgoTrader $trader, MarketScanService $scanner, SignalAlerts $alerts, array &$stats, ?string $interval = null): void
    {
        if ($fresh === []) {
            return;
        }

        $entriesAllowed = $pausedReason === null && $account->fresh()->canTrade();
        if (! $entriesAllowed) {
            $this->log("[{$mode}] New entries blocked: ".($pausedReason ?? $this->accountBlockReason($account->fresh())));
        }

        $decisions = $trader->processFreshSignals($fresh, $mode, $entriesAllowed);

        foreach ($fresh as ['signal' => $signal, 'record' => $record]) {
            $decision = collect($decisions)->first(fn (array $d): bool => $d['symbol'] === $signal->symbol && $d['side'] === $signal->side);
            if (($decision['status'] ?? '') === 'duplicate') {
                continue;
            }

            $scanner->markAutoTrade($signal->symbol, $decision['message'] ?? '', $interval);
            $alerts->announceSignal($signal, $record->fresh(), $decision['message'] ?? null);
            $this->log(sprintf('%s %s %s %s %s · %s', strtoupper("[{$mode}]"), $signal->symbol, $signal->side, $signal->setupLabel.($signal->interval === SetupStats::baseInterval() ? '' : " {$signal->interval}"), $signal->grade ?? '-', preg_replace('/^\[\w+\] /', '', (string) ($decision['message'] ?? 'no decision'))));

            if (($decision['status'] ?? '') === 'taken') {
                $stats['opened']++;
                $this->log("OPENED {$signal->symbol} {$signal->side} {$signal->setupLabel}");
            }
        }
    }

    /**
     * Per-run counters, keeping the last scan and last error from earlier runs (the scan runs once per candle).
     *
     * @return array<string, mixed>
     */
    protected function initialStats(TradingModeManager $modeManager, MarketScanService $scanner): array
    {
        $previous = $modeManager->heartbeat()['details'];
        $results = $scanner->latestResults();

        return [
            'managed' => 0,
            'closed' => 0,
            'opened' => 0,
            'scans' => 0,
            'last_scan_at' => $results['scanned_at'] ?? ($previous['last_scan_at'] ?? null),
            'last_scan_summary' => $previous['last_scan_summary'] ?? (isset($results['symbols_scanned']) ? "Scanned {$results['symbols_scanned']} symbols, {$results['fresh_signals']} fresh signals." : null),
            'last_error' => $previous['last_error'] ?? null,
            'last_error_at' => $previous['last_error_at'] ?? null,
            'scans_by_interval' => (array) ($previous['scans_by_interval'] ?? []),
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
     * The next candle close among the scanned timeframes.
     *
     * @param  array<int, string>  $intervals
     */
    protected function nextScanAt(array $intervals): string
    {
        $next = min(array_map(function (string $interval): int {
            $bar = MarketScanService::barSeconds($interval);

            return (intdiv(now()->timestamp, $bar) + 1) * $bar;
        }, $intervals));

        return now()->setTimestamp($next)->toIso8601String();
    }

    protected function accountBlockReason(TradingAccount $account): string
    {
        return match (true) {
            (bool) $account->kill_switch => 'kill switch is active.',
            ! $account->is_running => 'auto-trading is stopped.',
            $account->paused_until !== null && $account->paused_until->isFuture() => 'paused until '.$account->paused_until->toDateTimeString().' UTC ('.($account->pause_reason ?? 'risk rule').').',
            default => 'risk rules.',
        };
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
                    app(SignalAlerts::class)->riskAlert('live', 'Live mode selected but not usable', $readiness['reason'].' No new trades will open until this is fixed.');
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
