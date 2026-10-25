<?php

namespace App\Services\Trading;

use App\Models\SystemLog;
use App\Models\Trade;
use App\Services\Binance\BinanceFuturesClient;
use App\Services\Crypto\BinanceClient;
use App\Services\Crypto\Indicators;
use App\Services\Notifications\TelegramNotifier;
use App\Services\Strategy\ExitPlan;
use App\Services\Strategy\MarketScanService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single owner of open-trade management (stop moves, partials, trailing, time stops, closes).
 * Rules come from ExitPlan so live, paper and backtests behave the same.
 */
class DynamicTradeManager
{
    protected ExitPlan $exitPlan;

    public function __construct(
        protected BinanceFuturesClient $client,
        protected RiskManager $riskManager,
        protected TelegramNotifier $notifier,
        protected TradeReconciler $tradeReconciler,
        protected ExchangeOrders $exchangeOrders,
        protected BinanceClient $marketData
    ) {
        $this->exitPlan = ExitPlan::fromConfig();
    }

    /**
     * Manage an open trade against the current mark price.
     *
     * @return array{status: string, message: string}
     */
    public function manageTrade(Trade $trade, ?float $currentPrice = null): array
    {
        $lock = Cache::lock("trade-manage:{$trade->id}", 30);
        if (! $lock->get()) {
            return ['status' => 'locked', 'message' => "Trade {$trade->id} is being managed by another process."];
        }

        try {
            $trade->refresh();
            if (! $trade->isOpen()) {
                return ['status' => 'ignored', 'message' => 'Trade is not open.'];
            }

            if ($trade->mode === 'live') {
                $syncResult = $this->syncLivePosition($trade);
                if ($syncResult !== null) {
                    return $syncResult;
                }
            }

            $price = $currentPrice ?? (float) $this->client->getMarkPrice($trade->symbol);
            if ($price <= 0) {
                return ['status' => 'error', 'message' => 'Invalid mark price.'];
            }

            if ($trade->setup_tag === SwingSarTrader::SETUP) {
                return $this->manageSwingSar($trade, $price);
            }
            if ($trade->setup_tag === LabTrader::SETUP) {
                return $this->manageLab($trade, $price);
            }
            if ($trade->setup_tag === DayRangeTrader::SETUP) {
                return $this->manageDayRange($trade, $price);
            }

            // Early breakout: the hourly candle closed back inside the squeeze box, so the breakout failed.
            if (($failedClose = $this->failedBreakoutClose($trade)) !== null) {
                return $this->closeTradeUnlocked($trade, $trade->mode === 'live' ? $price : $failedClose, 'FAILED_BREAKOUT');
            }

            [$atr, $isBarClose] = $this->trailingInputs($trade);
            $result = $this->exitPlan->evaluate($this->stateOf($trade), $price, $price, $price, now()->timestamp, $atr, $isBarClose);
            $state = $result['state'];

            $trade->highest_price = $state['highest'];
            $trade->lowest_price = $state['lowest'];

            foreach ($result['events'] as $event) {
                if ($event['type'] === 'close') {
                    $exitPrice = $trade->mode === 'live' ? $price : $this->paperExitPrice($trade, (float) $event['price'], $price);

                    return $this->closeTradeUnlocked($trade, $exitPrice, (string) $event['reason']);
                }

                if ($event['type'] === 'partial') {
                    $this->bookTp1($trade, (float) $event['price']);
                }
            }

            $this->applyStop($trade, $state);
            $trade->save();

            return ['status' => 'managed', 'message' => "Trade {$trade->id} {$trade->symbol} at {$price}, stop {$trade->current_sl}."];
        } catch (Throwable $e) {
            Log::error("[DynamicTradeManager] Trade {$trade->id} management error: {$e->getMessage()}");

            return ['status' => 'error', 'message' => $e->getMessage()];
        } finally {
            $lock->release();
        }
    }

    /**
     * Close the remaining position. A live trade is only marked CLOSED once Binance shows it flat.
     *
     * @return array{status: string, message: string}
     */
    public function closeTrade(Trade $trade, float $exitPrice, string $reason): array
    {
        $lock = Cache::lock("trade-manage:{$trade->id}", 30);
        if (! $lock->block(10)) {
            return ['status' => 'locked', 'message' => "Trade {$trade->id} is busy, try again."];
        }

        try {
            $trade->refresh();
            if (! $trade->isOpen()) {
                return ['status' => 'closed', 'message' => "Trade {$trade->id} is already closed."];
            }

            return $this->closeTradeUnlocked($trade, $exitPrice, $reason);
        } finally {
            $lock->release();
        }
    }

