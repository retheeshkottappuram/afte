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
        $stage1 = (array) config('trading.stages.stage_1', []);
        $excluded = (array) ($stage1['exclude_symbols'] ?? ['BTCUSDT', 'ETHUSDT']);
        $maxPrice = (float) ($stage1['max_coin_price'] ?? 50.0);

        try {
            $tickers = $this->client->get24hrTickers();
            $exchangeInfo = $this->client->getExchangeInfo();
            $candidates = [];

            foreach ($tickers as $ticker) {
                $sym = (string) ($ticker['symbol'] ?? '');
                $vol = (float) ($ticker['quoteVolume'] ?? 0.0);
                $high = (float) ($ticker['highPrice'] ?? 0.0);
                $low = (float) ($ticker['lowPrice'] ?? 0.0);
                $last = (float) ($ticker['lastPrice'] ?? 0.0);
                $changePct = abs((float) ($ticker['priceChangePercent'] ?? 0.0));

                if (! str_ends_with($sym, 'USDT') || $vol < $minVol || $last <= 0 || $high <= 0 || $low <= 0) {
                    continue;
                }

                // Exclude heavyweight coins (BTC/ETH) to allow fine-grained lot sizing on micro capital (< $25)
                if (in_array(strtoupper($sym), $excluded, true)) {
                    continue;
                }

                // If coin price is too high (e.g. > $50) and not an explicitly whitelisted priority coin (like SOL), skip
                if ($last > $maxPrice && ! in_array($sym, $priority, true)) {
                    continue;
                }

                // In Stage 1, skip coins whose minimum lot step notional exceeds $7.50 (cannot be sized to ~$5.50)
                $symInfo = $exchangeInfo[$sym] ?? null;
                $step = (float) ($symInfo['stepSize'] ?? 0.001);
                if ($step > 0 && ($step * $last) > 7.50) {
                    continue;
                }

                // 1. Proximity to 24h High (Bullish breakout) or Low (Bearish breakdown)
                $distHighPct = (($high - $last) / $high) * 100.0;
                $distLowPct = (($last - $low) / $low) * 100.0;
                $minDistPct = min(abs($distHighPct), abs($distLowPct));

                // Highest score if within 0.2% - 2.5% of the breakout boundary
                $proximityScore = max(0.0, 45.0 - ($minDistPct * 12.0));

                // 2. Active Volatility & Momentum Score (rewards coins actively in motion, 3% to 20%)
                $momentumScore = min(35.0, $changePct * 2.8);

                // 3. Liquidity Weighting
                $liquidityScore = min(15.0, log10(max(1.0, $vol / 1000000.0)) * 5.0);

                // Priority High-Beta Coin Bonus
                $priorityBonus = in_array($sym, $priority, true) ? 15.0 : 0.0;

                $breakoutReadiness = $proximityScore + $momentumScore + $liquidityScore + $priorityBonus;

                $candidates[] = [
                    'symbol' => $sym,
                    'score' => $breakoutReadiness,
                    'volume' => $vol,
                ];
            }

            // Sort candidates by Breakout Readiness Score descending
            usort($candidates, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

            $symbols = array_column(array_slice($candidates, 0, $limit), 'symbol');

            // Ensure top priority symbols are included if not present
            foreach ($priority as $sym) {
                if (! in_array($sym, $excluded, true) && ! in_array($sym, $symbols, true) && count($symbols) < $limit + 5) {
                    $symbols[] = $sym;
                }
            }

            return array_values(array_unique($symbols));
        } catch (\Exception) {
            // Fallback to non-excluded priority symbols
            return array_values(array_diff($priority, $excluded));
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

    /**
     * Get BTC Macro Market Trend direction to prevent counter-trend altcoin executions.
     *
     * @return array{trend: string, allow_long: bool, allow_short: bool, btc_price: float}
     */
    public function getBtcMarketTrend(): array
    {
        return Cache::remember('binance:btc:macro_trend', 45, function (): array {
            try {
                $klines = $this->client->klines('BTCUSDT', '1h', 60);
                $closes = $klines['closes'] ?? [];
                $count = count($closes);
                if ($count < 30) {
                    return ['trend' => 'NEUTRAL', 'allow_long' => true, 'allow_short' => true, 'btc_price' => 0.0];
                }

                $i = $count - 2;
                $lastClose = (float) ($closes[$i] ?? 0.0);
                $ema21 = Indicators::ema($closes, 21);
                $ema50 = Indicators::ema($closes, 50);

                $vEma21 = $ema21[$i] ?? $lastClose;
                $vEma50 = $ema50[$i] ?? $lastClose;

                // Strong Bullish: BTC above 21 and 50 EMA, 21 EMA >= 50 EMA
                if ($lastClose > $vEma21 && $vEma21 >= $vEma50) {
                    return ['trend' => 'BULLISH', 'allow_long' => true, 'allow_short' => false, 'btc_price' => $lastClose];
                }

                // Strong Bearish: BTC below 21 and 50 EMA, 21 EMA <= 50 EMA
                if ($lastClose < $vEma21 && $vEma21 <= $vEma50) {
                    return ['trend' => 'BEARISH', 'allow_long' => false, 'allow_short' => true, 'btc_price' => $lastClose];
                }

                return ['trend' => 'RANGING', 'allow_long' => true, 'allow_short' => true, 'btc_price' => $lastClose];
            } catch (\Throwable) {
                return ['trend' => 'NEUTRAL', 'allow_long' => true, 'allow_short' => true, 'btc_price' => 0.0];
            }
        });
    }

    /**
     * Get BTC base timeframe (15m) klines for Relative Strength calculations.
     *
     * @return array{opens: float[], highs: float[], lows: float[], closes: float[], volumes: float[], closeTimes: int[]}
     */
    public function getBtcBaseKlines(int $limit = 120): array
    {
        return Cache::remember('binance:btc:base_klines', 20, function () use ($limit): array {
            try {
                return $this->client->klines('BTCUSDT', config('trading.scanner.base_interval', '15m'), $limit);
            } catch (\Throwable) {
                return ['opens' => [], 'highs' => [], 'lows' => [], 'closes' => [], 'volumes' => [], 'closeTimes' => []];
            }
        });
    }
}
