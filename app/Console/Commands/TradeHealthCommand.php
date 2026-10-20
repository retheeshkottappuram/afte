<?php

namespace App\Console\Commands;

use App\Models\CryptoSignal;
use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Notifications\TelegramGateway;
use App\Services\Strategy\MarketScanService;
use App\Services\Strategy\Watchlist;
use App\Services\Trading\SwingSarTrader;
use App\Services\Trading\TradingModeManager;
use Illuminate\Console\Command;

/**
 * Read-only check of why no trades or Telegram messages are arriving: cron heartbeat, mode, pauses and
 * circuit breakers, the last scan, the Telegram setup and the last 24h of signals. Never prints secrets.
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

    public function handle(TradingModeManager $modeManager, MarketScanService $scanner, TelegramGateway $telegram): int
    {
        $causes = [];

        // 1. Engine (cron) heartbeat
        $beat = $modeManager->heartbeat();
        $details = $beat['details'];
        $mode = $modeManager->activeMode();
        $this->info('Engine');
        $this->line('  Heartbeat: '.$beat['state'].($beat['seconds_ago'] === null ? '' : " ({$beat['seconds_ago']}s ago)"));
        $this->line('  Mode: '.strtoupper($mode));
        $this->line('  Last scan: '.($details['last_scan_at'] ?? 'never').' · '.($details['last_scan_summary'] ?? '-'));
        $this->line('  Next scan: '.($details['next_scan_at'] ?? '-'));
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

        // 3. Last base-timeframe scan
        $results = $scanner->latestResults();
        $this->newLine();
        $this->info('Last scan results');
        $this->line(sprintf('  At %s · %d coins · %d fresh signals · %d swing turns · watching %d',
            $results['scanned_at'] ?? 'never', (int) ($results['symbols_scanned'] ?? 0), (int) ($results['fresh_signals'] ?? 0),
            count((array) ($results['reversals'] ?? [])), (int) ($results['watching'] ?? 0)));

        // 4. Telegram
        $health = $telegram->health();
        $this->newLine();
        $this->info('Telegram');
        $this->line('  Configured and enabled: '.($health['enabled'] ? 'yes' : 'NO'));
        $this->line('  Alerts switch (dashboard): '.(Watchlist::alertsEnabled() ? 'ON' : 'OFF'));
        $this->line('  Swing turn alerts: '.(config('trading.strategy.topbottom_alerts', true) ? 'on' : 'off').' · breakout alerts: '.(config('trading.strategy.breakout_alerts', true) ? 'on' : 'off'));
        $this->line('  Last sent OK: '.($health['last_ok_at'] ?? 'never').' · last error: '.($health['last_error'] ?? 'none').($health['last_error_at'] ? " ({$health['last_error_at']})" : ''));
        if ($health['problem'] !== null) {
            $causes[] = $health['problem'];
        }
        if (! Watchlist::alertsEnabled()) {
            $causes[] = 'Telegram alerts are switched OFF on the dashboard.';
        }
        if ($this->option('send-test')) {
            $id = $telegram->send('✅ <b>AFTE test</b>: the bot can reach this chat ('.now()->toDateTimeString().' UTC).', priority: true);
            $this->line('  Test message: '.($id !== null ? "sent (message id {$id})" : 'NOT sent · '.($telegram->health()['problem'] ?? 'see the last error above')));
        }

        // 5. Effective settings
        $this->newLine();
        $this->info('Settings in effect');
        $this->line('  Config cached: '.(app()->configurationIsCached() ? 'yes (run php artisan config:clear after editing .env)' : 'no'));
        $this->line('  Scan: '.implode(',', MarketScanService::scanIntervals()).' · trade: '.implode(',', (array) config('trading.strategy.trade_intervals', ['1h'])));
        $this->line(sprintf('  Risk %.1f%% per trade · seed $%.2f · early-breakout trading %s',
            (float) config('trading.sizing.risk_per_trade_pct', 2.0), (float) config('trading.seed_capital', 24.0),
            config('trading.strategy.intrabar_breakouts', false) ? 'ON' : 'off (alerts only)'));
        $this->line(sprintf('  Swing SAR: %s · %s · %s · TP1 %s · %s · coins %s · %d%% of the fund: $%.2f margin x %dx · open %d (details: php artisan trade:sar-check)',
            SwingSarTrader::enabled() ? 'ON' : 'off (SWING_SAR_ENABLED=false)', SwingSarTrader::interval(),
            config('trading.swing_sar.confirmed_only', true) ? 'confirmed flips only' : 'every flip incl. "?"',
            config('trading.swing_sar.take_profit', false) ? 'on' : 'off',
            'book '.implode(', ', array_map(fn (array $p): string => round($p[1] * 100).'% at +'.$p[0].'R', (array) config('trading.swing_sar.partials', [])))
                .' · stop '.implode(', ', array_map(fn (array $l): string => '+'.$l[0].'R→'.($l[1] == 0 ? 'BE' : $l[1].'R'), (array) config('trading.swing_sar.ladder', [])))
                .' · trail '.(float) config('trading.swing_sar.trail_atr', 1.5).' ATR from +'.(float) config('trading.swing_sar.trail_from_r', 1.5).'R',
            implode(',', SwingSarTrader::symbols()) ?: '-', (int) round(100 * (float) config('trading.swing_sar.fund_share', 0.5)),
            SwingSarTrader::marginPerTrade(TradingAccount::getForMode($mode)), (int) config('trading.swing_sar.leverage', 5),
            Trade::where('mode', $mode)->where('status', 'OPEN')->where('setup_tag', SwingSarTrader::SETUP)->count()));

        // 6. Last 24 hours
        $signals = CryptoSignal::where('source', '!=', 'backtest')->where('sent_at', '>=', now()->subDay())
            ->get(['passed_filters', 'auto_trade_status', 'telegram_sent']);
        $opened = Trade::where('opened_at', '>=', now()->subDay())->count();
        $this->newLine();
        $this->info('Last 24 hours');
        $this->line(sprintf('  Signals %d · passed filters %d · sent to Telegram %d · trades opened %d',
            $signals->count(), $signals->where('passed_filters', true)->count(), $signals->where('telegram_sent', true)->count(), $opened));
        $skips = $signals->where('passed_filters', true)
            ->groupBy(fn (CryptoSignal $s): string => DiagnoseTradingCommand::decision($s->auto_trade_status, true))
            ->map->count()->sortDesc()->take(3);
        foreach ($skips as $decision => $count) {
            $this->line("  {$count} × {$decision}");
        }

        $this->newLine();
        $this->info('LIKELY CAUSE');
        $allClear = $opened > 0
            ? "Nothing is blocked. The bot is working: {$opened} trade(s) opened in the last 24 hours (php artisan trade:review --days=1 lists them)."
            : 'Nothing is blocked. No setup qualified yet: about 1-2 trades and ~5 swing alerts a day are normal, and some days have none.';
        foreach ($causes ?: [$allClear] as $cause) {
            $this->line('  • '.$cause);
        }

        return self::SUCCESS;
    }
}
