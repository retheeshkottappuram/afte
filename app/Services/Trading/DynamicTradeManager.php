<?php

namespace App\Services\Trading;

use App\Models\SystemLog;
use App\Models\Trade;
use App\Services\Binance\BinanceFuturesClient;
use App\Services\Notifications\TelegramNotifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single owner of open-trade management. NEAR breakout trades: the safety stop and the 0.1 ATR trail on the
 * mark price (the EMA exit runs in NearBreakoutTrader on each closed candle). Any other open trade (left from the
 * removed strategies) keeps its stop only, so nothing is orphaned.
 */
class DynamicTradeManager
{
    public function __construct(
        protected BinanceFuturesClient $client,
        protected RiskManager $riskManager,
        protected TelegramNotifier $notifier,
        protected TradeReconciler $tradeReconciler,
        protected ExchangeOrders $exchangeOrders
    ) {}

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

            return $trade->setup_tag === NearBreakoutTrader::SETUP ? $this->manageNear($trade, $price) : $this->manageStopOnly($trade, $price);
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

            $this->recordPartial($trade, $fill, $quantity);
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
        $buffer = $trade->entry_price * self::feeRate() * 2;
        $target = $trade->isLong() ? $trade->entry_price + $buffer : $trade->entry_price - $buffer;
        $improves = $trade->isLong() ? $target > (float) $trade->current_sl : $target < (float) $trade->current_sl;

        if (! $improves) {
            return false;
        }

        $this->applyStop($trade, ['current_sl' => $target, 'be_locked' => true]);
        $trade->save();

        return true;
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
            $maxPct = (float) config('trading.near_vgb.max_sl_pct', 8.0);
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
            $trade->be_locked = ($stop - $entry * (1 + $dir * 2 * self::feeRate())) * $dir >= 0;
            if ($trade->stage === 'ENTRY' && $trade->be_locked) {
                $trade->stage = 'BE_LOCKED';
            }
            $meta = $trade->meta ?? [];
            $meta['manual_sl'] = ['price' => $stop, 'previous' => $previous, 'at' => now()->toIso8601String()];
            $trade->meta = $meta;
            $trade->save();

            $atStop = ($stop - $entry) * $dir * (float) $trade->remaining_quantity;
            $result = sprintf('%s$%.2f', $atStop >= 0 ? '+' : '-', abs($atStop));
            SystemLog::write('trade', sprintf('%s %s #%d: stop changed by user %s -> %s (at the stop %s).', strtoupper($trade->mode), $trade->symbol, $trade->id, $previous, $stop, $result));
            $this->notifier->notifyStopEdited($trade, $stop, $atStop);

            return ['success' => true, 'message' => "{$trade->symbol} stop set to {$stop} (at the stop {$result})."];
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
        if ($trade->setup_tag === NearBreakoutTrader::SETUP) {
            // The close message reported this exit; the strategy alerts start again from flat.
            Cache::forget(NearBreakoutTrader::SIGNAL_KEY);
        }

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
     * Detect exchange-side fills (stop-loss, partial closes) by comparing the live position size.
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

        if ($liveQty < (float) $trade->remaining_quantity * 0.98) {
            // Part of the position was closed on the exchange (an old take-profit order or by hand).
            $closedQty = (float) $trade->remaining_quantity - $liveQty;
            $mark = (float) $this->client->getMarkPrice($trade->symbol);
            $this->recordPartial($trade, $mark, $closedQty);
            $trade->remaining_quantity = $liveQty;
            $trade->margin_used = round($liveQty * $trade->entry_price / max(1, $trade->leverage), 4);
            $trade->save();
            $this->notifier->notifyPartlyClosedOnExchange($trade, round($closedQty, 8), $mark);
        }

