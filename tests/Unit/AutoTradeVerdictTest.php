<?php

namespace Tests\Unit;

use App\Models\CryptoSignal;
use App\Services\Strategy\MarketScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoTradeVerdictTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    protected function signal(array $overrides = []): array
    {
        return array_merge([
            'symbol' => 'ZKUSDT', 'interval' => '1h', 'side' => 'LONG', 'setup' => 'SQUEEZE_BREAKOUT', 'time' => 1_800_000_000,
            'grade' => 'B', 'tradable' => true, 'is_shadow' => false, 'auto_trade' => null,
        ], $overrides);
    }

    public function test_recorded_engine_decision_is_shown_without_the_mode_tag(): void
    {
        $verdict = app(MarketScanService::class)->autoTradeVerdict($this->signal(['auto_trade' => '[LIVE] skipped: Max open positions reached (1).']));

        $this->assertSame('skipped: Max open positions reached (1).', $verdict);
    }

    public function test_decision_is_looked_up_in_the_ledger_for_manual_scan_rows(): void
    {
        CryptoSignal::create([
            'symbol' => 'ZKUSDT', 'interval' => '1h', 'side' => 'BUY', 'setup' => 'SQUEEZE_BREAKOUT', 'setup_type' => 'SQUEEZE_BREAKOUT', 'passed_filters' => true,
            'entry_price' => 1, 'stop_loss' => 0.98, 'take_profit_1' => 1.03, 'take_profit_2' => 1.06, 'take_profit_3' => 1.09,
            'candle_close_time' => now()->setTimestamp(1_800_000_000), 'source' => 'scanner', 'sent_at' => now(), 'outcome' => 'OPEN',
            'auto_trade_status' => '[PAPER] taken',
        ]);

        $this->assertSame('taken', app(MarketScanService::class)->autoTradeVerdict($this->signal()));
    }

    public function test_paused_setup_and_low_grade_are_explained(): void
    {
        foreach (range(1, 60) as $i) {
            CryptoSignal::create([
                'symbol' => 'ETHUSDT', 'interval' => '1h', 'side' => 'BUY', 'setup' => 'TREND_PULLBACK', 'setup_type' => 'TREND_PULLBACK', 'passed_filters' => true,
                'entry_price' => 100, 'stop_loss' => 99, 'take_profit_1' => 101.5, 'take_profit_2' => 103, 'take_profit_3' => 104.5,
                'candle_close_time' => now()->subHours($i), 'source' => 'backtest', 'sent_at' => now()->subHours($i), 'outcome' => 'SL', 'r_multiple' => -0.05,
            ]);
        }
        $scanner = app(MarketScanService::class);

        $this->assertStringContainsString('Trend Pullback is paused', $scanner->autoTradeVerdict($this->signal(['setup' => 'TREND_PULLBACK', 'time' => 1_800_003_600])));
        $this->assertStringContainsString('grade C (the bot takes A and B)', $scanner->autoTradeVerdict($this->signal(['grade' => 'C', 'time' => 1_800_007_200])));
    }
}
