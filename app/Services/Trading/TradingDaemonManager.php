<?php

namespace App\Services\Trading;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\AI\SignalValidator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class TradingDaemonManager
{
    public const CACHE_HEARTBEAT_KEY = 'trading:daemon:heartbeat';

    public const CACHE_PID_KEY = 'trading:daemon:pid';

    public const CACHE_STATS_KEY = 'trading:daemon:stats';

    public const CACHE_STATUS_KEY = 'trading:daemon:status';

    public const CACHE_STOP_KEY = 'trading:daemon:stop';

    public const CACHE_REVIVE_LOCK = 'trading:daemon:revive_lock';

    public function __construct(
        protected MarketEngine $marketEngine,
        protected SignalEngine $signalEngine,
        protected SignalValidator $validator,
        protected DynamicTradeManager $tradeManager,
        protected OrderExecutor $executor,
        protected ExchangePositionSync $exchangeSync
    ) {}

    /**
     * Get live status and diagnostics of the 24/7 autonomous trading daemon.
     *
     * @return array<string, mixed>
     */
    public function status(?string $mode = null): array
    {
        $mode = $mode ?: config('trading.mode', 'live');
        $account = TradingAccount::getForMode($mode);

        $heartbeat = (int) Cache::get(self::CACHE_HEARTBEAT_KEY, 0);
        $diffSeconds = $heartbeat > 0 ? (now()->timestamp - $heartbeat) : 9999;

        // Daemon writes heartbeat in continuous mode (every 2-5s) or scheduled cron mode (every 60s)
        $maxHeartbeatAge = (int) config('trading.daemon_heartbeat_timeout', 90);
        $isRunning = ($diffSeconds <= $maxHeartbeatAge);

        // Auto-Revive Watchdog: Only trigger background process spawning if daemon_auto_spawn is explicitly enabled
        $autoSpawn = (bool) config('trading.daemon_auto_spawn', true);
        if ($autoSpawn && $account->is_running && $diffSeconds > $maxHeartbeatAge) {
            if (Cache::add(self::CACHE_REVIVE_LOCK, true, 30)) {
                Log::warning("[TradingDaemon] Auto-Revive triggered: daemon silent for {$diffSeconds}s while auto-trading is enabled.");
                $this->launchBackgroundProcess($mode);
            }
        }

        $cachedStatus = (string) Cache::get(self::CACHE_STATUS_KEY, 'STOPPED');
        if (! $isRunning) {
            $cachedStatus = 'STOPPED';
        }

        $stats = (array) Cache::get(self::CACHE_STATS_KEY, []);
        $pid = Cache::get(self::CACHE_PID_KEY);

        $phpCli = $this->resolvePhpCliBinary();

        return [
            'is_running' => $isRunning,
            'is_active' => $isRunning,
            'status' => $isRunning ? ($cachedStatus === 'STOPPED' ? 'RUNNING' : $cachedStatus) : 'STOPPED',
            'heartbeat_ago_sec' => $heartbeat > 0 ? $diffSeconds : null,
            'heartbeat_age_seconds' => $diffSeconds,
            'heartbeat' => $diffSeconds,
            'last_heartbeat' => $heartbeat > 0 ? Carbon::createFromTimestamp($heartbeat)->toIso8601String() : null,
            'pid' => $isRunning ? $pid : null,
            'uptime_human' => isset($stats['started_at']) ? Carbon::parse($stats['started_at'])->diffForHumans(null, true) : ($isRunning ? 'Active' : null),
            'cycles_count' => (int) ($stats['loop_count'] ?? 0),
            'mode' => $mode,
            'account_is_running' => (bool) $account->is_running,
            'can_trade' => $account->canTrade(),
            'kill_switch' => (bool) $account->kill_switch,
            'paused_until' => $account->paused_until?->toIso8601String(),
            'stats' => array_merge([
                'loop_count' => 0,
                'managed_positions_count' => 0,
                'closed_trades_count' => 0,
                'signals_scanned_count' => 0,
                'orders_opened_count' => 0,
                'last_tick_at' => null,
                'last_scan_at' => null,
                'memory_mb' => 0.0,
            ], $stats),
            'php_cli' => $phpCli,
            'os_family' => PHP_OS_FAMILY,
        ];
    }

