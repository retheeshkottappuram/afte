<?php

namespace App\Http\Controllers;

use App\Services\Crypto\MarketScanner;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class DaemonController extends Controller
{
    /**
     * Get the live status and stats of the background watcher daemon.
     */
    public function status(): JsonResponse
    {
        $sentinelEnabled = (bool) Cache::get('crypto:sentinel:enabled', false);
        $heartbeat = (int) Cache::get('crypto:daemon:heartbeat', 0);
        $diffSeconds = $heartbeat > 0 ? (now()->timestamp - $heartbeat) : 9999;

        // In continuous daemon mode, heartbeat updates every 25s; in cron mode, every 60s.
        $isRunning = ($diffSeconds <= 90);

        // Auto-Revive Watchdog: If user started sentinel, but process died or was terminated by host (> 75s silent)
        if ($sentinelEnabled && $diffSeconds > 75) {
            $lockKey = 'crypto:sentinel:pulse_lock';
            if (Cache::add($lockKey, true, 45)) {
                $this->launchBackgroundDaemon();
            }
        }

        $cachedStatus = (string) Cache::get('crypto:daemon:status', 'STOPPED');
        if (! $isRunning) {
            $cachedStatus = 'STOPPED';
        }

        $stats = (array) Cache::get('crypto:daemon:stats', []);
        $stats['is_running'] = $isRunning;
        $stats['sentinel_enabled'] = $sentinelEnabled;
        $stats['heartbeat_age_seconds'] = $diffSeconds;
        $stats['status'] = $isRunning ? ($cachedStatus === 'STOPPED' ? 'RUNNING' : $cachedStatus) : 'STOPPED';
        $stats['total_cycles'] = $stats['loop_count'] ?? 0;
        $stats['last_cycle_time'] = isset($stats['last_loop_at'])
            ? Carbon::parse($stats['last_loop_at'])->format('H:i:s')
            : null;

        $phpCli = $this->resolvePhpCliBinary();
        $stats['os_family'] = PHP_OS_FAMILY;
        $stats['php_cli'] = $phpCli;
        $stats['cron_command'] = '* * * * * cd '.base_path()." && {$phpCli} artisan schedule:run >> /dev/null 2>&1";
        $stats['cli_command'] = "{$phpCli} artisan crypto:watch-signals --sleep=25";

        return response()->json([
            'success' => true,
            'is_running' => $isRunning,
            'sentinel_enabled' => $sentinelEnabled,
            'stats' => $stats,
        ]);
    }

    /**
     * Start the watcher daemon in the background (Cross-Platform: Linux + Windows).
     */
    public function start(): JsonResponse
    {
        Cache::forever('crypto:sentinel:enabled', true);
        Cache::forget('crypto:daemon:stop');

        $stopFile = storage_path('framework/stop-sentinel');
        if (file_exists($stopFile)) {
            @unlink($stopFile);
        }

        return $this->launchBackgroundDaemon();
    }

    /**
     * Launch the background watcher process across platforms.
     */
    protected function launchBackgroundDaemon(): JsonResponse
    {
        // 1. Check if already running
        $heartbeat = (int) Cache::get('crypto:daemon:heartbeat', 0);
        if ($heartbeat > 0 && (now()->timestamp - $heartbeat) <= 35) {
            return response()->json([
                'success' => true,
                'message' => 'Background Sentinel Watcher is already actively running!',
                'is_running' => true,
            ]);
        }

        // 2. Resolve PHP CLI binary path and artisan
        $phpCli = $this->resolvePhpCliBinary();
        $artisanPath = base_path('artisan');
        $logPath = storage_path('logs/watcher.log');

        if (! is_dir(storage_path('logs'))) {
            @mkdir(storage_path('logs'), 0755, true);
        }

        // 3. Inspect disabled functions on this server
        $rawDisabled = (string) ini_get('disable_functions');
        $disabled = array_filter(array_map('trim', explode(',', strtolower($rawDisabled))));

        $isWindows = (PHP_OS_FAMILY === 'Windows');
        $launched = false;
        $capturedPid = null;
        $errorMessage = null;

        try {
            if ($isWindows) {
                // Windows launch
                $bgBat = base_path('start-signals-watcher-bg.bat');
                if (file_exists($bgBat)) {
                    pclose(popen("start /B \"\" \"{$bgBat}\"", 'r'));
                    $launched = true;
                } else {
                    $basePath = base_path();
                    $command = "cmd /c \"cd /d \"{$basePath}\" && start /B \"\" \"{$phpCli}\" artisan crypto:watch-signals --sleep=25 >> \"{$logPath}\" 2>&1\"";
                    pclose(popen($command, 'r'));
                    $launched = true;
                }
            } else {
                // Linux / Unix / macOS launch
                $bgSh = base_path('start-signals-watcher-bg.sh');
                if (file_exists($bgSh)) {
                    @chmod($bgSh, 0755);
                    $cmd = sprintf('nohup bash %s > /dev/null 2>&1 & echo $!', escapeshellarg($bgSh));
                } else {
                    $cmd = sprintf(
                        'nohup %s %s crypto:watch-signals --sleep=25 >> %s 2>&1 & echo $!',
                        escapeshellarg($phpCli),
                        escapeshellarg($artisanPath),
                        escapeshellarg($logPath)
                    );
                }

                if (! in_array('exec', $disabled, true) && function_exists('exec')) {
                    $pidOutput = trim((string) exec($cmd));
                    if (! empty($pidOutput) && is_numeric($pidOutput)) {
                        $capturedPid = (int) $pidOutput;
                        Cache::put('crypto:daemon:pid', $capturedPid, 3600);
                        $launched = true;
                    }
                } elseif (! in_array('proc_open', $disabled, true) && function_exists('proc_open')) {
                    $descriptorspec = [
                        0 => ['pipe', 'r'],
                        1 => ['file', $logPath, 'a'],
                        2 => ['file', $logPath, 'a'],
                    ];
                    $proc = proc_open("{$phpCli} {$artisanPath} crypto:watch-signals --sleep=25 &", $descriptorspec, $pipes);
                    if (is_resource($proc)) {
                        $st = proc_get_status($proc);
                        $capturedPid = $st['pid'] ?? null;
                        if ($capturedPid) {
                            Cache::put('crypto:daemon:pid', (int) $capturedPid, 3600);
                        }
                        $launched = true;
                    }
                } elseif (! in_array('popen', $disabled, true) && function_exists('popen')) {
                    $handle = popen($cmd, 'r');
                    if ($handle !== false) {
                        pclose($handle);
                        $launched = true;
                    }
                } else {
                    $errorMessage = 'Process execution functions (exec/proc_open) are disabled by your web hosting in php.ini.';
                }
            }
        } catch (Throwable $e) {
            Log::error('Failed to launch watcher daemon from web: '.$e->getMessage());
            $errorMessage = $e->getMessage();
        }

        // 4. Verification Check: Wait 1.5s for process initialization
        usleep(1500000); // 1.5s

        $heartbeat = (int) Cache::get('crypto:daemon:heartbeat', 0);
        $diffSeconds = $heartbeat > 0 ? (now()->timestamp - $heartbeat) : 9999;
        $isNowRunning = ($diffSeconds <= 15);

        if ($isNowRunning) {
            return response()->json([
                'success' => true,
                'message' => '24/7 Background Signal Watcher is active! Continuously monitoring all featured coins.',
                'is_running' => true,
            ]);
        }

        // If process was launched but heartbeat is not yet written, it may be scanning first cycle
        if ($launched) {
            Cache::put('crypto:daemon:status', 'STARTING', 30);
            $msg = $capturedPid
                ? "Background Watcher process spawned (PID: {$capturedPid}). First cycle is initializing..."
                : 'Background Watcher process spawned. First cycle is initializing...';

            return response()->json([
                'success' => true,
                'message' => $msg,
                'is_running' => true,
            ]);
        }

        // If spawn failed, provide exact troubleshooting advice
        $cronCommand = '* * * * * cd '.base_path()." && {$phpCli} artisan schedule:run >> /dev/null 2>&1";
        $cliCommand = "{$phpCli} artisan crypto:watch-signals --sleep=25";

        $lastLogTail = '';
        if (file_exists($logPath)) {
            $logLines = array_slice(file($logPath), -5);
            $lastLogTail = trim(implode('', $logLines));
        }

        return response()->json([
            'success' => false,
            'message' => ($errorMessage ?: 'Web server cannot spawn persistent background process directly.').
                         ' Recommended for production: configure Laravel cron or run CLI daemon.',
            'is_running' => false,
            'cron_command' => $cronCommand,
            'cli_command' => $cliCommand,
            'log_tail' => $lastLogTail,
        ], 422);
    }

    /**
     * Stop the watcher daemon.
     */
    public function stop(): JsonResponse
    {
        Cache::forget('crypto:sentinel:enabled');
        Cache::put('crypto:daemon:stop', true, 120);
        Cache::put('crypto:daemon:status', 'STOPPED', 3600);

        // Write disk stop file for supervisor and daemon
        $stopFile = storage_path('framework/stop-sentinel');
        @file_put_contents($stopFile, (string) now()->timestamp);

        // Terminate any supervisor PID recorded in sentinel.pid
        $pidFile = storage_path('framework/sentinel.pid');
        if (file_exists($pidFile) && function_exists('posix_kill')) {
            $supPid = (int) trim((string) @file_get_contents($pidFile));
            if ($supPid > 0) {
                @posix_kill($supPid, 15);
            }
            @unlink($pidFile);
        }

        // If on Linux/Unix and posix kill exists, attempt gentle termination of daemon process
        if (function_exists('posix_kill')) {
            $pid = (int) Cache::get('crypto:daemon:pid', 0);
            if ($pid > 0) {
                @posix_kill($pid, 15); // SIGTERM
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Stop signal sent to Background Watcher. It will terminate gracefully.',
            'is_running' => false,
        ]);
    }

    /**
     * Get the dynamically monitored crypto coins and active scan mode.
     */
    public function getMonitoredCoins(): JsonResponse
    {
        $coins = MarketScanner::getMonitoredSymbols();
        $scanMode = MarketScanner::getScanMode();

        return response()->json([
            'success' => true,
            'coins' => $coins,
            'count' => count($coins),
            'scan_mode' => $scanMode,
            'core_pairs' => MarketScanner::CORE_PAIRS,
        ]);
    }

    /**
     * Add a coin to the monitored list.
     */
    public function addMonitoredCoin(Request $request): JsonResponse
    {
        $rawSymbol = (string) $request->input('symbol', '');
        $symbol = strtoupper(trim(str_replace([' ', '/', '-'], '', $rawSymbol)));

        if (empty($symbol)) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide a valid cryptocurrency symbol (e.g. ADAUSDT or DOGE).',
            ], 422);
        }

        if (! str_ends_with($symbol, 'USDT')) {
            $symbol .= 'USDT';
        }

        $coins = MarketScanner::addMonitoredSymbol($symbol);

        return response()->json([
            'success' => true,
            'message' => "Successfully added {$symbol} to 24/7 monitored coins!",
            'coins' => $coins,
            'count' => count($coins),
            'added_symbol' => $symbol,
        ]);
    }

    /**
     * Remove a coin from the monitored list.
     */
    public function removeMonitoredCoin(Request $request): JsonResponse
    {
        $rawSymbol = (string) $request->input('symbol', '');
        $symbol = strtoupper(trim(str_replace([' ', '/', '-'], '', $rawSymbol)));

        if (empty($symbol)) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide a valid coin symbol to remove.',
            ], 422);
        }

        if (! str_ends_with($symbol, 'USDT')) {
            $symbol .= 'USDT';
        }

        $coins = MarketScanner::removeMonitoredSymbol($symbol);

        return response()->json([
            'success' => true,
            'message' => "Removed {$symbol} from monitored list.",
            'coins' => $coins,
            'count' => count($coins),
            'removed_symbol' => $symbol,
        ]);
    }

    /**
     * Reset monitored coins to default institutional core pairs.
     */
    public function resetMonitoredCoins(): JsonResponse
    {
        $coins = MarketScanner::resetMonitoredSymbols();

        return response()->json([
            'success' => true,
            'message' => 'Monitored coins reset to default 10 institutional core pairs.',
            'coins' => $coins,
            'count' => count($coins),
        ]);
    }

    /**
     * Update the active scan mode.
     */
    public function setScanMode(Request $request): JsonResponse
    {
        $mode = (string) $request->input('mode', 'both');
        $updatedMode = MarketScanner::setScanMode($mode);

        $labels = [
            'both' => 'Monitored Coins + Whole-Market Breakouts',
            'monitored_only' => 'Monitored Coins Only',
            'whole_market' => 'Whole Market Only',
        ];

        return response()->json([
            'success' => true,
            'message' => 'Scan mode updated to: '.($labels[$updatedMode] ?? $updatedMode),
            'scan_mode' => $updatedMode,
        ]);
    }

    /**
     * Resolve the PHP CLI binary path safely on both Windows and Linux.
     */
    protected function resolvePhpCliBinary(): string
    {
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

        // Linux / Unix / macOS
        if (defined('PHP_BINARY') && file_exists(PHP_BINARY)) {
            $binName = strtolower(basename(PHP_BINARY));
            if (! str_contains($binName, 'fpm') && ! str_contains($binName, 'cgi')) {
                return PHP_BINARY;
            }
        }

        $candidates = [
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
                $verOutput = @shell_exec(escapeshellcmd($candidate).' -r "echo PHP_VERSION;" 2>/dev/null');
                if ($verOutput && version_compare(trim($verOutput), '8.3.0', '>=')) {
                    return $candidate;
                }
            }
        }

        return 'php';
    }
}
