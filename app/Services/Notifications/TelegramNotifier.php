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

    protected bool $liveOnly;

    public function __construct()
    {
        $this->enabled = (bool) config('trading.telegram.enabled', false);
        $this->botToken = (string) config('trading.telegram.bot_token', '');
        $this->chatId = (string) config('trading.telegram.chat_id', '');
        $this->liveOnly = (bool) config('trading.telegram.live_only', false);
    }

    /**
     * Determine if a notification should be dispatched for this trade.
     */
    public function shouldNotify(Trade $trade): bool
    {
        if (! $this->enabled || empty($this->botToken) || empty($this->chatId)) {
            return false;
        }

        if ($this->liveOnly && $trade->mode !== 'live') {
            return false;
        }

        return true;
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
        if (! $this->shouldNotify($trade)) {
            return;
        }
        $icon = $trade->isLong() ? '🟢' : '🔴';
        $modeTag = strtoupper($trade->mode);

        $notional = round($trade->quantity * $trade->entry_price, 2);
        $msg = "⚡ *TRADE EXECUTED [{$modeTag}]*\n\n"
            ."{$icon} *{$trade->symbol} {$trade->side}*\n"
            ."• *Amount Added (Margin):* \${$trade->margin_used} USDT\n"
            ."• *Position Size:* \${$notional} USDT ({$trade->leverage}x Leverage)\n"
            ."• *Entry Price:* \${$trade->entry_price}\n"
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
        if (! $this->shouldNotify($trade)) {
            return;
        }

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
        if (! $this->shouldNotify($trade)) {
            return;
        }

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
        if (! $this->shouldNotify($trade)) {
            return;
        }

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
        if (! $this->shouldNotify($trade)) {
            return;
        }

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

    /**
     * Notify when a SignalAlgo PRO chart signal triggers an automated entry.
     *
     * @param  array<string, mixed>  $signal
     */
    public function notifySignalAlgoEntry(Trade $trade, array $signal, string $timeframe): void
    {
        if (! $this->shouldNotify($trade)) {
            return;
        }

        $icon = $trade->isLong() ? '🟢' : '🔴';
        $modeTag = strtoupper($trade->mode);
        $score = $signal['score'] ?? 90;
        $grade = $signal['grade'] ?? 'A';
        $setupLabel = $signal['setup_label'] ?? 'CONFIRMED SIGNAL';
        $notional = round($trade->quantity * $trade->entry_price, 2);

        $msg = "⚡ *SIGNALALGO PRO™ CHART SIGNAL EXECUTED*\n\n"
            ."{$icon} *{$trade->symbol} {$trade->side}* [{$modeTag}]\n"
            ."• *Chart Timeframe:* `{$timeframe}`\n"
            ."• *Setup:* {$setupLabel} (Score: *{$score}/100*, Grade *{$grade}*)\n"
            ."• *Entry Price:* \${$trade->entry_price}\n"
            ."• *Stop Loss:* \${$trade->current_sl}\n"
            ."• *Target TP1:* \${$trade->tp1_price}\n"
            ."• *Target TP2:* \${$trade->tp2_price}\n"
            ."• *Margin Added:* \${$trade->margin_used} USDT ({$trade->leverage}x Leverage, \${$notional} size)\n\n"
            .'🎯 _Chart continuous monitor active: Following profit with dynamic ratchet & trailing SL. Reversal signal will immediately flip position._';

        $this->sendMessage($msg);
    }

    /**
     * Notify when an open trade is closed immediately due to a chart reversal signal.
     */
    public function notifyReversalExit(Trade $trade, string $reverseSide, float $exitPrice, float $pnl, string $newDirection): void
    {
        if (! $this->shouldNotify($trade)) {
            return;
        }

        $modeTag = strtoupper($trade->mode);
        $pnlSign = $pnl >= 0 ? '+' : '';
        $icon = $pnl >= 0 ? '💰' : '⚠️';

        $msg = "🔄 *SIGNALALGO PRO™ REVERSAL EXIT [{$modeTag}]*\n\n"
            ."{$icon} *{$trade->symbol} {$trade->side} Position Closed*\n"
            ."• *Exit Price:* \${$exitPrice}\n"
            ."• *Reason:* Chart printed opposite *{$reverseSide}* signal\n"
            ."• *Realized PnL:* {$pnlSign}\${$pnl} USD\n"
            ."• *Action:* Immediately reversing and entering *{$newDirection}* position on {$trade->symbol}!\n\n"
            .'⚡ _Active trend alignment maintained._';

        $this->sendMessage($msg);
    }
}
