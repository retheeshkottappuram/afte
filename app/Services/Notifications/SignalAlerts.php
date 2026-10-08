<?php

namespace App\Services\Notifications;

use App\Models\CryptoSignal;
use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Strategy\MarketScanService;
use App\Services\Strategy\OpportunityScorer;
use App\Services\Strategy\SetupStats;
use App\Services\Strategy\Signal;
use App\Services\Strategy\Watchlist;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Telegram messages for strategy signals: new signal (with measured setup stats and the
 * auto-trader decision), outcome follow-ups as replies, risk/health alerts and the daily summary.
 */
class SignalAlerts
{
    /**
     * Measured 2026-10-08 (12 months, top 50, 1h): of 1,312 leaning squeeze boxes that broke within 24 hours,
     * 82.5% broke in the lean direction (82.4% in the last 4 months alone). Direction only, not profit.
     */
    public const LEAN_HIT_RATE_NOTE = 'In a 12-month test about 4 in 5 of these setups broke in the stated direction (not every break keeps going).';

    public function __construct(
        protected TelegramGateway $gateway,
        protected SetupStats $stats,
        protected OpportunityScorer $opportunity
    ) {}

    /**
     * Announce a fresh signal (grade-filtered, de-duplicated) and store the Telegram message id.
     */
    public function announceSignal(Signal $signal, ?CryptoSignal $record, ?string $autoTradeStatus): bool
    {
        if ($record === null || $signal->isShadow || ! Watchlist::alertsEnabled()) {
            return false;
        }

        // Watchlist coins alert on every signal; others need a tradable signal of an alert grade.
        $watched = Watchlist::contains($signal->symbol);
        $gradeOk = in_array($signal->grade, (array) config('trading.strategy.telegram_grades', ['A', 'B']), true);
        if (! $watched && (! $signal->isTradable() || ! $gradeOk)) {
            return false;
        }

        if (! Cache::add("tg:signal:{$record->id}", true, now()->addDays(3))) {
            return false;
        }

        $messageId = $this->gateway->send($this->signalMessage($signal, $autoTradeStatus));
        if ($messageId === null) {
            return false;
        }

        $record->update(['telegram_sent' => true, 'telegram_message_id' => (string) $messageId]);

        return true;
    }

    /**
     * "Coiled for a breakout" list after an hourly scan: coins in a squeeze with every filter passing,
     * close to their breakout level. Each coin is announced once per 6 hours.
     *
     * @param  array<string, array<string, mixed>>  $watches  From the scan, keyed by symbol (with 'price')
     */
    public function breakoutWatchAlert(array $watches): bool
    {
        if ($watches === [] || ! Watchlist::alertsEnabled()) {
            return false;
        }

        // One direction per coin: only boxes that lean one way are announced (a box without a lean can
        // break either way, so it is alerted only once it actually breaks, by breakoutNowAlert).
        $rows = [];
        foreach ($watches as $key => $watch) {
            $price = (float) ($watch['price'] ?? 0);
            $entry = (float) ($watch['levels']['entry'] ?? $watch['level'] ?? 0);
            if ($price <= 0 || $entry <= 0 || ($watch['bias'] ?? null) !== $watch['side']) {
                continue;
            }
            $rows[] = ['symbol' => (string) ($watch['symbol'] ?? $key)] + $watch + ['distance_pct' => abs($entry - $price) / $price * 100];
        }
        usort($rows, fn (array $a, array $b): int => $a['distance_pct'] <=> $b['distance_pct']);

        $blocks = [];
        foreach ($rows as $row) {
            if (count($blocks) >= 6 || ! Cache::add("tg:coiled:{$row['symbol']}", true, now()->addHours(6))) {
                continue;
            }
            $isLong = $row['side'] === 'LONG';
            $levels = (array) ($row['levels'] ?? []);
            $entry = (float) ($levels['entry'] ?? $row['level']);
            $sl = (float) ($levels['sl'] ?? (((float) $row['box_high'] + (float) $row['box_low']) / 2));

            $block = sprintf("%s <b>%s %s</b>: breakout setup (%s)\n", $isLong ? '🟢' : '🔴', $isLong ? 'BUY' : 'SELL', $row['symbol'], $row['interval'] ?? '1h')
                .sprintf("Entry %s <code>%s</code> (now %s, %.1f%% away)\n", $isLong ? 'above' : 'below', $this->fmt($entry), $this->fmt((float) $row['price']), $row['distance_pct'])
                .sprintf('Stop <code>%s</code> (−%.2f%%)', $this->fmt($sl), abs($entry - $sl) / $entry * 100);
            if (isset($levels['tp1'], $levels['tp2'])) {
                $block .= " · TP1 <code>{$this->fmt((float) $levels['tp1'])}</code> · TP2 <code>{$this->fmt((float) $levels['tp2'])}</code>";
            }
            $blocks[] = $block."\nWhy: ".e(self::whyLine($row['side'], (array) ($row['lean'] ?? []), (array) ($row['indicators'] ?? []), $row['regime_side'] ?? null));
        }

        if ($blocks === []) {
            return false;
        }

        $auto = (bool) config('trading.strategy.intrabar_breakouts', true)
            ? 'The bot enters automatically when the break is confirmed (1% risk).'
            : 'The bot trades only the confirmed candle close.';

        return $this->gateway->send("🔭 <b>Breakout setups</b> (this 1h candle)\n\n".implode("\n\n", $blocks)
            ."\n\nEnter only if price breaks the entry level; the ⚡ BREAKING OUT alert confirms it with volume. "
            .self::LEAN_HIT_RATE_NOTE." {$auto}") !== null;
    }

