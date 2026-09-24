<?php

namespace App\Services\Crypto;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class MarketScanner
{
    /**
     * Core institutional assets always prioritized for monitoring.
     *
     * @var array<int, string>
     */
    public const CORE_PAIRS = [
        'BTCUSDT',
        'ETHUSDT',
        'SOLUSDT',
        'BNBUSDT',
        'XRPUSDT',
        'DOGEUSDT',
        'NEARUSDT',
        'AVAXUSDT',
        'SUIUSDT',
        '1000PEPEUSDT',
    ];

    public function __construct(
        protected BinanceClient $binanceClient,
        protected SignalEngine $signalEngine,
        protected BreakoutDetector $breakoutDetector,
        protected TimingGuard $timingGuard
    ) {}

    /**
     * Get active monitored symbols (dynamically managed by user).
     *
     * @return array<int, string>
     */
    public static function getMonitoredSymbols(): array
    {
        $cached = Cache::get('crypto:monitored_symbols');
        if (is_array($cached) && ! empty($cached)) {
            return array_values(array_unique(array_filter(array_map('strtoupper', array_map('trim', $cached)))));
        }

        $configured = (array) config('crypto.symbols', []);
        $clean = array_values(array_filter($configured, fn ($s) => strtoupper($s) !== 'ALL'));

        if (! empty($clean)) {
            return array_values(array_unique(array_merge(self::CORE_PAIRS, $clean)));
        }

        return self::CORE_PAIRS;
    }

    /**
     * Add a symbol to the monitored list.
     *
     * @return array<int, string> Updated list of symbols
     */
    public static function addMonitoredSymbol(string $symbol): array
    {
        $symbol = strtoupper(trim(str_replace([' ', '/', '-'], '', $symbol)));
        if (! str_ends_with($symbol, 'USDT')) {
            $symbol .= 'USDT';
        }

        $symbols = self::getMonitoredSymbols();
        if (! in_array($symbol, $symbols, true)) {
            $symbols[] = $symbol;
            Cache::forever('crypto:monitored_symbols', $symbols);
        }

        return $symbols;
    }

    /**
     * Remove a symbol from the monitored list.
     *
     * @return array<int, string> Updated list of symbols
     */
    public static function removeMonitoredSymbol(string $symbol): array
    {
        $symbol = strtoupper(trim(str_replace([' ', '/', '-'], '', $symbol)));
        if (! str_ends_with($symbol, 'USDT')) {
            $symbol .= 'USDT';
        }

        $symbols = self::getMonitoredSymbols();
        $symbols = array_values(array_filter($symbols, fn ($s) => $s !== $symbol));
        Cache::forever('crypto:monitored_symbols', $symbols);

        return $symbols;
    }

    /**
     * Reset monitored symbols to default core pairs.
     *
     * @return array<int, string>
     */
    public static function resetMonitoredSymbols(): array
    {
        Cache::forever('crypto:monitored_symbols', self::CORE_PAIRS);

        return self::CORE_PAIRS;
    }

    /**
     * Get active scan mode: 'both' (monitored + breakouts), 'monitored_only', or 'whole_market'.
     */
    public static function getScanMode(): string
    {
        return (string) Cache::get('crypto:scan_mode', 'both');
    }

    /**
     * Set active scan mode.
     */
    public static function setScanMode(string $mode): string
    {
        $validModes = ['both', 'monitored_only', 'whole_market'];
        if (! in_array($mode, $validModes, true)) {
            $mode = 'both';
        }

        Cache::forever('crypto:scan_mode', $mode);

        return $mode;
    }

