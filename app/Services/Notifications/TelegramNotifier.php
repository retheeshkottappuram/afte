<?php

namespace App\Services\Notifications;

use App\Models\Trade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramNotifier
{
    protected bool $enabled;

    protected string $botToken;

    protected string $chatId;

    public function __construct()
    {
        $this->enabled = (bool) config('trading.telegram.enabled', false);
        $this->botToken = (string) config('trading.telegram.bot_token', '');
        $this->chatId = (string) config('trading.telegram.chat_id', '');
    }

    /**
     * Send Markdown message to Telegram chat.
     */
    public function sendMessage(string $text): bool
    {
        if (! $this->enabled || empty($this->botToken) || empty($this->chatId)) {
            return false;
        }

        try {
            $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
            $response = Http::timeout(5)->post($url, [
                'chat_id' => $this->chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true,
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::warning("Telegram notification failed: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Notify when a new trade is executed.
     */
    public function notifyTradeOpened(Trade $trade, int $score, string $aiReason): void
    {
        $icon = $trade->isLong() ? '🟢' : '🔴';
        $modeTag = strtoupper($trade->mode);

        $msg = "⚡ *TRADE EXECUTED [{$modeTag}]*\n\n"
            ."{$icon} *{$trade->symbol} {$trade->side}*\n"
            ."• *Entry:* \${$trade->entry_price}\n"
            ."• *Margin:* \${$trade->margin_used} ({$trade->leverage}x)\n"
            ."• *Stop Loss:* \${$trade->initial_sl}\n"
            ."• *TP1 (33%):* \${$trade->tp1_price}\n"
            ."• *TP2 (33%):* \${$trade->tp2_price}\n"
            ."• *Signal Score:* {$score}/100\n"
            ."• *AI Verdict:* {$aiReason}\n\n"
            .'_Targeting dynamic partials & trailing runner._';

        $this->sendMessage($msg);
    }

    /**
     * Notify when Stop Loss is moved to Breakeven.
     */
    public function notifyBreakevenLocked(Trade $trade, float $currentPrice): void
    {
        $msg = "🛡️ *BREAKEVEN LOCKED*\n\n"
            ."*{$trade->symbol} {$trade->side}*\n"
            ."• *Current Price:* \${$currentPrice}\n"
            ."• *New SL:* \${$trade->current_sl} (Entry + Fee Buffer)\n"
            .'• *Status:* Risk-free trade secured!';

        $this->sendMessage($msg);
    }

    /**
     * Notify when TP1 is booked.
     */
    public function notifyTp1Hit(Trade $trade, float $closedQty, float $pnl): void
    {
        $msg = "🎯 *TP1 HIT — 33% PROFIT BOOKED*\n\n"
            ."*{$trade->symbol} {$trade->side}*\n"
            ."• *Partial Profit:* +\${$pnl}\n"
            ."• *Remaining Qty:* {$trade->remaining_quantity}\n"
            .'• *Action:* SL locked at breakeven. Riding remaining position.';

        $this->sendMessage($msg);
    }

    /**
     * Notify when TP2 is booked.
     */
    public function notifyTp2Hit(Trade $trade, float $closedQty, float $pnl): void
    {
        $msg = "🎯🎯 *TP2 HIT — 33% PROFIT BOOKED*\n\n"
            ."*{$trade->symbol} {$trade->side}*\n"
            ."• *Partial Profit:* +\${$pnl}\n"
            ."• *Remaining Qty:* {$trade->remaining_quantity}\n"
            .'• *Action:* Trailing Stop activated on remaining 34% runner!';

        $this->sendMessage($msg);
    }

    /**
     * Notify when trade is fully closed.
     */
    public function notifyTradeClosed(Trade $trade, float $accountBalance): void
    {
        $icon = $trade->realized_pnl >= 0 ? '💰' : '🛑';
        $pnlSign = $trade->realized_pnl >= 0 ? '+' : '';

        $msg = "{$icon} *TRADE CLOSED [{$trade->symbol}]*\n\n"
            ."• *Side:* {$trade->side}\n"
            ."• *Exit Price:* \${$trade->exit_price}\n"
            ."• *Reason:* {$trade->exit_reason}\n"
            ."• *Realized PnL:* {$pnlSign}\${$trade->realized_pnl} ({$trade->pnl_percent}%)\n"
            ."• *Updated Balance:* \${$accountBalance}\n\n"
            .'_Compounding challenge in progress._';

        $this->sendMessage($msg);
    }
}
