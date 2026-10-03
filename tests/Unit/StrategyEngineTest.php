<?php

namespace Tests\Unit;

use App\Services\Strategy\Signal;
use App\Services\Strategy\StrategyEngine;
use Tests\TestCase;

class StrategyEngineTest extends TestCase
{
    protected const START = 1_700_000_000; // aligned to the hour

    /**
     * Deterministic random walk of 1h candles with alternating trend phases.
     *
     * @return array<string, array<int, float|int>>
     */
    public static function randomWalk(int $bars, int $seed = 7): array
    {
        mt_srand($seed);
        $c = ['opens' => [], 'highs' => [], 'lows' => [], 'closes' => [], 'volumes' => [], 'closeTimes' => []];
        $price = 100.0;

        for ($i = 0; $i < $bars; $i++) {
            $drift = (intdiv($i, 120) % 2 === 0) ? 0.0012 : -0.0010;
            $open = $price;
            $close = $open * (1 + $drift + (mt_rand(-100, 100) / 10000));
            $high = max($open, $close) * (1 + mt_rand(0, 40) / 10000);
            $low = min($open, $close) * (1 - mt_rand(0, 40) / 10000);
            $c['opens'][] = $open;
            $c['highs'][] = $high;
            $c['lows'][] = $low;
            $c['closes'][] = $close;
            $c['volumes'][] = 1000.0 * (1 + mt_rand(0, 200) / 100);
            $c['closeTimes'][] = (self::START + 3600 * ($i + 1)) * 1000 - 1;
            $price = $close;
        }

        return $c;
    }

    /**
     * Aggregate 1h candles into 4h candles.
     *
     * @param  array<string, array<int, float|int>>  $c
     * @return array<string, array<int, float|int>>
     */
    public static function toFourHour(array $c): array
    {
        $out = ['opens' => [], 'highs' => [], 'lows' => [], 'closes' => [], 'volumes' => [], 'closeTimes' => []];
        for ($i = 0; $i + 4 <= count($c['closes']); $i += 4) {
            $out['opens'][] = $c['opens'][$i];
            $out['highs'][] = max(array_slice($c['highs'], $i, 4));
            $out['lows'][] = min(array_slice($c['lows'], $i, 4));
            $out['closes'][] = $c['closes'][$i + 3];
            $out['volumes'][] = array_sum(array_slice($c['volumes'], $i, 4));
            $out['closeTimes'][] = $c['closeTimes'][$i + 3];
        }

        return $out;
    }

    /**
     * @param  array<string, array<int, float|int>>  $c
     * @return array<string, array<int, float|int>>
     */
    protected function slice(array $c, int $length): array
    {
        return array_map(fn (array $series): array => array_slice($series, 0, $length), $c);
    }

    /**
     * @return array<string, mixed>
     */
    protected function analyze(array $base, int $nowMs, array $context = []): array
    {
        return (new StrategyEngine)->analyze($base, self::toFourHour($base), null, null, array_merge([
            'symbol' => 'TESTUSDT',
            'interval' => '1h',
            'lookback' => 2000,
            'now_ms' => $nowMs,
        ], $context));
    }

    public function test_signals_never_repaint_when_more_candles_arrive(): void
    {
        $full = self::randomWalk(900);
        $cut = 700;
        $early = $this->analyze($this->slice($full, $cut), $full['closeTimes'][$cut - 1] + 1);
        $late = $this->analyze($full, $full['closeTimes'][899] + 1);

        $cutoff = intdiv($full['closeTimes'][$cut - 1], 1000);
        $earlySignals = array_map(fn (Signal $s): array => $s->toArray(), $early['signals']);
        $lateSignals = array_map(fn (Signal $s): array => $s->toArray(), array_values(array_filter($late['signals'], fn (Signal $s): bool => $s->time <= $cutoff)));

        $this->assertNotEmpty($earlySignals, 'The synthetic market should produce signals');
        $this->assertEquals($earlySignals, $lateSignals, 'Signals up to a bar must be identical whatever happens afterwards');
    }

