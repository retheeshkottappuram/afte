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

            // 2. Anti-Giveback Circuit: Check Peak Reversal Exit (Locks green profit before retracement)
            $peakExit = $this->checkPeakReversalExit($trade, $price);
            if ($peakExit !== null) {
                return $peakExit;
            }

            // 3. Check Breakeven Protection (Locks early at +0.30% gain or +3.0% ROE to guarantee no loss)
            $this->checkBreakeven($trade, $price);

            // 4. Check Stagnation & Dead-Position Timeout Pruner (Releases trapped margin on slow trades)
            $stagnationExit = $this->checkStagnationExit($trade, $price);
            if ($stagnationExit !== null) {
                return $stagnationExit;
            }

            // 5. Check TP1 Partial Booking (Close 40% at +0.65% gain / +6.5% ROE and lock guaranteed profit)
            $this->checkTp1($trade, $price);

            // 6. Check TP2 Partial Booking (Close 30% at +1.25% gain / +12.5% ROE and trail SL to TP1)
            $this->checkTp2($trade, $price);

            // 7. Dynamic Trailing Stop & Stepped Ratchet Profit Protection on Runner
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
     * Anti-Giveback Circuit: Peak Reversal Exit.
     * Prevents profitable trades from retracing into red or breakeven.
     * If price reached at least +0.40% gain (+4.0% ROE) and pulls back by >= 35% of peak gain,
     * or if gain falls to <= 0.15% after peaking >= +0.40%, execute immediate profit-take exit.
     *
     * @return array{status: string, message: string}|null
     */
    protected function checkPeakReversalExit(Trade $trade, float $currentPrice): ?array
    {
        $minPeakGainPct = (float) config('trading.management.peak_profit_min_gain_pct', 0.40);
        $maxGivebackPct = (float) config('trading.management.peak_profit_giveback_pct', 35.0);

        if ($trade->entry_price <= 0) {
            return null;
        }

        $highestPrice = $trade->highest_price ?? $trade->entry_price;
        $lowestPrice = $trade->lowest_price ?? $trade->entry_price;

        $peakGainPct = $trade->isLong()
            ? (($highestPrice - $trade->entry_price) / $trade->entry_price) * 100.0
            : (($trade->entry_price - $lowestPrice) / $trade->entry_price) * 100.0;

        if ($peakGainPct < $minPeakGainPct) {
            return null;
        }

        $currentGainPct = $trade->isLong()
            ? (($currentPrice - $trade->entry_price) / $trade->entry_price) * 100.0
            : (($trade->entry_price - $currentPrice) / $trade->entry_price) * 100.0;

        $givebackRatio = $peakGainPct > 0 ? (($peakGainPct - $currentGainPct) / $peakGainPct) * 100.0 : 0.0;

        if (($givebackRatio >= $maxGivebackPct && $currentGainPct > 0.05) || ($currentGainPct <= 0.15 && $peakGainPct >= $minPeakGainPct)) {
            Log::info("Peak profit protection triggered on trade {$trade->id}: Peak was +{$peakGainPct}%, current +{$currentGainPct}% ({$givebackRatio}% surrendered). Preserving profit!");

            return $this->closeTrade($trade, $currentPrice, 'PEAK_PROFIT_PROTECTION');
        }

        return null;
    }

    /**
     * Breakeven logic: locks in Entry + fee buffer when price reaches +0.30% gain or +3.0% ROE.
     * Prevents winning positions from ever turning into losses.
     */
    protected function checkBreakeven(Trade $trade, float $currentPrice): void
    {
        if ($trade->be_locked) {
            return;
        }

        $gainPctThreshold = (float) config('trading.management.be_gain_pct', 0.30);
        $bufferPct = (float) config('trading.management.be_fee_buffer_pct', 0.08);
        $roeThreshold = (float) config('trading.management.be_roe_threshold', 3.0);

        $gainPct = $trade->isLong()
            ? (($currentPrice - $trade->entry_price) / $trade->entry_price) * 100.0
            : (($trade->entry_price - $currentPrice) / $trade->entry_price) * 100.0;

        $currentRoe = $trade->calculateRoe($currentPrice);

        if ($gainPct >= $gainPctThreshold || $currentRoe >= $roeThreshold) {
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
     * Stagnation & Dead-Position Timeout Pruner.
     * Closes trades holding longer than timeout to recycle margin for explosive setups.
     *
     * @return array{status: string, message: string}|null
     */
    protected function checkStagnationExit(Trade $trade, float $currentPrice): ?array
    {
        if (! $trade->opened_at) {
            return null;
        }

        $timeoutMinutes = (int) config('trading.management.stagnation_timeout_minutes', 30);
        $hardTimeout = (int) config('trading.management.max_hold_minutes', 60);

        if ($timeoutMinutes <= 0) {
            return null;
        }

        $ageMinutes = $trade->opened_at->diffInMinutes(Carbon::now());
        if ($ageMinutes < $timeoutMinutes) {
            return null;
        }

        $gainPct = $trade->isLong()
            ? (($currentPrice - $trade->entry_price) / $trade->entry_price) * 100.0
            : (($trade->entry_price - $currentPrice) / $trade->entry_price) * 100.0;

        // Positive profit stagnation: Holding > 30m with positive profit (+0.10% or more), take the banked win!
        if ($gainPct >= 0.10) {
            return $this->closeTrade($trade, $currentPrice, 'STAGNATION_PROFIT_TAKE');
        }

        // Hard timeout: Holding > 60m without reaching TP1, exit to prevent blocking capital
        if ($ageMinutes >= $hardTimeout && ! $trade->tp1_hit) {
            return $this->closeTrade($trade, $currentPrice, 'STAGNATION_TIMEOUT_EXIT');
        }

        return null;
    }

    /**
     * TP1 Logic: Book 50% profit and guarantee breakeven lock.
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

        $ratio = (float) config('trading.management.tp1_close_ratio', 0.40);
        $closeQty = $this->client->formatQuantity($trade->symbol, $trade->quantity * $ratio);

        if ($closeQty > 0 && $closeQty < $trade->remaining_quantity) {
            $pnl = $trade->isLong()
                ? ($currentPrice - $trade->entry_price) * $closeQty
                : ($trade->entry_price - $currentPrice) * $closeQty;

            $trade->realized_pnl = round($trade->realized_pnl + $pnl, 4);
            $trade->remaining_quantity = round($trade->remaining_quantity - $closeQty, 6);
            $trade->margin_used = round(($trade->remaining_quantity * $trade->entry_price) / max(1, $trade->leverage), 4);
            $trade->tp1_hit = true;
            $trade->stage = 'TP1_HIT';

            // Ensure SL is elevated to at least Breakeven + small buffer (or lock1 SL)
            $bufferPct = (float) config('trading.management.be_fee_buffer_pct', 0.08);
            $lock1Sl = (float) config('trading.management.lock1_sl_pct', 0.18);
            $minElevatedPct = max($bufferPct, $lock1Sl);
            $targetSl = $trade->isLong()
                ? round($trade->entry_price * (1.0 + ($minElevatedPct / 100.0)), 6)
                : round($trade->entry_price * (1.0 - ($minElevatedPct / 100.0)), 6);
            $trade->be_locked = true;
            $this->applyElevatedStopLoss($trade, $targetSl);

            $this->notifier->notifyTp1Hit($trade, $closeQty, round($pnl, 2));

            if ($trade->mode === 'live') {
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
     * TP2 Logic: Book 30% profit and trail SL to TP1 level.
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

        $ratio = (float) config('trading.management.tp2_close_ratio', 0.30);
        $closeQty = $this->client->formatQuantity($trade->symbol, $trade->quantity * $ratio);

        if ($closeQty > 0 && $closeQty < $trade->remaining_quantity) {
            $pnl = $trade->isLong()
                ? ($currentPrice - $trade->entry_price) * $closeQty
                : ($trade->entry_price - $currentPrice) * $closeQty;

            $trade->realized_pnl = round($trade->realized_pnl + $pnl, 4);
            $trade->remaining_quantity = round($trade->remaining_quantity - $closeQty, 6);
            $trade->margin_used = round(($trade->remaining_quantity * $trade->entry_price) / max(1, $trade->leverage), 4);
            $trade->tp2_hit = true;
            $trade->stage = 'TRAILING';

            // Move SL up to TP1 price level or lock2 SL level!
            $lock2Sl = (float) config('trading.management.lock2_sl_pct', 0.45);
            $lock2TargetSl = $trade->isLong()
                ? round($trade->entry_price * (1.0 + ($lock2Sl / 100.0)), 6)
                : round($trade->entry_price * (1.0 - ($lock2Sl / 100.0)), 6);

            $targetSl = $trade->isLong()
                ? max($trade->tp1_price, $lock2TargetSl)
                : min($trade->tp1_price, $lock2TargetSl);

            $this->applyElevatedStopLoss($trade, $targetSl);

            $this->notifier->notifyTp2Hit($trade, $closeQty, round($pnl, 2));

            if ($trade->mode === 'live') {
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
     * Dynamic Trailing Stop & High-Water Mark Stepped Profit Ratchet.
     */
    protected function updateTrailingStop(Trade $trade, float $currentPrice): void
    {
        // 1. High-Water Mark Stepped Ratchet Protection (permanently elevates SL as profit grows)
        $peakGainPct = $trade->isLong()
            ? ((($trade->highest_price ?? $currentPrice) - $trade->entry_price) / $trade->entry_price) * 100.0
            : (($trade->entry_price - ($trade->lowest_price ?? $currentPrice)) / $trade->entry_price) * 100.0;

        $lock1Gain = (float) config('trading.management.lock1_gain_pct', 0.45);
        $lock1Sl = (float) config('trading.management.lock1_sl_pct', 0.18);
        $lock2Gain = (float) config('trading.management.lock2_gain_pct', 0.90);
        $lock2Sl = (float) config('trading.management.lock2_sl_pct', 0.45);

        // Tier 3: Peak >= 1.50% (+15% ROE) -> Ratchet SL to lock in +0.95% profit (+9.5% ROE)
        if ($peakGainPct >= 1.50) {
            $steppedSl = $trade->isLong()
                ? $trade->entry_price * 1.0095
                : $trade->entry_price * 0.9905;
            $this->applyElevatedStopLoss($trade, $steppedSl);
        }
        // Tier 2: Peak >= 0.90% (+9.0% ROE) -> Ratchet SL to lock in +0.45% profit (+4.5% ROE)
        elseif ($peakGainPct >= $lock2Gain) {
            $steppedSl = $trade->isLong()
                ? $trade->entry_price * (1.0 + ($lock2Sl / 100.0))
                : $trade->entry_price * (1.0 - ($lock2Sl / 100.0));
            $this->applyElevatedStopLoss($trade, $steppedSl);
        }
        // Tier 1: Peak >= 0.45% (+4.5% ROE) -> Ratchet SL to lock in +0.18% profit (+1.8% ROE)
        elseif ($peakGainPct >= $lock1Gain) {
            $steppedSl = $trade->isLong()
                ? $trade->entry_price * (1.0 + ($lock1Sl / 100.0))
                : $trade->entry_price * (1.0 - ($lock1Sl / 100.0));
            $this->applyElevatedStopLoss($trade, $steppedSl);
        }

        // 2. ATR Trailing Stop (active on final runner in TRAILING stage)
        if ($trade->stage !== 'TRAILING') {
            return;
        }

        $atr = (float) ($trade->meta['atr'] ?? ($trade->entry_price * 0.010));
        $trailDist = $atr * (float) config('trading.management.trailing_sl_atr_mult', 1.4);

        if ($trade->isLong()) {
            $candidateSl = round(($trade->highest_price ?? $currentPrice) - $trailDist, 6);
            $this->applyElevatedStopLoss($trade, $candidateSl);
        } else {
            $candidateSl = round(($trade->lowest_price ?? $currentPrice) + $trailDist, 6);
            $this->applyElevatedStopLoss($trade, $candidateSl);
        }
    }

    /**
     * Helper to ratchet Stop Loss upward for Long (or downward for Short).
     */
    protected function applyElevatedStopLoss(Trade $trade, float $newSl): void
    {
        $newSl = round($newSl, 6);
        $shouldUpdate = $trade->isLong()
            ? ($newSl > $trade->current_sl)
            : ($newSl < $trade->current_sl);

        if ($shouldUpdate) {
            $trade->current_sl = $newSl;
            $this->updateExchangeStopLoss($trade, $trade->current_sl);
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

        if ($trade->mode === 'live') {
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
        if ($trade->mode !== 'live') {
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