        return null;
    }

    protected function recordPartial(Trade $trade, float $price, float $quantity): void
    {
        $gross = ($price - $trade->entry_price) * $quantity * ($trade->isLong() ? 1 : -1);
        $fee = $price * $quantity * self::feeRate();

        $meta = $trade->meta ?? [];
        $meta['partial_gross'] = round((float) ($meta['partial_gross'] ?? 0) + $gross, 6);
        $meta['partial_fee'] = round((float) ($meta['partial_fee'] ?? 0) + $fee, 6);
        $meta['partial_qty'] = round((float) ($meta['partial_qty'] ?? 0) + $quantity, 8);
        $trade->meta = $meta;
        $trade->realized_pnl = round((float) $meta['partial_gross'] - (float) $meta['partial_fee'], 4);
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

        if (! $wasBeLocked && $trade->be_locked) {
            $this->notifier->notifyBreakevenLocked($trade, $newSl);
        }
    }

    protected function paperExitPrice(Trade $trade, float $plannedPrice, float $currentPrice): float
    {
        // A gap through the stop fills at the worse price.
        if ($trade->isLong()) {
            return min($plannedPrice, $currentPrice) > 0 ? min($plannedPrice, $currentPrice) : $plannedPrice;
        }

        return max($plannedPrice, $currentPrice);
    }

    public static function feeRate(): float
    {
        return (float) config('trading.exits.fee_rate', 0.0005);
    }

    /**
     * NEAR breakout: the safety stop, then the script's trail. Once price reaches entry + trail_d the trail is armed
     * and the trade closes when price falls trail_d below the highest price since entry.
     *
     * @return array{status: string, message: string}
     */
    protected function manageNear(Trade $trade, float $price): array
    {
        $trade->highest_price = max((float) ($trade->highest_price ?? $price), $price);
        $trade->lowest_price = min((float) ($trade->lowest_price ?? $price), $price);
        if ($price <= (float) $trade->current_sl) {
            return $this->closeTradeUnlocked($trade, $trade->mode === 'live' ? $price : $this->paperExitPrice($trade, (float) $trade->current_sl, $price), 'STOP_LOSS');
        }

        $d = (float) ($trade->meta['trail_d'] ?? 0);
        $high = (float) $trade->highest_price;
        if ($d > 0) {
            $meta = $trade->meta ?? [];
            if (empty($meta['trail_active']) && $high >= (float) $trade->entry_price + $d) {
                $meta['trail_active'] = true;
                $trade->meta = $meta;
                $trade->stage = 'TRAILING';
                $this->notifier->notifyTrailArmed($trade, $high - $d);
            }
            if (! empty($meta['trail_active']) && $price <= $high - $d) {
                return $this->closeTradeUnlocked($trade, $trade->mode === 'live' ? $price : $this->paperExitPrice($trade, $high - $d, $price), 'TRAILING_STOP');
            }
        }
        $trade->save();

        return ['status' => 'managed', 'message' => "NEAR {$trade->id} {$trade->symbol} at {$price}, high {$high}, trail ".(! empty($trade->meta['trail_active']) ? round($high - $d, 6) : 'not armed').", stop {$trade->current_sl}."];
    }

    /**
     * Trades from the removed strategies: keep the stop (on the exchange for live) until it is hit or closed by hand.
     *
     * @return array{status: string, message: string}
     */
    protected function manageStopOnly(Trade $trade, float $price): array
    {
        $trade->highest_price = max((float) ($trade->highest_price ?? $price), $price);
        $trade->lowest_price = min((float) ($trade->lowest_price ?? $price), $price);
        $stopHit = $trade->isLong() ? $price <= (float) $trade->current_sl : $price >= (float) $trade->current_sl;
        if ($stopHit) {
            $moved = abs((float) $trade->current_sl - (float) $trade->initial_sl) > 1e-12;

            return $this->closeTradeUnlocked($trade, $trade->mode === 'live' ? $price : $this->paperExitPrice($trade, (float) $trade->current_sl, $price), $moved ? 'TRAILING_STOP' : 'STOP_LOSS');
        }
        $trade->save();

        return ['status' => 'managed', 'message' => "Trade {$trade->id} {$trade->symbol} at {$price}, stop {$trade->current_sl}."];
    }
}