    public function test_forming_candle_is_ignored(): void
    {
        $base = self::randomWalk(700);
        $lastClose = $base['closeTimes'][699];
        $withForming = $base;
        $withForming['opens'][] = $base['closes'][699];
        $withForming['highs'][] = $base['closes'][699] * 1.3;
        $withForming['lows'][] = $base['closes'][699] * 0.7;
        $withForming['closes'][] = $base['closes'][699] * 1.25;
        $withForming['volumes'][] = 999999.0;
        $withForming['closeTimes'][] = $lastClose + 3600 * 1000;

        $a = $this->analyze($base, $lastClose + 1);
        $b = $this->analyze($withForming, $lastClose + 1);

        $this->assertEquals(
            array_map(fn (Signal $s): array => $s->toArray(), $a['signals']),
            array_map(fn (Signal $s): array => $s->toArray(), $b['signals'])
        );
        $this->assertSame($a['state']['status'], $b['state']['status']);
    }

    public function test_every_signal_respects_stop_bounds_regime_and_shadow_rules(): void
    {
        $base = self::randomWalk(900, seed: 11);
        $result = $this->analyze($base, $base['closeTimes'][899] + 1);

        $this->assertNotEmpty($result['signals']);

        foreach ($result['signals'] as $signal) {
            $this->assertGreaterThanOrEqual(0.6 - 1e-9, $signal->slPct());
            $this->assertSame($signal->indicators['regime'] === $signal->side, $signal->filters['regime']['pass']);
            $this->assertSame(in_array($signal->setup, ['EMA_CROSS', 'SWING_REVERSAL'], true), $signal->isShadow);
            $this->assertEqualsWithDelta(1.5, abs($signal->tp1 - $signal->entry) / abs($signal->entry - $signal->stopLoss), 1e-6);

            if ($signal->slPct() > 1.8 + 1e-9) {
                $this->assertFalse($signal->isTradable(), 'A stop wider than the limit is never tradable');
            }
        }
    }

    public function test_context_filters_appear_in_the_inspector_checklist(): void
    {
        $base = self::randomWalk(700);
        $result = $this->analyze($base, $base['closeTimes'][699] + 1, [
            'quote_volume_24h' => 20_000_000.0,
            'listing_days' => 400,
            'funding_rate' => 0.0001,
        ]);

        $this->assertArrayHasKey('liquidity', $result['state']['checklist']);
        $this->assertFalse($result['state']['checklist']['liquidity']['pass'], '$20M volume is below the $50M liquidity floor');
        $this->assertTrue($result['state']['checklist']['funding']['pass']);
    }

    public function test_trend_pullback_fires_after_a_dip_into_the_ema_zone(): void
    {
        $base = self::randomWalk(400, seed: 3);
        // Overwrite the tail with a clean uptrend, a pullback into EMA21 and a strong reclaim candle.
        $price = 100.0;
        $set = function (int $i, float $open, float $high, float $low, float $close, float $volume = 1000.0) use (&$base): void {
            $base['opens'][$i] = $open;
            $base['highs'][$i] = $high;
            $base['lows'][$i] = $low;
            $base['closes'][$i] = $close;
            $base['volumes'][$i] = $volume;
        };

        for ($i = 0; $i < 380; $i++) {
            $close = $price * (1.002 + (($i % 5) - 2) * 0.0015);
            $set($i, $price, max($price, $close) * 1.001, min($price, $close) * 0.999, $close);
            $price = $close;
        }
        for ($i = 380; $i < 386; $i++) { // pullback
            $close = $price * 0.9955;
            $set($i, $price, $price * 1.0005, $close * 0.999, $close);
            $price = $close;
        }
        $reclaim = $price * 1.015;
        $set(386, $price, $reclaim * 1.0005, $price * 0.999, $reclaim, 2500.0);
        $base = $this->slice($base, 387);

        $result = $this->analyze($base, $base['closeTimes'][386] + 1);

        $this->assertNotNull($result['latest'], 'Expected a signal on the reclaim candle. State: '.json_encode($result['state']['reason']));
        $this->assertSame('TREND_PULLBACK', $result['latest']->setup);
        $this->assertSame('LONG', $result['latest']->side);
        $this->assertTrue($result['latest']->filters['regime']['pass']);
        $this->assertLessThan($result['latest']->entry, $result['latest']->stopLoss);
    }
}
