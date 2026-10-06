<?php

namespace Tests\Unit;

use App\Services\Strategy\StrategyEngine;
use ReflectionMethod;
use Tests\TestCase;

class SetupFilterRulesTest extends TestCase
{
    /**
     * @return array<string, array{pass: bool, detail: string}>
     */
    protected function filters(string $setup, float $atrPct, array $context): array
    {
        $engine = new StrategyEngine([
            'max_volume_rank_by_setup' => ['SQUEEZE_BREAKOUT' => 10],
            'min_atr_pct_by_setup' => ['TREND_PULLBACK' => 1.06],
        ]);
        $method = new ReflectionMethod($engine, 'evaluateFilters');

        return $method->invoke($engine, 'LONG', ['side' => 'LONG', 'adx' => 25.0, 'detail' => 'up'], ['block_long' => false, 'block_short' => false, 'state' => 'ok', 'returns_24' => 0.0], $atrPct, 1.0, $context, $setup);
    }

    public function test_breakouts_only_pass_on_the_most_traded_coins(): void
    {
        $this->assertTrue($this->filters('SQUEEZE_BREAKOUT', 1.0, ['volume_rank' => 4])['volume_rank']['pass']);
        $this->assertFalse($this->filters('SQUEEZE_BREAKOUT', 1.0, ['volume_rank' => 23])['volume_rank']['pass']);
        $this->assertArrayNotHasKey('volume_rank', $this->filters('TREND_PULLBACK', 1.2, ['volume_rank' => 23]), 'Pullbacks are not limited by rank');
        $this->assertArrayNotHasKey('volume_rank', $this->filters('SQUEEZE_BREAKOUT', 1.0, []), 'Unknown rank never blocks');
    }

    public function test_pullbacks_need_enough_volatility(): void
    {
        $this->assertFalse($this->filters('TREND_PULLBACK', 0.8, [])['volatility']['pass']);
        $this->assertTrue($this->filters('TREND_PULLBACK', 1.2, [])['volatility']['pass']);
        $this->assertTrue($this->filters('SQUEEZE_BREAKOUT', 0.8, [])['volatility']['pass'], 'Other setups keep the normal band');
    }
}
