<?php

namespace Tests\Unit;

use App\Services\Strategy\ReversalEngine;
use App\Services\Strategy\StrategyEngine;
use Tests\TestCase;

class ReversalEngineTest extends TestCase
{
    /**
     * 15m candles swinging between support ~100 and resistance ~104 (period 24 candles), then a last candle that
     * sweeps below support and closes back above it ($last: open, high, low, close; null = keep swinging).
     * $dir = -1 mirrors everything around 102 (resistance sweep, short).
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}|null  $last
     * @return array<string, array<int, float|int>>
     */
    protected function candles(?array $last, int $dir = 1, float $lastVolume = 2500.0): array
    {
        $c = ['opens' => [], 'highs' => [], 'lows' => [], 'closes' => [], 'volumes' => [], 'closeTimes' => []];
        $end = intdiv(time(), 900) * 900 * 1000 - 1;
        $n = 240;
        $price = 102.0;
        $m = fn (float $p): float => $dir > 0 ? $p : 204 - $p; // mirror around 102
        for ($i = 0; $i < $n; $i++) {
            $open = $price;
            if ($i === $n - 1 && $last !== null) {
                [$o, $h, $l, $cl] = $last;
                [$o, $h, $l, $cl] = $dir > 0 ? [$o, $h, $l, $cl] : [$m($o), $m($l), $m($h), $m($cl)];
                $c['opens'][] = $o;
                $c['highs'][] = $h;
                $c['lows'][] = $l;
                $c['closes'][] = $cl;
                $c['volumes'][] = $lastVolume;
                $c['closeTimes'][] = $end;
                break;
            }
            // Ends falling into support on the candle before the last one.
            $price = $m(102 + 2 * sin(2 * M_PI * ($i - $n + 7) / 24));
            $c['opens'][] = $open;
            $c['closes'][] = $price;
            $c['highs'][] = max($open, $price) + 0.3;
            $c['lows'][] = min($open, $price) - 0.3;
            $c['volumes'][] = 1000.0;
            $c['closeTimes'][] = $end - ($n - 1 - $i) * 900000;
        }

        return $c;
    }

    /** Hourly candles trending $dir. */
    protected function hourly(int $dir): array
    {
        $c = ['opens' => [], 'highs' => [], 'lows' => [], 'closes' => [], 'volumes' => [], 'closeTimes' => []];
        $end = intdiv(time(), 3600) * 3600 * 1000 - 1;
        for ($i = 0; $i < 250; $i++) {
            $p = 102 - $dir * 20 + $dir * $i * 0.08;
            $c['opens'][] = $p;
            $c['closes'][] = $p + $dir * 0.05;
            $c['highs'][] = $p + 0.2;
            $c['lows'][] = $p - 0.2;
            $c['volumes'][] = 1.0;
            $c['closeTimes'][] = $end - (249 - $i) * 3600000;
        }

        return $c;
    }

    protected function engine(): ReversalEngine
    {
        return app(ReversalEngine::class);
    }

    /** Hammer: opens 100.3, dips to 99.2 (through support ~99.7), closes 100.5 near the high. */
    protected const HAMMER = [100.3, 100.6, 99.2, 100.5];

    public function test_a_hammer_sweeping_support_in_an_uptrend_is_a_confirmed_buy_targeting_resistance(): void
    {
        $c = $this->candles(self::HAMMER);
        $signals = $this->engine()->signals($c, $this->hourly(1), count($c['closes']) - 1, true);

        $this->assertCount(1, $signals, json_encode($this->engine()->zonesAt($c, $this->hourly(1))));
        $buy = $signals[0];
        $this->assertSame('LONG', $buy['side']);
        $this->assertTrue($buy['confirmed']);
        $this->assertSame('Hammer', $buy['pattern']);
        $this->assertSame('UP', $buy['trend']);
        $this->assertLessThan(99.2, $buy['sl'], 'Stop below the sweep wick');
        $this->assertGreaterThan(103.5, $buy['tp2'], 'Target = the resistance zone');
        $this->assertGreaterThanOrEqual(2.0, $buy['rr']);
        $this->assertStringContainsString('sweep of support', $buy['reason']);
    }

    public function test_a_sweep_without_a_reversal_candle_is_no_signal(): void
    {
        // Closes near its low: no hammer, no engulfing.
        $c = $this->candles([100.3, 100.4, 99.2, 99.75]);

        $this->assertSame([], $this->engine()->signals($c, $this->hourly(1), count($c['closes']) - 1, true));
    }

    public function test_too_little_room_to_the_next_zone_is_rejected(): void
    {
        config(['trading.swing_sar.sr.min_rr' => 10.0]);
        $c = $this->candles(self::HAMMER);

        $this->assertSame([], $this->engine()->signals($c, $this->hourly(1), count($c['closes']) - 1, true));
    }

    public function test_a_mirrored_sweep_of_resistance_in_a_downtrend_is_a_sell(): void
    {
        $c = $this->candles(self::HAMMER, dir: -1);
        $signals = $this->engine()->signals($c, $this->hourly(-1), count($c['closes']) - 1, true);

        $this->assertCount(1, $signals);
        $this->assertSame('SHORT', $signals[0]['side']);
        $this->assertSame('Shooting star', $signals[0]['pattern']);
        $this->assertGreaterThan($signals[0]['entry'], $signals[0]['sl']);
    }

    public function test_against_the_trend_it_is_only_a_question_mark_and_needs_the_rsi_divergence(): void
    {
        $c = $this->candles(self::HAMMER);
        $k = count($c['closes']) - 1;

        // The sweep made a lower low with a higher RSI (divergence): allowed counter-trend, as BUY?.
        $signals = $this->engine()->signals($c, $this->hourly(-1), $k, true);
        $this->assertCount(1, $signals);
        $this->assertFalse($signals[0]['confirmed']);
        $this->assertSame('DOWN', $signals[0]['trend']);
        $this->assertStringContainsString('RSI divergence', $signals[0]['reason']);
        $this->assertSame([], $this->engine()->signals($c, $this->hourly(-1), $k, false), 'Not shown as a confirmed signal');

        config(['trading.swing_sar.sr.counter_trend' => false]);
        $this->assertSame([], $this->engine()->signals($c, $this->hourly(-1), $k, true));
    }

    public function test_the_chart_gets_s_r_signals_and_zones_and_supertrend_stays_the_bot_source(): void
    {
        $this->assertSame('supertrend', StrategyEngine::signalSource());
        $this->assertNotEmpty($this->engine()->zonesAt($this->candles(null), $this->hourly(1)));
        $signals = app(StrategyEngine::class)->swingSignalsFrom('sr', $this->candles(self::HAMMER), 239, true, $this->hourly(1));
        $this->assertSame('sr', $signals[0]['source']);
    }
}
