<?php

namespace Tests\Unit\Breakout;

use App\Services\Breakout\BreakoutQualityScorer;
use App\Services\Breakout\EbdConfig;
use App\Services\Breakout\SignalRiskCalculator;
use Tests\TestCase;

class ScoreAndRiskTest extends TestCase
{
    protected function scorer(): BreakoutQualityScorer
    {
        return new BreakoutQualityScorer(EbdConfig::fromConfig());
    }

    protected function risk(array $overrides = []): SignalRiskCalculator
    {
        return new SignalRiskCalculator(EbdConfig::fromConfig($overrides));
    }

    public function test_each_component_hits_its_documented_anchors(): void
    {
        $s = $this->scorer();

        $this->assertEqualsWithDelta(15, $s->compression(0, 1.5), 1e-9);
        $this->assertEqualsWithDelta(0, $s->compression(30, 4.0), 1e-9);
        $this->assertEqualsWithDelta(7.5, $s->compression(15, 2.75), 1e-9);

        $this->assertEqualsWithDelta(10, $s->breakout(0.05), 1e-9, 'At the early buffer: half');
        $this->assertEqualsWithDelta(20, $s->breakout(0.5), 1e-9);
        $this->assertEqualsWithDelta(0, $s->breakout(1.5), 1e-9, 'At the maximum extension: nothing');
        $this->assertEqualsWithDelta(0, $s->breakout(-0.2), 1e-9);

        $this->assertEqualsWithDelta(0, $s->volume(1.0, false), 1e-9);
        $this->assertEqualsWithDelta(25, $s->volume(3.0, true), 1e-9);
        $this->assertEqualsWithDelta(10, $s->volume(2.0, null), 1e-9);

        $this->assertEqualsWithDelta(15, $s->candle(1.0, 1.0), 1e-9);
        $this->assertEqualsWithDelta(0, $s->candle(0, 0.5), 1e-9);

        $this->assertEqualsWithDelta(10, $s->momentum('LONG', 70, 60, 2, 1), 1e-9);
        $this->assertEqualsWithDelta(10, $s->momentum('SHORT', 30, 40, 1, 2), 1e-9, 'Mirrored for shorts');
        $this->assertEqualsWithDelta(0, $s->momentum('LONG', 45, 50, 1, 2), 1e-9);

        $this->assertEqualsWithDelta(10, $s->liquidity(1.0, 500_000_000), 1e-9);
        $this->assertEqualsWithDelta(3.5, $s->liquidity(null, null), 1e-9, 'Unknown spread scores half of its part');
        $this->assertEqualsWithDelta(5, $s->higherTimeframe(true, true), 1e-9);
        $this->assertEqualsWithDelta(0, $s->higherTimeframe(null, false), 1e-9);
    }

    public function test_total_score_buckets_and_missing_inputs(): void
    {
        $best = $this->scorer()->score(['direction' => 'LONG', 'bbw_pct' => 0, 'range_ratio' => 1.5, 'extension_atr' => 0.4, 'volume' => 3.5,
            'volume_accelerating' => true, 'body_atr' => 1.2, 'close_location' => 1.0, 'rsi' => 75, 'rsi_prev' => 60, 'ema_fast' => 2, 'ema_slow' => 1,
            'spread_bps' => 0.5, 'quote_volume_24h' => 1e9, 'h1_aligned' => true, 'm15_compressed' => true]);
        $this->assertSame(100, $best['score']);
        $this->assertSame('HIGH', $best['bucket']);

        $thin = $this->scorer()->score(['direction' => 'LONG', 'bbw_pct' => 25, 'range_ratio' => 3.5, 'extension_atr' => 0.05, 'volume' => 1.6]);
        $this->assertSame('REJECT', $thin['bucket']);
        $this->assertContains('spread_bps', $thin['missing']);

        $this->assertSame('VALID', $this->scorer()->bucket(80));
        $this->assertSame('WATCHLIST', $this->scorer()->bucket(60));
        $this->assertSame('REJECT', $this->scorer()->bucket(59));
    }

