<?php

namespace Tests\Unit\Breakout;

use App\Services\Breakout\BreakoutQualityScorer;
use App\Services\Breakout\BreakoutTriggerDetector;
use App\Services\Breakout\Candles;
use App\Services\Breakout\ConsolidationDetector;
use App\Services\Breakout\EbdConfig;
use App\Services\Breakout\IndicatorCalculator;
use App\Services\Breakout\SignalLifecycleService as L;
use App\Services\Breakout\SignalRiskCalculator;
use Tests\Support\EbdFixtures;
use Tests\TestCase;

class LifecycleTest extends TestCase
{
    protected EbdConfig $config;

    protected L $lifecycle;

    protected IndicatorCalculator $ind;

    /** Score context: a liquid coin with the 1h trend and 15m chart agreeing with a long. */
    protected array $context = ['spread_bps' => 1.5, 'quote_volume_24h' => 300_000_000, 'funding_rate' => 0.0001,
        'h1' => ['close' => 101, 'ema' => 100, 'ema_slope' => 0.2], 'm15_compressed' => true];

    protected function setUp(): void
    {
        parent::setUp();
        // Net R:R gate eased here: these tests are about state transitions, risk has its own tests.
        $this->config = EbdConfig::fromConfig(['risk.min_net_rr' => 0.3]);
        $this->ind = new IndicatorCalculator($this->config);
        $this->lifecycle = new L($this->config, new BreakoutTriggerDetector($this->config), new BreakoutQualityScorer($this->config), new SignalRiskCalculator($this->config));
    }

    /** A COMPRESSION_DETECTED setup from the coiled fixture (range closes at T0 - 1). */
    protected function coiledSetup(?EbdFixtures $f = null): array
    {
        $c = ($f ?? EbdFixtures::coiled())->candles();
        $found = (new ConsolidationDetector($this->config))->detect($c, $this->ind->series($c), $c->lastIndex());
        $this->assertTrue($found['ok']);

        return $this->lifecycle->open('TESTUSDT', $found['range']);
    }

    /** Coiled fixture plus extra candles (bars after T0 - 1). */
    protected function after(array $bars, ?EbdFixtures $f = null): Candles
    {
        $f ??= EbdFixtures::coiled();
        foreach ($bars as $bar) {
            $f->bar(...$bar);
        }

        return $f->candles(EbdFixtures::T0 - 1 + count($bars) * EbdFixtures::BAR_MS);
    }

    /** Runs every candle after the setup's state time through the state machine. */
    protected function play(array $setup, Candles $c, array $context = []): array
    {
        $s = $this->ind->series($c);
        $events = [];
        foreach ($c->closeTimes as $i => $t) {
            if ($t > $setup['state_since_ms'] && ! L::isTerminal($setup['state'])) {
                [$setup, $new] = $this->lifecycle->onClosedBar($setup, $c, $s, $i, $context ?: $this->context);
                $events = [...$events, ...$new];
            }
        }

        return [$setup, $events];
    }

    protected function tick(array $setup, float $price, ?float $pace = 2.0, int $seconds = 150): array
    {
        return $this->lifecycle->onTick($setup, [
            'price' => $price, 'now_ms' => EbdFixtures::T0 + $seconds * 1000, 'bar_open_ms' => EbdFixtures::T0, 'bar_ms' => EbdFixtures::BAR_MS,
            'pace' => $pace, 'pace_minutes' => 2, 'spread_bps' => 1.5, 'funding_rate' => 0.0001,
            'forming' => ['open' => 100.0, 'high' => $price, 'low' => 99.97],
            'structure' => ['lows' => [99.93, 99.95], 'highs' => [100.08, 100.1]],
            'rsi' => 56.0, 'rsi_prev' => 52.0, 'ema_fast' => 100.02, 'ema_slow' => 100.0, 'context' => $this->context,
        ]);
    }

