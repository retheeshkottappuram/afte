<?php

namespace Tests\Unit;

use App\Services\Strategy\StrategyEngine;
use ReflectionMethod;
use Tests\TestCase;

class TwoSidedWatchTest extends TestCase
{
    /**
     * A wide range for 110 hours, then a tight 20-hour box around 100: a squeeze with no trend.
     *
     * @return array<string, array<int, float|int>>
     */
    protected function coiledCandles(): array
    {
        $c = ['opens' => [], 'highs' => [], 'lows' => [], 'closes' => [], 'volumes' => [], 'closeTimes' => []];
        $start = 1_800_000_000_000;
        for ($i = 0; $i < 130; $i++) {
            $amp = $i < 110 ? 3.0 : 0.4;
            $close = 100 + ($i % 2 ? $amp : -$amp) * 0.5;
            $c['opens'][] = 100.0;
            $c['highs'][] = max(100.0, $close) + $amp * 0.3;
            $c['lows'][] = min(100.0, $close) - $amp * 0.3;
            $c['closes'][] = $close;
            $c['volumes'][] = 1000.0;
            $c['closeTimes'][] = $start + ($i + 1) * 3_600_000 - 1;
        }

        return $c;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array<string, mixed>>
     */
    protected function watches(array $config): array
    {
        $engine = new StrategyEngine($config);
        $c = $this->coiledCandles();
        $series = (new ReflectionMethod($engine, 'computeSeries'))->invoke($engine, $c);
        $noTrend = ['side' => 'NONE', 'adx' => 12.0, 'detail' => 'No clear 4h trend'];
        $btc = ['block_long' => false, 'block_short' => false, 'state' => 'BTC neutral', 'returns_24' => 0.0];

        return (new ReflectionMethod($engine, 'watchCandidates'))->invoke($engine, 'SOLUSDT', '1h', 129, $c, $series, $noTrend, $btc, ['volume_rank' => 30]);
    }

    public function test_a_squeezed_coin_without_a_trend_is_watched_on_both_edges(): void
    {
        $watches = $this->watches(['early_breakout' => ['two_sided' => true]]);

        $this->assertSame(['LONG', 'SHORT'], array_column($watches, 'side'));
        $this->assertGreaterThan($watches[1]['level'], $watches[0]['level']);
        $this->assertTrue($watches[0]['filters']['regime']['pass'], 'The 4h trend is not required');
        $this->assertTrue($watches[0]['filters']['volume_rank']['pass'], 'Rank 30 is inside the top 50 for early breakouts');
    }

    public function test_one_sided_mode_still_needs_a_trend(): void
    {
        $this->assertSame([], $this->watches(['early_breakout' => ['two_sided' => false]]));
    }
}