    /**
     * Start the 24/7 background trading daemon.
     *
     * @return array{success: bool, message: string, is_running: bool}
     */
    public function start(string $mode = 'live'): array
    {
        if ($mode === 'live' && ! config('trading.allow_live_trading', true)) {
            return [
                'success' => false,
                'message' => 'LIVE trading daemon cannot be started from this environment (ALLOW_LIVE_TRADING is false).',
                'is_running' => false,
            ];
        }

        $account = TradingAccount::getForMode($mode);
        $account->is_running = true;
        $account->save();

        Cache::forget(self::CACHE_STOP_KEY);

        $stopFile = storage_path('framework/stop-trading-daemon');
        if (file_exists($stopFile)) {
            @unlink($stopFile);
        }

        // Check if already actively running
        $heartbeat = (int) Cache::get(self::CACHE_HEARTBEAT_KEY, 0);
        if ($heartbeat > 0 && (now()->timestamp - $heartbeat) <= 15) {
            return [
                'success' => true,
                'message' => '24/7 Trading Daemon is already actively running.',
                'is_running' => true,
            ];
        }

        // On environments where background auto-spawning is disabled:
        if (! config('trading.daemon_auto_spawn', true)) {
            Cache::put(self::CACHE_STATUS_KEY, 'RUNNING', 120);

            return [
                'success' => true,
                'message' => 'Auto-trading activated. Scheduled cron engine will execute on the next cycle.',
                'is_running' => true,
            ];
        }

        return $this->launchBackgroundProcess($mode);
    }

    /**
     * Launch the background process cross-platform.
     *
     * @return array{success: bool, message: string, is_running: bool}
     */
    protected function launchBackgroundProcess(string $mode): array
    {
        $phpCli = $this->resolvePhpCliBinary();
        $artisanPath = base_path('artisan');
        $logPath = storage_path('logs/trading_daemon.log');

        if (! is_dir(storage_path('logs'))) {
            @mkdir(storage_path('logs'), 0755, true);
        }

        $isWindows = (PHP_OS_FAMILY === 'Windows');
        $launched = false;
        $capturedPid = null;
        $errorMessage = null;

        try {
            if ($isWindows) {
                $headlessVbs = base_path('start-trading-daemon-headless.vbs');
                $bgBat = base_path('start-trading-daemon-bg.bat');
                if (file_exists($headlessVbs)) {
                    pclose(popen("wscript.exe \"{$headlessVbs}\" {$mode}", 'r'));
                    $launched = true;
                } elseif (file_exists($bgBat)) {
                    pclose(popen("start /B \"\" \"{$bgBat}\" {$mode}", 'r'));
                    $launched = true;
                } else {
                    $basePath = base_path();
                    $cmd = "cmd /c \"cd /d \"{$basePath}\" && start /B \"\" \"{$phpCli}\" artisan trade:daemon --mode={$mode} --start >> \"{$logPath}\" 2>&1\"";
                    pclose(popen($cmd, 'r'));
                    $launched = true;
                }
            } else {
                // Production Linux VPS: use nohup with stdin closed (/dev/null) so PHP-FPM worker recycling never kills the daemon!
                $cmd = sprintf(
                    'nohup %s %s trade:daemon --mode=%s --start </dev/null >> %s 2>&1 & echo $!',
                    escapeshellarg($phpCli),
                    escapeshellarg($artisanPath),
                    escapeshellarg($mode),
                    escapeshellarg($logPath)
                );

                if (function_exists('exec')) {
                    $pidOutput = trim((string) exec($cmd));
                    if (! empty($pidOutput) && is_numeric($pidOutput)) {
                        $capturedPid = (int) $pidOutput;
                        Cache::put(self::CACHE_PID_KEY, $capturedPid, 3600);
                        $launched = true;
                    }
                } elseif (function_exists('proc_open')) {
                    $descriptorspec = [
                        0 => ['file', '/dev/null', 'r'],
                        1 => ['file', $logPath, 'a'],
                        2 => ['file', $logPath, 'a'],
                    ];
                    $proc = proc_open("nohup {$phpCli} {$artisanPath} trade:daemon --mode={$mode} --start </dev/null >> {$logPath} 2>&1 &", $descriptorspec, $pipes);
                    if (is_resource($proc)) {
                        $st = proc_get_status($proc);
                        $capturedPid = $st['pid'] ?? null;
                        if ($capturedPid) {
                            Cache::put(self::CACHE_PID_KEY, (int) $capturedPid, 3600);
                        }
                        $launched = true;
                    }
                } elseif (function_exists('popen')) {
                    $handle = popen($cmd, 'r');
                    if ($handle !== false) {
                        pclose($handle);
                        $launched = true;
                    }
                }
            }
        } catch (Throwable $e) {
            Log::error('Failed to spawn 24/7 trading daemon: '.$e->getMessage());
            $errorMessage = $e->getMessage();
        }

        // Brief delay to allow initial spinup
        usleep(1200000); // 1.2s

        $heartbeat = (int) Cache::get(self::CACHE_HEARTBEAT_KEY, 0);
        $diffSeconds = $heartbeat > 0 ? (now()->timestamp - $heartbeat) : 9999;

        if ($diffSeconds <= 15) {
            return [
                'success' => true,
                'message' => '24/7 Autonomous Trading Daemon is online and actively monitoring markets.',
                'is_running' => true,
            ];
        }

        if ($launched) {
            Cache::put(self::CACHE_STATUS_KEY, 'STARTING', 30);

            return [
                'success' => true,
                'message' => '24/7 Trading Daemon process spawned. First cycle is initializing...',
                'is_running' => true,
            ];
        }

        return [
            'success' => false,
            'message' => 'Could not spawn daemon process directly: '.($errorMessage ?: 'Unknown error. Fallback scheduler is configured.'),
            'is_running' => false,
        ];
    }

