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
     * Fetch all Binance Futures 24hr tickers with caching.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get24hrTickers(): array
    {
        return Cache::remember("binance:tickers:24hr:{$this->market}", 20, function (): array {
            $url = $this->market === 'spot'
                ? "{$this->baseUrl}/api/v3/ticker/24hr"
                : "{$this->baseUrl}/fapi/v1/ticker/24hr";

            $response = Http::timeout(12)->acceptJson()->get($url);
            if (! $response->successful()) {
                Log::warning("BinanceClient: Failed to fetch 24hr tickers: HTTP {$response->status()}");

                return [];
            }

            $data = $response->json();

            return is_array($data) ? $data : [];
        });
    }

    /**
     * Fetch best bid and ask prices (bookTicker) for spread calculations.
     *
     * @return array<string, array{bidPrice: float, askPrice: float, bidQty: float, askQty: float}>
     */
    public function getBookTickers(): array
    {
        return Cache::remember("binance:tickers:book:{$this->market}", 15, function (): array {
            $url = $this->market === 'spot'
                ? "{$this->baseUrl}/api/v3/ticker/bookTicker"
                : "{$this->baseUrl}/fapi/v1/ticker/bookTicker";

            $response = Http::timeout(10)->acceptJson()->get($url);
            if (! $response->successful()) {
                Log::warning("BinanceClient: Failed to fetch book tickers: HTTP {$response->status()}");

                return [];
            }

            $data = $response->json();
            if (! is_array($data)) {
                return [];
            }

            $mapped = [];
            foreach ($data as $item) {
                $sym = strtoupper((string) ($item['symbol'] ?? ''));
                if ($sym !== '') {
                    $mapped[$sym] = [
                        'bidPrice' => (float) ($item['bidPrice'] ?? 0.0),
                        'askPrice' => (float) ($item['askPrice'] ?? 0.0),
                        'bidQty' => (float) ($item['bidQty'] ?? 0.0),
                        'askQty' => (float) ($item['askQty'] ?? 0.0),
                    ];
                }
            }

            return $mapped;
        });
    }

    /**
     * Fetch live ticker price for a symbol.
     */
    public function tickerPrice(string $symbol): float
    {
        $symbol = strtoupper($symbol);
        $url = $this->market === 'spot'
            ? "{$this->baseUrl}/api/v3/ticker/price"
            : "{$this->baseUrl}/fapi/v1/ticker/price";

        try {
            $response = Http::timeout(6)->acceptJson()->get($url, [
                'symbol' => $symbol,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['price']) && is_numeric($data['price'])) {
                    return (float) $data['price'];
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::debug("BinanceClient tickerPrice error for {$symbol}: {$e->getMessage()}");
        }

        return 0.0;
    }

    /**
     * Fetch exchange metadata (listing dates, symbols status, filters).
     *
     * @return array<string, array{status: string, onboardDate: int}>
     */
    public function getExchangeInfo(): array
    {
        return Cache::remember("binance:exchange_info:{$this->market}", 3600, function (): array {
            $url = $this->market === 'spot'
                ? "{$this->baseUrl}/api/v3/exchangeInfo"
                : "{$this->baseUrl}/fapi/v1/exchangeInfo";

            $response = Http::timeout(15)->acceptJson()->get($url);
            if (! $response->successful()) {
                Log::warning("BinanceClient: Failed to fetch exchangeInfo: HTTP {$response->status()}");

                return [];
            }

            $data = $response->json();
            $symbols = $data['symbols'] ?? [];
            if (! is_array($symbols)) {
                return [];
            }

            $mapped = [];
            foreach ($symbols as $s) {
                $sym = strtoupper((string) ($s['symbol'] ?? ''));
                if ($sym !== '') {
                    $mapped[$sym] = [
                        'status' => (string) ($s['status'] ?? 'TRADING'),
                        'onboardDate' => (int) ($s['onboardDate'] ?? 0),
                    ];
                }
            }

            return $mapped;
        });
    }

    /**
     * Fetch active liquid USDT perpetual trading pairs from Binance Futures.
    /**
     * Fetch all active Binance USDT Perpetual Futures symbols sorted by 24h quote volume.
     * Excludes non-USDT pairs, stablecoins, and blacklisted toxic assets.
     *
     * @param  float  $minQuoteVolume24h  Minimum 24h quote volume in USDT (default 1,000,000 = $1M)
     * @return array<int, string>
     */
    public function getActiveFuturesSymbols(float $minQuoteVolume24h = 1000000.0): array
    {
        $tickers = $this->get24hrTickers();
        if (empty($tickers)) {
            return [];
        }

        $bannedSymbols = ['GRAMUSDT', 'AKEUSDT', 'GUSDT', 'USUSDT', 'USDCUSDT', 'FDUSDUSDT', 'TUSDUSDT', 'EURUSDT', 'BUSDUSDT', 'DAIUSDT'];
        $symbols = [];
        foreach ($tickers as $ticker) {
            $sym = (string) ($ticker['symbol'] ?? '');
            $quoteVol = (float) ($ticker['quoteVolume'] ?? 0.0);

            if (preg_match('/^[A-Z0-9]+USDT$/', $sym) && ! in_array($sym, $bannedSymbols, true) && $quoteVol >= $minQuoteVolume24h) {
                $symbols[] = [
                    'symbol' => $sym,
                    'volume' => $quoteVol,
                ];
            }
        }

        usort($symbols, fn (array $a, array $b): int => $b['volume'] <=> $a['volume']);

        return array_column($symbols, 'symbol');
    }

    /**
     * Get BTC Macro Market Trend direction using explicit BtcMacroAlignment.
     * Evaluates closed 1h and 4h candles. Neutral/choppy BTC sets allow_long=false, allow_short=false.
     *
     * @return array{
     *     trend: string,
     *     allow_long: bool,
     *     allow_short: bool,
     *     btc_price: float,
     *     ema_fast_1h?: ?float,
     *     ema_slow_1h?: ?float,
     *     slope_pct?: float,
     *     confluence_4h?: ?string,
     *     reason?: string
     * }
     */
    public function getBtcMarketTrend(): array
    {
        return Cache::remember('binance:btc:macro_trend', 45, function (): array {
            try {
                $btc1h = $this->klines('BTCUSDT', '1h', 210);
                $btc4h = null;
                try {
                    $btc4h = $this->klines('BTCUSDT', '4h', 60);
                } catch (\Throwable) {
                }

                $alignment = new BtcMacroAlignment;

                return $alignment->evaluate($btc1h, $btc4h);
            } catch (\Throwable $e) {
                Log::warning("BinanceClient: Error evaluating BTC macro trend ({$e->getMessage()}) - defaulting to NEUTRAL (signals blocked)");

                return [
                    'trend' => 'NEUTRAL',
                    'allow_long' => false,
                    'allow_short' => false,
                    'btc_price' => 0.0,
                    'reason' => 'Error evaluating BTC macro trend: '.$e->getMessage().' - all signals blocked',
                ];
            }
        });
    }
}
