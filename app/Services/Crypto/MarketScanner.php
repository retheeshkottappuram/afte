<?php

namespace App\Services\Crypto;

use Illuminate\Support\Facades\Cache;
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
        protected TimingGuard $timingGuard,
        protected ?UniverseFilter $universeFilter = null
    ) {
        $this->universeFilter ??= new UniverseFilter;
    }

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
     * Evaluates strictly on CLOSED candles. Neutral/choppy BTC blocks all setups.
     *
     * @param  float|null  $minQuoteVolume24h  Minimum 24h USDT volume (defaults to universe config, $100M)
     * @param  int  $maxCandidateSymbols  Maximum dynamic pairs to evaluate per cycle
     * @param  string  $baseInterval  Base evaluation timeframe (default 15m)
     * @return array<int, array{
     *     symbol: string,
     *     type: string,
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
        ?float $minQuoteVolume24h = null,
        int $maxCandidateSymbols = 35,
        string $baseInterval = '15m'
    ): array {
        $detectionStart = microtime(true);
        $minQuoteVolume24h ??= (float) config('crypto.universe.min_24h_volume', 100000000.0);

        // 1. Evaluate explicit BTC Macro Alignment first
        $btcTrend = $this->binanceClient->getBtcMarketTrend();

        // If BTC is neutral or choppy, rule requires all signals to be blocked
        if (! $btcTrend['allow_long'] && ! $btcTrend['allow_short']) {
            Log::info("MarketScanner: BTC macro regime is {$btcTrend['trend']} ({$btcTrend['reason']}). Neutral/choppy BTC blocks all signals.");

            return [];
        }

        // 2. Discover and rank candidate pairs from entire Binance Futures universe passing institutional filter
        $targetSymbols = $this->getRankedCandidateSymbols($minQuoteVolume24h, $maxCandidateSymbols);

        if (empty($targetSymbols)) {
            $targetSymbols = self::CORE_PAIRS;
        }

        $signals = [];
        $htf1 = $this->resolveHtf1($baseInterval);
        $htf2 = $this->resolveHtf2($baseInterval);

        $btcBaseCandles = null;
        try {
            $rawBtcCandles = $this->binanceClient->klines('BTCUSDT', $baseInterval, 320);
            $btcBaseCandles = CandleSanitizer::onlyClosedCandles($rawBtcCandles, null, true);
        } catch (Throwable) {
        }

        // 3. Scan pairs in concurrent batches
        $chunks = array_chunk($targetSymbols, 8);

        foreach ($chunks as $chunk) {
            $batchBase = $this->binanceClient->fetchBatchKlines($chunk, $baseInterval, 320);

            foreach ($chunk as $symbol) {
                try {
                    $rawBase = $batchBase[$symbol] ?? null;
                    if (! $rawBase) {
                        continue;
                    }

                    // Enforce strictly CLOSED candles
                    $baseCandles = CandleSanitizer::onlyClosedCandles($rawBase, null, true);
                    $closes = $baseCandles['closes'] ?? [];
                    $highs = $baseCandles['highs'] ?? [];
                    $lows = $baseCandles['lows'] ?? [];
                    $count = count($closes);

                    if ($count < 35) {
                        continue;
                    }

                    $lastIdx = $count - 1;
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
                        $rawHtf1 = $this->binanceClient->klines($symbol, $htf1, 260);
                        $htf1Candles = CandleSanitizer::onlyClosedCandles($rawHtf1, null, true);
                    } catch (Throwable) {
                    }

                    $btcCandlesForSym = ($symbol === 'BTCUSDT') ? $baseCandles : $btcBaseCandles;

                    // A. Evaluate Breakout Detector (Watchlist & Confirmed Breakout)
                    $breakoutResult = $this->breakoutDetector->evaluate(
                        $baseCandles,
                        $htf1Candles,
                        $htf2Candles,
                        null,
                        $btcCandlesForSym
                    );

                    if ($breakoutResult !== null) {
                        // Strict BTC Macro Trend Gate
                        if ($breakoutResult['side'] === 'BUY' && ! $btcTrend['allow_long']) {
                            Log::info("MarketScanner: Filtered out {$symbol} BUY setup - Counter to BTC {$btcTrend['trend']} macro trend");

                            continue;
                        }
                        if ($breakoutResult['side'] === 'SELL' && ! $btcTrend['allow_short']) {
                            Log::info("MarketScanner: Filtered out {$symbol} SELL setup - Counter to BTC {$btcTrend['trend']} macro trend");

                            continue;
                        }

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
                    $trendEval = $this->signalEngine->evaluateDetailed($baseCandles, $htf1Candles, $htf2Candles, $btcCandlesForSym);
                    $trendSignal = $trendEval['signal'];

                    if ($trendSignal !== null && ($trendSignal['score'] ?? 0) >= 82) {
                        $side = $trendSignal['side'];

                        // Strict BTC Macro Trend Gate
                        if ($side === 'BUY' && ! $btcTrend['allow_long']) {
                            Log::info("MarketScanner: Filtered out {$symbol} BUY trend setup - Counter to BTC {$btcTrend['trend']} macro trend");

                            continue;
                        }
                        if ($side === 'SELL' && ! $btcTrend['allow_short']) {
                            Log::info("MarketScanner: Filtered out {$symbol} SELL trend setup - Counter to BTC {$btcTrend['trend']} macro trend");

                            continue;
                        }

                        // Minimum Volume Surge Requirement
                        $volRatio = (float) ($trendSignal['volume_ratio'] ?? 1.0);
                        if ($volRatio < 1.25) {
                            continue;
                        }
                        $entry = (float) $trendSignal['entry'];
                        $tp1 = (float) $trendSignal['tp1'];
                        $closeTimeMs = (int) ($baseCandles['closeTimes'][$lastIdx] ?? 0);

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
     * Fetch all Binance Futures 24hr tickers and rank candidates using institutional UniverseFilter.
     *
     * @return array<int, string>
     */
    public function getRankedCandidateSymbols(?float $minVolume = null, int $limit = 35): array
    {
        $minVol = $minVolume ?? (float) config('crypto.universe.min_24h_volume', 100000000.0);

        return Cache::remember("crypto:market:ranked_candidates:{$minVol}:{$limit}", 20, function () use ($limit): array {
            try {
                $tickers = $this->binanceClient->get24hrTickers();
                if (empty($tickers)) {
                    return self::CORE_PAIRS;
                }

                $bookTickers = $this->binanceClient->getBookTickers();
                $exchangeInfo = $this->binanceClient->getExchangeInfo();

                // Apply institutional universe gates: Volume >= $100M, Spread < 0.03%, Age > 30d, blacklist & ASCII
                $universeResult = $this->universeFilter->filterCandidates($tickers, $bookTickers, $exchangeInfo);
                $eligibleSymbols = $universeResult['eligible_symbols'];

                if (empty($eligibleSymbols)) {
                    Log::warning('MarketScanner: No symbols passed institutional universe filters; falling back to core pairs.');

                    return self::CORE_PAIRS;
                }

                $tickerMap = [];
                foreach ($tickers as $t) {
                    $sym = (string) ($t['symbol'] ?? '');
                    if ($sym !== '') {
                        $tickerMap[$sym] = $t;
                    }
                }

                $ranked = [];
                foreach ($eligibleSymbols as $sym) {
                    $ticker = $tickerMap[$sym] ?? null;
                    if (! $ticker) {
                        continue;
                    }

                    $vol = (float) ($ticker['quoteVolume'] ?? 0.0);
                    $priceChange = abs((float) ($ticker['priceChangePercent'] ?? 0.0));
                    $high = (float) ($ticker['highPrice'] ?? 0.0);
                    $low = (float) ($ticker['lowPrice'] ?? 0.0);
                    $last = (float) ($ticker['lastPrice'] ?? 0.0);

                    // Activity Score: Volume weighting + price momentum + 24h high/low breakout proximity
                    $range = max(0.0001, $high - $low);
                    $rangePos = ($last - $low) / $range;
                    $nearBreakout = ($rangePos >= 0.88 || $rangePos <= 0.12) ? 25.0 : 0.0;

                    $score = ($vol / 10000000.0) + ($priceChange * 2.0) + $nearBreakout;

                    $ranked[] = [
                        'symbol' => $sym,
                        'score' => $score,
                    ];
                }

                usort($ranked, fn ($a, $b) => $b['score'] <=> $a['score']);
                $topDynamic = array_slice(array_column($ranked, 'symbol'), 0, $limit);

                // Always include core institutional pairs that pass universe filters at the front
                $corePassed = array_values(array_intersect(self::CORE_PAIRS, $eligibleSymbols));

                return array_values(array_unique(array_merge($corePassed, $topDynamic)));
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
