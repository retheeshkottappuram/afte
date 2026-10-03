<?php

namespace App\Services\Trading;

use App\Models\SystemLog;
use App\Models\Trade;
use App\Services\Binance\BinanceFuturesClient;
use App\Services\Crypto\BinanceClient;
use App\Services\Crypto\Indicators;
use App\Services\Notifications\TelegramNotifier;
use App\Services\Strategy\ExitPlan;
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
            // Stop or target filled on the exchange.
            $hint = $trade->tp1_hit ? 'TRAILING_STOP' : 'EXCHANGE_CLOSED';

            return $this->closeTradeUnlocked($trade, (float) $trade->current_sl, $hint);
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

    protected function recordPartial(Trade $trade, float $price, float $quantity): void
    {
        $gross = ($price - $trade->entry_price) * $quantity * ($trade->isLong() ? 1 : -1);
        $fee = $price * $quantity * $this->exitPlan->feeRate();

        $meta = $trade->meta ?? [];
        $meta['partial_gross'] = round((float) ($meta['partial_gross'] ?? 0) + $gross, 6);
        $meta['partial_fee'] = round((float) ($meta['partial_fee'] ?? 0) + $fee, 6);
        $meta['partial_qty'] = round((float) ($meta['partial_qty'] ?? 0) + $quantity, 8);
        $trade->meta = $meta;
        $trade->realized_pnl = round((float) $meta['partial_gross'] - (float) $meta['partial_fee'], 4);

        $this->notifier->notifyTp1Hit($trade, $quantity, round($gross - $fee, 4));
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
        $barSeconds = $interval === '15m' ? 900 : ($interval === '4h' ? 14400 : 3600);
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
