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

        // Price moves up to 101.35 (+1.35% gain, exceeding 1.30% threshold)
        $manager->manageTrade($trade, 101.35);
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

    public function test_early_breakeven_locked_at_point_four_five_gain(): void
    {
        config([
            'trading.management.be_gain_pct' => 0.45,
            'trading.management.be_roe_threshold' => 4.5,
        ]);

        $manager = app(DynamicTradeManager::class);

        TradingAccount::create([
            'mode' => 'paper',
            'balance' => 5.0,
            'initial_balance' => 5.0,
        ]);

        $trade = Trade::create([
            'symbol' => 'SUIUSDT',
            'side' => 'LONG',
            'mode' => 'paper',
            'status' => 'OPEN',
            'stage' => 'ENTRY',
            'entry_price' => 2.0,
            'quantity' => 25.0,
            'remaining_quantity' => 25.0,
            'margin_used' => 5.0,
            'leverage' => 10,
            'initial_sl' => 1.98,
            'current_sl' => 1.98,
            'tp1_price' => 2.02,
            'tp2_price' => 2.04,
            'be_locked' => false,
            'tp1_hit' => false,
            'tp2_hit' => false,
            'opened_at' => Carbon::now(),
        ]);

        // Price moves up by +0.50% to 2.01 (exceeds 0.45% / 4.5% ROE threshold)
        $manager->manageTrade($trade, 2.01);
        $trade->refresh();

        $this->assertTrue($trade->be_locked);
        $this->assertTrue($trade->isProtected());
        $this->assertGreaterThan(2.0, $trade->current_sl);
    }

    public function test_stagnation_timeout_closes_trade_after_hard_timeout(): void
    {
        config(['trading.management.max_hold_minutes' => 90]);

        $manager = app(DynamicTradeManager::class);

        TradingAccount::create([
            'mode' => 'paper',
            'balance' => 5.0,
            'initial_balance' => 5.0,
        ]);

        $trade = Trade::create([
            'symbol' => 'DOGEUSDT',
            'side' => 'LONG',
            'mode' => 'paper',
            'status' => 'OPEN',
            'stage' => 'ENTRY',
            'entry_price' => 0.15,
            'quantity' => 300.0,
            'remaining_quantity' => 300.0,
            'margin_used' => 4.5,
            'leverage' => 10,
            'initial_sl' => 0.147,
            'current_sl' => 0.147,
            'tp1_price' => 0.155,
            'tp2_price' => 0.160,
            'be_locked' => false,
            'tp1_hit' => false,
            'tp2_hit' => false,
            'opened_at' => Carbon::now()->subMinutes(95), // 95 minutes old
        ]);

        // Price is slightly flat at 0.1498 (below entry, but above SL)
        $manager->manageTrade($trade, 0.1498);
        $trade->refresh();

        $this->assertEquals('CLOSED', $trade->status);
        $this->assertEquals('STAGNATION_TIMEOUT_EXIT', $trade->exit_reason);
    }

    public function test_peak_profit_reversal_clawback_protection_locks_green_profit(): void
    {
        config([
            'trading.management.peak_profit_min_gain_pct' => 0.50,
            'trading.management.peak_profit_giveback_pct' => 35.0,
        ]);

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

        // 1. Price rallies to 100.70 (+0.70% peak gain)
        $manager->manageTrade($trade, 100.70);
        $trade->refresh();
        $this->assertEquals(100.70, $trade->highest_price);

        // 2. Price retraces to 100.40 (surrendering > 35% of the +0.70% peak gain)
        // Anti-giveback circuit must close trade immediately with PEAK_PROFIT_PROTECTION
        $manager->manageTrade($trade, 100.40);
        $trade->refresh();

        $this->assertEquals('CLOSED', $trade->status);
        $this->assertEquals('PEAK_PROFIT_PROTECTION', $trade->exit_reason);
        $this->assertGreaterThan(0.0, $trade->realized_pnl, 'Must close with positive green profit in the bank');
    }

    public function test_stepped_ratchet_moves_stop_loss_at_point_nine_percent_gain(): void
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

        // Price rises to 100.95 (+0.95% gain, which exceeds Tier 2 ratchet threshold of 0.90%)
        $manager->manageTrade($trade, 100.95);
        $trade->refresh();

        $this->assertGreaterThanOrEqual(100.45, $trade->current_sl, 'SL must be ratcheted to lock in +0.45% profit');
    }
}
