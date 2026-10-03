<?php

namespace Tests\Unit;

use App\Services\Strategy\ExitPlan;
use PHPUnit\Framework\TestCase;

class ExitPlanTest extends TestCase
{
    protected function plan(): ExitPlan
    {
        return new ExitPlan([
            'tp1_r' => 1.5,
            'tp2_r' => 3.0,
            'tp1_close_ratio' => 0.5,
            'breakeven_at_r' => 1.0,
            'after_tp1_lock_r' => 0.5,
            'trail_atr_mult' => 1.5,
            'time_stop_hours' => 12,
            'time_stop_min_r' => 0.5,
            'max_hold_hours' => 48,
            'fee_rate' => 0.0005,
        ]);
    }

    /**
     * Long from 100 with a stop at 99 (R = 1).
     *
     * @return array<string, mixed>
     */
    protected function longState(array $overrides = []): array
    {
        return array_merge([
            'side' => 'LONG',
            'entry' => 100.0,
            'initial_sl' => 99.0,
            'current_sl' => 99.0,
            'tp1' => 101.5,
            'tp2' => 103.0,
            'tp1_hit' => false,
            'be_locked' => false,
            'highest' => 100.0,
            'lowest' => 100.0,
            'opened_at' => 1_000_000,
        ], $overrides);
    }

    public function test_targets_are_multiples_of_initial_risk(): void
    {
        $targets = $this->plan()->targets('LONG', 100.0, 99.0);

        $this->assertEqualsWithDelta(101.5, $targets['tp1'], 1e-9);
        $this->assertEqualsWithDelta(103.0, $targets['tp2'], 1e-9);

        $short = $this->plan()->targets('SHORT', 100.0, 101.0);
        $this->assertEqualsWithDelta(98.5, $short['tp1'], 1e-9);
        $this->assertEqualsWithDelta(97.0, $short['tp2'], 1e-9);
    }

    public function test_stop_is_checked_before_targets_on_the_same_bar(): void
    {
        $result = $this->plan()->evaluate($this->longState(), 102.0, 98.9, 100.0, 1_000_000 + 3600);

        $this->assertCount(1, $result['events']);
        $this->assertSame('close', $result['events'][0]['type']);
        $this->assertSame('STOP_LOSS', $result['events'][0]['reason']);
        $this->assertSame(99.0, $result['events'][0]['price']);
    }

    public function test_breakeven_plus_fees_at_one_r(): void
    {
        $result = $this->plan()->evaluate($this->longState(), 101.0, 100.5, 101.0, 1_000_000 + 600);

        $this->assertTrue($result['state']['be_locked']);
        $this->assertEqualsWithDelta(100.1, $result['state']['current_sl'], 1e-9, 'Breakeven stop must cover round-trip fees');
        $this->assertFalse($result['state']['tp1_hit']);
    }

    public function test_tp1_books_half_and_locks_half_r(): void
    {
        $result = $this->plan()->evaluate($this->longState(), 101.6, 100.8, 101.5, 1_000_000 + 600);

        $partials = array_values(array_filter($result['events'], fn (array $e): bool => $e['type'] === 'partial'));
        $this->assertCount(1, $partials);
        $this->assertSame(0.5, $partials[0]['ratio']);
        $this->assertSame(101.5, $partials[0]['price']);
        $this->assertTrue($result['state']['tp1_hit']);
        $this->assertEqualsWithDelta(100.5, $result['state']['current_sl'], 1e-9);
    }

    public function test_trailing_only_on_candle_close_and_only_in_profit_direction(): void
    {
        $state = $this->longState(['tp1_hit' => true, 'be_locked' => true, 'current_sl' => 100.5, 'highest' => 102.0]);

        $intrabar = $this->plan()->evaluate($state, 102.5, 101.8, 102.4, 1_000_000 + 7200, atr: 0.4, isBarClose: false);
        $this->assertEqualsWithDelta(100.5, $intrabar['state']['current_sl'], 1e-9, 'No trailing between candle closes');

        $onClose = $this->plan()->evaluate($state, 102.5, 101.8, 102.4, 1_000_000 + 7200, atr: 0.4, isBarClose: true);
        $this->assertEqualsWithDelta(102.5 - 0.6, $onClose['state']['current_sl'], 1e-9);

        $lower = $this->plan()->evaluate($onClose['state'], 101.95, 101.92, 101.93, 1_000_000 + 10800, atr: 2.0, isBarClose: true);
        $this->assertEqualsWithDelta(101.9, $lower['state']['current_sl'], 1e-9, 'A wider ATR must never loosen the stop');
    }

    public function test_time_stop_after_twelve_hours_without_progress(): void
    {
        $result = $this->plan()->evaluate($this->longState(), 100.3, 100.1, 100.2, 1_000_000 + 12 * 3600 + 1);

        $this->assertSame('TIME_STOP', $result['events'][0]['reason']);
    }

    public function test_max_hold_closes_even_profitable_runners(): void
    {
        $state = $this->longState(['tp1_hit' => true, 'be_locked' => true, 'current_sl' => 100.5, 'highest' => 102.0]);
        $result = $this->plan()->evaluate($state, 102.0, 101.5, 101.8, 1_000_000 + 48 * 3600);

        $close = array_values(array_filter($result['events'], fn (array $e): bool => $e['type'] === 'close'));
        $this->assertSame('MAX_HOLD_TIME', $close[0]['reason']);
    }

    public function test_short_side_mirrors_long_rules(): void
    {
        $state = [
            'side' => 'SHORT', 'entry' => 100.0, 'initial_sl' => 101.0, 'current_sl' => 101.0,
            'tp1' => 98.5, 'tp2' => 97.0, 'tp1_hit' => false, 'be_locked' => false,
            'highest' => 100.0, 'lowest' => 100.0, 'opened_at' => 1_000_000,
        ];

        $result = $this->plan()->evaluate($state, 99.2, 98.4, 98.6, 1_000_000 + 600);

        $this->assertTrue($result['state']['tp1_hit']);
        $this->assertEqualsWithDelta(99.5, $result['state']['current_sl'], 1e-9);

        $stopped = $this->plan()->evaluate($result['state'], 99.6, 99.0, 99.4, 1_000_000 + 1200);
        $this->assertSame('TRAILING_STOP', $stopped['events'][0]['reason']);
    }
}
