<?php

namespace App\Services\Crypto;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BinanceClient
{
    protected string $market;

    protected string $baseUrl;

    protected string $endpoint;

    public function __construct(?string $market = null, ?string $baseUrl = null)
    {
        $this->market = strtolower($market ?? (string) config('crypto.market', 'futures'));

        if ($this->market === 'spot') {
            $this->baseUrl = rtrim($baseUrl ?? 'https://api.binance.com', '/');
            $this->endpoint = '/api/v3/klines';
        } else {
            // Default: Binance USDⓈ-M Perpetual Futures
            $this->baseUrl = rtrim($baseUrl ?? 'https://fapi.binance.com', '/');
            $this->endpoint = '/fapi/v1/klines';
        }
    }

    /**
     * Get the active market identifier ('futures' or 'spot').
     */
    public function getMarket(): string
    {
        return $this->market;
    }

    /**
     * Get the human-readable market label.
     */
    public function getMarketLabel(): string
    {
        return $this->market === 'spot' ? 'Binance Spot' : 'Binance USDⓈ-M Futures';
    }

    /**
     * Fetch OHLCV klines from Binance public REST API (Futures or Spot).
     *
     * @param  string  $symbol  Trading pair (e.g., BTCUSDT, SOLUSDT)
     * @param  string  $interval  Timeframe (e.g., 15m, 1h)
     * @param  int  $limit  Number of candles (default 320, max 1000)
     * @return array{
     *     opens: array<int, float>,
     *     highs: array<int, float>,
     *     lows: array<int, float>,
     *     closes: array<int, float>,
     *     volumes: array<int, float>,
     *     closeTimes: array<int, int>
     * }
     *
     * @throws RuntimeException
     */
    /**
     * Parse raw Binance API kline records into typed series.
     *
     * @param  array<int, array<int, mixed>>  $rawKlines
     * @return array{
     *     opens: array<int, float>,
     *     highs: array<int, float>,
     *     lows: array<int, float>,
     *     closes: array<int, float>,
     *     volumes: array<int, float>,
     *     closeTimes: array<int, int>
     * }
     */
    public function parseRawKlines(array $rawKlines): array
    {
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
    }

