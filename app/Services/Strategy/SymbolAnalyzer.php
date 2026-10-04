<?php

namespace App\Services\Strategy;

use App\Models\TradingAccount;
use App\Services\Crypto\BinanceClient;
use App\Services\Trading\RiskManager;
use App\Services\Trading\TradingModeManager;
use Throwable;

/**
 * Fetches market data and runs the StrategyEngine for one symbol (chart, auto-trader)
 * or many symbols (whole-market scanner), returning scored signals.
 */
class SymbolAnalyzer
{
    public const BASE_LIMIT = 499;

    public const REGIME_LIMIT = 300;

    public function __construct(
        protected BinanceClient $market,
        protected StrategyEngine $engine,
        protected SignalScorer $scorer,
        protected RiskManager $riskManager,
        protected TradingModeManager $modeManager
    ) {}

    public static function regimeInterval(string $baseInterval): string
    {
        return match ($baseInterval) {
            '5m', '15m' => '1h',
            '4h' => '1d',
            default => '4h',
        };
    }

    /**
     * Liquid, established USDT perpetuals sorted by 24h quote volume.
     *
     * @return array<string, array{symbol: string, quote_volume: float, listing_days: ?int, funding_rate: ?float}>
     */
    public function universe(?int $limit = null, ?float $minVolume = null): array
    {
        $minVolume ??= (float) config('trading.strategy.min_quote_volume_24h', 50000000.0);
        $minListingDays = (int) config('trading.strategy.min_listing_days', 30);
        $banned = (array) config('trading.strategy.banned_symbols', []);
        $limit ??= (int) config('trading.strategy.max_universe', 120);

        $info = $this->market->getExchangeInfo();
        $funding = $this->market->getFundingRates();
        $rows = [];

        foreach ($this->market->get24hrTickers() as $ticker) {
            $symbol = strtoupper((string) ($ticker['symbol'] ?? ''));
            $volume = (float) ($ticker['quoteVolume'] ?? 0);

            if (! preg_match('/^[A-Z0-9]+USDT$/', $symbol) || in_array($symbol, $banned, true) || $volume < $minVolume) {
                continue;
            }

            // Crypto perpetuals only: TradFi contracts (stocks, gold, FX) trade on market hours and behave differently.
            $meta = $info[$symbol] ?? null;
            if ($meta !== null && (($meta['status'] ?? 'TRADING') !== 'TRADING' || ($meta['contractType'] ?? 'PERPETUAL') !== 'PERPETUAL' || ($meta['underlyingType'] ?? 'COIN') !== 'COIN')) {
                continue;
            }

            $listingDays = $this->listingDays($meta);
            if ($listingDays !== null && $listingDays < $minListingDays) {
                continue;
            }

            $rows[$symbol] = ['symbol' => $symbol, 'quote_volume' => $volume, 'listing_days' => $listingDays, 'funding_rate' => $funding[$symbol] ?? null];
        }

        uasort($rows, fn (array $a, array $b): int => $b['quote_volume'] <=> $a['quote_volume']);

        return array_slice($rows, 0, $limit, true);
    }

    /**
     * Full analysis of one symbol (used by the chart and the auto-trader).
     *
     * @return array{symbol: string, interval: string, signals: array<int, Signal>, latest: ?Signal, state: array<string, mixed>, series: array<string, mixed>, candles: array<string, array<int, float|int>>, regime_candles: ?array<string, array<int, float|int>>}
     */
    public function analyze(string $symbol, string $interval = '1h', int $lookback = 140): array
    {
        $symbol = strtoupper($symbol);
        $regimeInterval = self::regimeInterval($interval);
        $multi = $this->market->fetchMultiTimeframeKlines($symbol, $interval, $regimeInterval, null, self::BASE_LIMIT, self::REGIME_LIMIT);
        [$btcBase, $btcRegime] = $this->btcCandles($interval, $regimeInterval, $symbol);

        return $this->run($symbol, $interval, $multi['base'], $multi['htf1'], $btcBase, $btcRegime, $this->contextFor($symbol), $lookback);
    }

    /**
     * Analyse many symbols with batched requests (whole-market scanner).
     *
     * @param  array<int, string>  $symbols
     * @param  array<string, array<string, mixed>>  $universe  Optional universe rows keyed by symbol
     * @return array<string, array<string, mixed>>
     */
    public function analyzeMany(array $symbols, string $interval = '1h', array $universe = [], int $lookback = 3, ?callable $progress = null): array
    {
        $regimeInterval = self::regimeInterval($interval);
        [$btcBase, $btcRegime] = $this->btcCandles($interval, $regimeInterval, 'ANY');
        $results = [];
        $done = 0;

        foreach (array_chunk($symbols, 20) as $chunk) {
            if ($progress !== null) {
                $progress($done, count($symbols), $chunk[0]);
            }
            $done += count($chunk);

            $bases = $this->market->fetchBatchKlines($chunk, $interval, self::BASE_LIMIT);
            $regimes = $this->market->fetchBatchKlines($chunk, $regimeInterval, self::REGIME_LIMIT);

            foreach ($chunk as $symbol) {
                if (! isset($bases[$symbol])) {
                    continue;
                }

                try {
                    $context = isset($universe[$symbol])
                        ? ['quote_volume_24h' => $universe[$symbol]['quote_volume'], 'listing_days' => $universe[$symbol]['listing_days'], 'funding_rate' => $universe[$symbol]['funding_rate']]
                        : $this->contextFor($symbol);
                    $results[$symbol] = $this->run($symbol, $interval, $bases[$symbol], $regimes[$symbol] ?? null, $btcBase, $btcRegime, $context, $lookback);
                } catch (Throwable $e) {
                    $results[$symbol] = ['symbol' => $symbol, 'error' => $e->getMessage(), 'signals' => [], 'latest' => null, 'state' => []];
                }
            }
        }

        return $results;
    }

