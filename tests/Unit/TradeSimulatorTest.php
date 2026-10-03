<?php

namespace Tests\Unit;

use App\Services\Strategy\TradeSimulator;
use Tests\TestCase;

class TradeSimulatorTest extends TestCase
{
    /**
     * Hourly candles starting one hour after t0, from [high, low, close] rows.
     *
     * @param  array<int, array{0: float, 1: float, 2: float}>  $rows
     * @return array<string, array<int, float|int>>
     */
    protected function candles(int $t0, array $rows): array
    {
        $out = ['opens' => [], 'highs' => [], 'lows' => [], 'closes' => [], 'volumes' => [], 'closeTimes' => []];
        foreach ($rows as $k => [$high, $low, $close]) {
            $out['opens'][] = $close;
            $out['highs'][] = $high;
            $out['lows'][] = $low;
            $out['closes'][] = $close;
            $out['volumes'][] = 1000.0;
            $out['closeTimes'][] = ($t0 + 3600 * ($k + 1)) * 1000;
        }

        return $out;
    }

    /**
     * @return array{side: string, entry: float, sl: float, tp1: float, tp2: float, atr: float, time: int}
     */
    protected function signal(int $t0): array
    {
        return ['side' => 'LONG', 'entry' => 100.0, 'sl' => 99.0, 'tp1' => 101.5, 'tp2' => 103.0, 'atr' => 0.5, 'time' => $t0];
    }

    public function test_stop_loss_costs_one_r_plus_fees(): void
    {
        $t0 = 1_700_000_000;
        $result = (new TradeSimulator)->run($this->signal($t0), $this->candles($t0, [[100.4, 99.6, 99.8], [99.9, 98.8, 99.0]]));

        $this->assertTrue($result['closed']);
        $this->assertSame('SL', $result['outcome']);
        $this->assertEqualsWithDelta(-1.1, $result['r_multiple'], 0.001, '-1R plus 0.1R of round-trip fees');
    }

    public function test_tp1_then_locked_stop_is_a_partial_win(): void
    {
        $t0 = 1_700_000_000;
        $result = (new TradeSimulator)->run($this->signal($t0), $this->candles($t0, [
            [101.6, 100.2, 101.4],  // TP1 hit: half booked at +1.5R; on the close the runner trails to 101.6 - 1.5 * 0.5 ATR
            [101.2, 100.4, 100.6],  // pulls back through the trailed stop at 100.85
        ]));

        $this->assertSame('TP1', $result['outcome']);
        $this->assertEqualsWithDelta(0.5 * 1.5 + 0.5 * 0.85 - 0.1, $result['r_multiple'], 0.001);
    }

    public function test_tp2_run_is_recorded(): void
    {
        $t0 = 1_700_000_000;
        $result = (new TradeSimulator)->run($this->signal($t0), $this->candles($t0, [
            [101.6, 100.2, 101.5],
            [103.2, 101.4, 103.1],
            [103.3, 101.4, 101.6], // stop locked at >= TP1 (101.5) is hit
        ]));

        $this->assertSame('TP2', $result['outcome']);
        $this->assertGreaterThan(1.4, $result['r_multiple']);
    }

    public function test_candles_before_the_signal_are_ignored(): void
    {
        $t0 = 1_700_000_000;
        $candles = $this->candles($t0 - 7200, [[100.0, 90.0, 95.0], [100.0, 90.0, 95.0], [100.5, 99.5, 100.2]]);

        $result = (new TradeSimulator)->run($this->signal($t0), $candles);

        $this->assertFalse($result['closed'], 'A crash before the signal candle must not count');
    }
}
