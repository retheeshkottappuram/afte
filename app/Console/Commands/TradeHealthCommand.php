<?php

namespace App\Console\Commands;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Notifications\TelegramGateway;
use App\Services\Trading\NearBreakoutTrader;
use App\Services\Trading\TradingModeManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Read-only check of why no trades or Telegram messages are arriving: cron heartbeat, mode, pauses and
 * circuit breakers, the last NEAR candle decision, the Telegram setup and the last 24h. Never prints secrets.
 */
class TradeHealthCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'trade:health {--send-test : Also send a test message to Telegram}';

    /**
     * @var string
     */
    protected $description = 'Show why the bot is or is not trading and sending Telegram alerts';

    public function handle(TradingModeManager $modeManager, TelegramGateway $telegram): int
    {
        $causes = [];

        // 1. Engine (cron) heartbeat
        $beat = $modeManager->heartbeat();
        $details = $beat['details'];
        $mode = $modeManager->activeMode();
        $this->info('Engine');
        $this->line('  Heartbeat: '.$beat['state'].($beat['seconds_ago'] === null ? '' : " ({$beat['seconds_ago']}s ago)"));
        $this->line('  Mode: '.strtoupper($mode));
        $this->line('  Last candle: '.($details['last_scan_at'] ?? 'never').' · '.($details['last_scan_summary'] ?? '-'));
        $this->line('  Next candle: '.($details['next_scan_at'] ?? '-'));
        if (! empty($details['last_error'])) {
            $this->line("  Last error ({$details['last_error_at']}): {$details['last_error']}");
        }
        if (in_array($beat['state'], ['never_ran', 'stalled'], true)) {
            $causes[] = 'The engine is not running: the cron must run "php artisan schedule:run" every minute.';
        }

        if ($mode === 'live') {
            $readiness = $modeManager->liveReadiness();
            $this->line('  Live ready: '.($readiness['ready'] ? 'yes' : 'NO · '.$readiness['reason']));
            if (! $readiness['ready']) {
                $causes[] = 'Live mode is not usable: '.$readiness['reason'];
            }
        }

        // 2. Account switches and circuit breakers
        $account = TradingAccount::getForMode($mode);
        $equity = (float) ($account->equity ?: $account->balance);
        $dayStart = (float) ($account->day_start_equity ?: $equity);
        $peak = max((float) $account->peak_equity, $equity);
        $this->newLine();
        $this->info('Account ('.$mode.')');
        $this->line(sprintf('  Balance $%.2f · equity $%.2f · start $%.2f', (float) $account->balance, $equity, (float) $account->initial_balance));
        $this->line('  Auto-trading: '.($account->is_running ? 'ON' : 'OFF'));
        $this->line('  Kill switch: '.($account->kill_switch ? 'ACTIVE' : 'off'));
        $this->line('  Paused until: '.($account->paused_until !== null && $account->paused_until->isFuture() ? $account->paused_until->toDateTimeString().' UTC ('.($account->pause_reason ?? 'risk rule').')' : 'not paused'));
        $this->line(sprintf('  Losses in a row: %d (pause at %d)', (int) $account->consecutive_losses, (int) config('trading.circuit_breakers.max_consecutive_losses', 3)));
        $this->line(sprintf('  Today: %+.2f%% (stop at -%.1f%%) · drawdown %.1f%% (kill at %.0f%%)',
            $dayStart > 0 ? ($equity - $dayStart) / $dayStart * 100 : 0,
            (float) config('trading.circuit_breakers.max_daily_loss_pct', 6.0),
            $peak > 0 ? ($peak - $equity) / $peak * 100 : 0,
            (float) config('trading.circuit_breakers.max_drawdown_pct', 30.0)));
        $this->line('  Open trades: '.Trade::where('mode', $mode)->where('status', 'OPEN')->count());
        if (! $account->canTrade()) {
            $causes[] = match (true) {
                (bool) $account->kill_switch => 'The kill switch is active (drawdown limit or manual). Reset it on the dashboard.',
                ! $account->is_running => 'Auto-trading is switched OFF. Press Start on the dashboard.',
                default => 'New entries are paused until '.$account->paused_until?->toDateTimeString().' UTC ('.($account->pause_reason ?? 'risk rule').').',
            };
        }

        // 3. NEAR breakout: the last closed 1h candle
        $last = (array) Cache::get(NearBreakoutTrader::LAST_KEY, []);
        $this->newLine();
        $this->info('NEAR 1h breakout ('.NearBreakoutTrader::symbol().')');
        $this->line('  Switch: '.(NearBreakoutTrader::enabled() ? 'ON' : 'OFF (dashboard)'));
        $this->line(sprintf('  Last candle %s · close %s · EMA50 %s · ATR %s%% · volatility rank %s · size %s',
            isset($last['candle_close']) ? now()->setTimestamp(intdiv((int) $last['candle_close'] + 1, 1000))->toDateTimeString().' UTC' : 'never',
            $last['close'] ?? '-', $last['ema'] ?? '-', $last['atr_pct'] ?? '-', $last['rank'] ?? '-', isset($last['size']) ? round((float) $last['size'] * 100).'%' : '-'));
        $this->line('  Decision: '.($last['message'] ?? '-'));
        if (! NearBreakoutTrader::enabled()) {
            $causes[] = 'The NEAR bot switch is OFF on the dashboard.';
        }

        // 4. Telegram
        $health = $telegram->health();
        $this->newLine();
        $this->info('Telegram');
        $this->line('  Configured and enabled: '.($health['enabled'] ? 'yes' : 'NO'));
        $this->line('  Last sent OK: '.($health['last_ok_at'] ?? 'never').' · last error: '.($health['last_error'] ?? 'none').($health['last_error_at'] ? " ({$health['last_error_at']})" : ''));
        if ($health['problem'] !== null) {
            $causes[] = $health['problem'];
        }
        if ($this->option('send-test')) {
            $id = $telegram->send('✅ <b>AFTE test</b>: the bot can reach this chat ('.now()->toDateTimeString().' UTC).', priority: true);
            $this->line('  Test message: '.($id !== null ? "sent (message id {$id})" : 'NOT sent · '.($telegram->health()['problem'] ?? 'see the last error above')));
        }

        // 5. Effective settings
        $this->newLine();
        $this->info('Settings in effect');
        $this->line('  Config cached: '.(app()->configurationIsCached() ? 'yes (run php artisan config:clear after editing .env)' : 'no'));
        $this->line(sprintf('  NEAR: trail %s ATR · safety stop %s ATR (max %s%%) · min ATR %s%% · rank gates %s / %s',
            (float) config('trading.near_vgb.trail_mult'), (float) config('trading.near_vgb.safety_atr'), (float) config('trading.near_vgb.max_sl_pct'),
            (float) config('trading.near_vgb.min_atr_pct'), (float) config('trading.near_vgb.low_gate'), (float) config('trading.near_vgb.high_gate')));

        // 6. Last 24 hours
        $opened = Trade::where('opened_at', '>=', now()->subDay())->count();
        $closed = Trade::where('closed_at', '>=', now()->subDay())->get();
        $this->newLine();
        $this->info('Last 24 hours');
        $this->line(sprintf('  Trades opened %d · closed %d · net $%.2f', $opened, $closed->count(), (float) $closed->sum('net_pnl')));

        $this->newLine();
        $this->info('LIKELY CAUSE');
        $allClear = $opened > 0
            ? "Nothing is blocked. The bot is working: {$opened} trade(s) opened in the last 24 hours."
            : 'Nothing is blocked. No NEAR breakout qualified in the last 24 hours (about one a day on average; some days have none).';
        foreach ($causes ?: [$allClear] as $cause) {
            $this->line('  • '.$cause);
        }

        return self::SUCCESS;
    }
}