    /**
     * @param  array<string, array<int, float|int>>  $base
     * @param  array<string, array<int, float|int>>|null  $regime
     * @param  array<string, array<int, float|int>>|null  $btcBase
     * @param  array<string, array<int, float|int>>|null  $btcRegime
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function run(string $symbol, string $interval, array $base, ?array $regime, ?array $btcBase, ?array $btcRegime, array $context, int $lookback): array
    {
        $context = array_merge($context, ['symbol' => $symbol, 'interval' => $interval, 'lookback' => $lookback]);
        $analysis = $this->engine->analyze($base, $regime, $btcBase, $btcRegime, $context);

        // Daily trend is a confluence for the latest signal only (one extra request when a signal prints).
        if ($analysis['latest'] !== null) {
            try {
                $context['daily'] = $this->market->klines($symbol, '1d', 120);
                $analysis = $this->engine->analyze($base, $regime, $btcBase, $btcRegime, $context);
            } catch (Throwable) {
                // Confluence falls back to 4h trend strength
            }
        }

        $signals = array_map(fn (Signal $s): Signal => $this->scorer->score($s), $analysis['signals']);
        $latest = null;
        if ($analysis['latest'] !== null && $signals !== []) {
            $latest = end($signals);
            $latest = $this->withSizingFilter($latest);
            $signals[array_key_last($signals)] = $latest;
        }

        return [
            'symbol' => $symbol,
            'interval' => $interval,
            'signals' => $signals,
            'latest' => $latest,
            'state' => $analysis['state'],
            'series' => $analysis['series'],
            'watch' => $analysis['watch'] ?? null,
            'candles' => $base,
            'regime_candles' => $regime,
        ];
    }

    /**
     * Mark whether the active account can size this trade within its risk limits.
     */
    protected function withSizingFilter(Signal $signal): Signal
    {
        if (! $signal->isTradable()) {
            return $signal;
        }

        try {
            $account = TradingAccount::getForMode($this->modeManager->activeMode());
            $sizing = $this->riskManager->calculatePositionSize($account, $signal->symbol, $signal->entry, $signal->stopLoss);

            return $signal->withFilter('position_size', $sizing['allowed'], $sizing['allowed']
                ? sprintf('Risk $%s (%s%%) at %dx', $sizing['risk_usd'], $sizing['risk_pct'], $sizing['leverage'])
                : $sizing['reason']);
        } catch (Throwable $e) {
            return $signal->withFilter('position_size', false, 'Sizing unavailable: '.$e->getMessage());
        }
    }

    /**
     * @return array{quote_volume_24h: ?float, listing_days: ?int, funding_rate: ?float}
     */
    protected function contextFor(string $symbol): array
    {
        $volume = null;
        foreach ($this->market->get24hrTickers() as $ticker) {
            if (strtoupper((string) ($ticker['symbol'] ?? '')) === $symbol) {
                $volume = (float) ($ticker['quoteVolume'] ?? 0);
                break;
            }
        }

        return [
            'quote_volume_24h' => $volume,
            'listing_days' => $this->listingDays($this->market->getExchangeInfo()[$symbol] ?? null),
            'funding_rate' => $this->market->getFundingRates()[$symbol] ?? null,
        ];
    }

    /**
     * @return array{0: ?array<string, array<int, float|int>>, 1: ?array<string, array<int, float|int>>}
     */
    protected function btcCandles(string $interval, string $regimeInterval, string $symbol): array
    {
        if ($symbol === 'BTCUSDT') {
            return [null, null];
        }

        try {
            return [
                $this->market->klines('BTCUSDT', $interval, self::BASE_LIMIT),
                $this->market->klines('BTCUSDT', $regimeInterval, self::REGIME_LIMIT),
            ];
        } catch (Throwable) {
            return [null, null];
        }
    }

    /**
     * @param  array{status?: string, onboardDate?: int}|null  $meta
     */
    protected function listingDays(?array $meta): ?int
    {
        $onboard = (int) ($meta['onboardDate'] ?? 0);

        return $onboard > 0 ? (int) floor((time() - intdiv($onboard, 1000)) / 86400) : null;
    }
}
