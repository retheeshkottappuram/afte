<?php

namespace App\Services\Trading;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Binance\BinanceFuturesClient;
use App\Services\Notifications\TelegramNotifier;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class DynamicTradeManager
{
    public function __construct(
        protected BinanceFuturesClient $client,
        protected RiskManager $riskManager,
        protected TelegramNotifier $notifier
    ) {}

    /**
     * Manage an active trade against current market price.
     *
     * @return array{status: string, message: string}
     */
    public function manageTrade(Trade $trade, ?float $currentPrice = null): array
    {
        if (! $trade->isOpen()) {
            return ['status' => 'ignored', 'message' => 'Trade is not open.'];
        }

        try {
            $price = $currentPrice ?? $this->client->getMarkPrice($trade->symbol);
            if ($price <= 0) {
                return ['status' => 'error', 'message' => 'Invalid mark price.'];
            }

            // Update highest / lowest peaks
            if ($trade->highest_price === null || $price > $trade->highest_price) {
                $trade->highest_price = $price;
            }
            if ($trade->lowest_price === null || $price < $trade->lowest_price) {
                $trade->lowest_price = $price;
            }

            // 1. Check Stop Loss Execution
            if ($this->isStopLossTriggered($trade, $price)) {
                $reason = $trade->stage === 'TRAILING' ? 'TRAILING_STOP' : ($trade->be_locked ? 'BREAKEVEN_STOP' : 'STOP_LOSS');

                return $this->closeTrade($trade, $price, $reason);
            }

            // 2. Check Breakeven Protection Trigger
            $this->checkBreakeven($trade, $price);

            // 3. Check TP1 Partial Booking (+2.0%)
            $this->checkTp1($trade, $price);

            // 4. Check TP2 Partial Booking (+4.0%)
            $this->checkTp2($trade, $price);

            // 5. Update Dynamic Trailing Stop on Runner
            $this->updateTrailingStop($trade, $price);

            $trade->save();

            return ['status' => 'managed', 'message' => "Trade {$trade->id} active at \${$price}."];
        } catch (\Exception $e) {
            Log::error("Dynamic trade manager error on trade {$trade->id}: {$e->getMessage()}");

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Check if stop loss level is breached.
     */
    protected function isStopLossTriggered(Trade $trade, float $currentPrice): bool
    {
        if ($trade->isLong()) {
            return $currentPrice <= $trade->current_sl;
        }

        return $currentPrice >= $trade->current_sl;
    }

    /**
     * Breakeven logic: locks in Entry + fee buffer when price reaches threshold.
     */
    protected function checkBreakeven(Trade $trade, float $currentPrice): void
    {
        if ($trade->be_locked) {
            return;
        }

        $gainPctThreshold = (float) config('trading.management.be_gain_pct', 1.0);
        $bufferPct = (float) config('trading.management.be_fee_buffer_pct', 0.12);

        $gainPct = $trade->isLong()
            ? (($currentPrice - $trade->entry_price) / $trade->entry_price) * 100.0
            : (($trade->entry_price - $currentPrice) / $trade->entry_price) * 100.0;

        if ($gainPct >= $gainPctThreshold) {
            $newSl = $trade->isLong()
                ? $trade->entry_price * (1.0 + ($bufferPct / 100.0))
                : $trade->entry_price * (1.0 - ($bufferPct / 100.0));

            $trade->current_sl = round($newSl, 6);
            $trade->be_locked = true;
            if ($trade->stage === 'ENTRY') {
                $trade->stage = 'BE_LOCKED';
            }

            $this->updateExchangeStopLoss($trade, $trade->current_sl);
            $this->notifier->notifyBreakevenLocked($trade, $currentPrice);
        }
    }

    /**
     * TP1 Logic: Book 33% profit and ensure breakeven is locked.
     */
    protected function checkTp1(Trade $trade, float $currentPrice): void
    {
        if ($trade->tp1_hit) {
            return;
        }

        $isHit = $trade->isLong()
            ? ($currentPrice >= $trade->tp1_price)
            : ($currentPrice <= $trade->tp1_price);

        if (! $isHit) {
            return;
        }

        $ratio = (float) config('trading.management.tp1_close_ratio', 0.33);
        $closeQty = $this->client->formatQuantity($trade->symbol, $trade->quantity * $ratio);

        if ($closeQty > 0 && $closeQty < $trade->remaining_quantity) {
            $pnl = $trade->isLong()
                ? ($currentPrice - $trade->entry_price) * $closeQty
                : ($trade->entry_price - $currentPrice) * $closeQty;

            $trade->realized_pnl = round($trade->realized_pnl + $pnl, 4);
            $trade->remaining_quantity = round($trade->remaining_quantity - $closeQty, 6);
            $trade->tp1_hit = true;
            $trade->stage = 'TP1_HIT';

            // Ensure SL is at least at Breakeven
            if (! $trade->be_locked) {
                $bufferPct = (float) config('trading.management.be_fee_buffer_pct', 0.12);
                $trade->current_sl = $trade->isLong()
                    ? round($trade->entry_price * (1.0 + ($bufferPct / 100.0)), 6)
                    : round($trade->entry_price * (1.0 - ($bufferPct / 100.0)), 6);
                $trade->be_locked = true;
            }

            $this->notifier->notifyTp1Hit($trade, $closeQty, round($pnl, 2));

            if (in_array($trade->mode, ['live', 'testnet'], true)) {
                try {
                    $client = $this->client->forMode($trade->mode);
                    if ($client->hasCredentials()) {
                        $closeSide = $trade->isLong() ? 'SELL' : 'BUY';
                        $client->placeOrder([
                            'symbol' => $trade->symbol,
                            'side' => $closeSide,
                            'type' => 'MARKET',
                            'quantity' => $closeQty,
                            'reduceOnly' => 'true',
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error("Failed to execute TP1 on Binance for {$trade->symbol}: {$e->getMessage()}");
                }
            }
        }
    }

    /**
     * TP2 Logic: Book second 33% profit and trail SL to TP1 level.
     */
    protected function checkTp2(Trade $trade, float $currentPrice): void
    {
        if ($trade->tp2_hit || ! $trade->tp1_hit) {
            return;
        }

        $isHit = $trade->isLong()
            ? ($currentPrice >= $trade->tp2_price)
            : ($currentPrice <= $trade->tp2_price);

        if (! $isHit) {
            return;
        }

        $ratio = (float) config('trading.management.tp2_close_ratio', 0.33);
        $closeQty = $this->client->formatQuantity($trade->symbol, $trade->quantity * $ratio);

        if ($closeQty > 0 && $closeQty < $trade->remaining_quantity) {
            $pnl = $trade->isLong()
                ? ($currentPrice - $trade->entry_price) * $closeQty
                : ($trade->entry_price - $currentPrice) * $closeQty;

            $trade->realized_pnl = round($trade->realized_pnl + $pnl, 4);
            $trade->remaining_quantity = round($trade->remaining_quantity - $closeQty, 6);
            $trade->tp2_hit = true;
            $trade->stage = 'TRAILING';

            // Move SL up to TP1 price level!
            if ($trade->isLong()) {
                $trade->current_sl = max($trade->current_sl, $trade->tp1_price);
            } else {
                $trade->current_sl = min($trade->current_sl, $trade->tp1_price);
            }

            $this->notifier->notifyTp2Hit($trade, $closeQty, round($pnl, 2));

            if (in_array($trade->mode, ['live', 'testnet'], true)) {
                try {
                    $client = $this->client->forMode($trade->mode);
                    if ($client->hasCredentials()) {
                        $closeSide = $trade->isLong() ? 'SELL' : 'BUY';
                        $client->placeOrder([
                            'symbol' => $trade->symbol,
                            'side' => $closeSide,
                            'type' => 'MARKET',
                            'quantity' => $closeQty,
                            'reduceOnly' => 'true',
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error("Failed to execute TP2 on Binance for {$trade->symbol}: {$e->getMessage()}");
                }
            }
        }
    }

    /**
     * Trailing Stop on the remaining runner (34%).
     */
    protected function updateTrailingStop(Trade $trade, float $currentPrice): void
    {
        if ($trade->stage !== 'TRAILING') {
            return;
        }

        // Use estimated ATR or 2.0% trail distance
        $atr = (float) ($trade->meta['atr'] ?? ($trade->entry_price * 0.015));
        $trailDist = $atr * (float) config('trading.management.trailing_sl_atr_mult', 2.0);

        if ($trade->isLong()) {
            $candidateSl = round($trade->highest_price - $trailDist, 6);
            // Only trail upward
            if ($candidateSl > $trade->current_sl) {
                $trade->current_sl = $candidateSl;
                $this->updateExchangeStopLoss($trade, $candidateSl);
            }
        } else {
            $candidateSl = round($trade->lowest_price + $trailDist, 6);
            // Only trail downward
            if ($candidateSl < $trade->current_sl) {
                $trade->current_sl = $candidateSl;
                $this->updateExchangeStopLoss($trade, $candidateSl);
            }
        }
    }

    /**
     * Close out remaining position and finalize trade.
     *
     * @return array{status: string, message: string}
     */
    public function closeTrade(Trade $trade, float $exitPrice, string $reason): array
    {
        $closeQty = $trade->remaining_quantity;
        $remainingPnl = 0.0;

        if ($closeQty > 0) {
            $remainingPnl = $trade->isLong()
                ? ($exitPrice - $trade->entry_price) * $closeQty
                : ($trade->entry_price - $exitPrice) * $closeQty;
        }

        $totalPnl = round($trade->realized_pnl + $remainingPnl, 4);

        if (in_array($trade->mode, ['live', 'testnet'], true)) {
            try {
                $client = $this->client->forMode($trade->mode);
                if ($client->hasCredentials()) {
                    if ($closeQty > 0) {
                        $closeSide = $trade->isLong() ? 'SELL' : 'BUY';
                        $client->placeOrder([
                            'symbol' => $trade->symbol,
                            'side' => $closeSide,
                            'type' => 'MARKET',
                            'quantity' => $client->formatQuantity($trade->symbol, $closeQty),
                            'reduceOnly' => 'true',
                        ]);
                    }
                    // Clean up any remaining exchange-side conditional orders
                    $client->cancelAllAlgoOrders($trade->symbol);
                    $client->cancelAllOrders($trade->symbol);
                }
            } catch (\Exception $e) {
                Log::error("Failed to close live position on Binance for {$trade->symbol}: {$e->getMessage()}");
            }
        }

        // Calculate fee (0.05% taker on notional)
        $notional = $trade->quantity * $trade->entry_price;
        $fee = round($notional * 0.0005 * 2, 4);
        $netPnl = round($totalPnl - $fee, 4);

        $trade->exit_price = $exitPrice;
        $trade->exit_reason = $reason;
        $trade->realized_pnl = $netPnl;
        $trade->pnl_percent = $trade->margin_used > 0 ? round(($netPnl / $trade->margin_used) * 100, 2) : 0;
        $trade->fee_paid = $fee;
        $trade->remaining_quantity = 0.0;
        $trade->status = 'CLOSED';
        $trade->stage = 'CLOSED';
        $trade->closed_at = Carbon::now();
        $trade->save();

        // Update account statistics
        $account = TradingAccount::getForMode($trade->mode);
        $this->riskManager->handleTradeClosed($account, $trade);

        // Send Telegram alert
        $this->notifier->notifyTradeClosed($trade, $account->balance);

        return [
            'status' => 'closed',
            'message' => "Trade {$trade->id} closed at \${$exitPrice}. Net PnL: \${$netPnl} ({$trade->pnl_percent}%). Reason: {$reason}.",
        ];
    }

    /**
     * Update exchange-side Stop Loss on Binance Futures via Algo Orders API.
     */
    protected function updateExchangeStopLoss(Trade $trade, float $newSl): void
    {
        if (! in_array($trade->mode, ['live', 'testnet'], true)) {
            return;
        }

        try {
            $client = $this->client->forMode($trade->mode);
            if (! $client->hasCredentials()) {
                return;
            }

            // Cancel existing algo orders for symbol to avoid conflicting stops
            $client->cancelAllAlgoOrders($trade->symbol);

            // Place updated Stop Loss algo order
            $closeSide = $trade->isLong() ? 'SELL' : 'BUY';
            $res = $client->placeStopLoss($trade->symbol, $closeSide, $newSl, null, true);

            $meta = $trade->meta ?? [];
            $meta['binance_sl_algo_id'] = $res['algoId'] ?? null;
            $trade->meta = $meta;
            $trade->save();
        } catch (\Throwable $e) {
            Log::warning("Failed to update exchange Stop Loss on Binance for {$trade->symbol}: {$e->getMessage()}");
        }
    }
}