    /**
     * Plain-language reasons for the direction: box lean (edge, structure, volume), 4h trend, momentum.
     *
     * @param  array<string, mixed>  $lean  From StrategyEngine::boxBiasDetail()
     * @param  array<string, mixed>  $indicators
     */
    public static function whyLine(string $side, array $lean, array $indicators, ?string $regimeSide): string
    {
        $isLong = $side === 'LONG';
        $parts = [];
        if (isset($lean['position']) && ($isLong ? (float) $lean['position'] >= 0.75 : (float) $lean['position'] <= 0.25)) {
            $parts[] = $isLong ? 'closing at the box top' : 'closing at the box bottom';
        }
        if (isset($lean['drift']) && ($isLong ? (float) $lean['drift'] > 0.02 : (float) $lean['drift'] < -0.02)) {
            $parts[] = $isLong ? 'higher lows' : 'lower highs';
        }
        $ratio = (float) ($lean['volume_ratio'] ?? 0);
        if ($ratio > 0 && ($isLong ? $ratio > 1.2 : $ratio < 1 / 1.2)) {
            $parts[] = $isLong ? sprintf('buy volume %.1f× sell', $ratio) : sprintf('sell volume %.1f× buy', 1 / $ratio);
        }
        if ($regimeSide === $side) {
            $parts[] = $isLong ? '4h trend up' : '4h trend down';
        } elseif (in_array($regimeSide, ['LONG', 'SHORT'], true)) {
            $parts[] = 'against the 4h trend';
        }
        if (isset($indicators['rsi'])) {
            $parts[] = sprintf('RSI %d', round((float) $indicators['rsi']));
        }
        if ((float) ($indicators['volume_ratio'] ?? 0) >= 1.5) {
            $parts[] = sprintf('volume %.1f× average', (float) $indicators['volume_ratio']);
        }

        return $parts === [] ? 'squeeze breakout' : implode(' · ', $parts);
    }

    /**
     * Instant "breaking out now" alert from the minute watcher (priority: never rate-limited).
     */
    public function breakoutNowAlert(Signal $signal, float $level, float $volumePace, ?string $autoTradeStatus, ?array $watch = null): bool
    {
        if (! Watchlist::alertsEnabled() || ! Cache::add("tg:breakout:{$signal->symbol}:{$signal->time}", true, now()->addHours(2))) {
            return false;
        }

        $isLong = $signal->isLong();
        $why = self::whyLine($signal->side, (array) ($watch['lean'] ?? []), ['volume_ratio' => $volumePace] + $signal->indicators, $watch['regime_side'] ?? ($signal->indicators['regime'] ?? null));
        $html = sprintf("⚡ %s <b>%s %s: BREAKING OUT</b>\n", $isLong ? '🟢' : '🔴', $isLong ? 'BUY' : 'SELL', $signal->symbol)
            .sprintf("Price %s %s %s · volume %.1fx pace\n", $this->fmt($signal->entry), $isLong ? 'above' : 'below', $this->fmt($level), $volumePace)
            .sprintf("Entry ≈<code>%s</code> · Stop <code>%s</code> (−%.2f%%)\n", $this->fmt($signal->entry), $this->fmt($signal->stopLoss), $signal->slPct())
            ."TP1 <code>{$this->fmt($signal->tp1)}</code> · TP2 <code>{$this->fmt($signal->tp2)}</code> · TP3 <code>{$this->fmt($signal->tp3)}</code>\n"
            .'Why: '.e($why)."\n"
            .'Early entry (before the candle closes): higher fake-out risk. Exit if the hourly candle closes back inside the box.';

        if ($autoTradeStatus) {
            $html .= "\nAuto-trader: ".e($autoTradeStatus);
        }

        try {
            $url = route('signals.dashboard', ['symbol' => $signal->symbol, 'interval' => $signal->interval]);
            if (str_starts_with($url, 'http') && ! str_contains($url, 'localhost')) {
                $html .= "\n<a href=\"{$url}\">Open chart</a>";
            }
        } catch (Throwable) {
            // URL generation unavailable
        }

        return $this->gateway->send($html, null, priority: true) !== null;
    }