    /**
     * Stop the 24/7 background trading daemon.
     *
     * @return array{success: bool, message: string, is_running: bool}
     */
    public function stop(string $mode = 'live'): array
    {
        $account = TradingAccount::getForMode($mode);
        $account->is_running = false;
        $account->save();

        Cache::put(self::CACHE_STOP_KEY, true, 120);
        Cache::put(self::CACHE_STATUS_KEY, 'STOPPED', 3600);

        $stopFile = storage_path('framework/stop-trading-daemon');
        @file_put_contents($stopFile, (string) now()->timestamp);

        if (function_exists('posix_kill')) {
            $pid = (int) Cache::get(self::CACHE_PID_KEY, 0);
            if ($pid > 0) {
                @posix_kill($pid, 15);
            }
        }

        return [
            'success' => true,
            'message' => 'Stop signal sent to 24/7 Trading Daemon. Trading paused gracefully.',
            'is_running' => false,
        ];
    }

    /**
     * Run a single complete execution tick (used by scheduled cron and fallback ticks).
     *
     * @return array<string, mixed>
     */
    public function tickOnce(string $mode = 'live'): array
    {
        $account = TradingAccount::getForMode($mode);

        // Record heartbeat timestamp for UI monitoring
        Cache::put(self::CACHE_HEARTBEAT_KEY, time(), 120);

        $currentPid = getmypid() ?: null;
        if ($currentPid) {
            Cache::put(self::CACHE_PID_KEY, $currentPid, 120);
        }

        $stats = (array) Cache::get(self::CACHE_STATS_KEY, []);
        $stats['loop_count'] = ((int) ($stats['loop_count'] ?? 0)) + 1;
        $stats['pid'] = $currentPid ?: ($stats['pid'] ?? null);
        $stats['last_tick_at'] = now()->toIso8601String();
        Cache::put(self::CACHE_STATS_KEY, $stats, 120);

        // 0. Live Binance Position & Balance Sync
        if ($mode === 'live') {
            try {
                $this->exchangeSync->syncLiveAccountAndPositions($account, $mode);
                $account->refresh();
            } catch (Throwable $e) {
                Log::warning("Tick live sync error: {$e->getMessage()}");
            }
        }

        // 1. Position management always protects open trades
        $openTrades = Trade::where('mode', $mode)
            ->where('status', 'OPEN')
            ->get();

        $managedCount = 0;
        $closedTrades = [];

        foreach ($openTrades as $trade) {
            try {
                $res = $this->tradeManager->manageTrade($trade);
                $managedCount++;
                if (($res['status'] ?? '') === 'closed') {
                    $closedTrades[] = "{$trade->symbol} closed ({$res['message']})";
                }
            } catch (Throwable $e) {
                Log::warning("Error managing trade {$trade->symbol}: {$e->getMessage()}");
            }
        }

        // 2. Scan and execute if account is permitted
        $scannedCount = 0;
        $openedTrade = null;

        if ($account->canTrade()) {
            try {
                $btcBase = $this->marketEngine->getBtcBaseKlines();
                $symbols = $this->marketEngine->getScannableSymbols();
                $candidates = array_slice($symbols, 0, 15);

                foreach ($candidates as $sym) {
                    $scannedCount++;
                    try {
                        $klines = $this->marketEngine->getMultiTimeframeKlines($sym);
                        $eval = $this->signalEngine->evaluate($sym, $klines['base'], $klines['htf1'], $klines['htf2'], $btcBase);

                        if ($eval !== null && $eval['score'] >= 80) {
                            $ai = $this->validator->validate($eval, $klines['base']);

                            if ($ai['approved']) {
                                $execResult = $this->executor->executeSignal($eval, $ai, $mode);
                                if ($execResult['status'] === 'opened') {
                                    $openedTrade = "{$sym} {$eval['direction']} opened!";
                                    Log::info("[AutonomousTrader] Order Executed: {$sym} {$eval['direction']} - {$execResult['message']}");
                                    break;
                                }
                            }
                        }
                    } catch (Throwable) {
                        // Skip individual symbol error
                    }
                }
            } catch (Throwable $e) {
                Log::error("[AutonomousTrader] Market scan error: {$e->getMessage()}");
            }
        }

        return [
            'is_running' => (bool) $account->is_running,
            'account_is_running' => (bool) $account->is_running,
            'managed_positions' => $managedCount,
            'closed_positions' => $closedTrades,
            'scanned_symbols' => $scannedCount,
            'opened_trade' => $openedTrade,
        ];
    }

