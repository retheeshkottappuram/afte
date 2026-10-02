<?php

namespace Tests\Unit;

use App\Services\Crypto\SignalEngine;
use App\Services\Trading\TradingTargetManager;
use Tests\TestCase;

class SignalEngineStrategyTest extends TestCase
{
    /**
     * Helper to generate a realistic synthetic series of N closed candles.
     */
    protected function generateCandles(int $count, float $startPrice = 100.0, float $drift = 0.0): array
    {
        $opens = [];
        $highs = [];
        $lows = [];
        $closes = [];
        $volumes = [];
        $closeTimes = [];

        $price = $startPrice;
        $startCloseTimeMs = 1700000000000;
        $intervalMs = 900000;

        for ($k = 0; $k < $count; $k++) {
            $open = $price;
            $delta = (sin($k * 0.2) * 0.5) + $drift;
            $close = max(1.0, $open + $delta);
            $high = max($open, $close) + 0.3;
            $low = min($open, $close) - 0.3;
            $vol = 1000.0;
            $cTime = $startCloseTimeMs + ($k * $intervalMs);

            $opens[] = round($open, 4);
            $highs[] = round($high, 4);
            $lows[] = round($low, 4);
            $closes[] = round($close, 4);
            $volumes[] = round($vol, 2);
            $closeTimes[] = $cTime;

            $price = $close;
        }

        return [
            'opens' => $opens,
            'highs' => $highs,
            'lows' => $lows,
            'closes' => $closes,
            'volumes' => $volumes,
            'closeTimes' => $closeTimes,
        ];
    }

    public function test_anti_exhaustion_guard_blocks_short_at_extended_bottom(): void
    {
        // Generate a base steady market of 220 candles at ~100 (trend_len requires at least 200)
        $candles = $this->generateCandles(220, 100.0, 0.0);

        // Now simulate a violent 10% dump over the last 2 candles
        $lastIdx = count($candles['closes']) - 1;
        $candles['closes'][$lastIdx - 1] = 93.0;
        $candles['lows'][$lastIdx - 1] = 92.5;
        $candles['volumes'][$lastIdx - 1] = 8000.0;

        $candles['opens'][$lastIdx] = 93.0;
        $candles['closes'][$lastIdx] = 88.0; // severe dump ~12% below EMA
        $candles['highs'][$lastIdx] = 93.5;
        $candles['lows'][$lastIdx] = 87.5;
        $candles['volumes'][$lastIdx] = 12000.0; // extreme volume climax

        $engine = new SignalEngine([]);

        $eval = $engine->evaluateDetailed($candles, null, null, null);

        // When price is severely dumped into support/oversold, the system should NOT recommend entering SHORT
        $side = $eval['signal']['side'] ?? null;
        $this->assertNotEquals('SELL', $side, 'Short entry should be blocked during exhaustion dump into oversold territory');
    }

    public function test_monitored_coins_contains_at_least_five_coins(): void
    {
        $coins = TradingTargetManager::getMonitoredCoins();
        $this->assertIsArray($coins);
        $this->assertGreaterThanOrEqual(5, count($coins));
    }

    public function test_swing_peak_reversal_fires_sell_at_top(): void
    {
        // 220 candles with upward drift reaching a peak
        $candles = $this->generateCandles(220, 100.0, 0.08);

        $lastIdx = count($candles['closes']) - 1;
        // Peak candle at $lastIdx - 1
        $candles['highs'][$lastIdx - 1] = 125.5;
        $candles['closes'][$lastIdx - 1] = 124.8;
        $candles['opens'][$lastIdx - 1] = 122.5;

        // Reversal candle rolling over off the peak and breaking below EMA 9
        $candles['opens'][$lastIdx] = 124.8;
        $candles['highs'][$lastIdx] = 125.0;
        $candles['lows'][$lastIdx] = 118.0;
        $candles['closes'][$lastIdx] = 118.5;
        $candles['volumes'][$lastIdx] = 3500.0;

        $engine = new SignalEngine([]);
        $eval = $engine->evaluateDetailed($candles, null, null, null);

        $this->assertNotNull($eval['signal']);
        $this->assertEquals('SELL', $eval['signal']['side']);
        $this->assertContains($eval['signal']['setup_type'], ['SWING_PEAK_REVERSAL', 'BREAKDOWN_START', 'EMA_CROSS_MOMENTUM']);
    }

