<?php

namespace Tests\Unit;

use App\Services\Crypto\UniverseFilter;
use PHPUnit\Framework\TestCase;

class UniverseFilterTest extends TestCase
{
    public function test_volume_filter_rejects_under_100m(): void
    {
        $filter = new UniverseFilter([
            'min_24h_volume' => 100000000.0,
            'max_spread_pct' => 0.03,
            'min_listing_days' => 30,
            'exclude_non_ascii' => true,
            'blacklist' => [],
        ]);

        $tickers = [
            ['symbol' => 'SOLUSDT', 'quoteVolume' => 450000000.0], // $450M -> PASS
            ['symbol' => 'LOWVOLUSDT', 'quoteVolume' => 85000000.0], // $85M -> FAIL
        ];

        $result = $filter->filterCandidates($tickers);

        $this->assertContains('SOLUSDT', $result['eligible_symbols']);
        $this->assertNotContains('LOWVOLUSDT', $result['eligible_symbols']);
        $this->assertArrayHasKey('LOWVOLUSDT', $result['rejected']);
        $this->assertStringContainsString('below minimum $100.00M', $result['rejected']['LOWVOLUSDT']['reason']);
    }

    public function test_spread_filter_rejects_above_or_equal_to_point_zero_three_percent(): void
    {
        $filter = new UniverseFilter([
            'min_24h_volume' => 100000000.0,
            'max_spread_pct' => 0.03,
            'min_listing_days' => 30,
            'exclude_non_ascii' => true,
            'blacklist' => [],
        ]);

        $tickers = [
            ['symbol' => 'TIGHTUSDT', 'quoteVolume' => 200000000.0],
            ['symbol' => 'WIDEUSDT', 'quoteVolume' => 200000000.0],
        ];

        $bookTickers = [
            'TIGHTUSDT' => ['bidPrice' => 100.00, 'askPrice' => 100.01], // spread = 0.01% -> PASS
            'WIDEUSDT' => ['bidPrice' => 100.00, 'askPrice' => 100.05],  // spread = 0.05% -> FAIL
        ];

        $result = $filter->filterCandidates($tickers, $bookTickers);

        $this->assertContains('TIGHTUSDT', $result['eligible_symbols']);
        $this->assertNotContains('WIDEUSDT', $result['eligible_symbols']);
        $this->assertArrayHasKey('WIDEUSDT', $result['rejected']);
        $this->assertStringContainsString('exceeds maximum 0.0300%', $result['rejected']['WIDEUSDT']['reason']);
    }

    public function test_listing_age_filter_rejects_under_30_days(): void
    {
        $filter = new UniverseFilter([
            'min_24h_volume' => 100000000.0,
            'max_spread_pct' => 0.03,
            'min_listing_days' => 30,
            'exclude_non_ascii' => true,
            'blacklist' => [],
        ]);

        $nowMs = 1700000000000;
        $tickers = [
            ['symbol' => 'MATUREUSDT', 'quoteVolume' => 200000000.0],
            ['symbol' => 'NEWBORNUSDT', 'quoteVolume' => 200000000.0],
        ];

        $exchangeInfo = [
            'MATUREUSDT' => ['onboardDate' => $nowMs - (45 * 86400 * 1000), 'status' => 'TRADING'],  // 45 days -> PASS
            'NEWBORNUSDT' => ['onboardDate' => $nowMs - (10 * 86400 * 1000), 'status' => 'TRADING'], // 10 days -> FAIL
        ];

        $result = $filter->filterCandidates($tickers, [], $exchangeInfo, referenceTimeMs: $nowMs);

        $this->assertContains('MATUREUSDT', $result['eligible_symbols']);
        $this->assertNotContains('NEWBORNUSDT', $result['eligible_symbols']);
        $this->assertArrayHasKey('NEWBORNUSDT', $result['rejected']);
        $this->assertStringContainsString('Listing age 10.0 days is below minimum 30 days', $result['rejected']['NEWBORNUSDT']['reason']);
    }

    public function test_blacklist_and_non_ascii_rejection(): void
    {
        $filter = new UniverseFilter([
            'min_24h_volume' => 100000000.0,
            'max_spread_pct' => 0.03,
            'min_listing_days' => 30,
            'exclude_non_ascii' => true,
            'blacklist' => ['GRAMUSDT', 'AKEUSDT', 'USDCUSDT'],
        ]);

        $tickers = [
            ['symbol' => 'BTCUSDT', 'quoteVolume' => 1000000000.0],
            ['symbol' => 'GRAMUSDT', 'quoteVolume' => 500000000.0],     // Blacklisted
            ['symbol' => 'USDCUSDT', 'quoteVolume' => 300000000.0],     // Blacklisted Stablecoin
            ['symbol' => '牛来USDT', 'quoteVolume' => 500000000.0],      // Non-ASCII
            ['symbol' => 'bad_symbol', 'quoteVolume' => 500000000.0],   // Non-USDT format
        ];

        $result = $filter->filterCandidates($tickers);

        $this->assertSame(['BTCUSDT'], $result['eligible_symbols']);
        $this->assertArrayHasKey('GRAMUSDT', $result['rejected']);
        $this->assertArrayHasKey('USDCUSDT', $result['rejected']);
        $this->assertArrayHasKey('牛来USDT', $result['rejected']);
        $this->assertArrayHasKey('BAD_SYMBOL', $result['rejected']);
    }

    public function test_passed_candidates_are_ranked_by_volume(): void
    {
        $filter = new UniverseFilter([
            'min_24h_volume' => 50000000.0,
            'max_spread_pct' => 0.05,
            'min_listing_days' => 30,
            'exclude_non_ascii' => true,
            'blacklist' => [],
        ]);

        $tickers = [
            ['symbol' => 'DOGEUSDT', 'quoteVolume' => 150000000.0],
            ['symbol' => 'BTCUSDT', 'quoteVolume' => 900000000.0],
            ['symbol' => 'ETHUSDT', 'quoteVolume' => 600000000.0],
        ];

        $result = $filter->filterCandidates($tickers);

        $this->assertSame(['BTCUSDT', 'ETHUSDT', 'DOGEUSDT'], $result['eligible_symbols']);
    }
}
