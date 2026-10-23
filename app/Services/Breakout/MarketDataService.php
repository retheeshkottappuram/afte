<?php

namespace App\Services\Breakout;

use App\Services\Crypto\BinanceClient;
use App\Services\Strategy\SymbolAnalyzer;
use Illuminate\Support\Facades\Cache;

/**
 * EBD market data on top of the existing BinanceClient (pooled requests, caching, rate-limit back-off).
 * Times are Binance exchange time in UTC epoch milliseconds; symbols are upper-case Binance futures symbols.
 */
class MarketDataService
{
    public function __construct(
        protected BinanceClient $market,
        protected SymbolAnalyzer $analyzer,
        protected EbdConfig $config,
    ) {}

    /**
     * Eligible symbols: liquid TRADING USDT perpetuals listed >= 30 days, by 24h quote volume.
     *
     * @return array<string, array{symbol: string, quote_volume: float, funding_rate: ?float, volume_rank: int}>
     */
    public function universe(): array
    {
        return $this->analyzer->universe(
            $this->config->int('universe.max_symbols'),
            $this->config->float('universe.min_quote_volume_24h'),
        );
    }

    /**
     * Closed candles per symbol for one timeframe. Higher timeframes are cached until their next close,
     * so each 1h/15m candle is fetched once per bar, not every 5 minutes.
     *
     * @param  array<int, string>  $symbols
     * @return array<string, Candles>
     */
    public function candles(array $symbols, string $interval, int $nowMs): array
    {
        $limit = (int) $this->config->get("history_bars.{$interval}", 150);
        $barMs = Candles::intervalMs($interval);
        $bar = intdiv($nowMs, $barMs);
        $out = [];
        $missing = [];

        foreach ($symbols as $symbol) {
            $cached = $interval === $this->config->get('timeframes.signal') ? null : Cache::get("ebd:candles:{$symbol}:{$interval}:{$bar}");
            if ($cached instanceof Candles) {
                $out[$symbol] = $cached;
            } else {
                $missing[] = $symbol;
            }
        }

        foreach (array_chunk($missing, max(1, $this->config->int('scan.batch_size'))) as $batch) {
            if (BinanceClient::backoffSeconds() > 0 || BinanceClient::usedWeight() > $this->config->int('scan.max_used_weight')) {
                break;
            }
            foreach ($this->market->fetchBatchKlines($batch, $interval, $limit) as $symbol => $klines) {
                $candles = Candles::fromKlines($klines, $interval)->closedAt($nowMs);
                $out[$symbol] = $candles;
                if ($interval !== $this->config->get('timeframes.signal')) {
                    Cache::put("ebd:candles:{$symbol}:{$interval}:{$bar}", $candles, intdiv($barMs, 1000) + 60);
                }
            }
        }

        return $out;
    }

    /**
     * 1m candles (closed and forming) for the armed coins only.
     *
     * @param  array<int, string>  $symbols
     * @return array<string, Candles>
     */
    public function minuteCandles(array $symbols): array
    {
        if ($symbols === [] || BinanceClient::backoffSeconds() > 0) {
            return [];
        }
        $out = [];
        foreach ($this->market->fetchBatchKlines($symbols, '1m', 130) as $symbol => $klines) {
            $out[$symbol] = Candles::fromKlines($klines, '1m');
        }

        return $out;
    }

    /**
     * Live price per symbol from the 24h ticker, with its age.
     *
     * @return array<string, array{price: float, time_ms: int}>
     */
    public function prices(): array
    {
        $out = [];
        foreach ($this->market->get24hrTickers() as $row) {
            $symbol = strtoupper((string) ($row['symbol'] ?? ''));
            $price = (float) ($row['lastPrice'] ?? 0);
            if ($symbol !== '' && $price > 0) {
                $out[$symbol] = ['price' => $price, 'time_ms' => (int) ($row['closeTime'] ?? 0)];
            }
        }

        return $out;
    }

    /**
     * Bid/ask spread in basis points of the mid price.
     *
     * @return array<string, float>
     */
    public function spreads(): array
    {
        $out = [];
        foreach ($this->market->getBookTickers() as $symbol => $book) {
            $bid = $book['bidPrice'];
            $ask = $book['askPrice'];
            if ($bid > 0 && $ask >= $bid) {
                $out[$symbol] = ($ask - $bid) / (($ask + $bid) / 2) * 10_000;
            }
        }

        return $out;
    }

    /** @return array<string, float> */
    public function fundingRates(): array
    {
        return $this->market->getFundingRates();
    }

    /**
     * Reasons a 5m series cannot be trusted right now (empty = usable): too short, the last closed
     * candle is not the one that should have just closed (stale feed), or gaps / duplicates / bad values.
     *
     * @return array<int, string>
     */
    public function problems(Candles $c, int $nowMs, int $minBars): array
    {
        $issues = $c->problems();
        if ($c->count() < $minBars) {
            $issues[] = "only {$c->count()} closed candles (need {$minBars})";
        }
        $expectedClose = intdiv($nowMs, $c->intervalMs) * $c->intervalMs - 1;
        if ($c->count() > 0 && $c->closeTimes[$c->lastIndex()] < $expectedClose) {
            $issues[] = 'stale candles: last close '.gmdate('H:i', intdiv($c->closeTimes[$c->lastIndex()] + 1, 1000)).' UTC';
        }

        return $issues;
    }
}
