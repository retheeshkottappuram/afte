<?php

namespace Tests\Unit;

use App\Models\CryptoSignal;
use App\Services\Strategy\MarketScanService;
use App\Services\Strategy\Signal;
use App\Services\Strategy\SignalScorer;
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

    public function test_early_breakouts_trade_even_with_a_negative_measured_edge(): void
    {
        foreach (range(1, 60) as $i) {
            CryptoSignal::create([
                'symbol' => 'ETHUSDT', 'interval' => '1h', 'side' => 'BUY', 'setup' => 'EARLY_BREAKOUT', 'setup_type' => 'EARLY_BREAKOUT', 'passed_filters' => true,
                'entry_price' => 100, 'stop_loss' => 99, 'take_profit_1' => 101.5, 'take_profit_2' => 103, 'take_profit_3' => 104.5,
                'candle_close_time' => now()->subHours($i), 'source' => 'backtest', 'sent_at' => now()->subHours($i), 'outcome' => 'SL', 'r_multiple' => -0.2,
            ]);
        }
        $signal = new Signal(
            symbol: 'SOLUSDT', interval: '1h', side: 'SHORT', setup: 'EARLY_BREAKOUT', setupLabel: 'Early Breakout', time: 1_800_000_000,
            entry: 100.0, stopLoss: 101.0, tp1: 98.5, tp2: 97.0, tp3: 95.5, atr: 0.8, isShadow: false,
            filters: ['regime' => ['pass' => true, 'detail' => 'not required']], confluences: ['Volume 2.1x'], features: [], indicators: [],
        );
        $scored = app(SignalScorer::class)->score($signal);

        $this->assertSame('C', $scored->grade, 'The grade stays honest about the negative edge');
        $this->assertTrue(app(SignalScorer::class)->autoTradeDecision($scored, 'live')['allowed']);

        $againstTrend = app(SignalScorer::class)->score(new Signal(
            symbol: 'SOLUSDT', interval: '1h', side: 'SHORT', setup: 'EARLY_BREAKOUT', setupLabel: 'Early Breakout', time: 1_800_000_000,
            entry: 100.0, stopLoss: 101.0, tp1: 98.5, tp2: 97.0, tp3: 95.5, atr: 0.8, isShadow: false,
            filters: ['regime' => ['pass' => true, 'detail' => 'not required']], confluences: ['Volume 2.1x'], features: ['proven_subset' => false], indicators: [],
        ));
        $this->assertTrue(app(SignalScorer::class)->autoTradeDecision($againstTrend, 'live')['allowed'], 'Every breakout trades by default');
        config(['trading.strategy.early_breakout.trade_all_breakouts' => false]);
        $this->assertStringContainsString('Alert only', app(SignalScorer::class)->autoTradeDecision($againstTrend, 'live')['reason']);
        config(['trading.strategy.early_breakout.trade_all_breakouts' => true]);

        config(['trading.strategy.early_breakout.ignore_min_edge' => false]);
        $this->assertFalse(app(SignalScorer::class)->autoTradeDecision($scored, 'live')['allowed']);
    }
}
