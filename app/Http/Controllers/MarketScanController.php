<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class MarketScanController extends Controller
{
    /**
     * Start the on-demand market scan in the background.
     * Command: php artisan crypto:check-signals --all --dry-run
     */
    public function start(Request $request): JsonResponse
    {
        // 1. Check if already running (with heartbeat check)
        $currentStatus = (string) Cache::get('crypto:manual_scan:status', 'IDLE');
        $heartbeat = (int) Cache::get('crypto:manual_scan:heartbeat', 0);
        $diffSeconds = $heartbeat > 0 ? (now()->timestamp - $heartbeat) : 9999;
        if ($currentStatus === 'RUNNING' && $diffSeconds <= 60) {
            return response()->json([
                'success' => true,
                'message' => 'Market scan is already running!',
                'is_running' => true,
                'status' => 'RUNNING',
            ]);
        }

        // 2. Clear previous scan state & stop signals
        Cache::forget('crypto:manual_scan:stop');
        $stopFile = storage_path('framework/stop-manual-scan');
        if (file_exists($stopFile)) {
            @unlink($stopFile);
        }

        Cache::put('crypto:manual_scan:status', 'RUNNING', 900);
        Cache::put('crypto:manual_scan:running', true, 900);
        Cache::put('crypto:manual_scan:heartbeat', now()->timestamp, 900);
        Cache::put('crypto:manual_scan:started_at', now()->toIso8601String(), 3600);
        Cache::put('crypto:manual_scan:signals', [], 3600);
        Cache::put('crypto:manual_scan:progress', [
            'current_symbol' => 'Initializing...',
            'index' => 0,
            'total' => 0,
            'percent' => 0,
            'signals_found' => 0,
        ], 900);

        // 3. Prepare log file
        $logPath = storage_path('logs/manual_scan.log');
        if (! is_dir(storage_path('logs'))) {
            @mkdir(storage_path('logs'), 0755, true);
        }
        $initTime = Carbon::now('Asia/Kolkata')->format('d-M-Y H:i:s \I\S\T');
        file_put_contents(
            $logPath,
            "================================================================================\n"
            ."⚡ SignalAlgo PRO™ On-Demand Whole-Market Scan Initiated\n"
            ."• Time: {$initTime}\n"
            ."• Executing: php artisan crypto:check-signals --all --dry-run\n"
            ."================================================================================\n\n"
        );

        // 4. Resolve PHP CLI path and launch process in background
        $phpCli = $this->resolvePhpCliBinary();
        $artisanPath = base_path('artisan');
        $basePath = base_path();
        $isWindows = (PHP_OS_FAMILY === 'Windows');
        $capturedPid = null;

        try {
            if ($isWindows) {
                $batPath = storage_path('framework/run-manual-scan.bat');
                $normBasePath = str_replace('/', '\\', $basePath);
                $normLogPath = str_replace('/', '\\', $logPath);
                $batContent = "@echo off\r\n"
                    ."cd /d \"{$normBasePath}\"\r\n"
                    ."\"{$phpCli}\" artisan crypto:check-signals --all --dry-run >> \"{$normLogPath}\" 2>&1\r\n";
                file_put_contents($batPath, $batContent);
                pclose(popen("start \"\" /B \"{$batPath}\" > NUL 2>&1", 'r'));
            } else {
                $cmd = sprintf(
                    'nohup %s %s crypto:check-signals --all --dry-run >> %s 2>&1 & echo $!',
                    escapeshellarg($phpCli),
                    escapeshellarg($artisanPath),
                    escapeshellarg($logPath)
                );
                $capturedPid = trim((string) exec($cmd));
                if (is_numeric($capturedPid)) {
                    Cache::put('crypto:manual_scan:pid', (int) $capturedPid, 900);
                }
            }

            Log::info("MarketScanController: Launched on-demand scan with command 'php artisan crypto:check-signals --all --dry-run'");

            return response()->json([
                'success' => true,
                'message' => 'Whole-market scan launched successfully! Monitoring live execution...',
                'is_running' => true,
                'status' => 'RUNNING',
            ]);
        } catch (Throwable $e) {
            Log::error("MarketScanController: Failed to spawn scan process: {$e->getMessage()}");
            Cache::put('crypto:manual_scan:status', 'STOPPED', 3600);
            Cache::put('crypto:manual_scan:running', false, 3600);

            return response()->json([
                'success' => false,
                'message' => 'Failed to launch scan process: '.$e->getMessage(),
                'is_running' => false,
                'status' => 'ERROR',
            ], 500);
        }
    }

    /**
     * Stop the ongoing manual market scan.
     */
    public function stop(): JsonResponse
    {
        Cache::put('crypto:manual_scan:stop', true, 120);
        Cache::put('crypto:manual_scan:status', 'STOPPED', 3600);
        Cache::put('crypto:manual_scan:running', false, 3600);

        // Write disk stop file for immediate detection by CLI loop
        $stopFile = storage_path('framework/stop-manual-scan');
        @file_put_contents($stopFile, (string) now()->timestamp);

        // Append to log
        $logPath = storage_path('logs/manual_scan.log');
        if (file_exists($logPath)) {
            @file_put_contents($logPath, "\n\n⚠️ [STOPPED] Market scan was stopped by user from frontend at ".Carbon::now('Asia/Kolkata')->format('H:i:s \I\S\T').".\n", FILE_APPEND);
        }

        // Kill process on Linux if PID known
        $pid = Cache::get('crypto:manual_scan:pid');
        if ($pid && function_exists('posix_kill')) {
            @posix_kill((int) $pid, 15);
        }

        Log::info('MarketScanController: Scan stopped by user from frontend.');

        return response()->json([
            'success' => true,
            'message' => 'Scan stopped successfully.',
            'is_running' => false,
            'status' => 'STOPPED',
        ]);
    }

    /**
     * Get live status, progress, detected signals, and terminal log tail.
     */
    public function status(): JsonResponse
    {
        $status = (string) Cache::get('crypto:manual_scan:status', 'IDLE');
        $heartbeat = (int) Cache::get('crypto:manual_scan:heartbeat', 0);
        $diffSeconds = $heartbeat > 0 ? (now()->timestamp - $heartbeat) : 9999;
        $progress = (array) Cache::get('crypto:manual_scan:progress', [
            'current_symbol' => 'Ready',
            'index' => 0,
            'total' => 0,
            'percent' => 0,
            'signals_found' => 0,
        ]);
        $signals = (array) Cache::get('crypto:manual_scan:signals', []);
        $startedAt = Cache::get('crypto:manual_scan:started_at');

        // Read log tail
        $logPath = storage_path('logs/manual_scan.log');
        $logTail = '';
        if (file_exists($logPath)) {
            $lines = file($logPath, FILE_IGNORE_NEW_LINES);
            $recentLines = array_slice($lines, -80);
            $logTail = implode("\n", $recentLines);

            if ($status === 'RUNNING') {
                if (str_contains($logTail, '[COMPLETED]') || str_contains($logTail, 'Market scan finished successfully')) {
                    $status = 'COMPLETED';
                    Cache::put('crypto:manual_scan:status', 'COMPLETED', 3600);
                    Cache::put('crypto:manual_scan:running', false, 3600);
                } elseif ($diffSeconds > 120) {
                    $status = 'STOPPED';
                    Cache::put('crypto:manual_scan:status', 'STOPPED', 3600);
                    Cache::put('crypto:manual_scan:running', false, 3600);
                }
            }
        }

        $isRunning = ($status === 'RUNNING');

        return response()->json([
            'success' => true,
            'status' => $status,
            'is_running' => $isRunning,
            'progress' => $progress,
            'signals' => $signals,
            'total_signals' => count($signals),
            'log_tail' => $logTail,
            'started_at' => $startedAt ? Carbon::parse($startedAt)->setTimezone('Asia/Kolkata')->format('H:i:s \I\S\T') : null,
        ]);
    }

    /**
     * Clear the log and scan results.
     */
    public function clear(): JsonResponse
    {
        Cache::put('crypto:manual_scan:status', 'IDLE', 3600);
        Cache::put('crypto:manual_scan:running', false, 3600);
        Cache::forget('crypto:manual_scan:signals');
        Cache::forget('crypto:manual_scan:progress');
        Cache::forget('crypto:manual_scan:started_at');

        $logPath = storage_path('logs/manual_scan.log');
        if (file_exists($logPath)) {
            @unlink($logPath);
        }

        return response()->json([
            'success' => true,
            'message' => 'Scan results cleared.',
            'status' => 'IDLE',
        ]);
    }

    /**
     * Resolve the PHP CLI binary path safely.
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
