<?php

namespace App\Http\Controllers;

use App\Models\CryptoSignal;
use App\Services\Notifications\TelegramGateway;
use App\Services\Strategy\MarketScanService;
use App\Services\Strategy\Watchlist;
use App\Services\Trading\PhpCliResolver;
use App\Services\Trading\TradingModeManager;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Signal Sentinel controls. Scanning and Telegram signal alerts run inside the cron-driven
 * trading engine (shared hosting: nothing is spawned from the web). Start/stop toggles the
 * Telegram signal alerts; the coin list is the "Alert me" watchlist.
 */
class DaemonController extends Controller
{
    public function __construct(
        protected TradingModeManager $modeManager,
        protected MarketScanService $scanner
    ) {}

    public function status(): JsonResponse
    {
        $heartbeat = $this->modeManager->heartbeat();
        $results = $this->scanner->latestResults();
        $isRunning = in_array($heartbeat['state'], ['running', 'paused'], true);
        $alertsOn = Watchlist::alertsEnabled();

        $stats = [
            'is_running' => $isRunning,
            'sentinel_enabled' => $alertsOn,
            'status' => $isRunning ? ($alertsOn ? 'RUNNING' : 'ALERTS_OFF') : 'STOPPED',
            'engine_state' => $heartbeat['state'],
            'heartbeat_age_seconds' => $heartbeat['seconds_ago'] ?? 9999,
            'last_cycle_time' => isset($heartbeat['details']['last_run_at']) ? Carbon::parse($heartbeat['details']['last_run_at'])->format('H:i:s') : null,
            'last_scan_at' => $results['scanned_at'] ?? null,
            'universe_size' => $results['universe_size'] ?? 0,
            'signals_last_scan' => count(array_filter((array) ($results['rows'] ?? []), fn (array $r): bool => ! empty($r['signal']))),
            'telegram_configured' => app(TelegramGateway::class)->isEnabled(),
            'alerts_sent_24h' => CryptoSignal::where('telegram_sent', true)->where('sent_at', '>=', now()->subDay())->count(),
            'last_alert_at' => CryptoSignal::where('telegram_sent', true)->max('sent_at'),
            'watchlist_count' => count(Watchlist::symbols()),
            'cron_command' => PhpCliResolver::resolve().' '.base_path('artisan').' schedule:run >> /dev/null 2>&1',
        ];

        return response()->json(['success' => true, 'is_running' => $isRunning, 'sentinel_enabled' => $alertsOn, 'stats' => $stats]);
    }

    public function start(): JsonResponse
    {
        Watchlist::setAlertsEnabled(true);

        return response()->json(['success' => true, 'message' => 'Telegram signal alerts ON. The background engine scans after every candle close.', 'is_running' => true]);
    }

    /**
     * Send a test message to confirm the Telegram bot token and chat id work.
     */
    public function testAlert(TelegramGateway $gateway): JsonResponse
    {
        if (! $gateway->isEnabled()) {
            return response()->json(['success' => false, 'message' => 'Telegram is not configured: set TELEGRAM_NOTIFICATIONS_ENABLED=true, TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID in .env, then run php artisan config:cache.'], 422);
        }

        $sent = $gateway->send("✅ <b>AFTE test alert</b>\nTelegram delivery works. Signal alerts are ".(Watchlist::alertsEnabled() ? 'ON' : 'OFF').'.', null, priority: true);

        return response()->json([
            'success' => $sent !== null,
            'message' => $sent !== null ? 'Test alert delivered to Telegram.' : 'Telegram rejected the message. Check the bot token, the chat id, and that the bot was added to the chat.',
        ], $sent !== null ? 200 : 502);
    }

    public function stop(): JsonResponse
    {
        Watchlist::setAlertsEnabled(false);

        return response()->json(['success' => true, 'message' => 'Telegram signal alerts OFF. Risk and health alerts stay on.', 'is_running' => false]);
    }

    public function getMonitoredCoins(): JsonResponse
    {
        $coins = Watchlist::symbols();

        return response()->json(['success' => true, 'coins' => $coins, 'count' => count($coins), 'scan_mode' => 'both', 'core_pairs' => Watchlist::DEFAULT]);
    }

    public function addMonitoredCoin(Request $request): JsonResponse
    {
        $symbol = $this->symbolFrom($request);
        if ($symbol === null) {
            return response()->json(['success' => false, 'message' => 'Please provide a valid symbol (e.g. ADAUSDT or DOGE).'], 422);
        }

        $coins = Watchlist::add($symbol);

        return response()->json(['success' => true, 'message' => "Alerts on for {$symbol}: every signal on it goes to Telegram.", 'coins' => $coins, 'count' => count($coins), 'added_symbol' => $symbol]);
    }

    public function removeMonitoredCoin(Request $request): JsonResponse
    {
        $symbol = $this->symbolFrom($request);
        if ($symbol === null) {
            return response()->json(['success' => false, 'message' => 'Please provide a valid symbol to remove.'], 422);
        }

        $coins = Watchlist::remove($symbol);

        return response()->json(['success' => true, 'message' => "Removed {$symbol} from the alert watchlist.", 'coins' => $coins, 'count' => count($coins), 'removed_symbol' => $symbol]);
    }

    public function resetMonitoredCoins(): JsonResponse
    {
        $coins = Watchlist::reset();

        return response()->json(['success' => true, 'message' => 'Alert watchlist reset to the default major pairs.', 'coins' => $coins, 'count' => count($coins)]);
    }

    /**
     * Kept for the existing UI: the scanner always covers the liquid market plus the watchlist.
     */
    public function setScanMode(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'The scanner always covers the liquid market plus your watchlist.', 'scan_mode' => 'both']);
    }

    protected function symbolFrom(Request $request): ?string
    {
        $symbol = strtoupper(trim(str_replace([' ', '/', '-'], '', (string) $request->input('symbol', ''))));
        if ($symbol === '' || ! preg_match('/^[A-Z0-9]+$/', $symbol)) {
            return null;
        }

        return str_ends_with($symbol, 'USDT') ? $symbol : $symbol.'USDT';
    }
}
