<?php

namespace Tests\Unit\Breakout;

use App\Services\Breakout\BreakoutTriggerDetector;
use App\Services\Breakout\Candles;
use App\Services\Breakout\ConsolidationDetector;
use App\Services\Breakout\EbdConfig;
use App\Services\Breakout\IndicatorCalculator;
use InvalidArgumentException;
use Tests\Support\EbdFixtures;
use Tests\TestCase;

/**
 * Stage A (compression), Stage B/C triggers, data quality and configuration validation.
 */
class DetectionTest extends TestCase
{
    protected function parts(array $overrides = []): array
    {
        $config = EbdConfig::fromConfig($overrides);

        return [new IndicatorCalculator($config), new ConsolidationDetector($config), new BreakoutTriggerDetector($config)];
    }

    public function test_a_tight_range_after_normal_volatility_is_detected_with_buffered_triggers(): void
    {
        [$ind, $det] = $this->parts();
        $c = EbdFixtures::coiled()->candles();
        $found = $det->detect($c, $ind->series($c), $c->lastIndex());

        $this->assertTrue($found['ok'], (string) $found['reason']);
        $r = $found['range'];
        $this->assertSame(max(array_slice($c->highs, -20)), $r['resistance']);
        $this->assertSame(min(array_slice($c->lows, -20)), $r['support']);
        $this->assertLessThanOrEqual(4.0, $r['range_ratio']);
        $this->assertLessThanOrEqual(30, $r['bbw_pct']);
        $this->assertEqualsWithDelta($r['resistance'] + 0.05 * $r['atr'], $r['long_trigger'], 1e-12);
        $this->assertEqualsWithDelta($r['support'] - 0.05 * $r['atr'], $r['short_trigger'], 1e-12);
        $this->assertEqualsWithDelta($r['resistance'] + 0.10 * $r['atr'], $r['long_confirm'], 1e-12);
        $this->assertEqualsWithDelta($r['support'] - 0.10 * $r['atr'], $r['short_confirm'], 1e-12);
    }

    public function test_normal_volatility_and_short_history_are_not_compression(): void
    {
        [$ind, $det] = $this->parts();
        $wide = EbdFixtures::start()->noisy(150, 100, 0.5)->noisy(30, 100, 2.0)->candles(); // volatility expanding
        $this->assertFalse($det->detect($wide, $ind->series($wide), $wide->lastIndex())['ok']);

        $short = EbdFixtures::start()->noisy(60, 100, 0.06)->candles();
        $this->assertSame('indicator warm-up incomplete', $det->detect($short, $ind->series($short), $short->lastIndex())['reason']);

        [$ind, $det] = $this->parts(['compression.max_range_atr' => 0.5]);
        $c = EbdFixtures::coiled()->candles();
        $this->assertStringContainsString('range', (string) $det->detect($c, $ind->series($c), $c->lastIndex())['reason']);
    }

    public function test_the_range_ignores_later_candles_no_look_ahead(): void
    {
        [$ind, $det] = $this->parts();
        $before = EbdFixtures::coiled();
        $c1 = $before->candles();
        $k = $c1->lastIndex();
        $first = $det->detect($c1, $ind->series($c1), $k);

        $c2 = $before->to(103.0, 9000)->to(97.0, 9000)->candles(EbdFixtures::T0 - 1 + 2 * EbdFixtures::BAR_MS);
        $second = $det->detect($c2, $ind->series($c2), $k);

        $this->assertEquals($first['range'], $second['range'], 'Appending candles after k must not change the range seen at k');
    }

    public function test_long_and_short_early_triggers_need_a_buffered_cross_and_volume_pace(): void
    {
        [$ind, $det, $trg] = $this->parts();
        $c = EbdFixtures::coiled()->candles();
        $r = $det->detect($c, $ind->series($c), $c->lastIndex())['range'];

        $this->assertNull($trg->early($r, $r['resistance'] + 0.01 * $r['atr'], 3.0, 2.0)['direction'], 'Above R but inside the 0.05 ATR buffer');

        $long = $trg->early($r, $r['long_trigger'] + 0.1 * $r['atr'], 2.0, 2.0);
        $this->assertTrue($long['ok']);
        $this->assertSame('LONG', $long['direction']);

        $short = $trg->early($r, $r['short_trigger'] - 0.1 * $r['atr'], 2.0, 2.0);
        $this->assertTrue($short['ok']);
        $this->assertSame('SHORT', $short['direction']);

        $lowVolume = $trg->early($r, $r['long_trigger'] + 0.1 * $r['atr'], 1.2, 2.0);
        $this->assertFalse($lowVolume['ok']);
        $noPace = $trg->early($r, $r['long_trigger'] + 0.1 * $r['atr'], null, 2.0);
        $this->assertFalse($noPace['ok'], 'Unavailable volume pace is not treated as satisfied');
        $this->assertContains('volume pace unavailable', $noPace['reasons']);
        $wideSpread = $trg->early($r, $r['long_trigger'] + 0.1 * $r['atr'], 2.0, 40.0);
        $this->assertFalse($wideSpread['ok']);

        $late = $trg->early($r, $r['resistance'] + 1.6 * $r['atr'], 3.0, 2.0);
        $this->assertTrue($late['extended']);
        $this->assertFalse($late['ok'], 'More than 1.5 ATR past R is never an entry');
    }