    /**
     * Scan the Binance Futures market dynamically for high-confluence breakouts and trend setups.
     *
     * @param  float  $minQuoteVolume24h  Minimum 24h USDT volume (default $5M)
     * @param  int  $maxCandidateSymbols  Maximum dynamic pairs to evaluate per cycle
     * @param  string  $baseInterval  Base evaluation timeframe (default 15m)
     * @return array<int, array{
     *     symbol: string,
     *     type: string, // 'BREAKOUT_WATCH', 'BREAKOUT_CONFIRMED', 'TREND', 'RETEST_ENTRY'
     *     side: string,
     *     score: int,
     *     grade: string,
     *     entry: float,
     *     sl: float,
     *     tp1: float,
     *     tp2: float,
     *     tp3: float,
     *     live_price: float,
     *     slippage_pct: float,
     *     is_actionable: bool,
     *     timing_status: string,
     *     timing_reason: ?string,
     *     ist_timestamp: string,
     *     latency_ms: int,
     *     breakout_level?: float,
     *     distance_pct?: float,
     *     setup_label?: string,
     *     candle_close_time: int,
     *     perpetual_options?: array<string, mixed>
     * }>
     */
    public function scanMarket(
        float $minQuoteVolume24h = 5000000.0,
        int $maxCandidateSymbols = 35,
        string $baseInterval = '15m'
    ): array {
        $detectionStart = microtime(true);

        // 1. Discover and rank candidate pairs from entire Binance Futures universe
        $targetSymbols = $this->getRankedCandidateSymbols($minQuoteVolume24h, $maxCandidateSymbols);

        if (empty($targetSymbols)) {
            $targetSymbols = self::CORE_PAIRS;
        }

        $signals = [];
        $htf1 = $this->resolveHtf1($baseInterval);
        $htf2 = $this->resolveHtf2($baseInterval);

        // 2. Scan pairs in concurrent batches
        $chunks = array_chunk($targetSymbols, 8);

        foreach ($chunks as $chunk) {
            $batchBase = $this->binanceClient->fetchBatchKlines($chunk, $baseInterval, 320);

            foreach ($chunk as $symbol) {
                try {
                    $baseCandles = $batchBase[$symbol] ?? null;

                    $closes = $baseCandles['closes'] ?? [];
                    $highs = $baseCandles['highs'] ?? [];
                    $lows = $baseCandles['lows'] ?? [];
                    $count = count($closes);

                    if ($count < 35) {
                        continue;
                    }

                    $lastIdx = $count - 2;
                    $curClose = (float) $closes[$lastIdx];
                    $recentHigh = max(array_slice($highs, max(0, $lastIdx - 25), 25));
                    $recentLow = min(array_slice($lows, max(0, $lastIdx - 25), 25));
                    $distHighPct = $recentHigh > 0 ? (($recentHigh - $curClose) / $recentHigh) * 100 : 999;
                    $distLowPct = $recentLow > 0 ? (($curClose - $recentLow) / $recentLow) * 100 : 999;

                    // High-speed candidate pre-filter: Only fetch HTF if near breakout (within 1.5%) or core pair
                    $isCandidate = ($distHighPct <= 1.5 || $distLowPct <= 1.5 || $curClose > $recentHigh || $curClose < $recentLow);
                    if (! $isCandidate && ! in_array($symbol, self::CORE_PAIRS, true)) {
                        continue;
                    }

                    // Fetch HTF1 for higher-timeframe trend confirmation
                    $htf1Candles = null;
                    $htf2Candles = null;
                    try {
                        $htf1Candles = $this->binanceClient->klines($symbol, $htf1, 260);
                    } catch (Throwable) {
                    }

                    // A. Evaluate Breakout Detector (Watchlist & Confirmed Breakout)
                    $breakoutResult = $this->breakoutDetector->evaluate(
                        $baseCandles,
                        $htf1Candles,
                        $htf2Candles
                    );

                    if ($breakoutResult !== null) {
                        $timing = $this->timingGuard->validateEntry(
                            symbol: $symbol,
                            side: $breakoutResult['side'],
                            entryPrice: $breakoutResult['entry'],
                            tp1Price: $breakoutResult['tp1'],
                            candleCloseTimeMs: $breakoutResult['candle_close_time'],
                            detectionTimeMs: $detectionStart
                        );

                        $signals[] = array_merge($breakoutResult, [
                            'symbol' => $symbol,
                            'live_price' => $timing['live_price'],
                            'slippage_pct' => $timing['slippage_pct'],
                            'is_actionable' => $timing['is_actionable'],
                            'timing_status' => $timing['status'],
                            'timing_reason' => $timing['reason'],
                            'ist_timestamp' => $timing['ist_timestamp'],
                            'latency_ms' => $timing['latency_ms'],
                        ]);

                        continue; // Breakout setup found, move to next symbol
                    }

                    // B. Evaluate Standard SignalEngine (Institutional Trend Continuation)
                    $trendEval = $this->signalEngine->evaluateDetailed($baseCandles, $htf1Candles, $htf2Candles);
                    $trendSignal = $trendEval['signal'];

                    if ($trendSignal !== null && ($trendSignal['score'] ?? 0) >= 80) {
                        $side = $trendSignal['side'];
                        $entry = (float) $trendSignal['entry'];
                        $tp1 = (float) $trendSignal['tp1'];
                        $closeTimeMs = (int) ($baseCandles['closeTimes'][count($baseCandles['closeTimes']) - 2] ?? 0);

                        $timing = $this->timingGuard->validateEntry(
                            symbol: $symbol,
                            side: $side,
                            entryPrice: $entry,
                            tp1Price: $tp1,
                            candleCloseTimeMs: $closeTimeMs,
                            detectionTimeMs: $detectionStart
                        );

                        $signals[] = [
                            'symbol' => $symbol,
                            'type' => 'TREND',
                            'side' => $side,
                            'score' => (int) $trendSignal['score'],
                            'grade' => (string) ($trendSignal['grade'] ?? 'B'),
                            'entry' => $entry,
                            'sl' => (float) $trendSignal['sl'],
                            'tp1' => $tp1,
                            'tp2' => (float) $trendSignal['tp2'],
                            'tp3' => (float) $trendSignal['tp3'],
                            'risk_reward' => '1 : 2.5',
                            'volume_ratio' => (float) ($trendSignal['volume_ratio'] ?? 1.2),
                            'rsi' => (float) ($trendSignal['rsi'] ?? 55.0),
                            'adx' => (float) ($trendSignal['adx'] ?? 24.0),
                            'atr_pct' => (float) ($trendSignal['atr_pct'] ?? 1.5),
                            'setup_label' => 'TREND CONTINUATION BREAKOUT',
                            'candle_close_time' => $closeTimeMs,
                            'perpetual_options' => $trendSignal['perpetual_options'] ?? [],
                            'live_price' => $timing['live_price'],
                            'slippage_pct' => $timing['slippage_pct'],
                            'is_actionable' => $timing['is_actionable'],
                            'timing_status' => $timing['status'],
                            'timing_reason' => $timing['reason'],
                            'ist_timestamp' => $timing['ist_timestamp'],
                            'latency_ms' => $timing['latency_ms'],
                        ];
                    }
                } catch (Throwable $e) {
                    Log::debug("MarketScanner: Error evaluating {$symbol}: {$e->getMessage()}");
                }
            }
        }

        return $signals;
    }

