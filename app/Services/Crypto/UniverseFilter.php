<?php

namespace App\Services\Crypto;

class UniverseFilter
{
    protected float $min24hVolume;

    protected float $maxSpreadPct;

    protected int $minListingDays;

    protected bool $excludeNonAscii;

    /** @var array<int, string> */
    protected array $blacklist;

    /**
     * @param  array<string, mixed>|null  $config
     */
    public function __construct(?array $config = null)
    {
        $cfg = $config ?? (array) config('crypto.universe', []);

        $this->min24hVolume = (float) ($cfg['min_24h_volume'] ?? config('crypto.min_24h_volume', 2500000.0));
        $this->maxSpreadPct = (float) ($cfg['max_spread_pct'] ?? 0.03);
        $this->minListingDays = (int) ($cfg['min_listing_days'] ?? 30);
        $this->excludeNonAscii = (bool) ($cfg['exclude_non_ascii'] ?? true);
        $this->blacklist = array_map('strtoupper', (array) ($cfg['blacklist'] ?? [
            'GRAMUSDT', 'AKEUSDT', 'GUSDT', 'USUSDT', 'USDCUSDT', 'FDUSDUSDT', 'TUSDUSDT', 'EURUSDT', 'BUSDUSDT', 'DAIUSDT',
        ]));
    }

    /**
     * Filter Binance candidate pairs against institutional universe rules:
     * 1. 24h quote volume >= $100M (configurable)
     * 2. Bid/Ask spread < 0.03% (configurable)
     * 3. Listing age > 30 days (configurable)
     * 4. Exclude configurable blacklist and stablecoins
     * 5. Exclude non-ASCII / non-standard characters
     *
     * @param  array<int|string, array<string, mixed>>  $tickers24h
     * @param  array<string, array<string, mixed>>  $bookTickersBySymbol  [symbol => ['bidPrice' => ..., 'askPrice' => ...]]
     * @param  array<string, array<string, mixed>>  $exchangeInfoBySymbol  [symbol => ['onboardDate' => ..., 'status' => ...]]
     * @return array{
     *     eligible_symbols: array<int, string>,
     *     passed: array<string, array{symbol: string, volume: float, spread_pct: ?float, age_days: ?float}>,
     *     rejected: array<string, array{symbol: string, reason: string, metrics: array<string, mixed>}>,
     *     summary: array{total: int, passed: int, rejected: int}
     * }
     */
    public function filterCandidates(
        array $tickers24h,
        array $bookTickersBySymbol = [],
        array $exchangeInfoBySymbol = [],
        ?int $referenceTimeMs = null
    ): array {
        $nowMs = $referenceTimeMs ?? (int) (microtime(true) * 1000);
        $passed = [];
        $rejected = [];

        foreach ($tickers24h as $ticker) {
            $sym = strtoupper(trim((string) ($ticker['symbol'] ?? '')));
            if ($sym === '') {
                continue;
            }

            $quoteVol = (float) ($ticker['quoteVolume'] ?? 0.0);

            // 1. ASCII & USDT Perpetual Regex Check
            if ($this->excludeNonAscii) {
                if (! preg_match('/^[A-Z0-9]+USDT$/', $sym)) {
                    $rejected[$sym] = [
                        'symbol' => $sym,
                        'reason' => 'Non-ASCII or invalid symbol format (requires uppercase alphanumeric + USDT)',
                        'metrics' => ['symbol' => $sym],
                    ];

                    continue;
                }
            }

            // 2. Blacklist & Stablecoin Check
            if (in_array($sym, $this->blacklist, true)) {
                $rejected[$sym] = [
                    'symbol' => $sym,
                    'reason' => "Symbol {$sym} is blacklisted",
                    'metrics' => ['blacklist' => true],
                ];

                continue;
            }

            // 3. 24h Quote Volume Check (default >= $100M)
            if ($quoteVol < $this->min24hVolume) {
                $rejected[$sym] = [
                    'symbol' => $sym,
                    'reason' => sprintf('24h quote volume $%.2fM is below minimum $%.2fM threshold', $quoteVol / 1000000.0, $this->min24hVolume / 1000000.0),
                    'metrics' => ['volume' => $quoteVol, 'min_volume' => $this->min24hVolume],
                ];

                continue;
            }

            // 4. Spread Check (default < 0.03%)
            $spreadPct = null;
            if (isset($bookTickersBySymbol[$sym])) {
                $book = $bookTickersBySymbol[$sym];
                $bid = (float) ($book['bidPrice'] ?? 0.0);
                $ask = (float) ($book['askPrice'] ?? 0.0);

                if ($bid > 0 && $ask >= $bid) {
                    $spreadPct = round((($ask - $bid) / $bid) * 100, 4);
                    if ($spreadPct >= $this->maxSpreadPct) {
                        $rejected[$sym] = [
                            'symbol' => $sym,
                            'reason' => sprintf('Bid/ask spread %.4f%% exceeds maximum %.4f%% threshold', $spreadPct, $this->maxSpreadPct),
                            'metrics' => ['spread_pct' => $spreadPct, 'max_spread' => $this->maxSpreadPct, 'bid' => $bid, 'ask' => $ask],
                        ];

                        continue;
                    }
                }
            }

            // 5. Listing Age Check (default > 30 days)
            $ageDays = null;
            if (isset($exchangeInfoBySymbol[$sym])) {
                $info = $exchangeInfoBySymbol[$sym];
                $status = (string) ($info['status'] ?? 'TRADING');
                if ($status !== 'TRADING') {
                    $rejected[$sym] = [
                        'symbol' => $sym,
                        'reason' => "Symbol status is {$status} (expected TRADING)",
                        'metrics' => ['status' => $status],
                    ];

                    continue;
                }

                $onboardDate = (int) ($info['onboardDate'] ?? 0);
                if ($onboardDate > 0) {
                    $ageDays = round(($nowMs - $onboardDate) / (86400 * 1000), 1);
                    if ($ageDays < $this->minListingDays) {
                        $rejected[$sym] = [
                            'symbol' => $sym,
                            'reason' => sprintf('Listing age %.1f days is below minimum %d days threshold', $ageDays, $this->minListingDays),
                            'metrics' => ['age_days' => $ageDays, 'min_days' => $this->minListingDays],
                        ];

                        continue;
                    }
                }
            }

            // Symbol Passed All Institutional Gates
            $passed[$sym] = [
                'symbol' => $sym,
                'volume' => $quoteVol,
                'spread_pct' => $spreadPct,
                'age_days' => $ageDays,
            ];
        }

        // Sort passed candidates descending by 24h volume
        uasort($passed, fn ($a, $b) => $b['volume'] <=> $a['volume']);

        return [
            'eligible_symbols' => array_keys($passed),
            'passed' => $passed,
            'rejected' => $rejected,
            'summary' => [
                'total' => count($tickers24h),
                'passed' => count($passed),
                'rejected' => count($rejected),
            ],
        ];
    }

