<?php

namespace App\Services\Notifications;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Trading\NearBreakoutTrader;

/**
 * Trade lifecycle messages, tagged [PAPER] / [LIVE]. Follow-ups (trail, stop changes, close) are threaded as replies
 * to the "opened" message. They are sent with priority, so the hourly message limit never holds a trade event back.
 */
class TelegramNotifier
{
    public function __construct(
        protected ?TelegramGateway $gateway = null
    ) {
        $this->gateway ??= new TelegramGateway;
    }

    /**
     * Paper trade events are suppressed when TELEGRAM_LIVE_ONLY is set.
     */
    public function shouldNotify(Trade $trade): bool
    {
        if (! $this->gateway->isEnabled()) {
            return false;
        }

        return ! ((bool) config('trading.telegram.live_only', false) && $trade->mode !== 'live');
    }

    /**
     * Send a plain HTML message (used by maintenance commands).
     */
    public function sendMessage(string $text): bool
    {
        return $this->gateway->send($text) !== null;
    }

    public function notifyTradeOpened(Trade $trade, int $score, string $setupLabel): void
    {
        if (! $this->shouldNotify($trade)) {
            return;
        }

        $icon = $trade->isLong() ? '🟢' : '🔴';
        $notional = round($trade->quantity * $trade->entry_price, 2);
        $risk = $trade->meta['risk_usd'] ?? null;
        $source = ($trade->meta['source'] ?? 'auto') === 'manual' ? 'Manual' : 'Auto';

        $html = "{$icon} <b>{$this->tag($trade)} {$trade->side} {$trade->symbol}</b> opened ({$source})\n"
            .'Setup: '.e($setupLabel)."\n"
            ."Entry: <code>{$this->fmt($trade->entry_price)}</code>\n"
            ."Stop: <code>{$this->fmt($trade->initial_sl)}</code>\n"
            .($trade->setup_tag === NearBreakoutTrader::SETUP
                ? "(safety stop for crashes; the script has none)\n"
                    ."Exit: a 1h close below EMA50 (<code>{$this->fmt((float) ($trade->meta['ema'] ?? 0))}</code>), or the trail: "
                    ."once price is 0.10 ATR above entry it closes 0.10 ATR below the highest price\n"
                    ."<i>Backtest on the real 5m price path: PF 0.92. Not proven.</i>\n"
                : '')
        ."Size: \${$notional} at {$trade->leverage}x, margin \${$trade->margin_used}"
        .($risk !== null ? "\nRisk: \${$risk} (".($trade->meta['risk_pct'] ?? '?').'% of equity)' : '');

        $messageId = $this->gateway->send($html, null, priority: true);
        if ($messageId !== null) {
            $meta = $trade->meta ?? [];
            $meta['telegram_message_id'] = $messageId;
            $trade->meta = $meta;
            if ($trade->exists) {
                $trade->save();
            }
        }
    }

    public function notifyBreakevenLocked(Trade $trade, float $stopPrice): void
    {
        $this->reply($trade, "🛡 {$this->tag($trade)} {$trade->symbol}: stop moved to breakeven <code>{$this->fmt($stopPrice)}</code>. This trade can no longer lose money (fees covered).");
    }

    public function notifyTrailArmed(Trade $trade, float $trailStop): void
    {
        $this->reply($trade, "↗️ {$this->tag($trade)} {$trade->symbol}: trail armed, exit if price falls to <code>{$this->fmt($trailStop)}</code> (moves up with the high).");
    }

    public function notifyPartlyClosedOnExchange(Trade $trade, float $closedQty, float $price): void
    {
        $this->reply($trade, "✂️ {$this->tag($trade)} {$trade->symbol}: {$closedQty} closed on Binance, not by the bot (near <code>{$this->fmt($price)}</code>). Left: {$trade->remaining_quantity}.");
    }

    public function notifyStopEdited(Trade $trade, float $stopPrice, float $atStopUsd): void
    {
        $this->reply($trade, "✏️ {$this->tag($trade)} {$trade->symbol}: stop changed by you to <code>{$this->fmt($stopPrice)}</code>."
            .($atStopUsd >= 0 ? sprintf(' Profit locked: +$%.2f at the stop.', $atStopUsd) : sprintf(' Worst case now −$%.2f.', abs($atStopUsd)))
            .'');
    }