    /**
     * Book part of a trade at market (dashboard "Book 25% / 50%"). The rest keeps its stop and exit plan;
     * the exchange stop uses closePosition, so it covers whatever remains.
     *
     * @return array{status: string, message: string, filled_qty?: float, avg_price?: float, realized_pnl?: float}
     */
    public function closePartial(Trade $trade, float $ratio, float $price): array
    {
        if ($ratio >= 0.999) {
            return $this->closeTrade($trade, $price, 'MANUAL_CLOSE');
        }

        $lock = Cache::lock("trade-manage:{$trade->id}", 30);
        if (! $lock->block(10)) {
            return ['status' => 'locked', 'message' => "Trade {$trade->id} is busy, try again."];
        }

        try {
            $trade->refresh();
            if (! $trade->isOpen()) {
                return ['status' => 'error', 'message' => "Trade {$trade->id} is already closed."];
            }

            $remaining = (float) $trade->remaining_quantity;
            $quantity = $this->client->formatQuantity($trade->symbol, $remaining * $ratio);
            if ($quantity <= 0 || $quantity >= $remaining) {
                return ['status' => 'error', 'message' => "{$trade->symbol}: position too small to split at this lot size. Close 100% instead."];
            }

            $fill = $price;
            if ($trade->mode === 'live') {
                $order = $this->client->forMode('live')->placeOrder([
                    'symbol' => $trade->symbol,
                    'side' => $trade->isLong() ? 'SELL' : 'BUY',
                    'type' => 'MARKET',
                    'quantity' => $quantity,
                    'reduceOnly' => 'true',
                    'newOrderRespType' => 'RESULT',
                ]);
                $fill = (float) ($order['avgPrice'] ?? 0) > 0 ? (float) $order['avgPrice'] : $price;
            }

            $this->recordPartial($trade, $fill, $quantity, notify: false);
            $trade->remaining_quantity = round($remaining - $quantity, 8);
            $trade->save();

            $message = sprintf('%s %s: booked %s of %s at %s (%d%%). Realized so far $%s.', strtoupper($trade->mode), $trade->symbol, $quantity, $remaining, $fill, round($ratio * 100), $trade->realized_pnl);
            SystemLog::write('trade', $message);

            return ['status' => 'partial', 'message' => $message, 'filled_qty' => $quantity, 'avg_price' => $fill, 'realized_pnl' => (float) $trade->realized_pnl];
        } catch (Throwable $e) {
            Log::error("[DynamicTradeManager] Partial close of trade {$trade->id} failed: {$e->getMessage()}");

            return ['status' => 'error', 'message' => "Partial close failed: {$e->getMessage()}"];
        } finally {
            $lock->release();
        }
    }

    /**
     * Manually move the stop to breakeven (dashboard action).
     */
    public function lockBreakeven(Trade $trade): bool
    {
        $buffer = $trade->entry_price * $this->exitPlan->feeRate() * 2;
        $state = $this->stateOf($trade);
        $target = $trade->isLong() ? $trade->entry_price + $buffer : $trade->entry_price - $buffer;
        $improves = $trade->isLong() ? $target > $state['current_sl'] : $target < $state['current_sl'];

        if (! $improves) {
            return false;
        }

        $state['current_sl'] = $target;
        $trade->be_locked = true;
        $trade->stage = $trade->stage === 'ENTRY' ? 'BE_LOCKED' : $trade->stage;
        $this->applyStop($trade, $state);
        $trade->save();

        return true;
    }

    /**
     * Move the stop forward to $stop (never back), on the exchange too for live trades. Used by the lab trader's
     * chandelier trail on each closed 4h candle. Returns true when the stop moved.
     */
    public function trailStopTo(Trade $trade, float $stop, string $why = 'trailing stop'): bool
    {
        $lock = Cache::lock("trade-manage:{$trade->id}", 30);
        if (! $lock->block(10)) {
            return false;
        }

        try {
            $trade->refresh();
            $before = (float) $trade->current_sl;
            $dir = $trade->isLong() ? 1 : -1;
            if (! $trade->isOpen() || ($stop - $before) * $dir <= 1e-12) {
                return false;
            }
            $this->applyStop($trade, ['current_sl' => $stop, 'be_locked' => ($stop - (float) $trade->entry_price) * $dir >= 0]);
            $trade->save();
            $moved = abs((float) $trade->current_sl - $before) > 1e-12;
            if ($moved) {
                $this->notifier->notifyStopTrailed($trade, (float) $trade->current_sl, (float) $this->client->getMarkPrice($trade->symbol), $why);
            }

            return $moved;
        } catch (Throwable $e) {
            Log::warning("[DynamicTradeManager] Trail of trade {$trade->id} failed: {$e->getMessage()}");

            return false;
        } finally {
            $lock->release();
        }
    }

