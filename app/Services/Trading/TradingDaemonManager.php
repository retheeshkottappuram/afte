<?php

namespace App\Services\Trading;

use App\Models\TradingAccount;

/**
 * Dashboard-facing control of the cron-driven trading engine (shared hosting: no background
 * processes are spawned). Start/stop is the account's is_running flag, read by the engine
 * every cycle; status comes from the engine heartbeat.
 */
class TradingDaemonManager
{
    public const LOG_FILE = 'logs/trading_daemon.log';

    public function __construct(
        protected TradingModeManager $modeManager
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function status(?string $mode = null): array
    {
        $activeMode = $this->modeManager->activeMode();
        $mode = $mode ?: $activeMode;
        $account = TradingAccount::getForMode($mode);
        $heartbeat = $this->modeManager->heartbeat();
        $details = $heartbeat['details'];
        $engineAlive = in_array($heartbeat['state'], ['running', 'paused'], true);

        return [
            'is_running' => $engineAlive,
            'is_active' => $engineAlive && $account->is_running,
            'status' => match (true) {
                $heartbeat['state'] === 'never_ran' => 'NOT_STARTED',
                $heartbeat['state'] === 'stalled' => 'STALLED',
                ! $account->is_running => 'PAUSED',
                $account->kill_switch => 'KILL_SWITCH',
                default => 'RUNNING',
            },
            'engine_state' => $heartbeat['state'],
            'heartbeat_ago_sec' => $heartbeat['seconds_ago'],
            'heartbeat_age_seconds' => $heartbeat['seconds_ago'] ?? 9999,
            'last_heartbeat' => $details['last_run_at'] ?? null,
            'mode' => $mode,
            'active_mode' => $activeMode,
            'account_is_running' => (bool) $account->is_running,
            'can_trade' => $account->canTrade(),
            'kill_switch' => (bool) $account->kill_switch,
            'paused_until' => $account->paused_until?->toIso8601String(),
            'pause_reason' => $account->pause_reason,
            'engine_paused_reason' => $details['paused_reason'] ?? null,
            'last_scan_at' => $details['last_scan_at'] ?? null,
            'last_scan_summary' => $details['last_scan_summary'] ?? null,
            'next_scan_at' => $details['next_scan_at'] ?? null,
            'watching' => (int) ($details['watching'] ?? 0),
            'last_error' => $details['last_error'] ?? null,
            'last_error_at' => $details['last_error_at'] ?? null,
            'open_trades' => $details['open_trades'] ?? 0,
            'cycle_ms' => $details['cycle_ms'] ?? null,
            'stats' => $details,
            'cron_hint' => '* * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1',
        ];
    }

    /**
     * @return array{success: bool, message: string, is_running: bool}
     */
    public function start(string $mode = 'paper'): array
    {
        if ($mode === 'live') {
            $readiness = $this->modeManager->liveReadiness();
            if (! $readiness['ready']) {
                return ['success' => false, 'message' => $readiness['reason'], 'is_running' => false];
            }
        }

        TradingAccount::getForMode($mode)->update(['is_running' => true]);

        return ['success' => true, 'message' => 'Auto-trading enabled. The cron engine picks this up within a minute.', 'is_running' => true];
    }

    /**
     * @return array{success: bool, message: string, is_running: bool}
     */
    public function stop(string $mode = 'paper'): array
    {
        TradingAccount::getForMode($mode)->update(['is_running' => false]);

        return ['success' => true, 'message' => 'Auto-trading stopped. No new entries; open trades stay protected and managed.', 'is_running' => false];
    }

    /**
     * Web requests never trade. Kept for the dashboard poller: returns status only.
     *
     * @return array<string, mixed>
     */
    public function tickOnce(?string $mode = null): array
    {
        return array_merge(['message' => 'Trading runs in the background cron engine; this endpoint only reports status.'], $this->status($mode));
    }

    /**
     * @return array<int, string>
     */
    public function getRecentLogs(int $maxLines = 40): array
    {
        $logPath = storage_path(self::LOG_FILE);
        if (! file_exists($logPath)) {
            return ['[Notice] No engine log yet. Make sure the hosting cron runs `php artisan schedule:run` every minute.'];
        }

        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $lines === false || $lines === [] ? ['[Notice] Engine log is empty.'] : array_values(array_slice($lines, -$maxLines));
    }

    /**
     * Append a line to the engine log, trimming the file when it grows past 2 MB.
     */
    public static function appendLog(string $line): void
    {
        $path = storage_path(self::LOG_FILE);

        if (file_exists($path) && filesize($path) > 2 * 1024 * 1024) {
            $tail = array_slice(file($path, FILE_IGNORE_NEW_LINES) ?: [], -2000);
            file_put_contents($path, implode(PHP_EOL, $tail).PHP_EOL);
        }

        file_put_contents($path, '['.now()->format('Y-m-d H:i:s').'] '.$line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public function resolvePhpCliBinary(): string
    {
        return PhpCliResolver::resolve();
    }
}