    /**
     * Whether a recorded signal was (or would be) sent to Telegram, and why not.
     *
     * @return array{sent: bool, reason: string}
     */
    public function sendDecision(CryptoSignal $record): array
    {
        if ($record->telegram_sent) {
            return ['sent' => true, 'reason' => 'Sent'];
        }

        $features = (array) ($record->features ?? []);

        return ['sent' => false, 'reason' => match (true) {
            ! $this->gateway->isEnabled() => 'Telegram is not configured or disabled',
            ! Watchlist::alertsEnabled() => 'Alerts are switched OFF',
            (bool) $record->is_shadow => 'Tracked setup (shadow), never alerted',
            ! $record->passed_filters && ! Watchlist::contains($record->symbol) => 'Filters failed: '.($record->auto_trade_status ? preg_replace('/^\[\w+\] skipped: (Filters failed: )?/', '', (string) $record->auto_trade_status) : 'market filters'),
            ! in_array($record->grade, (array) config('trading.strategy.telegram_grades', ['A', 'B']), true) && ! Watchlist::contains($record->symbol) => "Grade {$record->grade} is below the alert grade",
            $record->source === 'backtest' => 'Backtest record',
            ($features['tradable'] ?? true) === false => 'Not tradable when recorded',
            default => 'Not sent (rate limit, or Telegram error at the time)',
        }];
    }

    /**
     * Reply to the original signal message with its outcome.
     */
    public function announceOutcome(CryptoSignal $signal): void
    {
        if (empty($signal->telegram_message_id) || ! Cache::add("tg:outcome:{$signal->id}", true, now()->addDays(7))) {
            return;
        }

        $r = (float) $signal->r_multiple;
        $sign = $r >= 0 ? '+' : '';
        $text = match ($signal->outcome) {
            'TP2' => "✅✅ {$signal->symbol}: TP2 reached. Result {$sign}{$r}R after fees.",
            'TP1' => "✅ {$signal->symbol}: TP1 hit, runner closed. Result {$sign}{$r}R after fees.",
            'BE' => "➖ {$signal->symbol}: closed at breakeven ({$sign}{$r}R).",
            'SL' => "❌ {$signal->symbol}: stop-loss hit. Result {$sign}{$r}R.",
            'EXPIRED' => "⏳ {$signal->symbol}: closed by time stop. Result {$sign}{$r}R.",
            default => null,
        };

        if ($text !== null) {
            $this->gateway->send($text."\n".self::outcomeTradeNote($signal->auto_trade_status), (int) $signal->telegram_message_id);
        }
    }

    /**
     * Outcome replies track the signal itself (as if entered at the signal price), whether or not the bot
     * traded it. Say which, so a "TP2 reached" never reads like a trade that did not happen.
     */
    public static function outcomeTradeNote(?string $autoTradeStatus): string
    {
        $status = trim((string) preg_replace('/^\[\w+\] /', '', (string) $autoTradeStatus));

        return match (true) {
            $status === 'taken' => '🤖 The bot traded this signal; its own result is in the trade-closed message.',
            $status === '' => '📊 Signal result only: the bot did not trade it (it was not an auto-trade candidate).',
            default => '📊 Signal result only: the bot did not trade it ('.preg_replace('/^skipped: /', '', $status).').',
        };
    }

    /**
     * High-priority risk / health alert (never rate-limited).
     */
    public function riskAlert(string $mode, string $title, string $details): void
    {
        $this->gateway->send('🚨 <b>'.e($title).'</b> ['.strtoupper($mode)."]\n\n".e($details), null, priority: true);
    }

