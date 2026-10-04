<?php

namespace Tests\Unit;

use App\Models\CryptoSignal;
use App\Services\Strategy\OpportunityScorer;
use App\Services\Strategy\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpportunityScorerTest extends TestCase
{
    use RefreshDatabase;

    protected function signal(array $overrides = []): Signal
    {
        return new Signal(...array_merge([
            'symbol' => 'SOLUSDT', 'interval' => '1h', 'side' => 'LONG', 'setup' => 'SQUEEZE_BREAKOUT', 'setupLabel' => 'Squeeze Breakout',
            'time' => 1_800_000_000, 'entry' => 100.0, 'stopLoss' => 99.0, 'tp1' => 101.5, 'tp2' => 103.0, 'tp3' => 104.5, 'atr' => 0.8,
            'isShadow' => false, 'filters' => ['regime' => ['pass' => true, 'detail' => 'up'], 'volatility' => ['pass' => true, 'detail' => 'ok']],
            'confluences' => ['Volume 2.1x', 'Strong 1h trend (ADX 28)'], 'features' => ['setup' => 'SQUEEZE_BREAKOUT', 'side' => 1], 'indicators' => [],
            'grade' => 'B', 'aiProbability' => null,
        ], $overrides));
    }

    protected function seedEdge(string $setup, float $r, int $n): void
    {
        foreach (range(1, $n) as $i) {
            CryptoSignal::create([
                'symbol' => 'ETHUSDT', 'interval' => '1h', 'side' => 'BUY', 'setup_type' => $setup, 'setup' => $setup, 'passed_filters' => true,
                'entry_price' => 100, 'stop_loss' => 99, 'take_profit_1' => 101.5, 'take_profit_2' => 103, 'take_profit_3' => 104.5,
                'candle_close_time' => now()->subHours($i), 'source' => 'backtest', 'sent_at' => now()->subHours($i), 'outcome' => 'TP1', 'r_multiple' => $r,
            ]);
        }
    }

    public function test_fresh_tradable_signal_with_measured_edge_scores_high(): void
    {
        $this->seedEdge('SQUEEZE_BREAKOUT', 0.30, 100);
        $signal = $this->signal();

        $result = app(OpportunityScorer::class)->score($signal, 100.1, $signal->time + 600);

        $this->assertSame('Enter now', $result['entry_status']);
        $this->assertSame(35.0, $result['breakdown']['edge']);
        $this->assertSame(20.0, $result['breakdown']['filters']);
        $this->assertSame(10.0, $result['breakdown']['confluence']);
        $this->assertSame(15.0, $result['breakdown']['entry']);
        $this->assertSame(0.0, $result['breakdown']['ai'], 'The AI part stays 0 while the model is inactive');
        $this->assertGreaterThanOrEqual(85, $result['score']);
        $this->assertSame('Strong', $result['label']);
    }

    public function test_filtered_stale_and_missed_signals_score_lower(): void
    {
        $scorer = app(OpportunityScorer::class);
        $good = $scorer->score($this->signal(), 100.0, 1_800_000_000 + 300)['score'];

        $filtered = $scorer->score($this->signal(['filters' => ['regime' => ['pass' => false, 'detail' => 'unclear'], 'volatility' => ['pass' => true, 'detail' => 'ok']]]), 100.0, 1_800_000_000 + 300);
        $stale = $scorer->score($this->signal(), 100.0, 1_800_000_000 + 7 * 3600);
        $missed = $scorer->score($this->signal(), 100.8, 1_800_000_000 + 300);
        $stopped = $scorer->score($this->signal(), 98.9, 1_800_000_000 + 300);

        $this->assertSame(10.0, $filtered['breakdown']['filters']);
        $this->assertLessThan($good, $filtered['score']);
        $this->assertLessThan($good, $stale['score']);
        $this->assertSame('Entry missed', $missed['entry_status']);
        $this->assertLessThan($good, $missed['score']);
        $this->assertSame('Stopped out', $stopped['entry_status']);
        $this->assertSame(0.0, $stopped['breakdown']['entry']);
    }

    public function test_shadow_setups_get_no_filter_points(): void
    {
        $result = app(OpportunityScorer::class)->score($this->signal(['setup' => 'EMA_CROSS', 'isShadow' => true]), 100.0, 1_800_000_000);

        $this->assertSame(0.0, $result['breakdown']['filters']);
    }

    public function test_short_drift_is_measured_in_the_trades_favour(): void
    {
        $short = $this->signal(['side' => 'SHORT', 'stopLoss' => 101.0, 'tp1' => 98.5, 'tp2' => 97.0, 'tp3' => 95.5]);

        $this->assertEqualsWithDelta(0.5, app(OpportunityScorer::class)->driftR($short, 99.5), 1e-9);
        $this->assertSame('Target hit', app(OpportunityScorer::class)->entryStatus(app(OpportunityScorer::class)->driftR($short, 98.4)));
    }
}
