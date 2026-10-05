<?php

namespace Tests\Feature;

use App\Models\CryptoSignal;
use App\Models\Setting;
use App\Services\Strategy\MarketScanService;
use App\Services\Strategy\SetupStats;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiTimeframeScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_intervals_put_the_base_timeframe_first_and_drop_unknown_ones(): void
    {
        config(['trading.strategy.scan_intervals' => ['15m', '1h', '7m']]);

        $this->assertSame(['1h', '15m'], MarketScanService::scanIntervals());
    }

    public function test_each_timeframe_is_due_after_its_own_candle_close(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 08:15:40', 'UTC'));
        $scanner = app(MarketScanService::class);

        // The 1h candle closed at 08:00 and was scanned; the 15m candle closed at 08:15 and was not.
        Setting::putValue(MarketScanService::RESULTS_KEY, ['bar' => intdiv(now()->timestamp, 3600)]);
        Setting::putValue(MarketScanService::resultsKey('15m'), ['bar' => intdiv(now()->timestamp, 900) - 1]);

        $this->assertFalse($scanner->isScanDue('1h'));
        $this->assertTrue($scanner->isScanDue('15m'));
        $this->assertSame('scanner_results_15m', MarketScanService::resultsKey('15m'));

        Carbon::setTestNow();
    }

    public function test_faster_timeframe_has_its_own_track_record(): void
    {
        foreach ([['1h', 1.0], ['15m', -1.0]] as [$interval, $r]) {
            foreach (range(1, 3) as $i) {
                CryptoSignal::create([
                    'symbol' => 'ETHUSDT', 'interval' => $interval, 'side' => 'BUY', 'setup' => 'SQUEEZE_BREAKOUT', 'setup_type' => 'SQUEEZE_BREAKOUT', 'passed_filters' => true,
                    'entry_price' => 100, 'stop_loss' => 99, 'take_profit_1' => 101.5, 'take_profit_2' => 103, 'take_profit_3' => 104.5,
                    'candle_close_time' => now()->subHours($i), 'source' => 'scanner', 'sent_at' => now()->subHours($i), 'outcome' => $r > 0 ? 'TP1' : 'SL', 'r_multiple' => $r,
                ]);
            }
        }
        $stats = app(SetupStats::class);

        $this->assertSame(1.0, $stats->forSetup('SQUEEZE_BREAKOUT')['expectancy']);
        $this->assertSame(-1.0, $stats->forSetup('SQUEEZE_BREAKOUT', null, 90, '15m')['expectancy']);
    }

    public function test_signals_on_an_alerts_only_timeframe_explain_why(): void
    {
        config(['trading.strategy.trade_intervals' => ['1h']]);

        $verdict = app(MarketScanService::class)->autoTradeVerdict([
            'symbol' => 'SOLUSDT', 'interval' => '15m', 'side' => 'LONG', 'setup' => 'SQUEEZE_BREAKOUT', 'time' => 1_800_000_000,
            'grade' => 'B', 'tradable' => true, 'is_shadow' => false, 'auto_trade' => null,
        ]);

        $this->assertStringContainsString('15m signals are alerts-only', $verdict);
    }
}
