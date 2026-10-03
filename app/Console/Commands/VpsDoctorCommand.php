<?php

namespace App\Console\Commands;

use App\Models\TradingAccount;
use App\Services\Binance\BinanceFuturesClient;
use App\Services\Trading\TradingDaemonManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class VpsDoctorCommand extends Command
{
    protected $signature = 'trade:vps-doctor';

    protected $description = 'Audit and diagnose online VPS hosting environment for 24/7 autonomous live trading.';

    public function handle(TradingDaemonManager $daemonManager): int
    {
        $this->info('================================================================');
        $this->info('🩺 AFTE VPS Hosting & 24/7 Trading Environment Doctor');
        $this->info('================================================================');

        $allPassed = true;

        // 1. PHP Version & CLI Detection
        $phpCli = $daemonManager->resolvePhpCliBinary();
        $phpVersion = PHP_VERSION;
        $os = PHP_OS_FAMILY;

        $this->line("• OS Family:          <comment>{$os}</comment>");
        $this->line("• PHP Version:        <comment>{$phpVersion}</comment>");
        $this->line("• Resolved PHP CLI:   <comment>{$phpCli}</comment>");

        if (version_compare($phpVersion, '8.2.0', '<')) {
            $this->error('  ❌ FAIL: PHP 8.2+ required.');
            $allPassed = false;
        } else {
            $this->info('  ✅ PASS: PHP runtime meets modern Laravel requirements.');
        }

        // 2. Storage & Logs Directory Permissions
        $storageWritable = is_writable(storage_path()) && is_writable(storage_path('logs')) && is_writable(storage_path('framework'));
        if ($storageWritable) {
            $this->info('  ✅ PASS: Storage and log paths are fully writable.');
        } else {
            $this->error('  ❌ FAIL: Storage directory permissions issue. Run: chmod -R 775 storage bootstrap/cache');
            $allPassed = false;
        }

        // 3. Database Connectivity & Reconnect Test
        try {
            DB::connection()->getPdo();
            $dbName = DB::connection()->getDatabaseName();
            $this->info("  ✅ PASS: Database connected ({$dbName}).");

            // Test reconnect capability
            DB::purge();
            DB::reconnect();
            $this->info('  ✅ PASS: Database reconnect capability verified (prevents overnight disconnects).');
        } catch (Throwable $e) {
            $this->error("  ❌ FAIL: Database connection error: {$e->getMessage()}");
            $allPassed = false;
        }

        // 4. Cache Store Verification
        try {
            $testKey = 'afte:vps_doctor_test_'.time();
            Cache::put($testKey, 'ok', 10);
            $val = Cache::get($testKey);
            Cache::forget($testKey);
            if ($val === 'ok') {
                $driver = config('cache.default');
                $this->info("  ✅ PASS: Cache store functional (Driver: {$driver}).");
            } else {
                $this->warn('  ⚠️  WARN: Cache write succeeded but read returned empty.');
            }
        } catch (Throwable $e) {
            $this->error("  ❌ FAIL: Cache failure: {$e->getMessage()}");
            $allPassed = false;
        }

        // 5. Binance Futures API Connectivity
        try {
            $pingStart = microtime(true);
            $res = Http::timeout(5)->get('https://fapi.binance.com/fapi/v1/ping');
            $latencyMs = (int) round((microtime(true) - $pingStart) * 1000);
            if ($res->successful()) {
                $this->info("  ✅ PASS: Binance Futures API reachable ({$latencyMs}ms latency).");
            } else {
                $this->warn("  ⚠️  WARN: Binance ping returned HTTP {$res->status()}.");
            }
        } catch (Throwable $e) {
            $this->error("  ❌ FAIL: Could not reach Binance API: {$e->getMessage()}");
            $allPassed = false;
        }

        // 6. Live Account & Credentials
        $mode = (string) config('trading.mode', 'live');
        $allowLive = (bool) config('trading.allow_live_trading', true);
        $this->line("• Configured Mode:    <comment>{$mode}</comment>");
        $this->line('• Live Trading Gate:  <comment>'.($allowLive ? 'ENABLED' : 'DISABLED').'</comment>');

        $account = TradingAccount::getForMode($mode);
        $this->line("• Account Balance:    <comment>\${$account->balance}</comment> (Auto-Trading: ".($account->is_running ? 'ACTIVE' : 'PAUSED').')');

        if ($mode === 'live') {
            $client = app(BinanceFuturesClient::class)->forMode('live');
            if ($client->hasCredentials()) {
                $this->info('  ✅ PASS: Live Binance API key and secret are configured.');
                try {
                    $balances = $client->getBalance();
                    $liveBal = 0.0;
                    foreach ($balances as $b) {
                        if (($b['asset'] ?? '') === 'USDT') {
                            $liveBal = (float) ($b['balance'] ?? $b['crossWalletBalance'] ?? 0.0);
                            break;
                        }
                    }
                    $this->info("  ✅ PASS: Live Binance Futures account verified! USDT Balance: \${$liveBal}");
                } catch (Throwable $e) {
                    $this->warn("  ⚠️  WARN: Binance balance query notice: {$e->getMessage()}");
                }
            } else {
                $this->error('  ❌ FAIL: Live mode active but BINANCE_API_KEY or BINANCE_API_SECRET is missing.');
                $allPassed = false;
            }
        }

        // 7. Background Daemon Diagnostics
        $daemonStatus = $daemonManager->status($mode);
        $isRunning = $daemonStatus['is_running'] ?? false;
        $heartbeatAge = $daemonStatus['heartbeat_ago_sec'] ?? 'None';
        $this->line('----------------------------------------------------------------');
        $this->line('• Engine State:       <comment>'.($isRunning ? '🟢 RUNNING' : '🔴 '.($daemonStatus['status'] ?? 'STOPPED')).'</comment>');
        $this->line("• Last Cycle:         <comment>{$heartbeatAge}s ago</comment>");
        $this->line('• Active Mode:        <comment>'.strtoupper((string) ($daemonStatus['active_mode'] ?? 'paper')).'</comment>');

        if (! $isRunning) {
            $this->warn('  ⚠️  NOTICE: The cron engine has not run recently.');
            $this->line('  Add this cron job in your hosting panel: '.($daemonStatus['cron_hint'] ?? '* * * * * php artisan schedule:run'));
        } else {
            $this->info('  ✅ PASS: Cron trading engine is running and sending heartbeats.');
        }

        $this->info('================================================================');
        if ($allPassed) {
            $this->info('🎉 System is fully optimized and ready for online VPS execution!');
        } else {
            $this->warn('⚠️  Please address the failed items above for optimal stability.');
        }
        $this->info('================================================================');

        return $allPassed ? self::SUCCESS : self::FAILURE;
    }
}