    /**
     * Lab trial trades: only the stop (kept on the exchange for live) and the best price here; the lab trader moves
     * the stop and takes the momentum exit on each closed 4h candle. No partials, no time stop.
     *
     * @return array{status: string, message: string}
     */
    protected function manageLab(Trade $trade, float $price): array
    {
        $trade->highest_price = max((float) ($trade->highest_price ?? $price), $price);
        $trade->lowest_price = min((float) ($trade->lowest_price ?? $price), $price);
        $stopHit = $trade->isLong() ? $price <= (float) $trade->current_sl : $price >= (float) $trade->current_sl;
        if ($stopHit) {
            $moved = abs((float) $trade->current_sl - (float) $trade->initial_sl) > 1e-12;

            return $this->closeTradeUnlocked($trade, $trade->mode === 'live' ? $price : $this->paperExitPrice($trade, (float) $trade->current_sl, $price), $moved ? 'TRAILING_STOP' : 'STOP_LOSS');
        }
        $trade->save();

        return ['status' => 'managed', 'message' => "Lab {$trade->id} {$trade->symbol} at {$price}, stop {$trade->current_sl}."];
    }

    /**
     * Day dip BUY: fixed stop, one full exit at the day target, and a close at 23:55 UTC (the trade lives one day).
     *
     * @return array{status: string, message: string}
     */
    protected function manageDayRange(Trade $trade, float $price): array
    {
        $trade->highest_price = max((float) ($trade->highest_price ?? $price), $price);
        $trade->lowest_price = min((float) ($trade->lowest_price ?? $price), $price);
        $dir = $trade->isLong() ? 1 : -1;
        $target = (float) ($trade->meta['day_target'] ?? 0);

        if (($price - (float) $trade->current_sl) * $dir <= 0) {
            return $this->closeTradeUnlocked($trade, $trade->mode === 'live' ? $price : $this->paperExitPrice($trade, (float) $trade->current_sl, $price), 'STOP_LOSS');
        }
        if ($target > 0 && ($price - $target) * $dir >= 0) {
            return $this->closeTradeUnlocked($trade, $trade->mode === 'live' ? $price : $target, 'TAKE_PROFIT');
        }
        if (now()->getTimestampMs() >= (int) ($trade->meta['day_close_at'] ?? PHP_INT_MAX)) {
            return $this->closeTradeUnlocked($trade, $price, 'DAY_END');
        }
        $trade->save();

        return ['status' => 'managed', 'message' => "Day range {$trade->id} {$trade->symbol} at {$price}, stop {$trade->current_sl}, target {$target}."];
    }

