<?php

namespace App\Services\Notifications;

use App\Models\CryptoSignal;
use App\Models\Trade;
use App\Models\TradingAccount;
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
    public function __construct(
        protected TelegramGateway $gateway,
        protected SetupStats $stats
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
            $this->gateway->send($text, (int) $signal->telegram_message_id);
        }
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
        $stats = $this->stats->forSetup($signal->setup, null, 90);
        $statsLine = $stats['n'] > 0
            ? sprintf('%s%% win · %+.2fR avg · n=%d (90d, %s)', $stats['win_rate'], $stats['expectancy'], $stats['n'], $stats['source'])
            : 'No track record yet (new setup data)';
        $ai = $signal->aiProbability !== null ? ' · AI '.round($signal->aiProbability * 100).'%' : '';
        $stars = str_repeat('★', $signal->stars()).str_repeat('☆', 5 - $signal->stars());
        $confluences = $signal->confluences !== [] ? "\nWhy: ".e(implode(', ', $signal->confluences)) : '';
        $aiReasons = $signal->aiReasons !== [] ? "\nAI factors: ".e(implode(', ', $signal->aiReasons)) : '';

        $html = "{$icon} <b>{$signal->side} {$signal->symbol}</b> · {$signal->setupLabel} · Grade {$signal->grade} {$stars}{$ai}\n"
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
        return rtrim(rtrim(number_format($price, $price >= 1 ? 4 : 8, '.', ''), '0'), '.');
    }
}