    /**
     * End-of-day summary for the active mode.
     */
    public function dailySummary(string $mode): void
    {
        $account = TradingAccount::getForMode($mode);
        $since = now()->subDay();
        $closed = Trade::where('mode', $mode)->where('status', 'CLOSED')->where('closed_at', '>=', $since)->get();
        $wins = $closed->filter(fn (Trade $t): bool => (float) $t->net_pnl > 0)->count();
        $dayPnl = round((float) $closed->sum('net_pnl'), 4);
        $best = $closed->sortByDesc('net_pnl')->first();
        $worst = $closed->sortBy('net_pnl')->first();
        $open = Trade::where('mode', $mode)->where('status', 'OPEN')->get();

        $setupLines = collect($this->stats->all())
            ->filter(fn (array $s): bool => ($s['d30']['n'] ?? 0) > 0)
            ->sortByDesc(fn (array $s): float => (float) ($s['d30']['expectancy'] ?? -99))
            ->take(3)
            ->map(fn (array $s): string => sprintf('• %s: %s%% win, %+.2fR avg (n=%d)', $s['label'], $s['d30']['win_rate'], $s['d30']['expectancy'], $s['d30']['n']))
            ->implode("\n");

        $sign = $dayPnl >= 0 ? '+' : '';
        $html = '📊 <b>Daily summary ['.strtoupper($mode)."]</b>\n"
            ."Balance: \${$account->balance}\n"
            ."Last 24h: {$closed->count()} trades, {$wins} wins, {$sign}\${$dayPnl}\n"
            .($best ? "Best: {$best->symbol} \$".round((float) $best->net_pnl, 4)."\n" : '')
            .($worst && $closed->count() > 1 ? "Worst: {$worst->symbol} \$".round((float) $worst->net_pnl, 4)."\n" : '')
            .'Open positions: '.($open->isEmpty() ? 'none' : $open->map(fn (Trade $t): string => "{$t->symbol} {$t->side}")->implode(', '))
            .($setupLines !== '' ? "\n\n<b>Top setups (30d)</b>\n{$setupLines}" : '');

        $this->gateway->send($html, null, priority: true);
    }

    protected function signalMessage(Signal $signal, ?string $autoTradeStatus): string
    {
        $icon = $signal->isLong() ? '🟢' : '🔴';
        $stats = $this->stats->forSetup($signal->setup, null, 90, $signal->interval);
        $statsLine = $stats['n'] > 0
            ? sprintf('%s%% win · %+.2fR avg · n=%d (90d, %s)', $stats['win_rate'], $stats['expectancy'], $stats['n'], $stats['source'])
            : 'No track record yet (new setup data)';
        $ai = $signal->aiProbability !== null ? ' · AI '.round($signal->aiProbability * 100).'%' : '';
        $stars = str_repeat('★', $signal->stars()).str_repeat('☆', 5 - $signal->stars());
        $confluences = $signal->confluences !== [] ? "\nWhy: ".e(implode(', ', $signal->confluences)) : '';
        $aiReasons = $signal->aiReasons !== [] ? "\nAI factors: ".e(implode(', ', $signal->aiReasons)) : '';

        $score = $this->opportunity->score($signal, $signal->entry);
        $html = "{$icon} <b>{$signal->side} {$signal->symbol}</b> · {$signal->setupLabel} · Grade {$signal->grade} {$stars}{$ai}\n"
            ."Opportunity: <b>{$score['score']}/100</b> ({$score['label']})\n"
            ."Entry: <code>{$this->fmt($signal->entry)}</code>\n"
            .sprintf("Stop: <code>%s</code> (%.2f%%)\n", $this->fmt($signal->stopLoss), $signal->slPct())
            ."TP1: <code>{$this->fmt($signal->tp1)}</code> (1.5R) · TP2: <code>{$this->fmt($signal->tp2)}</code> (3R)\n"
            ."Track record: {$statsLine}"
            .$confluences
            .$aiReasons
            ."\nTimeframe: {$signal->interval}, signal on closed candle";

        if (! $signal->isTradable()) {
            $html .= "\n⚠️ Watchlist alert, NOT tradable: ".e(implode('; ', $signal->failedFilters()));
        }

        if ($autoTradeStatus) {
            $html .= "\nAuto-trader: ".e($autoTradeStatus);
        }

        try {
            $url = route('signals.dashboard', ['symbol' => $signal->symbol, 'interval' => $signal->interval]);
            if (str_starts_with($url, 'http') && ! str_contains($url, 'localhost')) {
                $html .= "\n<a href=\"{$url}\">Open chart</a>";
            }
        } catch (Throwable) {
            // URL generation unavailable (e.g. no APP_URL)
        }

        return $html;
    }

    protected function fmt(float $price): string
    {
        return number_format($price, MarketScanService::priceDecimals($price), '.', '');
    }
}