    /**
     * Fetch all Binance Futures 24hr tickers and rank candidates dynamically by volume and momentum.
     *
     * @return array<int, string>
     */
    public function getRankedCandidateSymbols(float $minVolume = 5000000.0, int $limit = 35): array
    {
        return Cache::remember('crypto:market:ranked_candidates', 20, function () use ($minVolume, $limit): array {
            $url = 'https://fapi.binance.com/fapi/v1/ticker/24hr';

            try {
                $response = Http::timeout(10)->acceptJson()->get($url);
                if (! $response->successful()) {
                    return self::CORE_PAIRS;
                }

                $tickers = $response->json();
                if (! is_array($tickers)) {
                    return self::CORE_PAIRS;
                }

                $ranked = [];
                foreach ($tickers as $ticker) {
                    $sym = (string) ($ticker['symbol'] ?? '');
                    $vol = (float) ($ticker['quoteVolume'] ?? 0.0);
                    $priceChange = abs((float) ($ticker['priceChangePercent'] ?? 0.0));
                    $high = (float) ($ticker['highPrice'] ?? 0.0);
                    $low = (float) ($ticker['lowPrice'] ?? 0.0);
                    $last = (float) ($ticker['lastPrice'] ?? 0.0);

                    // Filter only USDT perpetual pairs exceeding volume threshold
                    if (! preg_match('/^[A-Z0-9]+USDT$/', $sym) || $vol < $minVolume) {
                        continue;
                    }

                    // Activity Score: Volume weighting + price momentum + 24h high/low breakout proximity
                    $range = max(0.0001, $high - $low);
                    $rangePos = ($last - $low) / $range; // 0.0 (near low) to 1.0 (near high)
                    $nearBreakout = ($rangePos >= 0.88 || $rangePos <= 0.12) ? 25.0 : 0.0;

                    $score = ($vol / 10000000.0) + ($priceChange * 2.0) + $nearBreakout;

                    $ranked[] = [
                        'symbol' => $sym,
                        'score' => $score,
                    ];
                }

                usort($ranked, fn ($a, $b) => $b['score'] <=> $a['score']);
                $topDynamic = array_slice(array_column($ranked, 'symbol'), 0, $limit);

                // Always include core institutional pairs at the front
                return array_values(array_unique(array_merge(self::CORE_PAIRS, $topDynamic)));
            } catch (Throwable $e) {
                Log::warning("MarketScanner: Failed to rank candidates ({$e->getMessage()})");

                return self::CORE_PAIRS;
            }
        });
    }

    protected function resolveHtf1(string $interval): string
    {
        return match (strtolower($interval)) {
            '1m' => '5m',
            '3m', '5m' => '15m',
            '15m' => '1h',
            '30m' => '2h',
            '1h' => '4h',
            '4h' => '1d',
            default => '1h',
        };
    }

    protected function resolveHtf2(string $interval): string
    {
        return match (strtolower($interval)) {
            '1m' => '15m',
            '3m', '5m', '15m' => '4h',
            '30m', '1h' => '1d',
            default => '4h',
        };
    }
}