    public function test_swing_trough_reversal_fires_buy_at_bottom(): void
    {
        // 220 candles with downward drift reaching a bottom
        $candles = $this->generateCandles(220, 100.0, -0.08);

        $lastIdx = count($candles['closes']) - 1;
        // Trough candle at $lastIdx - 1
        $candles['lows'][$lastIdx - 1] = 75.0;
        $candles['closes'][$lastIdx - 1] = 76.0;
        $candles['opens'][$lastIdx - 1] = 77.0;

        // Reversal candle bouncing up off the trough and reclaiming above EMA 9
        $candles['opens'][$lastIdx] = 76.0;
        $candles['lows'][$lastIdx] = 75.2;
        $candles['highs'][$lastIdx] = 84.5;
        $candles['closes'][$lastIdx] = 84.0;
        $candles['volumes'][$lastIdx] = 3500.0;

        $engine = new SignalEngine([]);
        $eval = $engine->evaluateDetailed($candles, null, null, null);

        $this->assertNotNull($eval['signal']);
        $this->assertEquals('BUY', $eval['signal']['side']);
        $this->assertContains($eval['signal']['setup_type'], ['SWING_TROUGH_REVERSAL', 'BREAKOUT_START', 'EMA_CROSS_MOMENTUM']);
    }

    public function test_evaluate_history_does_not_spam_repeat_signals_in_sideways_consolidation(): void
    {
        $candles = $this->generateCandles(220, 100.0, 0.0);
        $engine = new SignalEngine([]);
        $history = $engine->evaluateHistory($candles);

        $markers = $history['markers'];
        $this->assertIsArray($markers);
        $this->assertLessThanOrEqual(10, count($markers), 'In sideways consolidation, total markers across 220 candles must be strictly limited to prevent noise');

        for ($k = 1; $k < count($markers); $k++) {
            $timeDiffSec = $markers[$k]['time'] - $markers[$k - 1]['time'];
            $barsDiff = (int) round($timeDiffSec / 900); // 15m candle = 900s
            $this->assertGreaterThanOrEqual(8, $barsDiff, 'All markers must have at least 8 bars separation to prevent whipsaw chop');

            if ($markers[$k]['side'] === $markers[$k - 1]['side']) {
                $this->assertGreaterThanOrEqual(15, $barsDiff, 'Repeat same-side markers must have at least 15 bars separation in history');
            }
        }
    }

    public function test_never_short_during_strong_bull_breakout_pump(): void
    {
        // Simulate a strong bull breakout pump (like XRP 1.50 -> 1.54)
        $candles = $this->generateCandles(220, 100.0, 0.15);
        $lastIdx = count($candles['closes']) - 1;

        // Big green expanding bull candle continuing the breakout
        $candles['opens'][$lastIdx] = 132.0;
        $candles['closes'][$lastIdx] = 135.0;
        $candles['highs'][$lastIdx] = 135.2;
        $candles['lows'][$lastIdx] = 131.8;
        $candles['volumes'][$lastIdx] = 6000.0;

        $engine = new SignalEngine([]);
        $eval = $engine->evaluateDetailed($candles, null, null, null);

        $side = $eval['signal']['side'] ?? null;
        $this->assertNotEquals('SELL', $side, 'Algorithm must never fire a SELL signal into an expanding green bull breakout candle');
    }

