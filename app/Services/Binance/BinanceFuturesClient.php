<?php

namespace App\Services\Binance;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BinanceFuturesClient
{
    protected string $mode;

    protected string $baseUrl;

    protected string $apiKey;

    protected string $apiSecret;

    protected int $recvWindow;

    /**
     * @param  string|null  $mode  'paper', 'testnet', 'shadow', or 'live'
     */
    public function __construct(?string $mode = null)
    {
        $this->mode = $mode ?? (string) config('trading.mode', 'paper');

        if ($this->mode === 'testnet') {
            $this->baseUrl = (string) config('trading.binance.endpoints.testnet_rest', 'https://testnet.binancefuture.com');
            $this->apiKey = (string) config('trading.binance.testnet_key', '');
            $this->apiSecret = (string) config('trading.binance.testnet_secret', '');
        } else {
            // Live, Paper, and Shadow fetch real live Binance market data from production API
            $this->baseUrl = (string) config('trading.binance.endpoints.live_rest', 'https://fapi.binance.com');
            $this->apiKey = (string) config('trading.binance.api_key', '');
            $this->apiSecret = (string) config('trading.binance.api_secret', '');
        }

        $this->recvWindow = (int) config('trading.binance.recv_window', 5000);
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function forMode(string $mode): self
    {
        return new self($mode);
    }

    public function hasCredentials(): bool
    {
        return ! empty($this->apiKey) && ! empty($this->apiSecret);
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Generate HMAC SHA256 signature for signed endpoints.
     *
     * @param  array<string, mixed>  $params
     */
    protected function sign(array $params): string
    {
        $queryString = http_build_query($params);

        return hash_hmac('sha256', $queryString, $this->apiSecret);
    }

    /**
     * Execute a signed GET request.
     *
     * @param  array<string, mixed>  $params
     */
    protected function signedGet(string $path, array $params = []): array
    {
        $params['timestamp'] = (int) (microtime(true) * 1000);
        $params['recvWindow'] = $this->recvWindow;
        $params['signature'] = $this->sign($params);

        $response = Http::timeout(10)
            ->withHeaders(['X-MBX-APIKEY' => $this->apiKey])
            ->get("{$this->baseUrl}{$path}", $params);

        return $this->handleResponse($response, $path);
    }

    /**
     * Execute a signed POST request.
     *
     * @param  array<string, mixed>  $params
     */
    protected function signedPost(string $path, array $params = []): array
    {
        $params['timestamp'] = (int) (microtime(true) * 1000);
        $params['recvWindow'] = $this->recvWindow;
        $params['signature'] = $this->sign($params);

        $response = Http::timeout(10)
            ->withHeaders(['X-MBX-APIKEY' => $this->apiKey])
            ->asForm()
            ->post("{$this->baseUrl}{$path}", $params);

        return $this->handleResponse($response, $path);
    }

    /**
     * Execute a signed DELETE request.
     *
     * @param  array<string, mixed>  $params
     */
    protected function signedDelete(string $path, array $params = []): array
    {
        $params['timestamp'] = (int) (microtime(true) * 1000);
        $params['recvWindow'] = $this->recvWindow;
        $params['signature'] = $this->sign($params);

        $queryString = http_build_query($params);

        $response = Http::timeout(10)
            ->withHeaders(['X-MBX-APIKEY' => $this->apiKey])
            ->delete("{$this->baseUrl}{$path}?{$queryString}");

        return $this->handleResponse($response, $path);
    }

    /**
     * Process response or throw informative exception.
     */
    protected function handleResponse(Response $response, string $path): array
    {
        if (! $response->successful()) {
            $status = $response->status();
            $body = $response->body();
            throw new RuntimeException("Binance API Error [{$path}] HTTP {$status}: {$body}");
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException("Binance API returned non-array payload on [{$path}]");
        }

        return $data;
    }

    /**
     * Fetch and cache exchange rules (stepSize, tickSize, minNotional).
     *
     * @return array<string, array<string, mixed>>
     */
    public function getExchangeInfo(): array
    {
        return Cache::remember('binance:futures:exchange_info', 3600, function (): array {
            $response = Http::timeout(15)->get("{$this->baseUrl}/fapi/v1/exchangeInfo");

            if (! $response->successful()) {
                throw new RuntimeException("Failed to fetch Binance Futures exchangeInfo: HTTP {$response->status()}");
            }

            $data = $response->json();
            $symbols = [];

            foreach ($data['symbols'] ?? [] as $sym) {
                if (($sym['status'] ?? '') !== 'TRADING' || ($sym['contractType'] ?? '') !== 'PERPETUAL') {
                    continue;
                }

                $pair = $sym['symbol'];
                $minNotional = 5.0;
                $stepSize = 0.001;
                $tickSize = 0.01;

                foreach ($sym['filters'] ?? [] as $filter) {
                    if ($filter['filterType'] === 'MIN_NOTIONAL') {
                        $minNotional = (float) ($filter['notional'] ?? 5.0);
                    }
                    if ($filter['filterType'] === 'LOT_SIZE') {
                        $stepSize = (float) ($filter['stepSize'] ?? 0.001);
                    }
                    if ($filter['filterType'] === 'PRICE_FILTER') {
                        $tickSize = (float) ($filter['tickSize'] ?? 0.01);
                    }
                }

                $symbols[$pair] = [
                    'symbol' => $pair,
                    'pricePrecision' => (int) ($sym['pricePrecision'] ?? 2),
                    'quantityPrecision' => (int) ($sym['quantityPrecision'] ?? 3),
                    'minNotional' => $minNotional,
                    'stepSize' => $stepSize,
                    'tickSize' => $tickSize,
                ];
            }

            return $symbols;
        });
    }

    /**
     * Fetch Klines (candlesticks) from Binance Futures REST API.
     *
     * @return array{
     *     opens: array<int, float>,
     *     highs: array<int, float>,
     *     lows: array<int, float>,
     *     closes: array<int, float>,
     *     volumes: array<int, float>,
     *     closeTimes: array<int, int>
     * }
     */
    public function klines(string $symbol, string $interval = '15m', int $limit = 320): array
    {
        $symbol = strtoupper($symbol);
        $interval = strtolower($interval);
        $cacheKey = "binance:fapi:klines:{$symbol}:{$interval}:{$limit}";

        return Cache::remember($cacheKey, 10, function () use ($symbol, $interval, $limit): array {
            $response = Http::timeout(10)->get("{$this->baseUrl}/fapi/v1/klines", [
                'symbol' => $symbol,
                'interval' => $interval,
                'limit' => $limit,
            ]);

            if (! $response->successful()) {
                throw new RuntimeException("Failed to fetch klines for {$symbol} ({$interval}): HTTP {$response->status()}");
            }

            $rawKlines = $response->json();
            if (! is_array($rawKlines) || empty($rawKlines)) {
                throw new RuntimeException("Empty klines response for {$symbol} ({$interval})");
            }

            $opens = [];
            $highs = [];
            $lows = [];
            $closes = [];
            $volumes = [];
            $closeTimes = [];

            foreach ($rawKlines as $kline) {
                $opens[] = (float) $kline[1];
                $highs[] = (float) $kline[2];
                $lows[] = (float) $kline[3];
                $closes[] = (float) $kline[4];
                $volumes[] = (float) $kline[5];
                $closeTimes[] = (int) $kline[6];
            }

            return [
                'opens' => $opens,
                'highs' => $highs,
                'lows' => $lows,
                'closes' => $closes,
                'volumes' => $volumes,
                'closeTimes' => $closeTimes,
            ];
        });
    }

    /**
     * Fetch current 24-hour tickers to filter volume & liquidity.
     */
    public function get24hrTickers(): array
    {
        return Cache::remember('binance:futures:24hr_tickers', 30, function (): array {
            $response = Http::timeout(12)->get("{$this->baseUrl}/fapi/v1/ticker/24hr");

            if (! $response->successful()) {
                throw new RuntimeException("Failed to fetch 24hr tickers: HTTP {$response->status()}");
            }

            return (array) $response->json();
        });
    }

    /**
     * Get real-time mark price for a symbol.
     */
    public function getMarkPrice(string $symbol): float
    {
        $symbol = strtoupper($symbol);

        return (float) Cache::remember("binance:mark:{$symbol}", 2, function () use ($symbol): float {
            $response = Http::timeout(4)->get("{$this->baseUrl}/fapi/v1/premiumIndex", [
                'symbol' => $symbol,
            ]);

            if (! $response->successful()) {
                throw new RuntimeException("Failed to get mark price for {$symbol}");
            }

            $data = $response->json();

            return (float) ($data['markPrice'] ?? 0.0);
        });
    }

    /**
     * Clear cached account/positions data for this client.
     */
    public function clearAccountCache(): void
    {
        $keySuffix = substr(md5($this->apiKey), 0, 10);
        Cache::forget("binance:{$this->mode}:account:{$keySuffix}");
        Cache::forget("binance:{$this->mode}:balance:{$keySuffix}");
        Cache::forget("binance:{$this->mode}:positions:{$keySuffix}");
    }

    /**
     * Get Futures account details (signed).
     */
    public function getAccount(): array
    {
        $keySuffix = substr(md5($this->apiKey), 0, 10);

        return Cache::remember("binance:{$this->mode}:account:{$keySuffix}", 2, fn (): array => $this->signedGet('/fapi/v2/account'));
    }

    /**
     * Get account balances (signed).
     */
    public function getBalance(): array
    {
        $keySuffix = substr(md5($this->apiKey), 0, 10);

        return Cache::remember("binance:{$this->mode}:balance:{$keySuffix}", 2, fn (): array => $this->signedGet('/fapi/v2/balance'));
    }

    /**
     * Get active positions (signed).
     */
    public function getPositions(): array
    {
        $keySuffix = substr(md5($this->apiKey), 0, 10);

        return Cache::remember("binance:{$this->mode}:positions:{$keySuffix}", 2, fn (): array => $this->signedGet('/fapi/v2/positionRisk'));
    }

    /**
     * Set leverage for a symbol (signed).
     */
    public function setLeverage(string $symbol, int $leverage): array
    {
        return $this->signedPost('/fapi/v1/leverage', [
            'symbol' => strtoupper($symbol),
            'leverage' => $leverage,
        ]);
    }

    /**
     * Set margin type (ISOLATED or CROSSED).
     */
    public function setMarginType(string $symbol, string $marginType = 'ISOLATED'): array
    {
        try {
            return $this->signedPost('/fapi/v1/marginType', [
                'symbol' => strtoupper($symbol),
                'marginType' => strtoupper($marginType),
            ]);
        } catch (\Exception $e) {
            // Code -4046: "No need to change margin type" if already set
            if (str_contains($e->getMessage(), '-4046')) {
                return ['msg' => 'already_set'];
            }
            throw $e;
        }
    }

    /**
     * Place order on Binance Futures.
     *
     * @param  array<string, mixed>  $params
     */
    public function placeOrder(array $params): array
    {
        $this->clearAccountCache();

        return $this->signedPost('/fapi/v1/order', $params);
    }

    /**
     * Cancel an open order.
     */
    public function cancelOrder(string $symbol, int|string $orderId): array
    {
        $this->clearAccountCache();

        return $this->signedDelete('/fapi/v1/order', [
            'symbol' => strtoupper($symbol),
            'orderId' => $orderId,
        ]);
    }

    /**
     * Cancel all open orders for a symbol.
     */
    public function cancelAllOrders(string $symbol): array
    {
        $this->clearAccountCache();

        return $this->signedDelete('/fapi/v1/allOpenOrders', [
            'symbol' => strtoupper($symbol),
        ]);
    }

    /**
     * Place an Algo / Conditional order on Binance Futures.
     *
     * @param  array<string, mixed>  $params
     */
    public function placeAlgoOrder(array $params): array
    {
        $this->clearAccountCache();

        return $this->signedPost('/fapi/v1/algoOrder', $params);
    }

    /**
     * Place an exchange-side Stop Loss on Binance Futures.
     */
    public function placeStopLoss(string $symbol, string $side, float $stopPrice, ?float $quantity = null, bool $closePosition = true): array
    {
        $params = [
            'algoType' => 'CONDITIONAL',
            'symbol' => strtoupper($symbol),
            'side' => strtoupper($side),
            'type' => 'STOP_MARKET',
            'triggerPrice' => (string) $this->formatPrice($symbol, $stopPrice),
        ];

        if ($closePosition) {
            $params['closePosition'] = 'true';
        } else {
            $params['quantity'] = (string) $this->formatQuantity($symbol, $quantity ?? 0.0);
            $params['reduceOnly'] = 'true';
        }

        return $this->placeAlgoOrder($params);
    }

    /**
     * Place an exchange-side Take Profit on Binance Futures.
     */
    public function placeTakeProfit(string $symbol, string $side, float $tpPrice, ?float $quantity = null, bool $closePosition = false): array
    {
        $params = [
            'algoType' => 'CONDITIONAL',
            'symbol' => strtoupper($symbol),
            'side' => strtoupper($side),
            'type' => 'TAKE_PROFIT_MARKET',
            'triggerPrice' => (string) $this->formatPrice($symbol, $tpPrice),
        ];

        if ($closePosition) {
            $params['closePosition'] = 'true';
        } else {
            $params['quantity'] = (string) $this->formatQuantity($symbol, $quantity ?? 0.0);
            $params['reduceOnly'] = 'true';
        }

        return $this->placeAlgoOrder($params);
    }

    /**
     * Get open algo / conditional orders.
     */
    public function getOpenAlgoOrders(?string $symbol = null): array
    {
        $params = [];
        if ($symbol) {
            $params['symbol'] = strtoupper($symbol);
        }

        return $this->signedGet('/fapi/v1/openAlgoOrders', $params);
    }

    /**
     * Cancel an open algo order.
     */
    public function cancelAlgoOrder(string $symbol, int|string $algoId): array
    {
        $this->clearAccountCache();

        return $this->signedDelete('/fapi/v1/algoOrder', [
            'symbol' => strtoupper($symbol),
            'algoId' => $algoId,
        ]);
    }

    /**
     * Cancel all open algo orders for a symbol.
     */
    public function cancelAllAlgoOrders(string $symbol): void
    {
        try {
            $orders = $this->getOpenAlgoOrders($symbol);
            foreach ($orders as $order) {
                if (isset($order['algoId'])) {
                    $this->cancelAlgoOrder($symbol, $order['algoId']);
                }
            }
        } catch (\Throwable) {
            // Ignore if no algo orders exist
        }
    }

    /**
     * Helper: Format quantity to exchange stepSize.
     */
    public function formatQuantity(string $symbol, float $quantity): float
    {
        $info = $this->getExchangeInfo()[$symbol] ?? null;
        if (! $info) {
            return round($quantity, 3);
        }

        $step = (float) $info['stepSize'];
        $precision = (int) $info['quantityPrecision'];

        if ($step <= 0) {
            return round($quantity, $precision);
        }

        $steps = floor($quantity / $step);

        return round($steps * $step, $precision);
    }

    /**
     * Helper: Format price to exchange tickSize.
     */
    public function formatPrice(string $symbol, float $price): float
    {
        $info = $this->getExchangeInfo()[$symbol] ?? null;
        if (! $info) {
            return round($price, 2);
        }

        $tick = (float) $info['tickSize'];
        $precision = (int) $info['pricePrecision'];

        if ($tick <= 0) {
            return round($price, $precision);
        }

        $ticks = round($price / $tick);

        return round($ticks * $tick, $precision);
    }

    /**
     * Helper: Minimum required notional value in USD.
     */
    public function getMinNotional(string $symbol): float
    {
        $info = $this->getExchangeInfo()[$symbol] ?? null;

        return (float) ($info['minNotional'] ?? 5.0);
    }
}