    /**
     * Get recent log lines from the daemon log file.
     *
     * @return array<int, string>
     */
    public function getRecentLogs(int $maxLines = 40): array
    {
        $logPath = storage_path('logs/trading_daemon.log');
        if (! file_exists($logPath)) {
            return ['[Notice] No daemon log file generated yet. Start the daemon to initiate logs.'];
        }

        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || empty($lines)) {
            return ['[Notice] Daemon log is empty.'];
        }

        return array_values(array_slice($lines, -$maxLines));
    }

    /**
     * Resolve PHP CLI binary path.
     */
    public function resolvePhpCliBinary(): string
    {
        // 1. Explicit configuration or environment override
        $configuredPhp = config('trading.php_binary', env('PHP_BINARY_PATH'));
        if (! empty($configuredPhp) && is_string($configuredPhp)) {
            if ($configuredPhp === 'php' || (file_exists($configuredPhp) && is_executable($configuredPhp))) {
                return $configuredPhp;
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $laragonPhps = glob('C:\\laragon\\bin\\php\\php*\\php.exe');
            if (! empty($laragonPhps)) {
                rsort($laragonPhps);

                return $laragonPhps[0];
            }

            if (defined('PHP_BINARY') && file_exists(PHP_BINARY) && ! str_contains(strtolower(PHP_BINARY), 'httpd')) {
                return PHP_BINARY;
            }

            return 'php';
        }

        if (defined('PHP_BINARY') && file_exists(PHP_BINARY)) {
            $binName = strtolower(basename(PHP_BINARY));
            if (! str_contains($binName, 'fpm') && ! str_contains($binName, 'cgi')) {
                return PHP_BINARY;
            }
        }

        // On Linux VPS: dynamically detect PHP CLI via system PATH
        if (function_exists('exec')) {
            $whichPhp = trim((string) @exec('which php 2>/dev/null'));
            if (! empty($whichPhp) && file_exists($whichPhp) && is_executable($whichPhp)) {
                return $whichPhp;
            }
            $whichPhp84 = trim((string) @exec('which php8.4 2>/dev/null'));
            if (! empty($whichPhp84) && file_exists($whichPhp84) && is_executable($whichPhp84)) {
                return $whichPhp84;
            }
        }

        $candidates = [
            '/usr/php84/usr/bin/php', // ServerByt / StackCP PHP 8.4
            '/usr/php83/usr/bin/php', // ServerByt / StackCP PHP 8.3
            '/usr/local/bin/ea-php84',
            '/opt/cpanel/ea-php84/root/usr/bin/php',
            '/usr/local/bin/ea-php83',
            '/opt/cpanel/ea-php83/root/usr/bin/php',
            '/usr/bin/php-8.4',
            '/usr/bin/php8.4',
            '/usr/bin/php84',
            '/usr/bin/php-8.3',
            '/usr/bin/php8.3',
            '/usr/bin/php83',
            '/usr/bin/php-cli',
            '/usr/local/bin/php',
            '/usr/bin/php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
            'php',
            '/usr/bin/php',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === 'php' || (file_exists($candidate) && is_executable($candidate))) {
                return $candidate;
            }
        }

        return 'php';
    }
}