    public function test_confirmation_on_the_closed_candle_long_and_short(): void
    {
        [$ind, $det, $trg] = $this->parts();
        foreach (['pepeLike' => 'LONG', 'irysLike' => 'LONG', 'arkLikeShort' => 'SHORT'] as $fixture => $direction) {
            $c = EbdFixtures::$fixture()->candles();
            $s = $ind->series($c);
            $n = $c->lastIndex();
            $range = $det->detect($c, $s, $n - 1)['range'];
            $confirm = $trg->confirm($c, $s, $n, $range, $direction);

            $this->assertTrue($confirm['ok'], $fixture.': '.implode('; ', $confirm['reasons']));
            $this->assertGreaterThanOrEqual(1.8, $confirm['metrics']['rvol']);
            $this->assertGreaterThanOrEqual(0.75, $confirm['metrics']['close_location']);
        }
    }

    public function test_low_volume_weak_candle_and_extended_closes_are_not_confirmed(): void
    {
        [$ind, $det, $trg] = $this->parts();
        $build = fn (array $bar): array => (function () use ($bar, $ind, $det): array {
            $f = EbdFixtures::start(100)->noisy(150, 100, 1.0)->noisy(40, 100, 0.06)->bar(...$bar);
            $c = $f->candles();
            $s = $ind->series($c);

            return [$c, $s, $det->detect($c, $s, $c->lastIndex() - 1)['range']];
        })();

        [$c, $s, $r] = $build([100.02, 100.225, 100.0, 100.21, 1100]);
        $this->assertStringContainsString('RVOL', implode(';', $trg->confirm($c, $s, $c->lastIndex(), $r, 'LONG')['reasons']));

        [$c, $s, $r] = $build([100.02, 100.40, 100.0, 100.21, 3200]); // long upper wick: close location ~0.48
        $this->assertStringContainsString('close location', implode(';', $trg->confirm($c, $s, $c->lastIndex(), $r, 'LONG')['reasons']));

        [$c, $s, $r] = $build([100.02, 100.62, 100.0, 100.58, 3200]);
        $this->assertStringContainsString('extended', implode(';', $trg->confirm($c, $s, $c->lastIndex(), $r, 'LONG')['reasons']));
    }

    public function test_bad_candle_data_is_reported(): void
    {
        $c = EbdFixtures::coiled()->candles();
        $this->assertSame([], $c->problems());

        $gap = $c->slice(0, 100);
        $times = $gap->openTimes;
        $times[50] += 300_000;
        $broken = new Candles($gap->intervalMs, $times, $gap->closeTimes, $gap->opens, $gap->highs, $gap->lows, $gap->closes, $gap->volumes, $gap->trades, true);
        $this->assertNotSame([], $broken->problems());

        $lows = $gap->lows;
        $lows[10] = $gap->highs[10] + 1;
        $bad = new Candles($gap->intervalMs, $gap->openTimes, $gap->closeTimes, $gap->opens, $gap->highs, $lows, $gap->closes, $gap->volumes, $gap->trades, true);
        $this->assertStringContainsString('inconsistent', implode(';', $bad->problems()));

        $this->assertSame(['no candles'], $c->slice(0, 0)->problems());
    }

    public function test_the_forming_candle_is_dropped(): void
    {
        $c = EbdFixtures::coiled()->candles(EbdFixtures::T0 + EbdFixtures::BAR_MS - 1); // last candle closes in the future

        $this->assertSame($c->count() - 1, $c->closedAt(EbdFixtures::T0 + 60_000)->count());
    }

    public function test_invalid_configuration_is_rejected_with_the_key(): void
    {
        EbdConfig::fromConfig()->validate();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('compression.bbw_percentile');
        EbdConfig::fromConfig(['compression.bbw_percentile' => 150])->validate();
    }

    public function test_confirm_buffer_below_the_early_buffer_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EbdConfig::fromConfig(['confirm.buffer_atr' => 0.01])->validate();
    }
}
