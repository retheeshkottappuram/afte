<?php

namespace App\Services\Trading;

use App\Models\Trade;
use App\Services\Binance\BinanceFuturesClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Live exchange order primitives with safety guarantees:
 *  - protective stops are retried and their ids recorded,
 *  - stop moves place the new stop BEFORE cancelling the old one (never a gap),
 *  - a close is only reported successful once Binance shows the position flat.
 */
class ExchangeOrders
{
    public function __construct(
        protected BinanceFuturesClient $client
    ) {}

    public function live(): BinanceFuturesClient
    {
        return $this->client->forMode('live');
    }

    /**
     * Place a reduce-only stop-market order, retrying transient failures.
     *
     * @return array{id: string, kind: string}
     */
    public function placeStop(string $symbol, string $positionSide, float $stopPrice, float $quantity, int $attempts = 3): array
    {
        $client = $this->live();
        $closeSide = $positionSide === 'LONG' ? 'SELL' : 'BUY';
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $client->placeStopLoss($symbol, $closeSide, $stopPrice, $quantity, false);

                return $this->orderRef($response);
            } catch (Throwable $e) {
                $lastError = $e;
                Log::warning("[ExchangeOrders] Stop placement attempt {$attempt}/{$attempts} failed for {$symbol}: {$e->getMessage()}");
                usleep(300000 * $attempt);
            }
        }

        throw new RuntimeException("Could not place protective stop for {$symbol}: ".($lastError?->getMessage() ?? 'unknown error'));
    }

    /**
     * Place a reduce-only take-profit order.
     *
     * @return array{id: string, kind: string}
     */
    public function placeTakeProfit(string $symbol, string $positionSide, float $price, float $quantity): array
    {
        $closeSide = $positionSide === 'LONG' ? 'SELL' : 'BUY';
        $response = $this->live()->placeTakeProfit($symbol, $closeSide, $price, $quantity, false);

        return $this->orderRef($response);
    }

    /**
     * Move the exchange stop: new stop first, then cancel the previous one.
     * Returns false (and leaves the old stop in place) when the new stop cannot be placed.
     */
    public function replaceStop(Trade $trade, float $newStop): bool
    {
        $meta = $trade->meta ?? [];
        $previous = $meta['sl_order'] ?? null;

        try {
            $ref = $this->placeStop($trade->symbol, $trade->side, $newStop, (float) $trade->remaining_quantity, attempts: 2);
        } catch (Throwable $e) {
            Log::error("[ExchangeOrders] Stop move failed for trade #{$trade->id}; previous stop kept: {$e->getMessage()}");

            return false;
        }

        if (is_array($previous)) {
            $this->cancelRef($trade->symbol, $previous);
        }

        $meta['sl_order'] = $ref;
        $meta['binance_sl_algo_id'] = $ref['id'];
        $trade->meta = $meta;

        return true;
    }

    /**
     * Cancel one recorded order reference, ignoring "already gone" errors.
     *
     * @param  array{id?: string, kind?: string}  $ref
     */
    public function cancelRef(string $symbol, array $ref): void
    {
        if (empty($ref['id'])) {
            return;
        }

        try {
            if (($ref['kind'] ?? 'algo') === 'algo') {
                $this->live()->cancelAlgoOrder($symbol, $ref['id']);
            } else {
                $this->live()->cancelOrder($symbol, $ref['id']);
            }
        } catch (Throwable $e) {
            Log::info("[ExchangeOrders] Cancel of {$symbol} order {$ref['id']} skipped: {$e->getMessage()}");
        }
    }

    /**
     * Market-close whatever position Binance reports for the trade and confirm it is flat.
     *
     * @return array{flat: bool, order_id: ?string, avg_price: ?float, message: string}
     */
    public function closeAndConfirmFlat(Trade $trade): array
    {
        $client = $this->live();
        $orderId = null;
        $avgPrice = null;

        try {
            $positionAmount = $client->getPositionAmount($trade->symbol);
        } catch (Throwable $e) {
            return ['flat' => false, 'order_id' => null, 'avg_price' => null, 'message' => "Could not read position: {$e->getMessage()}"];
        }

        $expectedSign = $trade->side === 'LONG' ? 1 : -1;
        $openQty = ($positionAmount !== null && $positionAmount * $expectedSign > 0) ? abs($positionAmount) : 0.0;

        if ($openQty > 0) {
            try {
                $response = $client->placeOrder([
                    'symbol' => $trade->symbol,
                    'side' => $trade->side === 'LONG' ? 'SELL' : 'BUY',
                    'type' => 'MARKET',
                    'quantity' => $client->formatQuantity($trade->symbol, $openQty),
                    'reduceOnly' => 'true',
                    'newOrderRespType' => 'RESULT',
                ]);
                $orderId = isset($response['orderId']) ? (string) $response['orderId'] : null;
                $avgPrice = (float) ($response['avgPrice'] ?? 0) > 0 ? (float) $response['avgPrice'] : null;
            } catch (Throwable $e) {
                return ['flat' => false, 'order_id' => null, 'avg_price' => null, 'message' => "Close order rejected: {$e->getMessage()}"];
            }
        }

        try {
            $remaining = (float) ($client->getPositionAmount($trade->symbol) ?? 0.0);
        } catch (Throwable $e) {
            return ['flat' => false, 'order_id' => $orderId, 'avg_price' => $avgPrice, 'message' => "Could not confirm flat: {$e->getMessage()}"];
        }

        if ($remaining * $expectedSign > 0) {
            return ['flat' => false, 'order_id' => $orderId, 'avg_price' => $avgPrice, 'message' => "Position still open on Binance ({$remaining})."];
        }

        // Flat: remaining protective orders for this trade are now orphans.
        $meta = $trade->meta ?? [];
        foreach (['sl_order', 'tp_order'] as $key) {
            if (is_array($meta[$key] ?? null)) {
                $this->cancelRef($trade->symbol, $meta[$key]);
            }
        }

        return ['flat' => true, 'order_id' => $orderId, 'avg_price' => $avgPrice, 'message' => 'Position confirmed flat.'];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{id: string, kind: string}
     */
    protected function orderRef(array $response): array
    {
        if (isset($response['algoId'])) {
            return ['id' => (string) $response['algoId'], 'kind' => 'algo'];
        }

        return ['id' => (string) ($response['orderId'] ?? ''), 'kind' => 'order'];
    }
}