    public function test_live_early_alert_then_confirmation_on_the_close_share_one_setup(): void
    {
        $setup = $this->coiledSetup();
        $price = $setup['long_trigger'] + 0.1 * $setup['atr'];

        [$early, $events] = $this->tick($setup, $price);
        $this->assertSame(L::EARLY, $early['state']);
        $this->assertSame('EARLY', $events[0]['type']);
        $this->assertTrue($events[0]['notify']);
        $this->assertSame('live', $early['detection_mode']);
        $this->assertSame(150, $early['detection_latency_s'], 'Seconds since the breakout candle opened');
        $this->assertGreaterThanOrEqual(60, $early['early_score']);

        [$again, $none] = $this->tick($early, $price + 0.01);
        $this->assertSame([], $none, 'An early alert is never repeated for the same setup');

        [$confirmed, $events] = $this->play($early, $this->after([[100.02, 100.225, 100.0, 100.21, 3200]]));
        $this->assertSame(L::CONFIRMED, $confirmed['state']);
        $this->assertSame('CONFIRMED', $events[0]['type']);
        $this->assertSame($setup['setup_key'], $confirmed['setup_key'], 'Early and confirmed share the setup id');
        $this->assertNotNull($confirmed['confirmed_score']);
        $this->assertSame($early['early_score'], $confirmed['early_score'], 'Both scores are kept');
        $this->assertSame(EbdFixtures::T0 - 1 + EbdFixtures::BAR_MS, $confirmed['confirmed_ms'], 'Confirmed at the real close time, not the crossing time');
    }

    public function test_short_break_confirmed_on_the_close_without_a_live_tick(): void
    {
        $setup = $this->coiledSetup();
        [$s, $events] = $this->play($setup, $this->after([[99.98, 100.0, 99.64, 99.65, 4000]]), ['spread_bps' => 1.5]);

        $this->assertSame(L::CONFIRMED, $s['state']);
        $this->assertSame('SHORT', $s['direction']);
        $this->assertSame('candle_close', $s['detection_mode'], 'No intrabar data: detection latency is marked, the crossing time is not invented');
        $this->assertSame(300, $s['detection_latency_s']);
        $this->assertGreaterThan($s['entry'], $s['sl']);
        $this->assertLessThan($s['entry'], $s['tp1']);
    }

    public function test_price_inside_the_buffer_or_a_missing_pace_never_alerts(): void
    {
        $setup = $this->coiledSetup();

        $this->assertSame([], $this->tick($setup, $setup['resistance'] + 0.01 * $setup['atr'])[1]);
        [$still, $events] = $this->tick($setup, $setup['long_trigger'] + 0.1 * $setup['atr'], null);
        $this->assertSame([], $events);
        $this->assertSame(L::COMPRESSION, $still['state']);
        $this->assertSame([], $this->tick($setup, $setup['long_trigger'] + 0.1 * $setup['atr'], 1.1)[1], 'Low volume pace');
    }

    public function test_a_late_first_sighting_is_extended_not_an_entry(): void
    {
        $setup = $this->coiledSetup();
        [$s, $events] = $this->tick($setup, $setup['resistance'] + 2.0 * $setup['atr']);

        $this->assertSame(L::EXTENDED, $s['state']);
        $this->assertSame('EXTENDED', $events[0]['type']);
        $this->assertFalse($events[0]['notify'], 'Nothing was alerted before, so no BUY NOW and no message');
        $this->assertArrayNotHasKey('entry', $s);
    }

    public function test_a_one_candle_close_far_past_the_box_is_extended(): void
    {
        $setup = $this->coiledSetup();
        [$s] = $this->play($setup, $this->after([[100.02, 100.62, 100.0, 100.58, 3200]]));

        $this->assertSame(L::EXTENDED, $s['state']);
    }

    public function test_two_closes_back_inside_the_range_fail_the_breakout(): void
    {
        [$early] = $this->tick($this->coiledSetup(), $this->coiledSetup()['long_trigger'] + 0.1 * $this->coiledSetup()['atr']);
        [$s, $events] = $this->play($early, $this->after([[100.05, 100.15, 100.0, 100.08, 1200], [100.08, 100.1, 100.0, 100.02, 900]]));

        $this->assertSame(L::FAILED, $s['state']);
        $this->assertSame('FAILED', $events[0]['type']);
        $this->assertTrue($events[0]['notify'], 'An alerted setup announces its failure');
        $this->assertStringContainsString('inside the range for 2', $s['cancel_reason']);
    }

    public function test_a_close_beyond_the_opposite_edge_fails_at_once(): void
    {
        [$early] = $this->tick($this->coiledSetup(), $this->coiledSetup()['long_trigger'] + 0.1 * $this->coiledSetup()['atr']);
        [$s] = $this->play($early, $this->after([[100.05, 100.1, 99.6, 99.7, 1500]]));

        $this->assertSame(L::FAILED, $s['state']);
        $this->assertStringContainsString('opposite', $s['cancel_reason']);
    }

