<?php

namespace Tests\Unit;

use App\Models\CryptoSignal;
use App\Services\Strategy\SetupStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function resolved(string $setup, float $r, string $source = 'scanner', int $index = 0): void
    {
        CryptoSignal::create([
            'symbol' => 'SOLUSDT', 'interval' => '1h', 'side' => 'BUY', 'setup_type' => $setup, 'setup' => $setup, 'passed_filters' => true,
            'entry_price' => 100, 'stop_loss' => 99, 'take_profit_1' => 101.5, 'take_profit_2' => 103, 'take_profit_3' => 104.5,
            'candle_close_time' => now()->subHours($index + 1), 'source' => $source, 'sent_at' => now()->subHours($index + 1),
            'outcome' => $r > 0 ? 'TP1' : 'SL', 'r_multiple' => $r,
        ]);
    }

    public function test_summary_metrics(): void
    {
        $summary = SetupStats::summarize([1.5, -1.0, 2.0, -1.0]);

        $this->assertSame(4, $summary['n']);
        $this->assertSame(50.0, $summary['win_rate']);
        $this->assertSame(0.375, $summary['expectancy']);
        $this->assertSame(1.75, $summary['profit_factor']);
    }

    public function test_backtest_seeds_are_blended_until_enough_live_samples_exist(): void
    {
        foreach (range(1, 5) as $i) {
            $this->resolved('TREND_PULLBACK', 1.0, 'scanner', $i);
        }
        foreach (range(1, 40) as $i) {
            $this->resolved('TREND_PULLBACK', -1.0, 'backtest', $i);
        }

        $stats = app(SetupStats::class)->forSetup('TREND_PULLBACK');

        $this->assertSame('live+backtest', $stats['source']);
        $this->assertSame(45, $stats['n']);
        $this->assertSame(5, $stats['n_live']);
    }

    public function test_a_core_setup_with_negative_expectancy_is_paused(): void
    {
        foreach (range(1, 60) as $i) {
            $this->resolved('SQUEEZE_BREAKOUT', $i % 3 === 0 ? 1.5 : -1.0, 'scanner', $i);
        }

        $this->assertFalse(app(SetupStats::class)->isActive('SQUEEZE_BREAKOUT'));
        $this->assertTrue(app(SetupStats::class)->isActive('TREND_PULLBACK'), 'Without data a core setup stays active');
        $this->assertFalse(app(SetupStats::class)->isActive('EMA_CROSS'), 'Without data a shadow setup stays inactive');
    }

    public function test_a_shadow_setup_is_promoted_once_it_proves_an_edge(): void
    {
        foreach (range(1, 60) as $i) {
            $this->resolved('EMA_CROSS', $i % 2 === 0 ? 2.0 : -1.0, 'scanner', $i);
        }

        $this->assertTrue(app(SetupStats::class)->isActive('EMA_CROSS'));
    }

    public function test_signals_that_failed_a_filter_are_not_counted(): void
    {
        $this->resolved('SQUEEZE_BREAKOUT', 1.5);
        CryptoSignal::create([
            'symbol' => 'ETHUSDT', 'interval' => '1h', 'side' => 'SELL', 'setup_type' => 'SQUEEZE_BREAKOUT', 'setup' => 'SQUEEZE_BREAKOUT', 'passed_filters' => false,
            'entry_price' => 100, 'stop_loss' => 101, 'take_profit_1' => 98.5, 'take_profit_2' => 97, 'take_profit_3' => 95.5,
            'candle_close_time' => now()->subHour(), 'source' => 'scanner', 'sent_at' => now()->subHour(), 'outcome' => 'SL', 'r_multiple' => -1.0,
        ]);

        $this->assertSame(1, app(SetupStats::class)->forSetup('SQUEEZE_BREAKOUT')['n']);
    }
}
