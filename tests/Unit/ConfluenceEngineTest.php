<?php

namespace Tests\Unit;

use App\Services\Strategy\ConfluenceEngine;
use App\Services\Strategy\StrategyEngine;
use Tests\TestCase;

class ConfluenceEngineTest extends TestCase
{
    /**
     * 15m candles: a trend ($dir) up to bar 259, a sideways range, then one breakout candle out of the range on the
     * last bar (volume x $spikeVol, move $move).
     *
     * @return array<string, array<int, float|int>>
     */
    protected function candles(int $dir, float $spikeVol = 3.0, float $move = 1.0): array
    {
        $c = ['opens' => [], 'highs' => [], 'lows' => [], 'closes' => [], 'volumes' => [], 'closeTimes' => []];
        $end = intdiv(time(), 900) * 900 * 1000 - 1;
        $price = 100.0;
        for ($i = 0; $i < 300; $i++) {
            $open = $price;
            $price = match (true) {
                $i < 260 => 100 + $dir * ($i - 260) * 0.04 + 0.3 * sin($i / 3),
                $i < 299 => 100 + 0.6 * sin($i / 2.3),
                default => 100 + $dir * $move,
            };
            $wick = $i === 299 ? 0.05 : 0.3;
            $c['opens'][] = $open;
            $c['closes'][] = $price;
            $c['highs'][] = max($open, $price) + $wick;
            $c['lows'][] = min($open, $price) - $wick;
            $c['volumes'][] = $i === 299 ? 1000 * $spikeVol : 1000.0;
            $c['closeTimes'][] = $end - (299 - $i) * 900000;
        }

        return $c;
    }

    /** Hourly candles trending $dir (the higher timeframe). */
    protected function hourly(int $dir): array
    {
        $c = ['opens' => [], 'highs' => [], 'lows' => [], 'closes' => [], 'volumes' => [], 'closeTimes' => []];
        $end = intdiv(time(), 3600) * 3600 * 1000 - 1;
        for ($i = 0; $i < 250; $i++) {
            $p = 80 + $dir * $i * 0.1;
            $c['opens'][] = $p;
            $c['closes'][] = $p + $dir * 0.05;
            $c['highs'][] = $p + 0.2;
            $c['lows'][] = $p - 0.2;
            $c['volumes'][] = 1.0;
            $c['closeTimes'][] = $end - (249 - $i) * 3600000;
        }

        return $c;
    }

    protected function engine(): ConfluenceEngine
    {
        return app(ConfluenceEngine::class);
    }

    public function test_an_aligned_breakout_on_volume_is_a_confirmed_buy_with_its_score(): void
    {
        $signals = $this->engine()->signals($this->candles(1), $this->hourly(1), 290, true);

        $this->assertCount(1, $signals, 'One label on the breakout candle, not on every bar');
        $buy = $signals[0];
        $this->assertSame('LONG', $buy['side']);
        $this->assertTrue($buy['confirmed']);
        $this->assertGreaterThanOrEqual(80, $buy['score']);
        $this->assertSame(['trend' => 20, 'htf' => 15, 'momentum' => 15, 'volume' => 15, 'breakout' => 20, 'candle' => 8], $buy['breakdown']);
        $this->assertLessThan($buy['entry'], $buy['sl']);
        $this->assertEqualsWithDelta($buy['entry'] + 1.5 * ($buy['entry'] - $buy['sl']), $buy['tp1'], 1e-9);
        $this->assertStringContainsString('V3 score', $buy['reason']);
    }

    public function test_the_same_breakout_without_volume_is_only_a_buy_question_mark(): void
    {
        $signals = $this->engine()->signals($this->candles(1, spikeVol: 1.0), $this->hourly(1), 290, true);

        $this->assertCount(1, $signals);
        $this->assertFalse($signals[0]['confirmed']);
        $this->assertGreaterThanOrEqual(70, $signals[0]['score']);
        $this->assertLessThan(80, $signals[0]['score']);
        $this->assertSame([], $this->engine()->signals($this->candles(1, spikeVol: 1.0), $this->hourly(1), 290, false), 'Hidden when "?" are not wanted');
    }

    public function test_an_overextended_spike_is_rejected(): void
    {
        $score = $this->engine()->scoreAt($this->candles(1, move: 4.0), $this->hourly(1), 299, 'LONG');

        $this->assertFalse($score['ok']);
        $this->assertStringContainsString('overbought', (string) $score['reject']);
        $this->assertSame([], $this->engine()->signals($this->candles(1, move: 4.0), $this->hourly(1), 290, true));
    }

    public function test_a_mirrored_breakdown_is_a_sell(): void
    {
        $signals = $this->engine()->signals($this->candles(-1), $this->hourly(-1), 290, true);

        $this->assertCount(1, $signals);
        $this->assertSame('SHORT', $signals[0]['side']);
        $this->assertGreaterThan($signals[0]['entry'], $signals[0]['sl']);
    }

    public function test_against_the_higher_timeframe_the_score_drops(): void
    {
        $with = $this->engine()->scoreAt($this->candles(1), $this->hourly(1), 299, 'LONG');
        $against = $this->engine()->scoreAt($this->candles(1), $this->hourly(-1), 299, 'LONG');

        $this->assertSame(15, $with['breakdown']['htf']);
        $this->assertSame(0, $against['breakdown']['htf']);
        $this->assertSame($with['score'] - 15, $against['score']);
    }

    public function test_v3_can_be_selected_as_the_swing_signal_source(): void
    {
        $this->assertFalse(StrategyEngine::usesV3(), 'SuperTrend is the default again (user reverted 2026-10-21)');
        config(['trading.swing_sar.signal_source' => 'v3']);
        $this->assertTrue(StrategyEngine::usesV3());
        $signals = app(StrategyEngine::class)->swingSignals($this->candles(1), 290, true, $this->hourly(1));
        $this->assertSame('v3', $signals[0]['source']);
    }
}