    /**
     * Fetch OHLCV klines from Binance public REST API (Futures or Spot).
     *
     * @param  string  $symbol  Trading pair (e.g., BTCUSDT, SOLUSDT)
     * @param  string  $interval  Timeframe (e.g., 15m, 1h)
     * @param  int  $limit  Number of candles (default 320, max 1000)
     * @return array{
     *     opens: array<int, float>,
     *     highs: array<int, float>,
     *     lows: array<int, float>,
     *     closes: array<int, float>,
     *     volumes: array<int, float>,
     *     closeTimes: array<int, int>
     * }
     *
     * @throws RuntimeException
     */
    public function klines(string $symbol, string $interval, int $limit = 320): array
    {
        $symbol = strtoupper($symbol);
        $interval = strtolower($interval);
        $cacheKey = "binance:klines:{$this->market}:{$symbol}:{$interval}:{$limit}";

        return Cache::remember($cacheKey, 15, function () use ($symbol, $interval, $limit): array {
            $url = "{$this->baseUrl}{$this->endpoint}";

            $response = Http::timeout(10)
                ->acceptJson()
                ->get($url, [
                    'symbol' => $symbol,
                    'interval' => $interval,
                    'limit' => $limit,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException("Failed to fetch klines for {$symbol} ({$interval}) from {$this->getMarketLabel()}: HTTP {$response->status()} - {$response->body()}");
            }

            /** @var array<int, array<int, mixed>> $rawKlines */
            $rawKlines = $response->json();

            if (! is_array($rawKlines) || empty($rawKlines)) {
                throw new RuntimeException("Empty or invalid klines response for {$symbol} ({$interval}) from {$this->getMarketLabel()}");
            }

            return $this->parseRawKlines($rawKlines);
        });
    }

    /**
     * Fetch base, HTF1, and HTF2 candles concurrently in parallel with caching.
     *
     * @return array{base: array, htf1: ?array, htf2: ?array}
     */
    public function fetchMultiTimeframeKlines(
        string $symbol,
        string $baseInterval,
        ?string $htf1Interval = null,
        ?string $htf2Interval = null,
        int $baseLimit = 320,
        int $htfLimit = 260
    ): array {
        $symbol = strtoupper($symbol);
        $baseInterval = strtolower($baseInterval);
        $htf1Interval = $htf1Interval !== null ? strtolower($htf1Interval) : null;
        $htf2Interval = $htf2Interval !== null ? strtolower($htf2Interval) : null;

        $results = [
            'base' => null,
            'htf1' => null,
            'htf2' => null,
        ];

        // 1. Check cache first
        $baseCacheKey = "binance:klines:{$this->market}:{$symbol}:{$baseInterval}:{$baseLimit}";
        $results['base'] = Cache::get($baseCacheKey);

        if ($htf1Interval !== null && $htf1Interval !== $baseInterval) {
            $htf1CacheKey = "binance:klines:{$this->market}:{$symbol}:{$htf1Interval}:{$htfLimit}";
            $results['htf1'] = Cache::get($htf1CacheKey);
        }

        if ($htf2Interval !== null && $htf2Interval !== $baseInterval && $htf2Interval !== $htf1Interval) {
            $htf2CacheKey = "binance:klines:{$this->market}:{$symbol}:{$htf2Interval}:{$htfLimit}";
            $results['htf2'] = Cache::get($htf2CacheKey);
        }

        // 2. Identify which ones need fetching
        $needFetch = [];
        if ($results['base'] === null) {
            $needFetch['base'] = ['interval' => $baseInterval, 'limit' => $baseLimit];
        }
        if ($htf1Interval !== null && $htf1Interval !== $baseInterval && $results['htf1'] === null) {
            $needFetch['htf1'] = ['interval' => $htf1Interval, 'limit' => $htfLimit];
        }
        if ($htf2Interval !== null && $htf2Interval !== $baseInterval && $htf2Interval !== $htf1Interval && $results['htf2'] === null) {
            $needFetch['htf2'] = ['interval' => $htf2Interval, 'limit' => $htfLimit];
        }

        // 3. Fetch missing in parallel
        if (! empty($needFetch)) {
            $url = "{$this->baseUrl}{$this->endpoint}";
            $poolResponses = Http::pool(function (Pool $pool) use ($needFetch, $symbol, $url): array {
                $requests = [];
                foreach ($needFetch as $key => $meta) {
                    $requests[] = $pool->as($key)->timeout(10)->acceptJson()->get($url, [
                        'symbol' => $symbol,
                        'interval' => $meta['interval'],
                        'limit' => $meta['limit'],
                    ]);
                }

                return $requests;
            });

            foreach ($needFetch as $key => $meta) {
                if (isset($poolResponses[$key]) && $poolResponses[$key]->successful()) {
                    $parsed = $this->parseRawKlines((array) $poolResponses[$key]->json());
                    $results[$key] = $parsed;

                    $ttl = match ($key) {
                        'base' => 15,
                        'htf1' => 60,
                        'htf2' => 120,
                        default => 30,
                    };
                    $cacheKey = "binance:klines:{$this->market}:{$symbol}:{$meta['interval']}:{$meta['limit']}";
                    Cache::put($cacheKey, $parsed, now()->addSeconds($ttl));
                }
            }
        }

        if ($results['base'] === null) {
            // Fallback to single klines if pool had an issue
            $results['base'] = $this->klines($symbol, $baseInterval, $baseLimit);
        }

        return $results;
    }

    /**
     * Fetch base klines for multiple symbols concurrently in a single HTTP pool.
     *
     * @param  array<int, string>  $symbols
     * @return array<string, array>
     */
    public function fetchBatchKlines(array $symbols, string $interval = '15m', int $limit = 320): array
    {
        $interval = strtolower($interval);
        $results = [];
        $needFetch = [];

        foreach ($symbols as $symbol) {
            $clean = strtoupper($symbol);
            $cacheKey = "binance:klines:{$this->market}:{$clean}:{$interval}:{$limit}";
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                $results[$clean] = $cached;
            } else {
                $needFetch[] = $clean;
            }
        }

        if (! empty($needFetch)) {
            $url = "{$this->baseUrl}{$this->endpoint}";
            $poolResponses = Http::pool(function (Pool $pool) use ($needFetch, $interval, $limit, $url): array {
                $requests = [];
                foreach ($needFetch as $symbol) {
                    $requests[] = $pool->as($symbol)->timeout(10)->acceptJson()->get($url, [
                        'symbol' => $symbol,
                        'interval' => $interval,
                        'limit' => $limit,
                    ]);
                }

                return $requests;
            });

            foreach ($needFetch as $symbol) {
                $resp = $poolResponses[$symbol] ?? null;
                if ($resp instanceof Response && $resp->successful()) {
                    $parsed = $this->parseRawKlines((array) $resp->json());
                    $results[$symbol] = $parsed;
                    $cacheKey = "binance:klines:{$this->market}:{$symbol}:{$interval}:{$limit}";
                    Cache::put($cacheKey, $parsed, now()->addSeconds(20));
                }
            }
        }

        return $results;
    }

    /**
     * Fetch active liquid USDT perpetual trading pairs from Binance Futures.
     *
     * @param  float  $minQuoteVolume24h  Minimum 24h quote volume in USDT (e.g., 5,000,000 = $5M)
     * @return array<int, string>
     */
    public function getActiveFuturesSymbols(float $minQuoteVolume24h = 5000000.0): array
    {
        $url = 'https://fapi.binance.com/fapi/v1/ticker/24hr';

        $response = Http::timeout(15)
            ->acceptJson()
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Failed to fetch 24hr tickers from Binance Futures: HTTP {$response->status()}");
        }

        $data = $response->json();
        if (! is_array($data)) {
            return [];
        }

        $symbols = [];
        foreach ($data as $ticker) {
            $sym = (string) ($ticker['symbol'] ?? '');
            $quoteVol = (float) ($ticker['quoteVolume'] ?? 0.0);

            // Filter for standard ASCII USDT perpetual symbols exceeding volume threshold
            if (preg_match('/^[A-Z0-9]+USDT$/', $sym) && $quoteVol >= $minQuoteVolume24h) {
                $symbols[] = [
                    'symbol' => $sym,
                    'volume' => $quoteVol,
                ];
            }
        }

        // Sort descending by 24h volume
        usort($symbols, fn (array $a, array $b): int => $b['volume'] <=> $a['volume']);

        return array_column($symbols, 'symbol');
    }
}