    public function test_an_unbroken_range_expires_after_12_candles_silently(): void
    {
        $f = EbdFixtures::coiled();
        $setup = $this->coiledSetup();
        [$s, $events] = $this->play($setup, $f->noisy(12, 100, 0.03)->candles(EbdFixtures::T0 - 1 + 12 * EbdFixtures::BAR_MS));

        $this->assertSame(L::EXPIRED, $s['state']);
        $this->assertFalse($events[0]['notify']);
        $this->assertSame(EbdFixtures::T0 - 1 + 12 * EbdFixtures::BAR_MS, $s['expired_ms']);
    }

    public function test_an_early_alert_not_confirmed_in_3_candles_expires_with_a_message(): void
    {
        [$early] = $this->tick($this->coiledSetup(), $this->coiledSetup()['long_trigger'] + 0.1 * $this->coiledSetup()['atr']);
        $r = $early['resistance'];
        $hover = [$r + 0.001, $r + 0.02, $r - 0.01, $r + 0.005, 800];
        [$s, $events] = $this->play($early, $this->after([$hover, $hover, $hover]));

        $this->assertSame(L::EXPIRED, $s['state']);
        $this->assertTrue($events[0]['notify']);
        $this->assertStringContainsString('not confirmed', $events[0]['reason']);
    }

    public function test_a_retest_that_holds_the_broken_level_is_a_separate_signal(): void
    {
        [$confirmed] = $this->play($this->coiledSetup(), $this->after([[100.02, 100.225, 100.0, 100.21, 3200]]));
        $this->assertSame(L::CONFIRMED, $confirmed['state']);
        $r = $confirmed['resistance'];

        [$s, $events] = $this->play($confirmed, $this->after([[100.02, 100.225, 100.0, 100.21, 3200], [100.2, 100.27, $r + 0.01, 100.26, 1500]]));

        $this->assertSame(L::RETEST_CONFIRMED, $s['state']);
        $this->assertSame('RETEST', $events[0]['type']);
        $this->assertNotNull($s['retest_ms']);
    }

    public function test_the_stop_level_trading_fails_a_confirmed_breakout(): void
    {
        [$confirmed] = $this->play($this->coiledSetup(), $this->after([[100.02, 100.225, 100.0, 100.21, 3200]]));
        $this->assertTrue($confirmed['entry_valid'], (string) $confirmed['cancel_reason']);

        [$s] = $this->play($confirmed, $this->after([[100.02, 100.225, 100.0, 100.21, 3200], [100.2, 100.21, $confirmed['sl'] - 0.01, 100.12, 1500]]));

        $this->assertSame(L::FAILED, $s['state']);
        $this->assertSame('stop hit', $s['cancel_reason']);
    }

    public function test_a_processed_candle_is_never_evaluated_twice(): void
    {
        $c = $this->after([[99.98, 100.0, 99.64, 99.65, 4000]]);
        [$s] = $this->play($this->coiledSetup(), $c);

        [$again, $events] = $this->lifecycle->onClosedBar($s, $c, $this->ind->series($c), $c->lastIndex(), $this->context);
        $this->assertSame([], $events);
        $this->assertSame($s, $again);
    }

    public function test_results_at_a_candle_do_not_depend_on_later_candles(): void
    {
        $setup = $this->coiledSetup();
        $bar = [100.02, 100.225, 100.0, 100.21, 3200];
        $known = $this->after([$bar]);
        $withFuture = $this->after([$bar, [100.2, 104, 100.1, 103.5, 9000], [103.5, 103.6, 95, 96, 9000]]);
        $i = $known->lastIndex();

        $a = $this->lifecycle->onClosedBar($setup, $known, $this->ind->series($known), $i, $this->context);
        $b = $this->lifecycle->onClosedBar($setup, $withFuture, $this->ind->series($withFuture), $i, $this->context);

        $this->assertEquals($a, $b, 'Look-ahead: candles after i must not change the decision at i');
    }

    public function test_reference_shaped_fixtures_confirm_in_the_right_direction(): void
    {
        foreach (['pepeLike' => 'LONG', 'irysLike' => 'LONG', 'arkLikeShort' => 'SHORT'] as $fixture => $direction) {
            $c = EbdFixtures::$fixture()->candles();
            $s = $this->ind->series($c);
            $range = (new ConsolidationDetector($this->config))->detect($c, $s, $c->lastIndex() - 1)['range'];
            [$setup, $events] = $this->lifecycle->onClosedBar($this->lifecycle->open('X', $range), $c, $s, $c->lastIndex(), $this->context);

            $this->assertSame(L::CONFIRMED, $setup['state'], $fixture);
            $this->assertSame($direction, $setup['direction'], $fixture);
            $this->assertSame('CONFIRMED', $events[0]['type']);
        }
    }
}