    public function notifyTradeClosed(Trade $trade, float $accountBalance): void
    {
        $pnl = (float) ($trade->net_pnl ?: $trade->realized_pnl);
        $icon = $pnl >= 0 ? '✅' : '❌';
        $sign = $pnl >= 0 ? '+' : '';
        $risk = abs($trade->entry_price - $trade->initial_sl) * $trade->quantity;
        $rMultiple = $risk > 0 ? round($pnl / $risk, 2) : null;
        $held = $trade->opened_at ? $trade->opened_at->diffForHumans($trade->closed_at ?? now(), true) : '?';

        $this->reply($trade, "{$icon} <b>{$this->tag($trade)} {$trade->symbol} closed</b> ({$this->reason($trade->exit_reason)})\n"
            ."Exit: <code>{$this->fmt((float) $trade->exit_price)}</code>\n"
            ."Net PnL: <b>{$sign}\$".round($pnl, 4).'</b>'.($rMultiple !== null ? " ({$sign}{$rMultiple}R)" : '')." after fees\n"
            ."Held: {$held} · Balance: \${$accountBalance}"
            .(isset($trade->meta['exit_client_order_id']) && in_array($trade->exit_reason, ['MANUAL_CLOSE', 'EXCHANGE_OR_MANUAL_CLOSE'], true)
                ? '
Exit order: <code>'.e((string) $trade->meta['exit_client_order_id']).'</code>' : ''));
    }

    /**
     * High-priority risk / health alert. Never rate-limited and sent regardless of the live-only filter.
     */
    public function notifyRiskEvent(string $mode, string $title, string $details): void
    {
        $this->gateway->send('🚨 <b>'.e($title).'</b> ['.strtoupper($mode)."]\n\n".e($details), null, priority: true);
    }

    /**
     * End-of-day summary for the active mode.
     */
    public function dailySummary(string $mode): void
    {
        $account = TradingAccount::getForMode($mode);
        $closed = Trade::where('mode', $mode)->where('status', 'CLOSED')->where('closed_at', '>=', now()->subDay())->get();
        $wins = $closed->filter(fn (Trade $t): bool => (float) $t->net_pnl > 0)->count();
        $dayPnl = round((float) $closed->sum('net_pnl'), 4);
        $open = Trade::where('mode', $mode)->where('status', 'OPEN')->get();

        $sign = $dayPnl >= 0 ? '+' : '';
        $html = '📊 <b>Daily summary ['.strtoupper($mode)."]</b>\n"
            ."Balance: \${$account->balance}\n"
            ."Last 24h: {$closed->count()} trades, {$wins} wins, {$sign}\${$dayPnl}\n"
            .'Open positions: '.($open->isEmpty() ? 'none' : $open->map(fn (Trade $t): string => "{$t->symbol} {$t->side}")->implode(', '));

        $this->gateway->send($html, null, priority: true);
    }

    protected function reply(Trade $trade, string $html): void
    {
        if (! $this->shouldNotify($trade)) {
            return;
        }

        $replyTo = isset($trade->meta['telegram_message_id']) ? (int) $trade->meta['telegram_message_id'] : null;
        $this->gateway->send($html, $replyTo, priority: true);
    }

    protected function tag(Trade $trade): string
    {
        return '['.strtoupper($trade->mode).']';
    }

    protected function reason(?string $reason): string
    {
        return match ($reason) {
            'STOP_LOSS' => 'stop-loss',
            'BREAKEVEN_STOP' => 'breakeven stop',
            'TRAILING_STOP' => 'trailing stop',
            'EMA_EXIT' => '1h close fell below EMA50',
            'MANUAL_CLOSE' => 'closed manually',
            'EXCHANGE_OR_MANUAL_CLOSE', 'EXCHANGE_CLOSED' => 'closed on Binance, not by the bot',
            'BOT_REDUCE_ONLY' => 'bot reduce-only close',
            'KILL_SWITCH' => 'kill switch',
            default => strtolower(str_replace('_', ' ', (string) $reason)),
        };
    }

    protected function fmt(float $price): string
    {
        return rtrim(rtrim(number_format($price, $price >= 1 ? 4 : 8, '.', ''), '0'), '.');
    }
}
