<?php

namespace App\Services\Trading;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Binance\BinanceFuturesClient;
use App\Services\Notifications\TelegramNotifier;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class TradeReconciler
{
    public function __construct(
        protected BinanceFuturesClient $client,
        protected RiskManager $riskManager,
        protected TelegramNotifier $notifier
    ) {}

    /**
     * Reconcile a closed trade strictly against Binance exchange data (userTrades & income).
     * Sourcing realized PnL, commissions, and funding fees ONLY from Binance for live trades.
     * Never writes a trade with a missing exit price or $0 fee.
     */
    public function reconcileClosedTrade(Trade $trade, int|string|null $exitOrderId = null, ?string $hintedReason = null): Trade
    {
        if ($trade->mode !== 'live') {
            return $this->reconcilePaperTrade($trade, $hintedReason);
        }

        return $this->reconcileLiveTrade($trade, $exitOrderId, $hintedReason);
    }

    /**
     * Reconcile a simulated paper trade with realistic fees, including TP1 partial profits.
     */
    protected function reconcilePaperTrade(Trade $trade, ?string $hintedReason = null): Trade
    {
        $this->applyEstimatedAccounting($trade, $hintedReason);
        $this->finalize($trade);

        return $trade;
    }

    /**
     * Fallback accounting when exchange fills cannot be fetched yet. The position is known to be flat;
     * figures are estimated from prices and the daily reconciler can correct them later.
     */
    public function reconcileEstimated(Trade $trade, ?string $hintedReason = null): Trade
    {
        $this->applyEstimatedAccounting($trade, $hintedReason);
        $this->finalize($trade);

        return $trade;
    }

    protected function applyEstimatedAccounting(Trade $trade, ?string $hintedReason): void
    {
        $exitPrice = $trade->exit_price ?: $trade->current_sl;
        if ($exitPrice <= 0) {
            $exitPrice = $trade->entry_price;
        }

        $closeQty = $trade->remaining_quantity > 0 ? $trade->remaining_quantity : $trade->quantity;
        $direction = $trade->isLong() ? 1 : -1;
        $feeRate = (float) config('trading.exits.fee_rate', 0.0005);
        $meta = $trade->meta ?? [];

        $partialGross = (float) ($meta['partial_gross'] ?? 0.0);
        $partialFee = (float) ($meta['partial_fee'] ?? 0.0);

        $grossPnl = ($exitPrice - $trade->entry_price) * $closeQty * $direction + $partialGross;
        $commission = round(($trade->quantity * $trade->entry_price * $feeRate) + ($closeQty * $exitPrice * $feeRate) + $partialFee, 4);
        $netPnl = round($grossPnl - $commission, 4);

        [$mae, $mfe] = $this->excursions($trade);

        $trade->exit_price = $exitPrice;
        $trade->exit_reason = $hintedReason ?: ($trade->exit_reason ?: 'MANUAL_CLOSE');
        $trade->gross_pnl = round($grossPnl, 4);
        $trade->commission = $commission;
        $trade->funding_fee = 0.0;
        $trade->net_pnl = $netPnl;
        $trade->realized_pnl = $netPnl;
        $trade->fee_paid = $commission;
        $initialMargin = $trade->leverage > 0 ? $trade->quantity * $trade->entry_price / $trade->leverage : $trade->margin_used;
        $trade->pnl_percent = $initialMargin > 0 ? round(($netPnl / $initialMargin) * 100, 2) : 0.0;
        $trade->stop_distance = round(abs($trade->entry_price - $trade->initial_sl), 8);
        $trade->mae = $mae;
        $trade->mfe = $mfe;
    }

    /**
     * Mark the trade closed, update account statistics and notify.
     */
    protected function finalize(Trade $trade): void
    {
        $trade->remaining_quantity = 0.0;
        $trade->status = 'CLOSED';
        $trade->stage = 'CLOSED';
        $trade->closed_at = $trade->closed_at ?: Carbon::now();
        $trade->save();

        $account = TradingAccount::getForMode($trade->mode);
        $this->riskManager->handleTradeClosed($account, $trade);
        $account->refresh();
        $this->notifier->notifyTradeClosed($trade, $account->balance);
    }

    /**
     * Maximum adverse / favourable excursion in USD over the full position size.
     *
     * @return array{0: float, 1: float}
     */
    protected function excursions(Trade $trade): array
    {
        $high = $trade->highest_price ?? $trade->entry_price;
        $low = $trade->lowest_price ?? $trade->entry_price;

        if ($trade->isLong()) {
            return [round(max(0, $trade->entry_price - $low) * $trade->quantity, 4), round(max(0, $high - $trade->entry_price) * $trade->quantity, 4)];
        }

        return [round(max(0, $high - $trade->entry_price) * $trade->quantity, 4), round(max(0, $trade->entry_price - $low) * $trade->quantity, 4)];
    }

    /**
     * Reconcile live trade strictly with Binance exchange fills (/userTrades) and funding (/income).
     */
    protected function reconcileLiveTrade(Trade $trade, int|string|null $exitOrderId = null, ?string $hintedReason = null): Trade
    {
        $fapi = $this->client->forMode('live');
        if (! $fapi->hasCredentials()) {
            throw new RuntimeException("Cannot reconcile live trade #{$trade->id}: Binance live credentials missing.");
        }

        $openedAt = $trade->opened_at ?: ($trade->created_at ?: now()->subHour());
        $startTimeMs = ($openedAt->timestamp - 120) * 1000; // 2 minutes before open to catch early fills

        // 1. Fetch user trades for this symbol
        $recentTrades = [];
        try {
            if ($exitOrderId !== null) {
                $recentTrades = $fapi->getUserTrades($trade->symbol, limit: 100, orderId: (int) $exitOrderId);
            }
            if (empty($recentTrades)) {
                $recentTrades = $fapi->getUserTrades($trade->symbol, limit: 100, startTime: $startTimeMs);
            }
        } catch (Throwable $e) {
            Log::error("[TradeReconciler] Failed fetching userTrades from Binance for #{$trade->id} ({$trade->symbol}): {$e->getMessage()}");
            throw new RuntimeException("Failed fetching userTrades from Binance for #{$trade->id}: {$e->getMessage()}", 0, $e);
        }

        if (empty($recentTrades)) {
            throw new RuntimeException("No user trades returned from Binance for #{$trade->id} ({$trade->symbol}). Cannot reconcile without true exchange fills.");
        }

        // 2. Identify closing fills: fills opposite to trade side occurring after opened_at
        $closingSide = $trade->isLong() ? 'SELL' : 'BUY';
        $entrySide = $trade->isLong() ? 'BUY' : 'SELL';

        $closingFills = [];
        $entryFills = [];
        $allFillsForSymbol = $recentTrades;

        foreach ($allFillsForSymbol as $fill) {
            $fillTime = (int) ($fill['time'] ?? 0);
            $fillSide = strtoupper((string) ($fill['side'] ?? ''));
            $fillOrderId = (string) ($fill['orderId'] ?? '');

            // Match entry order if binance_order_id is set
            if ($trade->binance_order_id && $fillOrderId === (string) $trade->binance_order_id) {
                $entryFills[] = $fill;

                continue;
            }

            // Match exit order if exitOrderId is set
            if ($exitOrderId && $fillOrderId === (string) $exitOrderId) {
                $closingFills[] = $fill;

                continue;
            }

            // Classify by side and timestamp window
            if ($fillSide === $closingSide && $fillTime >= ($openedAt->timestamp * 1000 - 5000)) {
                $closingFills[] = $fill;
            } elseif ($fillSide === $entrySide && $fillTime >= $startTimeMs && $fillTime <= ($openedAt->timestamp * 1000 + 30000)) {
                $entryFills[] = $fill;
            }
        }

        if (empty($closingFills)) {
            // If explicit closing fills were not separated, look for latest fill on closingSide
            $matchingSides = array_filter($allFillsForSymbol, fn (array $f): bool => strtoupper((string) ($f['side'] ?? '')) === $closingSide);
            if (! empty($matchingSides)) {
                $closingFills = array_slice($matchingSides, -5);
            }
        }

        if (empty($closingFills)) {
            throw new RuntimeException("Cannot find closing fills on Binance for {$trade->symbol} trade #{$trade->id}. Aborting reconciliation to prevent corrupt data.");
        }

        // 3. Compute weighted exit price, gross realized PnL, exit commission, and trade IDs
        $totalClosingQty = 0.0;
        $totalClosingValue = 0.0;
        $grossRealizedPnl = 0.0;
        $exitCommission = 0.0;
        $tradeIds = [];
        $detectedExitOrderId = $exitOrderId;

        foreach ($closingFills as $fill) {
            $qty = (float) ($fill['qty'] ?? 0);
            $price = (float) ($fill['price'] ?? 0);
            $pnl = (float) ($fill['realizedPnl'] ?? 0);
            $comm = (float) ($fill['commission'] ?? 0);

            $totalClosingQty += $qty;
            $totalClosingValue += ($qty * $price);
            $grossRealizedPnl += $pnl;
            $exitCommission += $comm;

            if (isset($fill['id'])) {
                $tradeIds[] = (string) $fill['id'];
            }
            if (! $detectedExitOrderId && isset($fill['orderId'])) {
                $detectedExitOrderId = (string) $fill['orderId'];
            }
        }

        $trueExitPrice = $totalClosingQty > 0 ? round($totalClosingValue / $totalClosingQty, 8) : 0.0;
        if ($trueExitPrice <= 0) {
            throw new RuntimeException("Invalid exit price ($trueExitPrice) reconciled from Binance for {$trade->symbol} #{$trade->id}.");
        }

        // 4. Compute entry commission from entry fills (if available) or existing fee_paid
        $entryCommission = 0.0;
        foreach ($entryFills as $fill) {
            $entryCommission += (float) ($fill['commission'] ?? 0);
            if (isset($fill['id'])) {
                $tradeIds[] = (string) $fill['id'];
            }
        }

        if ($entryCommission <= 0 && $trade->fee_paid > 0) {
            $entryCommission = $trade->fee_paid;
        }

        // If entry commission is still zero, calculate minimum estimated entry commission (0.05% taker)
        if ($entryCommission <= 0) {
            $entryCommission = round($trade->quantity * $trade->entry_price * 0.0005, 4);
        }

        $totalCommission = round($entryCommission + $exitCommission, 6);
        if ($totalCommission <= 0) {
            throw new RuntimeException("Total commission is $0.00 for {$trade->symbol} #{$trade->id}. Real exchange fees are required.");
        }

        // 5. Fetch funding fees from /fapi/v1/income
        $fundingFee = 0.0;
        try {
            $incomeRecords = $fapi->getIncome(
                symbol: $trade->symbol,
                incomeType: 'FUNDING_FEE',
                startTime: $startTimeMs,
                limit: 100
            );

            $closedTimeMs = ($trade->closed_at ? $trade->closed_at->timestamp : now()->timestamp) * 1000;
            foreach ($incomeRecords as $inc) {
                $incTime = (int) ($inc['time'] ?? 0);
                if ($incTime >= ($openedAt->timestamp * 1000 - 5000) && $incTime <= ($closedTimeMs + 5000)) {
                    $fundingFee += (float) ($inc['income'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            Log::warning("[TradeReconciler] Could not fetch funding fees for #{$trade->id}: {$e->getMessage()}");
        }
        $fundingFee = round($fundingFee, 6);

        // 6. Net Realized PnL = gross realized PnL - commission + funding fees
        $netPnl = round($grossRealizedPnl - $totalCommission + $fundingFee, 4);

        // 7. Classify Exit Reason
        $classifiedReason = $this->classifyExitReason($trade, $detectedExitOrderId, $trueExitPrice, $hintedReason, $fapi);

        // 8. Analytics: MAE, MFE, stop distance
        $stopDistance = round(abs($trade->entry_price - $trade->initial_sl), 8);
        $mae = 0.0;
        $mfe = 0.0;
        if ($trade->isLong()) {
            if ($trade->lowest_price !== null && $trade->lowest_price < $trade->entry_price) {
                $mae = round(($trade->entry_price - $trade->lowest_price) * $trade->quantity, 4);
            }
            if ($trade->highest_price !== null && $trade->highest_price > $trade->entry_price) {
                $mfe = round(($trade->highest_price - $trade->entry_price) * $trade->quantity, 4);
            }
        } else {
            if ($trade->highest_price !== null && $trade->highest_price > $trade->entry_price) {
                $mae = round(($trade->highest_price - $trade->entry_price) * $trade->quantity, 4);
            }
            if ($trade->lowest_price !== null && $trade->lowest_price < $trade->entry_price) {
                $mfe = round(($trade->entry_price - $trade->lowest_price) * $trade->quantity, 4);
            }
        }

        // 9. Persist truthful accounting fields
        $trade->exit_price = $trueExitPrice;
        $trade->exit_reason = $classifiedReason;
        $trade->gross_pnl = round($grossRealizedPnl, 4);
        $trade->commission = $totalCommission;
        $trade->funding_fee = $fundingFee;
        $trade->net_pnl = $netPnl;
        $trade->realized_pnl = $netPnl;
        $trade->fee_paid = $totalCommission;
        $trade->pnl_percent = $trade->margin_used > 0 ? round(($netPnl / $trade->margin_used) * 100, 2) : 0.0;
        $trade->stop_distance = $stopDistance;
        $trade->mae = $mae;
        $trade->mfe = $mfe;
        $trade->binance_exit_order_id = $detectedExitOrderId ? (string) $detectedExitOrderId : null;
        $trade->binance_trade_ids = array_values(array_unique($tradeIds));
        $trade->remaining_quantity = 0.0;
        $trade->status = 'CLOSED';
        $trade->stage = 'CLOSED';
        $trade->closed_at = $trade->closed_at ?: Carbon::now();
        $trade->save();

        // 10. Update account and notify
        $account = TradingAccount::getForMode($trade->mode);
        $this->riskManager->handleTradeClosed($account, $trade);
        $this->notifier->notifyTradeClosed($trade, $account->balance);

        Log::info("[TradeReconciler] Reconciled trade #{$trade->id} ({$trade->symbol}): Exit={$trueExitPrice}, Reason={$classifiedReason}, Gross=\${$trade->gross_pnl}, Comm=\${$totalCommission}, Funding=\${$fundingFee}, Net=\${$netPnl}");

        return $trade;
    }

    /**
     * Accurately classify closing reason based on Binance order details, triggers, and SL/TP proximity.
     */
    public function classifyExitReason(
        Trade $trade,
        int|string|null $exitOrderId,
        float $exitPrice,
        ?string $hintedReason,
        BinanceFuturesClient $fapi
    ): string {
        $orderInfo = null;
        if ($exitOrderId !== null) {
            try {
                $orderInfo = $fapi->getOrder($trade->symbol, $exitOrderId);
            } catch (Throwable) {
                // If order lookup fails, continue with heuristic analysis
            }
        }

        $orderType = strtoupper((string) ($orderInfo['origType'] ?? $orderInfo['type'] ?? ''));
        $clientOrderId = (string) ($orderInfo['clientOrderId'] ?? '');
        $isReduceOnly = ! empty($orderInfo['reduceOnly']);

        // 1. Liquidation
        if ($orderType === 'LIQUIDATION' || str_starts_with($clientOrderId, 'autoclose-')) {
            return 'LIQUIDATION';
        }

        // 2. Native Stop Loss hit on exchange
        $slAlgoId = (string) ($trade->meta['binance_sl_algo_id'] ?? '');
        $isSlOrder = ($orderType === 'STOP_MARKET' || $orderType === 'STOP');
        if ($isSlOrder || ($slAlgoId && (string) $exitOrderId === $slAlgoId)) {
            return 'STOP_LOSS';
        }
        if ($trade->current_sl > 0) {
            $slDiffPct = abs($exitPrice - $trade->current_sl) / $trade->current_sl;
            if ($slDiffPct <= 0.005) {
                $isAdverseMove = $trade->isLong() ? ($exitPrice <= $trade->current_sl * 1.002) : ($exitPrice >= $trade->current_sl * 0.998);
                if ($isAdverseMove) {
                    return 'STOP_LOSS';
                }
            }
        }

        // 3. Take Profit hit on exchange
        $tpAlgoId = (string) ($trade->meta['binance_tp_algo_id'] ?? '');
        $isTpOrder = ($orderType === 'TAKE_PROFIT_MARKET' || $orderType === 'TAKE_PROFIT');
        if ($isTpOrder || ($tpAlgoId && (string) $exitOrderId === $tpAlgoId)) {
            return 'TAKE_PROFIT';
        }
        if ($trade->tp1_price > 0 && abs($exitPrice - $trade->tp1_price) / $trade->tp1_price <= 0.005) {
            return 'TAKE_PROFIT';
        }
        if ($trade->tp2_price > 0 && abs($exitPrice - $trade->tp2_price) / $trade->tp2_price <= 0.005) {
            return 'TAKE_PROFIT';
        }

        // 4. Bot internal close reasons (Trailing SL, stagnation, beak profit lock, etc.)
        if (! empty($hintedReason) && ! in_array($hintedReason, ['EXCHANGE_CLOSED', 'EXCHANGE_OR_MANUAL_CLOSE'], true)) {
            return $hintedReason;
        }

        // 5. Reduce-only market order from bot
        if ($isReduceOnly) {
            return 'BOT_REDUCE_ONLY';
        }

        // 6. Manual close on Binance web/app
        return 'MANUAL_CLOSE';
    }
}
