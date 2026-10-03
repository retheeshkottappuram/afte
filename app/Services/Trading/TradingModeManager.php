<?php

namespace App\Services\Trading;

use App\Models\Setting;
use App\Models\SystemLog;
use App\Services\Binance\BinanceFuturesClient;
use Throwable;

/**
 * Server-side source of truth for the active trading mode (paper / live) and
 * the background engine heartbeat. The cron engine reads the mode every cycle,
 * so a dashboard switch applies within one minute without a redeploy.
 */
class TradingModeManager
{
    public const MODE_KEY = 'active_mode';

    public const HEARTBEAT_KEY = 'engine_heartbeat';

    public const MODES = ['paper', 'live'];

    public function __construct(
        protected BinanceFuturesClient $client
    ) {}

    /**
     * The mode the background engine opens new trades in.
     */
    public function activeMode(): string
    {
        $mode = (string) Setting::getValue(self::MODE_KEY, config('trading.mode', 'paper'));

        return in_array($mode, self::MODES, true) ? $mode : 'paper';
    }

    /**
     * Check whether live trading can be used right now.
     *
     * @return array{ready: bool, reason: string}
     */
    public function liveReadiness(bool $verifyWithExchange = false): array
    {
        if (! config('trading.allow_live_trading', false)) {
            return ['ready' => false, 'reason' => 'Live trading is disabled on this server (ALLOW_LIVE_TRADING=false).'];
        }

        $liveClient = $this->client->forMode('live');
        if (! $liveClient->hasCredentials()) {
            return ['ready' => false, 'reason' => 'Binance API key/secret are not configured.'];
        }

        if ($verifyWithExchange) {
            try {
                $liveClient->getAccount();
            } catch (Throwable $e) {
                return ['ready' => false, 'reason' => 'Binance rejected the API keys: '.$e->getMessage()];
            }
        }

        return ['ready' => true, 'reason' => 'Live trading is available.'];
    }

    /**
     * Switch the active mode. Live is only accepted when it is actually usable.
     *
     * @return array{success: bool, mode: string, message: string}
     */
    public function setMode(string $mode, ?string $changedBy = null): array
    {
        $mode = strtolower(trim($mode));
        if (! in_array($mode, self::MODES, true)) {
            return ['success' => false, 'mode' => $this->activeMode(), 'message' => "Unknown trading mode '{$mode}'."];
        }

        if ($mode === 'live') {
            $readiness = $this->liveReadiness(verifyWithExchange: true);
            if (! $readiness['ready']) {
                return ['success' => false, 'mode' => $this->activeMode(), 'message' => $readiness['reason']];
            }
        }

        $previous = $this->activeMode();
        Setting::putValue(self::MODE_KEY, $mode);

        if ($previous !== $mode) {
            $who = $changedBy ?: 'system';
            SystemLog::write('mode', "Trading mode switched from {$previous} to {$mode} by {$who}.", 'warning');
        }

        return ['success' => true, 'mode' => $mode, 'message' => 'Trading mode set to '.strtoupper($mode).'.'];
    }

    /**
     * Record that an engine cycle ran.
     *
     * @param  array<string, mixed>  $details
     */
    public function recordHeartbeat(array $details): void
    {
        Setting::putValue(self::HEARTBEAT_KEY, array_merge($details, [
            'last_run_at' => now()->toIso8601String(),
            'last_run_ts' => now()->timestamp,
        ]));
    }

    /**
     * Read the engine heartbeat with a computed health state.
     *
     * @return array{state: string, seconds_ago: ?int, details: array<string, mixed>}
     */
    public function heartbeat(): array
    {
        $details = (array) Setting::getValue(self::HEARTBEAT_KEY, []);
        $lastTs = isset($details['last_run_ts']) ? (int) $details['last_run_ts'] : null;
        $secondsAgo = $lastTs !== null ? max(0, now()->timestamp - $lastTs) : null;

        $state = match (true) {
            $secondsAgo === null => 'never_ran',
            $secondsAgo > (int) config('trading.engine.stall_after_seconds', 180) => 'stalled',
            ! empty($details['paused_reason']) => 'paused',
            default => 'running',
        };

        return ['state' => $state, 'seconds_ago' => $secondsAgo, 'details' => $details];
    }
}