    public function test_never_buy_during_cascading_waterfall_dump(): void
    {
        // Simulate a cascading waterfall dump (like NEAR 5.50 -> 4.70)
        $candles = $this->generateCandles(220, 100.0, -0.15);
        $lastIdx = count($candles['closes']) - 1;

        // Big red expanding bear candle plunging down
        $candles['opens'][$lastIdx] = 68.0;
        $candles['closes'][$lastIdx] = 64.0;
        $candles['highs'][$lastIdx] = 68.2;
        $candles['lows'][$lastIdx] = 63.8;
        $candles['volumes'][$lastIdx] = 6000.0;

        $engine = new SignalEngine([]);
        $eval = $engine->evaluateDetailed($candles, null, null, null);

        $side = $eval['signal']['side'] ?? null;
        $this->assertNotEquals('BUY', $side, 'Algorithm must never fire a BUY signal into a cascading red waterfall dump');
    }

    public function test_100_usd_trade_simulation_guarantees_minimum_10_percent_profit_target(): void
    {
        $candles = $this->generateCandles(220, 100.0, 0.08);
        $lastIdx = count($candles['closes']) - 1;
        $candles['highs'][$lastIdx - 1] = 125.5;
        $candles['closes'][$lastIdx - 1] = 124.8;
        $candles['opens'][$lastIdx - 1] = 122.5;

        $candles['opens'][$lastIdx] = 124.8;
        $candles['highs'][$lastIdx] = 125.0;
        $candles['lows'][$lastIdx] = 118.0;
        $candles['closes'][$lastIdx] = 118.5;
        $candles['volumes'][$lastIdx] = 3500.0;

        $engine = new SignalEngine([]);
        $eval = $engine->evaluateDetailed($candles, null, null, null);

        $this->assertNotNull($eval['signal']);
        $signal = $eval['signal'];

        // Validate $100 capital trade simulation
        $this->assertArrayHasKey('dollar_sim', $signal);
        $sim = $signal['dollar_sim'];
        $this->assertEquals(100, $sim['capital']);
        $this->assertEquals(10, $sim['leverage']);
        $this->assertEquals(1000, $sim['position_size']);

        // Must guarantee at least 10% ROE ($10 profit) on TP1
        $this->assertGreaterThanOrEqual(10.0, $sim['tp1_roe_pct']);
        $this->assertGreaterThanOrEqual(10.0, $sim['tp1_profit']);

        // Must guarantee at least 25% ROE ($25 profit) on TP2
        $this->assertGreaterThanOrEqual(25.0, $sim['tp2_roe_pct']);
        $this->assertGreaterThanOrEqual(25.0, $sim['tp2_profit']);

        // Risk capped
        $this->assertLessThanOrEqual(13.5, $sim['sl_roe_pct']);
        $this->assertLessThanOrEqual(13.50, $sim['sl_loss']);
    }

    public function test_1h_strategy_confluence_blocks_counter_trend_trades(): void
    {
        $engine = new SignalEngine([]);

        // Generate bearish 1H candles (closes declining consistently below EMA 21 and 50)
        $htfBearCandles = $this->generateCandles(60, 100.0, -0.30);
        $htfAnalysis = $engine->analyzeHtfStrategy($htfBearCandles);

        $this->assertEquals('BEARISH', $htfAnalysis['trend']);
        $this->assertFalse($htfAnalysis['allow_long'], '1H bearish trend must not allow LONG entries');
        $this->assertTrue($htfAnalysis['allow_short'], '1H bearish trend allows SHORT entries');

        // Generate bullish 1H candles (closes climbing consistently above EMA 21 and 50)
        $htfBullCandles = $this->generateCandles(60, 100.0, 0.30);
        $htfBullAnalysis = $engine->analyzeHtfStrategy($htfBullCandles);

        $this->assertEquals('BULLISH', $htfBullAnalysis['trend']);
        $this->assertTrue($htfBullAnalysis['allow_long'], '1H bullish trend allows LONG entries');
        $this->assertFalse($htfBullAnalysis['allow_short'], '1H bullish trend must not allow SHORT entries');
    }
}
