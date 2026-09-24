<?php

namespace App\Console\Commands;

use App\Models\CryptoSignal;
use App\Services\Crypto\TelegramNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class HealthCheckCommand extends Command
{
    protected $signature = 'crypto:health-check
                            {--json : Output health check results as JSON}';

    protected $description = 'Perform end-to-end diagnostic health check on database, cache, Binance API, Telegram, and Sentinel daemon.';

    public function handle(): int
    {
        $this->info('╔════════════════════════════════════════════════════════════════════════════════════╗');
        $this->info('║  🔍 SignalAlgo PRO™ System Health & Latency Diagnostics                             ║');
        $this->info('╚════════════════════════════════════════════════════════════════════════════════════╝');
        $this->newLine();

        $results = [];
        $overallHealthy = true;

        // 1. Database Connection & Latency
        $dbStart = microtime(true);
        try {
            DB::connection()->getPdo();
            $recentCount = CryptoSignal::count();
            $dbLatencyMs = (int) round((microtime(true) - $dbStart) * 1000);
            $results['database'] = [
                'status' => 'PASS',
                'latency_ms' => $dbLatencyMs,
                'details' => "Connected (Total Signals Recorded: {$recentCount})",
            ];
        } catch (Throwable $e) {
            $overallHealthy = false;
            $results['database'] = [
                'status' => 'FAIL',
                'latency_ms' => 0,
                'details' => 'Connection failed: '.$e->getMessage(),
            ];
        }

        // 2. Cache & Redis / File Storage
        $cacheStart = microtime(true);
        try {
            $testKey = 'crypto:health_check_test_'.time();
            Cache::put($testKey, 'ok', 10);
            $val = Cache::get($testKey);
            Cache::forget($testKey);
            $cacheLatencyMs = (int) round((microtime(true) - $cacheStart) * 1000);

            $results['cache'] = [
                'status' => ($val === 'ok') ? 'PASS' : 'WARN',
                'latency_ms' => $cacheLatencyMs,
                'details' => 'Driver: '.config('cache.default', 'file').' (Read/Write OK)',
            ];
        } catch (Throwable $e) {
            $overallHealthy = false;
            $results['cache'] = [
                'status' => 'FAIL',
                'latency_ms' => 0,
                'details' => 'Cache error: '.$e->getMessage(),
            ];
        }

        // 3. Binance Futures API Latency
        $binanceStart = microtime(true);
        try {
            $res = Http::timeout(5)->get('https://fapi.binance.com/fapi/v1/ping');
            $binanceLatencyMs = (int) round((microtime(true) - $binanceStart) * 1000);
            $results['binance_api'] = [
                'status' => $res->successful() ? 'PASS' : 'WARN',
                'latency_ms' => $binanceLatencyMs,
                'details' => "fapi.binance.com reachable (HTTP {$res->status()})",
            ];
        } catch (Throwable $e) {
            $overallHealthy = false;
            $results['binance_api'] = [
                'status' => 'FAIL',
                'latency_ms' => 0,
                'details' => 'Unreachable: '.$e->getMessage(),
            ];
        }

        // 4. Telegram Bot API Connectivity
        $telegramToken = (string) config('crypto.telegram.bot_token', '');
        $telegramChatId = (string) config('crypto.telegram.chat_id', '');
        $telegramNotifier = new TelegramNotifier($telegramToken, $telegramChatId);

        if (! $telegramNotifier->isConfigured()) {
            $results['telegram_bot'] = [
                'status' => 'WARN',
                'latency_ms' => 0,
                'details' => 'Token or Chat ID not configured in .env',
            ];
        } else {
            $tgStart = microtime(true);
            try {
                $tgRes = Http::timeout(5)->get("https://api.telegram.org/bot{$telegramToken}/getMe");
                $tgLatencyMs = (int) round((microtime(true) - $tgStart) * 1000);

                if ($tgRes->successful()) {
                    $botName = $tgRes->json('result.username') ?? 'Bot';
                    $results['telegram_bot'] = [
                        'status' => 'PASS',
                        'latency_ms' => $tgLatencyMs,
                        'details' => "@{$botName} active (Chat ID: {$telegramChatId})",
                    ];
                } else {
                    $results['telegram_bot'] = [
                        'status' => 'FAIL',
                        'latency_ms' => $tgLatencyMs,
                        'details' => "HTTP {$tgRes->status()}: {$tgRes->body()}",
                    ];
                }
            } catch (Throwable $e) {
                $results['telegram_bot'] = [
                    'status' => 'FAIL',
                    'latency_ms' => 0,
                    'details' => 'Connection failed: '.$e->getMessage(),
                ];
            }
        }

        // 5. Sentinel Daemon Heartbeat
        $heartbeat = (int) Cache::get('crypto:daemon:heartbeat', 0);
        $daemonStatus = (string) Cache::get('crypto:daemon:status', 'STOPPED');
        $pid = Cache::get('crypto:daemon:pid');
        $ageSec = $heartbeat > 0 ? (now()->timestamp - $heartbeat) : 9999;
        $daemonAlive = ($ageSec <= 90);

        $results['sentinel_daemon'] = [
            'status' => $daemonAlive ? 'PASS' : 'WARN',
            'latency_ms' => 0,
            'details' => $daemonAlive
                ? "{$daemonStatus} (PID: {$pid}, Heartbeat: {$ageSec}s ago)"
                : "Inactive/Stopped (Last heartbeat: {$ageSec}s ago)",
        ];

        // Output results
        if ($this->option('json')) {
            $this->line(json_encode([
                'healthy' => $overallHealthy,
                'timestamp' => now()->toIso8601String(),
                'results' => $results,
            ], JSON_PRETTY_PRINT));

            return $overallHealthy ? Command::SUCCESS : Command::FAILURE;
        }

        $tableRows = [];
        foreach ($results as $component => $data) {
            $badge = match ($data['status']) {
                'PASS' => '<fg=green;options=bold>✓ PASS</>',
                'WARN' => '<fg=yellow;options=bold>⚠ WARN</>',
                default => '<fg=red;options=bold>✗ FAIL</>',
            };
            $lat = $data['latency_ms'] > 0 ? "{$data['latency_ms']} ms" : '—';
            $tableRows[] = [ucwords(str_replace('_', ' ', $component)), $badge, $lat, $data['details']];
        }

        $this->table(['Component', 'Status', 'Latency', 'Details'], $tableRows);
        $this->newLine();

        if ($overallHealthy) {
            $this->info('✅ All critical pipeline components are healthy and operating normally.');
        } else {
            $this->warn('⚠️ Some components reported errors or warnings. Check details above.');
        }

        return $overallHealthy ? Command::SUCCESS : Command::FAILURE;
    }
}
