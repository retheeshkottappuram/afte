<?php

namespace App\Services\Notifications;

use App\Models\Trade;
use App\Services\Trading\LabTrader;
use App\Services\Trading\SwingSarTrader;

/**
 * Trade lifecycle messages, tagged [PAPER] / [LIVE]. Follow-ups (breakeven, TP1, close)
 * are threaded as replies to the "opened" message.
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
            .match ($trade->setup_tag) {
                SwingSarTrader::SETUP => $this->sarPlan($trade),
                LabTrader::SETUP => 'Exit: trailing stop moved on each 4h close'
                    .(str_contains((string) ($trade->meta['setup_label'] ?? ''), 'momentum') ? ', or when the 7-day momentum turns' : '')
                    ." (1-week live trial)\n",
                default => "TP1: <code>{$this->fmt($trade->tp1_price)}</code> · TP2: <code>{$this->fmt($trade->tp2_price)}</code>\n",
            }
        ."Size: \${$notional} at {$trade->leverage}x, margin \${$trade->margin_used}"
        .($risk !== null ? "\nRisk: \${$risk} (".($trade->meta['risk_pct'] ?? '?').'% of equity)' : '');

        $messageId = $this->gateway->send($html);
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

    public function notifyStopTrailed(Trade $trade, float $stopPrice, float $price, string $why = 'SuperTrend line'): void
    {
        $locked = ($stopPrice - (float) $trade->entry_price) * ($trade->isLong() ? 1 : -1) >= 0;
        $risk = abs((float) $trade->entry_price - $stopPrice) * (float) $trade->remaining_quantity;
        $this->reply($trade, "↗️ {$this->tag($trade)} {$trade->symbol}: stop moved to <code>{$this->fmt($stopPrice)}</code> ({$why}, price {$this->fmt($price)})."
            .($locked ? ' Profit is now locked in.' : sprintf(' Worst case now −$%.2f.', $risk)));
    }

    public function notifyStopEdited(Trade $trade, float $stopPrice, float $atStopUsd): void
    {
        $this->reply($trade, "✏️ {$this->tag($trade)} {$trade->symbol}: stop changed by you to <code>{$this->fmt($stopPrice)}</code>."
            .($atStopUsd >= 0 ? sprintf(' Profit locked: +$%.2f at the stop.', $atStopUsd) : sprintf(' Worst case now −$%.2f.', abs($atStopUsd)))
            .' The bot keeps moving it forward from here.');
    }

    public function notifyPartialBooked(Trade $trade, float $closedQty, float $pnl, float $atR): void
    {
        $sign = $pnl >= 0 ? '+' : '';
        $this->reply($trade, "🎯 {$this->tag($trade)} {$trade->symbol}: booked {$closedQty} at +{$atR}R for {$sign}\${$pnl}. Left: {$trade->remaining_quantity}.");
    }

    public function notifyTp1Hit(Trade $trade, float $closedQty, float $pnl): void
    {
        $sign = $pnl >= 0 ? '+' : '';
        $this->reply($trade, "🎯 {$this->tag($trade)} {$trade->symbol}: TP1 hit, booked {$closedQty} for {$sign}\${$pnl}. Stop locked in profit at <code>{$this->fmt($trade->current_sl)}</code>; the rest trails.");
    }

    public function notifyTp2Hit(Trade $trade, float $closedQty, float $pnl): void
    {
        $sign = $pnl >= 0 ? '+' : '';
        $this->reply($trade, "🎯 {$this->tag($trade)} {$trade->symbol}: TP2 reached ({$sign}\${$pnl}).");
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

    protected function reply(Trade $trade, string $html): void
    {
        if (! $this->shouldNotify($trade)) {
            return;
        }

        $replyTo = isset($trade->meta['telegram_message_id']) ? (int) $trade->meta['telegram_message_id'] : null;
        $this->gateway->send($html, $replyTo);
    }

    /**
     * Swing SAR exit plan in dollars, so the stop and the profit lock can be compared.
     */
    protected function sarPlan(Trade $trade): string
    {
        $entry = (float) $trade->entry_price;
        $qty = (float) $trade->quantity;
        $risk = abs($entry - (float) $trade->initial_sl);
        $dir = $trade->isLong() ? 1 : -1;
        $lines = sprintf("Stop loss: −\$%.2f (%.2f%%) if hit%s\n", $qty * $risk, $entry > 0 ? $risk / $entry * 100 : 0,
            str_contains((string) ($trade->meta['setup_label'] ?? ''), '"?" flip') ? ' (unconfirmed "?" flip: tight stop)' : '');
        foreach ((array) config('trading.swing_sar.partials', []) as [$atR, $share]) {
            $lines .= sprintf("Book %d%% at <code>%s</code> (+%sR, +\$%.2f)\n", round($share * 100), $this->fmt($entry + $dir * $atR * $risk), $atR, $qty * $share * $atR * $risk);
        }
        foreach ((array) config('trading.swing_sar.ladder', []) as [$atR, $stopR]) {
            $lines .= sprintf("At +%sR the stop moves to %s\n", $atR, $stopR == 0 ? 'breakeven' : '<code>'.$this->fmt($entry + $dir * $stopR * $risk).'</code> ('.($stopR > 0 ? '+' : '').$stopR.'R)');
        }
        if ((float) config('trading.swing_sar.trail_from_r', 1.5) > 0) {
            $lines .= sprintf("From +%sR the stop trails %s ATR behind the best price\n", (float) config('trading.swing_sar.trail_from_r', 1.5), (float) config('trading.swing_sar.trail_atr', 1.5));
        }

        return $lines.'Exit: the trailing stop or the opposite swing flip ('.SwingSarTrader::interval().")\n";
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
            'TIME_STOP' => 'time stop: no progress in 12h',
            'MAX_HOLD_TIME' => 'max hold time',
            'OPPOSITE_SIGNAL' => 'opposite signal',
            'MANUAL_CLOSE' => 'closed manually',
            'DAY_END' => 'end of the day',
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