    /**
     * Validate an individual symbol on the fly.
     *
     * @return array{eligible: bool, reason: ?string}
     */
    public function validateSymbol(
        string $symbol,
        float $quoteVolume,
        ?float $spreadPct = null,
        ?float $ageDays = null
    ): array {
        $sym = strtoupper(trim($symbol));

        if ($this->excludeNonAscii && ! preg_match('/^[A-Z0-9]+USDT$/', $sym)) {
            return ['eligible' => false, 'reason' => 'Non-ASCII or invalid symbol format'];
        }

        if (in_array($sym, $this->blacklist, true)) {
            return ['eligible' => false, 'reason' => "Symbol {$sym} is blacklisted"];
        }

        if ($quoteVolume < $this->min24hVolume) {
            return ['eligible' => false, 'reason' => sprintf('Volume $%.2fM < $%.2fM threshold', $quoteVolume / 1e6, $this->min24hVolume / 1e6)];
        }

        if ($spreadPct !== null && $spreadPct >= $this->maxSpreadPct) {
            return ['eligible' => false, 'reason' => sprintf('Spread %.4f%% >= %.4f%% threshold', $spreadPct, $this->maxSpreadPct)];
        }

        if ($ageDays !== null && $ageDays < $this->minListingDays) {
            return ['eligible' => false, 'reason' => sprintf('Listing age %.1f days < %d days threshold', $ageDays, $this->minListingDays)];
        }

        return ['eligible' => true, 'reason' => null];
    }

    public function getMinVolume(): float
    {
        return $this->min24hVolume;
    }

    public function getMaxSpreadPct(): float
    {
        return $this->maxSpreadPct;
    }

    public function getMinListingDays(): int
    {
        return $this->minListingDays;
    }

    /**
     * @return array<int, string>
     */
    public function getBlacklist(): array
    {
        return $this->blacklist;
    }
}
