<?php

namespace Tests\Unit\Breakout;

use App\Services\Breakout\Candles;
use App\Services\Breakout\EbdConfig;
use App\Services\Breakout\IndicatorCalculator;
use App\Services\Crypto\Indicators;
use Tests\Support\EbdFixtures;
use Tests\TestCase;

class IndicatorCalculatorTest extends TestCase
{
    public function test_atr_uses_true_range_with_wilder_smoothing(): void
    {
        $h = [10, 11, 12, 11.5];
        $l = [9, 10, 10.5, 9];
        $c = [9.5, 10.8, 11, 10];
        // TR: 1, max(1, 1.5, 0.5)=1.5, max(1.5, 1.2, 0.3)=1.5, max(2.5, 0.5, 2)=2.5 ; ATR(2): seed (1+1.5)/2, then (1*1.25+1.5)/2, (1*1.375+2.5)/2
        $atr = Indicators::atr($h, $l, $c, 2);

        $this->assertNull($atr[0]);
        $this->assertEqualsWithDelta(1.25, $atr[1], 1e-9);
        $this->assertEqualsWithDelta(1.375, $atr[2], 1e-9);
        $this->assertEqualsWithDelta(1.9375, $atr[3], 1e-9);
    }

    public function test_ema_uses_alpha_two_over_n_plus_one(): void
    {
        $ema = Indicators::ema([1, 2, 3, 4], 3); // seed SMA(1,2,3)=2, alpha 0.5 -> 0.5*4 + 0.5*2

        $this->assertEqualsWithDelta(3.0, $ema[3], 1e-9);
    }

    public function test_rsi_is_100_for_only_gains_and_null_during_warm_up(): void
    {
        $rsi = Indicators::rsi([1, 2, 3, 4, 5], 3);

        $this->assertNull($rsi[2]);
        $this->assertEqualsWithDelta(100.0, $rsi[3], 1e-9);
    }

    public function test_bandwidth_is_four_sigma_over_the_mean_for_two_sigma_bands(): void
    {
        $calc = new IndicatorCalculator(EbdConfig::fromConfig());
        $bw = $calc->bandwidth([1.0, 3.0], 2, 2.0); // mean 2, population sigma 1 -> (2+2 - (2-2)) / 2 = 2

        $this->assertNull($bw[0]);
        $this->assertEqualsWithDelta(2.0, $bw[1], 1e-9);
    }

    public function test_volume_baseline_excludes_the_current_candle(): void
    {
        $mean = IndicatorCalculator::priorMean([10, 20, 30, 1000], 3);

        $this->assertNull($mean[2]);
        $this->assertEqualsWithDelta(20.0, $mean[3], 1e-9, 'The spike at #3 is not in its own baseline');
    }

    public function test_bandwidth_percentile_ranks_against_previous_values_only(): void
    {
        $pct = IndicatorCalculator::priorPercentile([5, 4, 3, 2, 1, 6], 4);

        $this->assertNull($pct[3]);
        $this->assertSame(0.0, $pct[4], 'Lowest of the last 4');
        $this->assertSame(100.0, $pct[5]);
    }

    public function test_missing_warm_up_stays_null_never_zero(): void
    {
        $s = (new IndicatorCalculator(EbdConfig::fromConfig()))->series(EbdFixtures::start()->noisy(30, 100, 1)->candles());

        $this->assertNull($s['bbw_pct'][29], 'Needs 100 prior bandwidths');
        $this->assertNull($s['rvol'][5]);
        $this->assertNotNull($s['atr'][29]);
    }

    public function test_close_location_is_directional_and_null_on_a_zero_range_candle(): void
    {
        $this->assertEqualsWithDelta(0.8, IndicatorCalculator::closeLocation(10, 0, 8, 'LONG'), 1e-9);
        $this->assertEqualsWithDelta(0.2, IndicatorCalculator::closeLocation(10, 0, 8, 'SHORT'), 1e-9);
        $this->assertNull(IndicatorCalculator::closeLocation(5, 5, 5, 'LONG'));
        $this->assertEqualsWithDelta(2.0, IndicatorCalculator::bodyAtr(10, 12, 1.0), 1e-9);
        $this->assertNull(IndicatorCalculator::bodyAtr(10, 12, null));
    }

    public function test_volume_pace_compares_the_same_elapsed_minutes_of_past_candles(): void
    {
        // 1m candles: 20 past 5m buckets of 100 per minute, then the forming bucket with 2 closed minutes of 300.
        $barOpen = EbdFixtures::T0;
        $m1 = $this->minutes($barOpen - 20 * 300_000, array_merge(array_fill(0, 100, 100.0), [300.0, 300.0, 50.0]));
        $now = $barOpen + 2 * 60_000 + 30_000; // third minute still forming

        $pace = IndicatorCalculator::volumePace($m1, $barOpen, 300_000, $now);

        $this->assertSame(2, $pace['minutes'], 'Only closed 1m candles count');
        $this->assertEqualsWithDelta(3.0, $pace['pace'], 1e-9, '600 vs a median of 200 for the first 2 minutes');
    }

    public function test_volume_pace_is_unavailable_without_a_closed_minute_or_a_baseline(): void
    {
        $barOpen = EbdFixtures::T0;
        $m1 = $this->minutes($barOpen - 20 * 300_000, array_fill(0, 101, 100.0));

        $this->assertNull(IndicatorCalculator::volumePace($m1, $barOpen, 300_000, $barOpen + 30_000), 'No closed minute yet');

        $thin = $this->minutes($barOpen - 3 * 300_000, array_fill(0, 16, 100.0));
        $this->assertNull(IndicatorCalculator::volumePace($thin, $barOpen, 300_000, $barOpen + 90_000), 'Only 3 past candles');

        $zero = $this->minutes($barOpen - 20 * 300_000, array_merge(array_fill(0, 100, 0.0), [50.0]));
        $this->assertNull(IndicatorCalculator::volumePace($zero, $barOpen, 300_000, $barOpen + 90_000), 'Zero baseline');
    }

    protected function minutes(int $firstOpen, array $volumes): Candles
    {
        $n = count($volumes);
        $opens = array_map(fn (int $i): int => $firstOpen + $i * 60_000, range(0, $n - 1));

        return new Candles(60_000, $opens, array_map(fn (int $t): int => $t + 59_999, $opens), array_fill(0, $n, 1.0), array_fill(0, $n, 1.0),
            array_fill(0, $n, 1.0), array_fill(0, $n, 1.0), $volumes, array_fill(0, $n, null), true);
    }
}
