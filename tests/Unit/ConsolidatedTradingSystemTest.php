<?php

namespace Tests\Unit;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Binance\BinanceFuturesClient;
use App\Services\Trading\ConsolidatedTradingSystem;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ConsolidatedTradingSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_php_cli_resolver_returns_valid_string(): void
    {
        $cli = ConsolidatedTradingSystem::resolvePhpCli();
        $this->assertNotEmpty($cli);
    }

    public function test_position_sizing_enforces_binance_min_notional_and_toxic_blacklist(): void
    {
        $client = Mockery::mock(BinanceFuturesClient::class);
        $engine = new ConsolidatedTradingSystem($client);

        $account = TradingAccount::create([
            'mode' => 'paper',
            'balance' => 4.60,
            'initial_balance' => 5.00,
        ]);

        // 1. Toxic asset must be blocked
        $toxicResult = $engine->calculatePositionSize($account, 'GRAMUSDT', 0.05, 0.048);
        $this->assertFalse($toxicResult['allowed']);
        $this->assertStringContainsString('toxic illiquid blacklist', $toxicResult['reason']);

        // 2. High-volume asset should be sized to ~$5.20 notional with 10x leverage
        $solResult = $engine->calculatePositionSize($account, 'SOLUSDT', 150.0, 147.0);
        $this->assertTrue($solResult['allowed']);
        $this->assertEquals(5.20, $solResult['notional']);
        $this->assertEquals(10, $solResult['leverage']);
        $this->assertEquals(0.52, $solResult['margin']);
    }

    public function test_breakeven_lock_with_fee_buffer_at_one_point_two_percent(): void
    {
        $client = Mockery::mock(BinanceFuturesClient::class);
        $engine = new ConsolidatedTradingSystem($client);

        $trade = Trade::create([
            'symbol' => 'SOLUSDT',
            'side' => 'LONG',
            'mode' => 'paper',
            'status' => 'OPEN',
            'stage' => 'ENTRY',
            'entry_price' => 100.0,
            'quantity' => 0.052,
            'remaining_quantity' => 0.052,
            'margin_used' => 0.52,
            'leverage' => 10,
            'initial_sl' => 98.5,
            'current_sl' => 98.5,
            'tp1_price' => 101.8,
            'tp2_price' => 103.2,
            'be_locked' => false,
            'tp1_hit' => false,
            'tp2_hit' => false,
            'opened_at' => Carbon::now(),
        ]);

        // Price moves up to 101.25 (+1.25% gain)
        $engine->manageActiveTrade($trade, 101.25);
        $trade->refresh();

        $this->assertTrue($trade->be_locked);
        $this->assertEquals('BE_LOCKED', $trade->stage);
        // Fee buffer is +0.25%, so SL must be 100.25
        $this->assertEquals(100.25, $trade->current_sl);
    }

    public function test_tp1_and_tp2_partial_harvesting_and_runner_trailing(): void
    {
        $client = Mockery::mock(BinanceFuturesClient::class);
        $client->shouldReceive('formatQuantity')
            ->andReturnUsing(function ($symbol, $qty) {
                return round($qty, 3);
            });

        $engine = new ConsolidatedTradingSystem($client);

        $trade = Trade::create([
            'symbol' => 'LINKUSDT',
            'side' => 'SHORT',
            'mode' => 'paper',
            'status' => 'OPEN',
            'stage' => 'ENTRY',
            'entry_price' => 10.00,
            'quantity' => 0.52,
            'remaining_quantity' => 0.52,
            'margin_used' => 0.52,
            'leverage' => 10,
            'initial_sl' => 10.15,
            'current_sl' => 10.15,
            'tp1_price' => 9.82,  // +1.8% drop
            'tp2_price' => 9.68,  // +3.2% drop
            'be_locked' => false,
            'tp1_hit' => false,
            'tp2_hit' => false,
            'opened_at' => Carbon::now(),
        ]);

        // 1. Move price to 9.80 (TP1 hit)
        $engine->manageActiveTrade($trade, 9.80);
        $trade->refresh();

        $this->assertTrue($trade->tp1_hit);
        $this->assertEquals('TP1_HIT', $trade->stage);
        $this->assertLessThan(0.52, $trade->remaining_quantity);
        $this->assertGreaterThan(0.0, $trade->realized_pnl);
        // SL ratcheted to +0.60% profit on short = 10.00 * (1 - 0.006) = 9.94
        $this->assertEquals(9.94, $trade->current_sl);

        // 2. Move price to 9.65 (TP2 hit)
        $engine->manageActiveTrade($trade, 9.65);
        $trade->refresh();

        $this->assertTrue($trade->tp2_hit);
        $this->assertEquals('TRAILING', $trade->stage);
        // SL ratcheted to +2.00% profit on short = 10.00 * (1 - 0.02) = 9.80
        $this->assertEquals(9.80, $trade->current_sl);

        // 3. Move price to 9.40 (Deep runner drop)
        $engine->manageActiveTrade($trade, 9.40);
        $trade->refresh();

        $this->assertEquals('TRAILING', $trade->stage);
        // Trailing stop candidate = 9.40 + (10.00 * 0.015) = 9.55 (< 9.80)
        $this->assertLessThanOrEqual(9.55, $trade->current_sl);
    }
}
