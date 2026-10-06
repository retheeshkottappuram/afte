<?php

namespace Tests\Unit;

use App\Services\Strategy\StrategyEngine;
use Tests\TestCase;

class BoxBiasTest extends TestCase
{
    /**
     * 20 candles drifting by $drift per bar, the last close at $lastClose, with more volume on $heavy candles.
     *
     * @return array<string, array<int, float>>
     */
    protected function box(float $drift, float $lastClose, string $heavy): array
    {
        $c = ['opens' => [], 'highs' => [], 'lows' => [], 'closes' => [], 'volumes' => []];
        for ($i = 0; $i < 20; $i++) {
            $mid = 100 + $drift * $i;
            $up = $i % 2 === 0;
            $c['opens'][] = $up ? $mid - 0.2 : $mid + 0.2;
            $c['closes'][] = $up ? $mid + 0.2 : $mid - 0.2;
            $c['highs'][] = $mid + 0.5;
            $c['lows'][] = $mid - 0.5;
            $c['volumes'][] = ($up xor $heavy === 'down') ? 300.0 : 100.0;
        }
        $c['closes'][19] = $lastClose;
        $c['opens'][19] = $lastClose - ($heavy === 'up' ? 0.1 : -0.1);

        return $c;
    }

    public function test_bias_needs_edge_structure_and_volume_to_agree(): void
    {
        $engine = new StrategyEngine;

        $rising = $this->box(0.05, 101.4, 'up');
        $this->assertSame('LONG', $engine->boxBias(19, $rising, 1.0));

        $falling = $this->box(-0.05, 98.6, 'down');
        $this->assertSame('SHORT', $engine->boxBias(19, $falling, 1.0));

        // Rising box but the close sits in the middle: no call.
        $this->assertNull($engine->boxBias(19, $this->box(0.05, 100.5, 'up'), 1.0));
        // Rising box, close at the top, but the volume is on the down candles: no call.
        $this->assertNull($engine->boxBias(19, $this->box(0.05, 101.4, 'down'), 1.0));
    }
}
