<?php

namespace Tests\Unit;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Trading\DynamicTradeManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DynamicTradeManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_breakeven_lock_triggered_at_one_percent_gain(): void
    {
        $manager = app(DynamicTradeManager::class);

        TradingAccount::create([
            'mode' => 'paper',
            'balance' => 5.0,
            'initial_balance' => 5.0,
        ]);

        $trade = Trade::create([
            'symbol' => 'SOLUSDT',
            'side' => 'LONG',
            'mode' => 'paper',
            'status' => 'OPEN',
            'stage' => 'ENTRY',
            'entry_price' => 100.0,
            'quantity' => 0.5,
            'remaining_quantity' => 0.5,
            'margin_used' => 5.0,
            'leverage' => 10,
            'initial_sl' => 98.5,
            'current_sl' => 98.5,
            'tp1_price' => 102.0,
            'tp2_price' => 104.0,
            'be_locked' => false,
            'tp1_hit' => false,
            'tp2_hit' => false,
            'opened_at' => Carbon::now(),
        ]);

        // Price moves up to 101.2 (+1.2% gain, exceeding 1.0% threshold)
        $manager->manageTrade($trade, 101.2);
        $trade->refresh();

        $this->assertTrue($trade->be_locked);
        $this->assertGreaterThan(100.0, $trade->current_sl, 'SL must be moved above entry price to cover round-trip taker fees');
        $this->assertEquals('BE_LOCKED', $trade->stage);
    }

    public function test_tp1_partial_profit_booked(): void
    {
        $manager = app(DynamicTradeManager::class);

        TradingAccount::create([
            'mode' => 'paper',
            'balance' => 5.0,
            'initial_balance' => 5.0,
        ]);

        $trade = Trade::create([
            'symbol' => 'SOLUSDT',
            'side' => 'LONG',
            'mode' => 'paper',
            'status' => 'OPEN',
            'stage' => 'ENTRY',
            'entry_price' => 100.0,
            'quantity' => 1.0,
            'remaining_quantity' => 1.0,
            'margin_used' => 5.0,
            'leverage' => 10,
            'initial_sl' => 98.5,
            'current_sl' => 98.5,
            'tp1_price' => 102.0,
            'tp2_price' => 104.0,
            'be_locked' => false,
            'tp1_hit' => false,
            'tp2_hit' => false,
            'opened_at' => Carbon::now(),
        ]);

        // Price reaches TP1 at 102.1
        $manager->manageTrade($trade, 102.1);
        $trade->refresh();

        $this->assertTrue($trade->tp1_hit);
        $this->assertGreaterThan(0.0, $trade->realized_pnl, 'Realized partial PnL must be recorded');
        $this->assertLessThan(1.0, $trade->remaining_quantity, 'Remaining quantity must be reduced by 33% partial close');
    }

    public function test_stop_loss_execution(): void
    {
        $manager = app(DynamicTradeManager::class);

        TradingAccount::create([
            'mode' => 'paper',
            'balance' => 5.0,
            'initial_balance' => 5.0,
        ]);

        $trade = Trade::create([
            'symbol' => 'SOLUSDT',
            'side' => 'LONG',
            'mode' => 'paper',
            'status' => 'OPEN',
            'stage' => 'ENTRY',
            'entry_price' => 100.0,
            'quantity' => 0.5,
            'remaining_quantity' => 0.5,
            'margin_used' => 5.0,
            'leverage' => 10,
            'initial_sl' => 98.5,
            'current_sl' => 98.5,
            'tp1_price' => 102.0,
            'tp2_price' => 104.0,
            'be_locked' => false,
            'tp1_hit' => false,
            'tp2_hit' => false,
            'opened_at' => Carbon::now(),
        ]);

        // Price crashes to 98.0 (below current SL 98.5)
        $manager->manageTrade($trade, 98.0);
        $trade->refresh();

        $this->assertEquals('CLOSED', $trade->status);
        $this->assertEquals(0.0, $trade->remaining_quantity);
        $this->assertNotNull($trade->closed_at);
    }
}
