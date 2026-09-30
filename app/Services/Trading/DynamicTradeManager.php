<?php

namespace App\Services\Trading;

use App\Models\Trade;
use App\Services\AI\ActiveTradeMonitorAgent;
use App\Services\Binance\BinanceFuturesClient;
use App\Services\Notifications\TelegramNotifier;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class DynamicTradeManager
{
    public function __construct(
        protected BinanceFuturesClient $client,
        protected RiskManager $riskManager,
        protected TelegramNotifier $notifier,
        protected ActiveTradeMonitorAgent $aiMonitor,
        protected TradeReconciler $tradeReconciler
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

            // 1. Check Hard Stop Loss Breach
            if ($this->isStopLossTriggered($trade, $price)) {
                $reason = $trade->stage === 'TRAILING' ? 'TRAILING_STOP' : ($trade->be_locked ? 'BREAKEVEN_STOP' : 'STOP_LOSS');

                return $this->closeTrade($trade, $price, $reason);
            }

            // 2. Active AI Sentinel Trade Monitor (Watches trend, volume, and momentum on every tick)
            $aiResult = $this->aiMonitor->monitorTrade($trade, $price);
            $meta = $trade->meta ?? [];
            $meta['ai_monitor'] = [
                'action' => $aiResult['action'],
                'decision' => $aiResult['decision'],
                'reason' => $aiResult['reason'],
                'target_price' => $aiResult['target_price'] ?? null,
                'metrics' => $aiResult['metrics'] ?? [],
                'updated_at' => now()->toIso8601String(),
            ];
            $trade->meta = $meta;

            // If AI detects high-confidence structural trend invalidation (high volume breakdown through support):
            if ($aiResult['action'] === 'EMERGENCY_EXIT') {
                return $this->closeTrade($trade, $price, 'AI_TREND_INVALIDATION');
            }

            // If AI recommends elevated structural trailing stop behind swing pivot:
            if ($aiResult['action'] === 'TRAIL_SL' && ! empty($aiResult['suggested_sl'])) {
                $this->applyElevatedStopLoss($trade, (float) $aiResult['suggested_sl']);
            }

            // 3. Anti-Giveback Circuit: Check Peak Reversal Exit
            // If the active AI monitor has confirmed the trend is intact and is letting the winner run
            // toward extended targets, protect the runner from premature noise exit.
            $aiHoldingWinner = ($aiResult['action'] ?? '') === 'HOLD' && ($aiResult['decision'] ?? '') === 'LET_WINNER_RUN';
            if (! $aiHoldingWinner) {
                $peakExit = $this->checkPeakReversalExit($trade, $price);
                if ($peakExit !== null) {
                    return $peakExit;
                }
            }

            // 4. Check Breakeven Protection
            $this->checkBreakeven($trade, $price);

            // 5. Check Stagnant Dead-Position Timeout (Does NOT kill profitable trades)
            $stagnationExit = $this->checkStagnationExit($trade, $price);
            if ($stagnationExit !== null) {
                return $stagnationExit;
            }

            // 6. Check TP1 Partial Booking
            $this->checkTp1($trade, $price);

            // 7. Check TP2 Partial Booking
            $this->checkTp2($trade, $price);

            // 8. Dynamic Trailing Stop & Stepped Ratchet Profit Protection on Runner
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
     * Prevents large accumulated profits from turning into losses, while giving normal
     * pullbacks ample room to breathe.
     *
     * @return array{status: string, message: string}|null
     */
    protected function checkPeakReversalExit(Trade $trade, float $currentPrice): ?array
    {
        $minPeakGainPct = (float) config('trading.management.peak_profit_min_gain_pct', 0.60);
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
     * Breakeven logic: locks in Entry + fee buffer when price reaches safe threshold.
     * Prevents winning positions from ever turning into losses without choking on noise.
     */
    protected function checkBreakeven(Trade $trade, float $currentPrice): void
    {
        if ($trade->be_locked) {
            return;
        }

        $gainPctThreshold = (float) config('trading.management.be_gain_pct', 0.45);
        $bufferPct = (float) config('trading.management.be_fee_buffer_pct', 0.08);
        $roeThreshold = (float) config('trading.management.be_roe_threshold', 4.5);

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
     * Dead-Position Timeout Pruner.
     * Only closes flat, inactive positions that never gained traction, freeing capital.
     * Never closes winning trades with positive momentum.
     *
     * @return array{status: string, message: string}|null
     */
    protected function checkStagnationExit(Trade $trade, float $currentPrice): ?array
    {
        if (! $trade->opened_at) {
            return null;
        }

        $hardTimeout = (int) config('trading.management.max_hold_minutes', 0);
        if ($hardTimeout <= 0) {
            return null;
        }

        $ageMinutes = $trade->opened_at->diffInMinutes(Carbon::now());
        if ($ageMinutes < $hardTimeout) {
            return null;
        }

        // Never kill trades if AI Sentinel is actively monitoring or letting winner run
        if ($this->aiMonitor !== null && $this->aiMonitor->shouldLetWinnerRun($trade, $currentPrice)) {
            return null;
        }

        $gainPct = $trade->isLong()
            ? (($currentPrice - $trade->entry_price) / $trade->entry_price) * 100.0
            : (($trade->entry_price - $currentPrice) / $trade->entry_price) * 100.0;

        // Only exit if trade has not hit TP1, is strictly non-profitable (gain <= 0.0%), and hard timeout is explicitly enabled
        // Winning trades and consolidations with positive momentum are NEVER closed by time!
        if (! $trade->tp1_hit && $gainPct <= 0.0) {
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
        $closeQty = $trade->remaining_quantity > 0 ? $trade->remaining_quantity : $trade->quantity;
        $exitOrderId = null;

        if ($trade->mode === 'live') {
            try {
                $client = $this->client->forMode($trade->mode);
                if ($client->hasCredentials()) {
                    if ($closeQty > 0) {
                        $closeSide = $trade->isLong() ? 'SELL' : 'BUY';
                        $orderRes = $client->placeOrder([
                            'symbol' => $trade->symbol,
                            'side' => $closeSide,
                            'type' => 'MARKET',
                            'quantity' => $client->formatQuantity($trade->symbol, $closeQty),
                            'reduceOnly' => 'true',
                        ]);
                        $exitOrderId = $orderRes['orderId'] ?? null;
                    }
                    // Clean up any remaining exchange-side conditional orders
                    $client->cancelAllAlgoOrders($trade->symbol);
                    $client->cancelAllOrders($trade->symbol);
                }
            } catch (\Exception $e) {
                Log::error("Failed to close live position on Binance for {$trade->symbol}: {$e->getMessage()}");
            }
        }

        $trade->exit_price = $exitPrice;
        $trade->exit_reason = $reason;

        // Truthfully reconcile trade (sourcing real fills, fees, and funding from Binance for live trades)
        $this->tradeReconciler->reconcileClosedTrade($trade, $exitOrderId, $reason);

        return [
            'status' => 'closed',
            'message' => "Trade {$trade->id} closed at \${$trade->exit_price}. Net PnL: \${$trade->net_pnl} ({$trade->pnl_percent}%). Reason: {$reason}.",
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
