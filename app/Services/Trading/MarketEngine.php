<?php

namespace App\Services\Trading;

use App\Services\Binance\BinanceFuturesClient;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MarketEngine
{
    public function __construct(
        protected BinanceFuturesClient $client
    ) {}

    /**
     * Get top liquid USDT perpetual symbols.
     *
     * @return array<int, string>
     */
    public function getScannableSymbols(): array
    {
        $limit = (int) config('trading.scanner.top_symbols_limit', 25);
        $minVol = (float) config('trading.scanner.min_quote_volume_24h', 10000000.0);
        $priority = (array) config('trading.scanner.priority_symbols', []);

        try {
            $tickers = $this->client->get24hrTickers();
            $candidates = [];

            foreach ($tickers as $ticker) {
                $sym = (string) ($ticker['symbol'] ?? '');
                $vol = (float) ($ticker['quoteVolume'] ?? 0.0);

                if (str_ends_with($sym, 'USDT') && $vol >= $minVol) {
                    $candidates[] = [
                        'symbol' => $sym,
                        'volume' => $vol,
                    ];
                }
            }

            usort($candidates, fn (array $a, array $b): int => $b['volume'] <=> $a['volume']);

            $symbols = array_column(array_slice($candidates, 0, $limit), 'symbol');

            // Merge priority symbols ensuring they are present first
            foreach (array_reverse($priority) as $sym) {
                if (! in_array($sym, $symbols, true)) {
                    array_unshift($symbols, $sym);
                }
            }

            return array_values(array_unique($symbols));
        } catch (\Exception) {
            // Fallback to priority symbols if API error
            return $priority;
        }
    }

    /**
     * Fetch multi-timeframe klines for a symbol concurrently.
     *
     * @return array{
     *     base: array{opens: float[], highs: float[], lows: float[], closes: float[], volumes: float[], closeTimes: int[]},
     *     htf1: ?array{opens: float[], highs: float[], lows: float[], closes: float[], volumes: float[], closeTimes: int[]},
     *     htf2: ?array{opens: float[], highs: float[], lows: float[], closes: float[], volumes: float[], closeTimes: int[]}
     * }
     */
    public function getMultiTimeframeKlines(
        string $symbol,
        string $baseInterval = '15m',
        string $htf1Interval = '1h',
        string $htf2Interval = '4h',
        int $limit = 200
    ): array {
        $baseUrl = $this->client->getBaseUrl();

        $cacheKeyBase = "binance:klines:{$symbol}:{$baseInterval}:{$limit}";
        $cacheKeyHtf1 = "binance:klines:{$symbol}:{$htf1Interval}:{$limit}";
        $cacheKeyHtf2 = "binance:klines:{$symbol}:{$htf2Interval}:{$limit}";

        $baseData = Cache::get($cacheKeyBase);
        $htf1Data = Cache::get($cacheKeyHtf1);
        $htf2Data = Cache::get($cacheKeyHtf2);

        $needFetch = [];
        if ($baseData === null) {
            $needFetch['base'] = $baseInterval;
        }
        if ($htf1Data === null) {
            $needFetch['htf1'] = $htf1Interval;
        }
        if ($htf2Data === null) {
            $needFetch['htf2'] = $htf2Interval;
        }

        if (! empty($needFetch)) {
            $responses = Http::pool(function (Pool $pool) use ($needFetch, $symbol, $limit, $baseUrl): array {
                $requests = [];
                foreach ($needFetch as $key => $interval) {
                    $requests[] = $pool->as($key)->timeout(8)->get("{$baseUrl}/fapi/v1/klines", [
                        'symbol' => strtoupper($symbol),
                        'interval' => $interval,
                        'limit' => $limit,
                    ]);
                }

                return $requests;
            });

            if (isset($responses['base']) && $responses['base']->successful()) {
                $baseData = $this->parseRawKlines((array) $responses['base']->json());
                Cache::put($cacheKeyBase, $baseData, now()->addSeconds(15));
            }
            if (isset($responses['htf1']) && $responses['htf1']->successful()) {
                $htf1Data = $this->parseRawKlines((array) $responses['htf1']->json());
                Cache::put($cacheKeyHtf1, $htf1Data, now()->addSeconds(60));
            }
            if (isset($responses['htf2']) && $responses['htf2']->successful()) {
                $htf2Data = $this->parseRawKlines((array) $responses['htf2']->json());
                Cache::put($cacheKeyHtf2, $htf2Data, now()->addSeconds(120));
            }
        }

        if ($baseData === null) {
            $baseData = $this->client->klines($symbol, $baseInterval, $limit);
        }

        return [
            'base' => $baseData,
            'htf1' => $htf1Data,
            'htf2' => $htf2Data,
        ];
    }

    /**
     * Parse raw Binance Klines.
     */
    protected function parseRawKlines(array $rawKlines): array
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
}
