<?php

namespace App\Services\Trading;

use App\Models\SystemLog;
use App\Models\Trade;
use App\Services\Binance\BinanceFuturesClient;
use App\Services\Notifications\TelegramNotifier;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Dashboard "Book 25% / 50% / Close" for real Binance positions. Bot trades go through
 * DynamicTradeManager (so its ledger stays right); manual positions get a reduce-only market order.
 */
class ExchangePositionCloser
{
    public function __construct(
        protected BinanceFuturesClient $client,
        protected DynamicTradeManager $tradeManager,
        protected TelegramNotifier $notifier
    ) {}

    /**
     * @return array{success: bool, message: string, filled_qty?: float, avg_price?: float, realized_pnl?: float}
     */
    public function close(string $symbol, ?string $side, float $ratio): array
    {
        $symbol = strtoupper($symbol);
        $ratio = max(0.01, min(1.0, $ratio));

        $bot = Trade::where('mode', 'live')->where('status', 'OPEN')->where('symbol', $symbol)
            ->when($side !== null, fn ($q) => $q->where('side', strtoupper($side)))->first();
        if ($bot !== null) {
            return $this->closeTrade($bot, $ratio);
        }

        $client = $this->client->forMode('live');
        if (! $client->hasCredentials()) {
            return ['success' => false, 'message' => 'Binance API keys are not configured.'];
        }

        $lock = Cache::lock("exchange-close:{$symbol}", 30);
        if (! $lock->block(10)) {
            return ['success' => false, 'message' => "{$symbol} is already being closed, try again."];
        }

        try {
            $position = $this->findPosition($client, $symbol, $side);
            if ($position === null) {
                return ['success' => false, 'message' => "No open {$symbol} position on Binance."];
            }

            $amount = (float) $position['positionAmt'];
            $held = abs($amount);
            $quantity = $ratio >= 0.999 ? $held : $client->formatQuantity($symbol, $held * $ratio);
            if ($quantity <= 0 || ($ratio < 0.999 && $quantity >= $held)) {
                return ['success' => false, 'message' => "{$symbol}: position too small to split at this lot size. Close 100% instead."];
            }

            $params = ['symbol' => $symbol, 'side' => $amount > 0 ? 'SELL' : 'BUY', 'type' => 'MARKET', 'quantity' => $quantity, 'newOrderRespType' => 'RESULT'];
            $positionSide = (string) ($position['positionSide'] ?? 'BOTH');
            if ($positionSide !== 'BOTH') {
                $params['positionSide'] = $positionSide; // hedge mode: the position side replaces reduceOnly
            } else {
                $params['reduceOnly'] = 'true';
            }

            $order = $client->placeOrder($params);
            $fill = (float) ($order['avgPrice'] ?? 0) > 0 ? (float) $order['avgPrice'] : (float) ($position['markPrice'] ?? 0);
            $pnl = round(($fill - (float) $position['entryPrice']) * $quantity * ($amount > 0 ? 1 : -1), 4);

            if ($ratio >= 0.999) {
                // Leave no orphaned stop / take-profit orders behind.
                try {
                    $client->cancelAllAlgoOrders($symbol);
                    $client->cancelAllOrders($symbol);
                } catch (Throwable) {
                    // Nothing left to cancel
                }
            }

            $message = sprintf('LIVE %s %s: %s %s at %s (manual position). PnL about $%s before fees.', $symbol, $amount > 0 ? 'LONG' : 'SHORT', $ratio >= 0.999 ? 'closed' : 'booked '.round($ratio * 100).'% =', $quantity, $fill, $pnl);
            SystemLog::write('trade', $message);
            TradingDaemonManager::appendLog($message);
            $this->notifier->sendMessage('✋ '.$message);

            return ['success' => true, 'message' => $message, 'filled_qty' => $quantity, 'avg_price' => $fill, 'realized_pnl' => $pnl];
        } catch (Throwable $e) {
            SystemLog::write('trade', "Close of {$symbol} failed: {$e->getMessage()}", 'error');

            return ['success' => false, 'message' => 'Binance rejected the order: '.$e->getMessage()];
        } finally {
            $lock->release();
        }
    }