    /**
     * User-set stop (dashboard "Edit SL"), tighter or looser. R (initial_sl) is kept, so the dynamic rules keep
     * working: every later check still only moves the stop forward from this value.
     *
     * @return array{success: bool, message: string}
     */
    public function setManualStop(Trade $trade, float $stop): array
    {
        $lock = Cache::lock("trade-manage:{$trade->id}", 30);
        if (! $lock->block(10)) {
            return ['success' => false, 'message' => "Trade {$trade->id} is busy, try again."];
        }

        try {
            $trade->refresh();
            if (! $trade->isOpen()) {
                return ['success' => false, 'message' => 'Trade is not open.'];
            }

            $mark = (float) $this->client->getMarkPrice($trade->symbol);
            if ($mark <= 0) {
                return ['success' => false, 'message' => 'Mark price unavailable, try again.'];
            }

            $dir = $trade->isLong() ? 1 : -1;
            $entry = (float) $trade->entry_price;
            if (($mark - $stop) * $dir < $mark * 0.001) {
                return ['success' => false, 'message' => sprintf('Stop must be %s the mark price %s (at least 0.1%% away).', $dir > 0 ? 'below' : 'above', $mark)];
            }
            $maxPct = (float) config('trading.swing_sar.max_order_sl_pct', 6.0);
            if (($entry - $stop) * $dir / $entry * 100 > $maxPct) {
                return ['success' => false, 'message' => "Stop may not be more than {$maxPct}% from entry ({$entry})."];
            }

            if ($trade->mode === 'live') {
                $stop = $this->client->formatPrice($trade->symbol, $stop);
                if (! $this->exchangeOrders->replaceStop($trade, $stop)) {
                    return ['success' => false, 'message' => 'Binance rejected the new stop; the previous stop is kept.'];
                }
            }

            $previous = (float) $trade->current_sl;
            $trade->current_sl = $stop;
            $trade->be_locked = ($stop - $entry * (1 + $dir * 2 * $this->exitPlan->feeRate())) * $dir >= 0;
            if ($trade->stage === 'ENTRY' && $trade->be_locked) {
                $trade->stage = 'BE_LOCKED';
            }
            $meta = $trade->meta ?? [];
            $meta['manual_sl'] = ['price' => $stop, 'previous' => $previous, 'at' => now()->toIso8601String()];
            $meta['sar_trail'] = null; // an older SuperTrend target must not override the user's stop; the next candle sets it again
            $meta['sar_notified_sl'] = $stop;
            $trade->meta = $meta;
            $trade->save();

            $atStop = ($stop - $entry) * $dir * (float) $trade->remaining_quantity;
            $result = sprintf('%s$%.2f', $atStop >= 0 ? '+' : '-', abs($atStop));
            SystemLog::write('trade', sprintf('%s %s #%d: stop changed by user %s -> %s (at the stop %s).', strtoupper($trade->mode), $trade->symbol, $trade->id, $previous, $stop, $result));
            $this->notifier->notifyStopEdited($trade, $stop, $atStop);

            return ['success' => true, 'message' => "{$trade->symbol} stop set to {$stop} (at the stop {$result}). The bot keeps moving it forward from here."];
        } catch (Throwable $e) {
            Log::error("[DynamicTradeManager] Manual stop for trade {$trade->id} failed: {$e->getMessage()}");

            return ['success' => false, 'message' => "Stop change failed: {$e->getMessage()}"];
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{status: string, message: string}
     */
    protected function closeTradeUnlocked(Trade $trade, float $exitPrice, string $reason): array
    {
        $exitOrderId = null;

        if ($trade->mode === 'live') {
            $result = $this->exchangeOrders->closeAndConfirmFlat($trade);

            if (! $result['flat']) {
                $meta = $trade->meta ?? [];
                $meta['close_failed_at'] = now()->toIso8601String();
                $meta['close_failed_reason'] = $result['message'];
                $trade->meta = $meta;
                $trade->save();

                SystemLog::write('risk', "Close of {$trade->symbol} #{$trade->id} not confirmed: {$result['message']}", 'error');
                $this->notifier->notifyRiskEvent('live', 'Close not confirmed', "{$trade->symbol} #{$trade->id} ({$reason}): {$result['message']} The trade stays OPEN and will be retried.");

                return ['status' => 'error', 'message' => "Close not confirmed on Binance: {$result['message']}"];
            }

            $exitOrderId = $result['order_id'];
            $exitPrice = $result['avg_price'] ?? $exitPrice;
        }

        $trade->exit_price = $exitPrice;
        $trade->exit_reason = $reason;
        $trade->closed_at = now();

        try {
            $this->tradeReconciler->reconcileClosedTrade($trade, $exitOrderId, $reason);
        } catch (Throwable $e) {
            // The position IS flat on Binance; record an estimate and flag it for the daily reconciler.
            Log::warning("[DynamicTradeManager] Reconciliation deferred for trade {$trade->id}: {$e->getMessage()}");
            $this->tradeReconciler->reconcileEstimated($trade, $reason);
            $meta = $trade->meta ?? [];
            $meta['reconcile_pending'] = true;
            $trade->meta = $meta;
            $trade->save();
        }

        return [
            'status' => 'closed',
            'message' => "Trade {$trade->id} {$trade->symbol} closed at {$trade->exit_price} ({$reason}). Net PnL \${$trade->net_pnl}.",
        ];
    }

    /**
     * Detect exchange-side fills (TP1 order, stop-loss) by comparing the live position size.
     *
     * @return array{status: string, message: string}|null
     */
    protected function syncLivePosition(Trade $trade): ?array
    {
        if ($trade->opened_at && $trade->opened_at->diffInSeconds(now()) < 15) {
            return null;
        }

        try {
            $amount = $this->client->forMode('live')->getPositionAmount($trade->symbol);
        } catch (Throwable $e) {
            Log::warning("[DynamicTradeManager] Position read failed for {$trade->symbol}: {$e->getMessage()}");

            return null;
        }

        if ($amount === null) {
            return null;
        }

        $expectedSign = $trade->isLong() ? 1 : -1;
        $liveQty = $amount * $expectedSign > 0 ? abs($amount) : 0.0;

        if ($liveQty <= 0) {
            // Gone on the exchange: the reconciler tells a stop / target fill from a close made outside the bot
            // (a "trailing stop" hint here used to label manual closes far from the stop as trailing stops).
            return $this->closeTradeUnlocked($trade, (float) $trade->current_sl, 'EXCHANGE_CLOSED');
        }

        if (! $trade->tp1_hit && $liveQty < (float) $trade->remaining_quantity * 0.98) {
            // TP1 take-profit order filled on the exchange.
            $closedQty = (float) $trade->remaining_quantity - $liveQty;
            $this->recordPartial($trade, (float) $trade->tp1_price, $closedQty);
            $trade->remaining_quantity = $liveQty;
            $this->afterTp1($trade);
            $trade->save();
        }

        return null;
    }

    /**
     * Book TP1 when the exit plan says the target was reached.
     */
    protected function bookTp1(Trade $trade, float $tp1Price): void
    {
        if ($trade->tp1_hit) {
            return;
        }

        $closeQty = $this->client->formatQuantity($trade->symbol, (float) $trade->quantity * $this->exitPlan->tp1CloseRatio());
        if ($closeQty <= 0 || $closeQty >= (float) $trade->remaining_quantity) {
            // Lot too small to split: keep the whole position running with the tighter stop.
            $trade->tp1_hit = true;
            $trade->stage = 'TP1_HIT';

            return;
        }

        if ($trade->mode === 'live') {
            if (! empty($trade->meta['tp_order'])) {
                // The exchange TP order fills it; syncLivePosition records it on the next pass.
                return;
            }

            try {
                $this->client->forMode('live')->placeOrder([
                    'symbol' => $trade->symbol,
                    'side' => $trade->isLong() ? 'SELL' : 'BUY',
                    'type' => 'MARKET',
                    'quantity' => $closeQty,
                    'reduceOnly' => 'true',
                ]);
            } catch (Throwable $e) {
                Log::error("[DynamicTradeManager] TP1 market partial failed for {$trade->symbol}: {$e->getMessage()}");

                return;
            }
        }

        $this->recordPartial($trade, $tp1Price, $closeQty);
        $trade->remaining_quantity = round((float) $trade->remaining_quantity - $closeQty, 8);
        $this->afterTp1($trade);
    }

    /**
     * Swing SAR partial take-profit: close $quantity (rounded to the lot step) at market. Skipped when the lot is too
     * small to split or the order fails (logged); the level is not retried.
     */
    protected function bookPartial(Trade $trade, float $price, float $quantity, float $atR): void
    {
        $closeQty = $this->client->formatQuantity($trade->symbol, $quantity);
        if ($closeQty <= 0 || $closeQty >= (float) $trade->remaining_quantity) {
            return;
        }

        if ($trade->mode === 'live') {
            try {
                $this->client->forMode('live')->placeOrder([
                    'symbol' => $trade->symbol,
                    'side' => $trade->isLong() ? 'SELL' : 'BUY',
                    'type' => 'MARKET',
                    'quantity' => $closeQty,
                    'reduceOnly' => 'true',
                ]);
            } catch (Throwable $e) {
                Log::error("[DynamicTradeManager] Swing SAR partial at +{$atR}R failed for {$trade->symbol}: {$e->getMessage()}");

                return;
            }
        }

        $gross = ($price - $trade->entry_price) * $closeQty * ($trade->isLong() ? 1 : -1);
        $fee = $price * $closeQty * $this->exitPlan->feeRate();
        $this->recordPartial($trade, $price, $closeQty, notify: false);
        $trade->remaining_quantity = round((float) $trade->remaining_quantity - $closeQty, 8);
        $trade->margin_used = round((float) $trade->remaining_quantity * $trade->entry_price / max(1, $trade->leverage), 4);
        $this->notifier->notifyPartialBooked($trade, $closeQty, round($gross - $fee, 4), $atR);
    }

    protected function recordPartial(Trade $trade, float $price, float $quantity, bool $notify = true): void
    {
        $gross = ($price - $trade->entry_price) * $quantity * ($trade->isLong() ? 1 : -1);
        $fee = $price * $quantity * $this->exitPlan->feeRate();

        $meta = $trade->meta ?? [];
        $meta['partial_gross'] = round((float) ($meta['partial_gross'] ?? 0) + $gross, 6);
        $meta['partial_fee'] = round((float) ($meta['partial_fee'] ?? 0) + $fee, 6);
        $meta['partial_qty'] = round((float) ($meta['partial_qty'] ?? 0) + $quantity, 8);
        $trade->meta = $meta;
        $trade->realized_pnl = round((float) $meta['partial_gross'] - (float) $meta['partial_fee'], 4);

        if ($notify) {
            $this->notifier->notifyTp1Hit($trade, $quantity, round($gross - $fee, 4));
        }
    }

    /**
     * After TP1: mark stage and lock +0.5R (ExitPlan moves the stop on the next evaluation too).
     */
    protected function afterTp1(Trade $trade): void
    {
        $trade->tp1_hit = true;
        $trade->be_locked = true;
        $trade->stage = 'TP1_HIT';
        $trade->margin_used = round((float) $trade->remaining_quantity * $trade->entry_price / max(1, $trade->leverage), 4);

        $state = $this->stateOf($trade);
        $risk = abs($trade->entry_price - $trade->initial_sl);
        $lockR = (float) config('trading.exits.after_tp1_lock_r', 0.5);
        $target = $trade->entry_price + ($trade->isLong() ? 1 : -1) * $risk * $lockR;
        if ($trade->setup_tag === SwingSarTrader::SETUP) {
            // Swing SAR (as tested): breakeven + round-trip fees, then the SuperTrend trail takes over.
            $target = $trade->entry_price * (1 + ($trade->isLong() ? 1 : -1) * 2 * $this->exitPlan->feeRate());
        }
        if ($trade->isLong() ? $target > $state['current_sl'] : $target < $state['current_sl']) {
            $state['current_sl'] = $target;
        }

        $this->applyStop($trade, $state);
    }

    /**
     * Persist a stop move (and push it to the exchange for live trades, new-then-cancel-old).
     *
     * @param  array<string, mixed>  $state
     */
    protected function applyStop(Trade $trade, array $state): void
    {
        $newSl = (float) $state['current_sl'];
        if (abs($newSl - (float) $trade->current_sl) < 1e-12) {
            if ($state['be_locked'] && ! $trade->be_locked) {
                $trade->be_locked = true;
            }

            return;
        }

        $improves = $trade->isLong() ? $newSl > $trade->current_sl : $newSl < $trade->current_sl;
        if (! $improves) {
            return;
        }

        if ($trade->mode === 'live') {
            $formatted = $this->client->formatPrice($trade->symbol, $newSl);
            if (! $this->exchangeOrders->replaceStop($trade, $formatted)) {
                return;
            }
            $newSl = $formatted;
        }

        $wasBeLocked = $trade->be_locked;
        $trade->current_sl = $newSl;
        $trade->be_locked = $trade->be_locked || $state['be_locked'];

        if ($trade->stage === 'ENTRY' && $trade->be_locked) {
            $trade->stage = 'BE_LOCKED';
        }
        if ($trade->tp1_hit && $trade->stage !== 'TRAILING' && $this->isTrailing($trade)) {
            $trade->stage = 'TRAILING';
        }

        if (! $wasBeLocked && $trade->be_locked && ! $trade->tp1_hit) {
            $this->notifier->notifyBreakevenLocked($trade, $newSl);
        }
    }

    /**
     * Swing SAR, every tick, on the best price reached in R: book the configured partials (swing_sar.partials),
     * step the stop up the ladder (swing_sar.ladder), and from trail_from_r R trail the best price by trail_atr ATR;
     * it also follows the SuperTrend line (SwingSarTrader stores the latest closed-candle value in meta.sar_trail).
     * The stop only moves in the trade's favour; the opposite flip closes the rest.
     * Optional: half booked at TP1 (swing_sar.take_profit). No time stop.
     *
     * @return array{status: string, message: string}
     */
    protected function manageSwingSar(Trade $trade, float $price): array
    {
        $trade->highest_price = max((float) ($trade->highest_price ?? $price), $price);
        $trade->lowest_price = min((float) ($trade->lowest_price ?? $price), $price);
        $moved = abs((float) $trade->current_sl - (float) $trade->initial_sl) > 1e-12;

        $stopHit = $trade->isLong() ? $price <= (float) $trade->current_sl : $price >= (float) $trade->current_sl;
        if ($stopHit) {
            $exitPrice = $trade->mode === 'live' ? $price : $this->paperExitPrice($trade, (float) $trade->current_sl, $price);

            return $this->closeTradeUnlocked($trade, $exitPrice, $moved ? 'TRAILING_STOP' : 'STOP_LOSS');
        }

        $tp1 = (float) $trade->tp1_price;
        if (! $trade->tp1_hit && $tp1 > 0 && config('trading.swing_sar.take_profit', true) && ($trade->isLong() ? $price >= $tp1 : $price <= $tp1)) {
            $this->bookTp1($trade, $trade->mode === 'live' ? $price : $tp1);
        }

        $dir = $trade->isLong() ? 1 : -1;
        $entry = (float) $trade->entry_price;
        $risk = abs($entry - (float) $trade->initial_sl);
        $best = $trade->isLong() ? (float) $trade->highest_price : (float) $trade->lowest_price;
        $atr = (float) ($trade->meta['atr'] ?? 0);
        $bestR = $risk > 0 ? ($best - $entry) * $dir / $risk : 0.0;

        // Partial profit booking, each level once (also marked when the lot is too small to split).
        $done = (array) ($trade->meta['sar_partials'] ?? []);
        foreach ((array) config('trading.swing_sar.partials', []) as $i => [$atR, $share]) {
            if (in_array($i, $done, true) || $bestR < (float) $atR) {
                continue;
            }
            $done[] = $i;
            $meta = $trade->meta ?? [];
            $meta['sar_partials'] = $done;
            $trade->meta = $meta;
            $this->bookPartial($trade, $trade->mode === 'live' ? $price : $entry + $dir * (float) $atR * $risk, (float) $trade->quantity * (float) $share, (float) $atR);
        }

        // Stop ladder (discrete steps) and the profit trail (moved in steps so a live stop is not replaced every 5s).
        $better = fn (?float $a, float $b): float => $a === null ? $b : ($dir > 0 ? max($a, $b) : min($a, $b));
        $profitStop = null;
        foreach ((array) config('trading.swing_sar.ladder', []) as [$atR, $stopR]) {
            if ($risk > 0 && $bestR >= (float) $atR) {
                $profitStop = $better($profitStop, (float) $stopR == 0.0 ? $entry * (1 + $dir * 2 * $this->exitPlan->feeRate()) : $entry + $dir * (float) $stopR * $risk);
            }
        }
        $trailFrom = (float) config('trading.swing_sar.trail_from_r', 1.5);
        if ($trailFrom > 0 && $atr > 0 && $risk > 0 && $bestR >= $trailFrom) {
            $chandelier = $best - $dir * (float) config('trading.swing_sar.trail_atr', 1.5) * $atr;
            if (($chandelier - (float) $trade->current_sl) * $dir >= max($price * 0.0015, 0.2 * $atr)) {
                $profitStop = $better($profitStop, $chandelier);
            }
        }

        // Profit lock on peak ROE: keep a share of the best gain (never below breakeven + fees), and from
        // swing_trail_from_roe also trail just past the last swing (low of the last 2 closed candles for a long).
        $peakRoe = $entry > 0 ? ($best - $entry) * $dir / $entry * 100 * max(1, (int) $trade->leverage) : 0.0;
        $keep = null;
        foreach ((array) config('trading.swing_sar.profit_lock', []) as [$roe, $share]) {
            if ($peakRoe >= (float) $roe) {
                $keep = max((float) $keep, (float) $share);
            }
        }
        $lockStop = null;
        if ($keep !== null) {
            $floor = $entry * (1 + $dir * 2 * $this->exitPlan->feeRate());
            $lockStop = $better($floor, $entry + $dir * ($best - $entry) * $keep);
            $swingFrom = (float) config('trading.swing_sar.swing_trail_from_roe', 10);
            $swing = $trade->meta[$dir > 0 ? 'sar_swing_low' : 'sar_swing_high'] ?? null;
            if ($swingFrom > 0 && $peakRoe >= $swingFrom && $swing !== null) {
                $lockStop = $better($lockStop, (float) $swing - $dir * 0.1 * $atr);
            }
            // Price already back through the lock (sudden drop between checks): book the rest now.
            if (($price - $lockStop) * $dir <= 0) {
                $this->notifier->notifyRiskEvent($trade->mode, 'Profit lock', sprintf('%s #%d: price %s fell back through the profit lock %s (peak ROE %.1f%%). Closing the rest.', $trade->symbol, $trade->id, $price, round($lockStop, 8), $peakRoe));

                return $this->closeTradeUnlocked($trade, $price, 'PROFIT_LOCK');
            }
            // Step limit as for the trail, except the first move into profit, which always goes through.
            $inProfit = ((float) $trade->current_sl - $entry) * $dir > 0;
            if (! $inProfit || ($lockStop - (float) $trade->current_sl) * $dir >= max($price * 0.0015, 0.2 * $atr)) {
                $profitStop = $better($profitStop, $lockStop);
            }
        }
        if ($profitStop !== null && ($profitStop - (float) $trade->current_sl) * $dir <= 1e-12) {
            $profitStop = null;
        }

        $trail = $trade->meta['sar_trail'] ?? null;
        if ($profitStop !== null && ($trail === null || ($profitStop - (float) $trail) * $dir > 0)) {
            $trail = $profitStop;
        }
        if ($trail !== null && ($trade->isLong() ? (float) $trail < $price : (float) $trail > $price)) {
            $before = (float) $trade->current_sl;
            $wasBeLocked = (bool) $trade->be_locked;
            $this->applyStop($trade, ['current_sl' => (float) $trail, 'be_locked' => ($trail - $entry) * $dir >= 0]);
            $notified = (float) ($trade->meta['sar_notified_sl'] ?? $trade->initial_sl);
            if (! $wasBeLocked && $trade->be_locked) {
                // applyStop already sent the "stop moved to breakeven" reply.
                $meta = $trade->meta;
                $meta['sar_notified_sl'] = (float) $trade->current_sl;
                $trade->meta = $meta;
            } elseif (abs((float) $trade->current_sl - $before) > 1e-12
                && abs((float) $trade->current_sl - $notified) / $price * 100 >= (float) config('trading.swing_sar.trail_notify_pct', 0.5)) {
                $meta = $trade->meta;
                $meta['sar_notified_sl'] = (float) $trade->current_sl;
                $trade->meta = $meta;
                $this->notifier->notifyStopTrailed($trade, (float) $trade->current_sl, $price, $trail === $profitStop ? ($lockStop !== null && abs($profitStop - $lockStop) < 1e-12 ? sprintf('locking %d%% of the peak profit (peak ROE %.1f%%)', round($keep * 100), $peakRoe) : 'protecting the profit') : 'SuperTrend line');
            }
            if ((float) $trade->current_sl !== $before && $trade->stage === 'ENTRY') {
                $trade->stage = 'TRAILING';
            }
        }
        $trade->save();

        return ['status' => 'managed', 'message' => "Swing SAR {$trade->id} {$trade->symbol} at {$price}, stop {$trade->current_sl}."];
    }

    protected function isTrailing(Trade $trade): bool
    {
        $risk = abs($trade->entry_price - $trade->initial_sl);
        $lockR = (float) config('trading.exits.after_tp1_lock_r', 0.5);

        return $risk > 0 && (($trade->current_sl - $trade->entry_price) * ($trade->isLong() ? 1 : -1)) / $risk > $lockR + 0.01;
    }

    /**
     * ATR of the base timeframe, refreshed once per closed candle.
     *
     * @return array{0: ?float, 1: bool}
     */
    protected function trailingInputs(Trade $trade): array
    {
        if (! $trade->tp1_hit) {
            return [null, false];
        }

        $interval = (string) ($trade->meta['interval'] ?? config('trading.strategy.base_interval', '1h'));
        $barSeconds = MarketScanService::barSeconds($interval);
        $currentBar = intdiv(now()->timestamp, $barSeconds);
        $meta = $trade->meta ?? [];

        if ((int) ($meta['last_trail_bar'] ?? 0) === $currentBar) {
            return [null, false];
        }

        try {
            $atr = Cache::remember("trail-atr:{$trade->symbol}:{$interval}:{$currentBar}", $barSeconds, function () use ($trade, $interval): ?float {
                $candles = $this->marketData->klines($trade->symbol, $interval, 60);
                $series = Indicators::atr($candles['highs'], $candles['lows'], $candles['closes'], 14);
                $closedIndex = count($series) - 2;

                return $closedIndex >= 0 && $series[$closedIndex] !== null ? (float) $series[$closedIndex] : null;
            });
        } catch (Throwable) {
            $atr = isset($meta['atr']) ? (float) $meta['atr'] : null;
        }

        $meta['last_trail_bar'] = $currentBar;
        $trade->meta = $meta;

        return [$atr, $atr !== null];
    }

    /**
     * Close of the last finished candle when an early-breakout trade closed back inside its box
     * (checked once per candle, only before TP1). Null when the breakout still holds.
     */
    protected function failedBreakoutClose(Trade $trade): ?float
    {
        $box = $trade->meta['breakout_box'] ?? null;
        if (! config('trading.strategy.early_breakout.failed_exit', false) || $box === null || $trade->tp1_hit || $trade->opened_at === null) {
            return null;
        }

        $interval = (string) ($trade->meta['interval'] ?? '1h');
        $barSeconds = MarketScanService::barSeconds($interval);
        $currentBar = intdiv(now()->timestamp, $barSeconds);
        $meta = $trade->meta;
        if ($currentBar <= intdiv($trade->opened_at->timestamp, $barSeconds) || (int) ($meta['box_checked_bar'] ?? 0) === $currentBar) {
            return null;
        }

        try {
            $candles = $this->marketData->klines($trade->symbol, $interval, 3);
        } catch (Throwable) {
            return null;
        }

        $meta['box_checked_bar'] = $currentBar;
        $trade->meta = $meta;
        $closed = count($candles['closes'] ?? []) - 2;
        if ($closed < 0) {
            return null;
        }

        $close = (float) $candles['closes'][$closed];

        return ($trade->isLong() ? $close < (float) $box : $close > (float) $box) ? $close : null;
    }

    protected function paperExitPrice(Trade $trade, float $plannedPrice, float $currentPrice): float
    {
        // A gap through the stop fills at the worse price.
        if ($trade->isLong()) {
            return min($plannedPrice, $currentPrice) > 0 ? min($plannedPrice, $currentPrice) : $plannedPrice;
        }

        return max($plannedPrice, $currentPrice);
    }

    /**
     * @return array{side: string, entry: float, initial_sl: float, current_sl: float, tp1: float, tp2: float, tp1_hit: bool, be_locked: bool, highest: ?float, lowest: ?float, opened_at: int}
     */
    protected function stateOf(Trade $trade): array
    {
        return [
            'side' => $trade->side,
            'entry' => (float) $trade->entry_price,
            'initial_sl' => (float) $trade->initial_sl,
            'current_sl' => (float) $trade->current_sl,
            'tp1' => (float) $trade->tp1_price,
            'tp2' => (float) $trade->tp2_price,
            'tp1_hit' => (bool) $trade->tp1_hit,
            'be_locked' => (bool) $trade->be_locked,
            'highest' => $trade->highest_price !== null ? (float) $trade->highest_price : null,
            'lowest' => $trade->lowest_price !== null ? (float) $trade->lowest_price : null,
            'opened_at' => ($trade->opened_at ?? $trade->created_at ?? now())->timestamp,
        ];
    }
}
