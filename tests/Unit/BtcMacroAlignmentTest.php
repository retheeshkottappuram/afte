<?php

namespace Tests\Unit;

use App\Services\Crypto\BtcMacroAlignment;
use PHPUnit\Framework\TestCase;

class BtcMacroAlignmentTest extends TestCase
{
    /**
     * Helper to create 1h trending candles.
     *
     * @return array{closes: array<int, float>, closeTimes: array<int, int>}
     */
    protected function make1hTrendCandles(int $count, float $startPrice, float $incrementPerBar): array
    {
        $closes = [];
        $closeTimes = [];
        $t = 1700000000000;

        for ($i = 0; $i < $count; $i++) {
            $closes[] = round($startPrice + ($i * $incrementPerBar), 2);
            $closeTimes[] = $t + ($i * 3600000);
        }

        return [
            'opens' => $closes,
            'highs' => $closes,
            'lows' => $closes,
            'closes' => $closes,
            'volumes' => array_fill(0, $count, 1000.0),
            'closeTimes' => $closeTimes,
        ];
    }

    public function test_bullish_alignment_requires_above_emas_rising_slope_and_4h_confluence(): void
    {
        // 250 bars of strongly ascending 1h BTC prices (e.g., $50,000 -> $75,000)
        $btc1h = $this->make1hTrendCandles(250, 50000.0, 100.0);
        $btc4h = $this->make1hTrendCandles(100, 50000.0, 250.0);

        $alignment = new BtcMacroAlignment([
            'enabled' => true,
            'ema_fast' => 50,
            'ema_slow' => 200,
            'slope_lookback' => 3,
            'min_slope_pct' => 0.01,
            'require_4h_confluence' => true,
            'ema_4h' => 50,
        ]);

        $eval = $alignment->evaluate($btc1h, $btc4h);

        $this->assertSame('BULLISH', $eval['trend']);
        $this->assertTrue($eval['allow_long'], 'Bullish BTC macro alignment must allow longs');
        $this->assertFalse($eval['allow_short'], 'Bullish BTC macro alignment must forbid shorts');
        $this->assertGreaterThan(0, $eval['slope_pct']);
        $this->assertSame('BULLISH', $eval['confluence_4h']);
    }

    public function test_bearish_alignment_requires_below_emas_falling_slope_and_4h_confluence(): void
    {
        // 250 bars of strongly descending 1h BTC prices (e.g., $70,000 -> $45,000)
        $btc1h = $this->make1hTrendCandles(250, 70000.0, -100.0);
        $btc4h = $this->make1hTrendCandles(100, 70000.0, -250.0);

        $alignment = new BtcMacroAlignment([
            'enabled' => true,
            'ema_fast' => 50,
            'ema_slow' => 200,
            'slope_lookback' => 3,
            'min_slope_pct' => 0.01,
            'require_4h_confluence' => true,
            'ema_4h' => 50,
        ]);

        $eval = $alignment->evaluate($btc1h, $btc4h);

        $this->assertSame('BEARISH', $eval['trend']);
        $this->assertFalse($eval['allow_long'], 'Bearish BTC macro alignment must forbid longs');
        $this->assertTrue($eval['allow_short'], 'Bearish BTC macro alignment must allow shorts');
        $this->assertLessThan(0, $eval['slope_pct']);
        $this->assertSame('BEARISH', $eval['confluence_4h']);
    }

    public function test_neutral_choppy_btc_blocks_both_longs_and_shorts(): void
    {
        // 1. Create a flat / oscillating series where slope is ~0 and price sits between EMAs
        $closes = [];
        $closeTimes = [];
        $t = 1700000000000;
        for ($i = 0; $i < 250; $i++) {
            $closes[] = round(60000.0 + sin($i * 0.3) * 50.0, 2);
            $closeTimes[] = $t + ($i * 3600000);
        }

        $btc1h = [
            'opens' => $closes,
            'highs' => $closes,
            'lows' => $closes,
            'closes' => $closes,
            'volumes' => array_fill(0, 250, 1000.0),
            'closeTimes' => $closeTimes,
        ];

        $alignment = new BtcMacroAlignment([
            'enabled' => true,
            'ema_fast' => 50,
            'ema_slow' => 200,
            'slope_lookback' => 3,
            'min_slope_pct' => 0.05,
            'require_4h_confluence' => false,
        ]);

        $eval = $alignment->evaluate($btc1h);

        $this->assertSame('NEUTRAL', $eval['trend']);
        $this->assertFalse($eval['allow_long'], 'Choppy BTC must block longs');
        $this->assertFalse($eval['allow_short'], 'Choppy BTC must block shorts');
        $this->assertStringContainsString('all signals blocked', $eval['reason']);
    }

    public function test_conflicting_4h_trend_forces_neutral_blocking(): void
    {
        // 1h is bullish
        $btc1h = $this->make1hTrendCandles(250, 50000.0, 100.0);

        // But 4h is bearish
        $btc4h = $this->make1hTrendCandles(100, 70000.0, -250.0);

        $alignment = new BtcMacroAlignment([
            'enabled' => true,
            'require_4h_confluence' => true,
        ]);

        $eval = $alignment->evaluate($btc1h, $btc4h);

        $this->assertSame('NEUTRAL', $eval['trend']);
        $this->assertFalse($eval['allow_long'], 'Conflicting 4h trend must block longs');
        $this->assertFalse($eval['allow_short'], 'Conflicting 4h trend must block shorts');
    }
}