    /**
     * Set, move or remove the stop-loss / take-profit of a manual Binance position.
     * $changes holds only the legs to change: ['sl' => float|null, 'tp' => float|null] (null removes).
     *
     * @param  array{sl?: float|null, tp?: float|null}  $changes
     * @return array{success: bool, message: string, http: int, sl?: ?float, tp?: ?float}
     */
    public function protect(string $symbol, ?string $side, array $changes): array
    {
        $symbol = strtoupper($symbol);

        if (Trade::where('mode', 'live')->where('status', 'OPEN')->where('symbol', $symbol)->exists()) {
            return ['success' => false, 'http' => 409, 'message' => "{$symbol} is managed by the bot (its stop trails automatically). Use Lock BE or Book instead."];
        }

        $client = $this->client->forMode('live');
        if (! $client->hasCredentials()) {
            return ['success' => false, 'http' => 422, 'message' => 'Binance API keys are not configured.'];
        }

        $lock = Cache::lock("exchange-close:{$symbol}", 30);
        if (! $lock->block(10)) {
            return ['success' => false, 'http' => 409, 'message' => "{$symbol} is busy, try again."];
        }

        try {
            $position = $this->findPosition($client, $symbol, $side);
            if ($position === null) {
                return ['success' => false, 'http' => 404, 'message' => "No open {$symbol} position on Binance."];
            }

            $isLong = (float) $position['positionAmt'] > 0;
            $mark = (float) $client->getMarkPrice($symbol) ?: (float) ($position['markPrice'] ?? 0);
            $positionSide = (string) ($position['positionSide'] ?? 'BOTH');

            // A trigger on the wrong side of the price would fire at once and close the position.
            foreach (['sl' => 'Stop', 'tp' => 'Take-profit'] as $leg => $label) {
                $price = $changes[$leg] ?? null;
                if ($price === null) {
                    continue;
                }
                $mustBeBelow = ($leg === 'sl') === $isLong;
                if ($price <= 0 || ($mustBeBelow ? $price >= $mark : $price <= $mark)) {
                    return ['success' => false, 'http' => 422, 'message' => sprintf('%s %s must be %s the mark price %s for a %s, or it triggers at once.', $label, $price, $mustBeBelow ? 'below' : 'above', $mark, $isLong ? 'LONG' : 'SHORT')];
                }
            }

            $closeSide = $isLong ? 'SELL' : 'BUY';
            $done = [];
            foreach (['sl', 'tp'] as $leg) {
                if (! array_key_exists($leg, $changes)) {
                    continue;
                }

                $this->cancelLeg($client, $symbol, $leg, $positionSide);
                $price = $changes[$leg];
                if ($price === null) {
                    $done[] = $leg === 'sl' ? 'stop removed' : 'take-profit removed';

                    continue;
                }

                $price = $client->formatPrice($symbol, $price);
                try {
                    $leg === 'sl'
                        ? $client->placeStopLoss($symbol, $closeSide, $price, null, true, $positionSide)
                        : $client->placeTakeProfit($symbol, $closeSide, $price, null, true, $positionSide);
                } catch (Throwable $e) {
                    if ($leg === 'sl') {
                        $this->notifier->notifyRiskEvent('live', 'Stop-loss not placed', "{$symbol}: the old stop was cancelled but the new one at {$price} failed: {$e->getMessage()} The position is UNPROTECTED.");
                    }

                    return ['success' => false, 'http' => 502, 'message' => ($leg === 'sl' ? "New stop at {$price} was rejected and the position is UNPROTECTED: " : "New take-profit at {$price} was rejected: ").$e->getMessage()];
                }
                $done[] = ($leg === 'sl' ? 'stop' : 'take-profit')." set at {$price}";
            }

            Cache::forget('exchange:protective-orders');
            $client->clearAccountCache();

            $message = "LIVE {$symbol} ".($isLong ? 'LONG' : 'SHORT').' (manual): '.implode(', ', $done).'.';
            SystemLog::write('trade', $message);
            TradingDaemonManager::appendLog($message);
            $this->notifier->sendMessage('🛡️ '.$message);

            return [
                'success' => true,
                'http' => 200,
                'message' => $message,
                'sl' => array_key_exists('sl', $changes) && $changes['sl'] !== null ? $client->formatPrice($symbol, $changes['sl']) : null,
                'tp' => array_key_exists('tp', $changes) && $changes['tp'] !== null ? $client->formatPrice($symbol, $changes['tp']) : null,
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'http' => 502, 'message' => 'Binance rejected the change: '.$e->getMessage()];
        } finally {
            $lock->release();
        }
    }

    /**
     * Cancel the existing stop (STOP*) or take-profit (TAKE_PROFIT*) orders of one position, algo and classic.
     */
    protected function cancelLeg(BinanceFuturesClient $client, string $symbol, string $leg, string $positionSide): void
    {
        $prefix = $leg === 'sl' ? 'STOP' : 'TAKE_PROFIT';
        $samePosition = fn (array $o): bool => $positionSide === 'BOTH' || in_array($o['positionSide'] ?? 'BOTH', ['BOTH', $positionSide], true);

        try {
            foreach ($client->getOpenAlgoOrders($symbol) as $order) {
                if (str_starts_with(strtoupper((string) ($order['orderType'] ?? $order['type'] ?? '')), $prefix) && $samePosition($order) && isset($order['algoId'])) {
                    $client->cancelAlgoOrder($symbol, $order['algoId']);
                }
            }
        } catch (Throwable) {
            // No algo orders
        }

        foreach ($client->getOpenOrders($symbol) as $order) {
            if (str_starts_with(strtoupper((string) ($order['type'] ?? '')), $prefix) && $samePosition($order) && isset($order['orderId'])) {
                $client->cancelOrder($symbol, $order['orderId']);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findPosition(BinanceFuturesClient $client, string $symbol, ?string $side): ?array
    {
        $client->clearAccountCache();

        return collect($client->getPositions())->first(function (array $p) use ($symbol, $side): bool {
            $amount = (float) ($p['positionAmt'] ?? 0);

            return ($p['symbol'] ?? '') === $symbol && $amount != 0.0
                && ($side === null || (strtoupper($side) === 'LONG') === ($amount > 0));
        });
    }

    /**
     * Full or partial close of a bot trade (paper or live) through the trade manager.
     *
     * @return array{success: bool, message: string, filled_qty?: float, avg_price?: float, realized_pnl?: float}
     */
    public function closeTrade(Trade $trade, float $ratio): array
    {
        try {
            $price = (float) ($this->client->getMarkPrice($trade->symbol) ?: $trade->entry_price);
        } catch (Throwable) {
            $price = (float) $trade->entry_price;
        }

        $result = $ratio >= 0.999
            ? $this->tradeManager->closeTrade($trade, $price, 'MANUAL_CLOSE')
            : $this->tradeManager->closePartial($trade, $ratio, $price);

        $success = in_array($result['status'], ['closed', 'partial'], true);
        if ($success) {
            TradingDaemonManager::appendLog(strtoupper("[{$trade->mode}] ").$result['message']);
        }

        return ['success' => $success] + $result;
    }
}