    public function test_long_levels_follow_the_formulas(): void
    {
        $range = ['resistance' => 100.0, 'support' => 98.0, 'atr' => 2.0];
        $l = $this->risk()->levels('LONG', 100.3, $range, [99.95, 99.8, 100.0], 2.0, 0.0);

        $this->assertTrue($l['ok'], (string) $l['reason']);
        $this->assertEqualsWithDelta(99.5, $l['sl'], 1e-9, 'min(structural low 99.8, R - 0.25 ATR = 99.5)');
        $this->assertEqualsWithDelta(100.3 + 1.5 * 0.8, $l['tp1'], 1e-9);
        $this->assertEqualsWithDelta(100.3 + 2.0 * 0.8, $l['tp2'], 1e-9);
        $costs = 100.3 * (2 * 0.0005 + 2 * 0.0003 + 0.0002);
        $this->assertEqualsWithDelta($costs, $l['cost_per_unit'], 1e-9);
        $this->assertEqualsWithDelta((1.2 - $costs) / (0.8 + $costs), $l['net_rr_tp1'], 1e-9);

        $structural = $this->risk()->levels('LONG', 100.3, ['resistance' => 100.0, 'support' => 98.0, 'atr' => 0.4], [99.95, 99.2], 2.0, 0.0);
        $this->assertEqualsWithDelta(99.2, $structural['sl'], 1e-9, 'min(structural low 99.2, R - 0.25 ATR = 99.9)');
    }

    public function test_short_levels_are_mirrored(): void
    {
        $range = ['resistance' => 102.0, 'support' => 100.0, 'atr' => 0.4];
        $l = $this->risk()->levels('SHORT', 99.7, $range, [100.05, 100.8], 2.0, null);

        $this->assertTrue($l['ok'], (string) $l['reason']);
        $this->assertEqualsWithDelta(100.8, $l['sl'], 1e-9, 'max(structural high 100.8, S + 0.25 ATR = 100.1)');
        $this->assertEqualsWithDelta(99.7 - 1.5 * 1.1, $l['tp1'], 1e-9);
        $this->assertEqualsWithDelta(99.7 - 2.0 * 1.1, $l['tp2'], 1e-9);
    }

    public function test_invalid_wide_and_cost_eaten_candidates_are_rejected_not_adjusted(): void
    {
        $range = ['resistance' => 100.0, 'support' => 98.0, 'atr' => 0.4];

        $this->assertStringContainsString('losing side', (string) $this->risk()->levels('LONG', 99.0, $range, [99.5])['reason']);
        $this->assertStringContainsString('wider', (string) $this->risk()->levels('LONG', 100.3, $range, [95.0])['reason']);
        $tight = $this->risk(['risk.min_sl_pct' => 0.0, 'risk.min_net_rr' => 1.0])->levels('LONG', 100.02, ['resistance' => 100.0, 'support' => 99.9, 'atr' => 0.02], [99.99], 2.0);
        $this->assertFalse($tight['ok']);
        $this->assertStringContainsString('net R:R', (string) $tight['reason'], 'Fees and spread eat a 3-cent stop');
    }

    public function test_adverse_funding_is_a_cost_only_for_the_paying_side(): void
    {
        $r = $this->risk();
        $this->assertGreaterThan(0, $r->costs(100, 'LONG', 1.0, 0.001)['funding']);
        $this->assertSame(0.0, $r->costs(100, 'SHORT', 1.0, 0.001)['funding']);
        $this->assertGreaterThan(0, $r->costs(100, 'SHORT', 1.0, -0.001)['funding']);
    }

    public function test_position_size_needs_equity(): void
    {
        $this->assertNull(SignalRiskCalculator::quantity(null, 0.02, 100, 99));
        $this->assertEqualsWithDelta(0.48, SignalRiskCalculator::quantity(24.0, 0.02, 100, 99), 1e-9);
    }
}
