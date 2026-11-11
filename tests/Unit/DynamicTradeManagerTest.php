<?php

namespace Tests\Unit;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Trading\DynamicTradeManager;
use App\Services\Trading\ExchangeOrders;
use App\Services\Trading\NearBreakoutTrader;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class DynamicTradeManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    /**
     * SOL long from 100 with the stop at 99 (R = 1).
     */
    protected function trade(string $mode = 'paper', array $overrides = []): Trade
    {
        TradingAccount::firstOrCreate(['mode' => $mode], ['balance' => 100.0, 'initial_balance' => 100.0, 'equity' => 100.0, 'peak_equity' => 100.0]);

        return Trade::create(array_merge([
            'symbol' => 'SOLUSDT', 'side' => 'LONG', 'mode' => $mode, 'status' => 'OPEN', 'stage' => 'ENTRY',
            'entry_price' => 100.0, 'quantity' => 0.5, 'remaining_quantity' => 0.5, 'margin_used' => 10.0, 'leverage' => 5,
            'initial_sl' => 99.0, 'current_sl' => 99.0, 'tp1_price' => 101.5, 'tp2_price' => 103.0,
            'highest_price' => 100.0, 'lowest_price' => 100.0, 'meta' => ['interval' => '1h'],
            'opened_at' => Carbon::now(),
        ], $overrides));
    }

    public function test_a_trade_from_a_removed_strategy_keeps_only_its_stop(): void
    {
        $trade = $this->trade();

        app(DynamicTradeManager::class)->manageTrade($trade, 103.5);
        $trade->refresh();

        $this->assertSame('OPEN', $trade->status, 'No take-profit or time stop any more');
        $this->assertEqualsWithDelta(99.0, $trade->current_sl, 1e-9);
        $this->assertEqualsWithDelta(103.5, $trade->highest_price, 1e-9);
    }

    public function test_near_trail_arms_at_entry_plus_d_and_exits_d_below_the_high(): void
    {
        $trade = $this->trade(overrides: ['symbol' => 'NEARUSDT', 'setup_tag' => NearBreakoutTrader::SETUP, 'meta' => ['interval' => '1h', 'trail_d' => 0.5]]);
        $manager = app(DynamicTradeManager::class);

        $manager->manageTrade($trade, 100.4);
        $this->assertEmpty($trade->refresh()->meta['trail_active'] ?? null, 'Not armed below entry + d');
        $manager->manageTrade($trade, 99.6);
        $this->assertSame('OPEN', $trade->refresh()->status, 'Before arming only the safety stop can close it');

        $manager->manageTrade($trade, 100.6);
        $this->assertTrue($trade->refresh()->meta['trail_active']);
        $manager->manageTrade($trade, 101.2);
        $manager->manageTrade($trade, 100.8);
        $this->assertSame('OPEN', $trade->refresh()->status, '0.4 below the 101.2 high: inside the trail');

        $manager->manageTrade($trade, 100.69);
        $trade->refresh();
        $this->assertSame('TRAILING_STOP', $trade->exit_reason);
        $this->assertEqualsWithDelta(100.69, (float) $trade->exit_price, 1e-9);
    }

    public function test_stop_loss_closes_for_about_minus_one_r(): void
    {
        $trade = $this->trade();

        app(DynamicTradeManager::class)->manageTrade($trade, 98.9);
        $trade->refresh();

        $this->assertSame('CLOSED', $trade->status);
        $this->assertSame('STOP_LOSS', $trade->exit_reason);
        $this->assertEqualsWithDelta(-0.55, $trade->gross_pnl, 1e-6, 'Gap through the stop fills at the worse price');
        $this->assertSame(1, TradingAccount::getForMode('paper')->consecutive_losses);
    }

    public function test_live_breakeven_lock_uses_new_then_cancel_replacement(): void
    {
        $this->mock(ExchangeOrders::class, function (MockInterface $mock): void {
            $mock->shouldReceive('replaceStop')->once()->withArgs(fn (Trade $t, float $price): bool => abs($price - 100.1) < 1e-9)->andReturn(true);
        });

        $trade = $this->trade('live');
        $this->assertTrue(app(DynamicTradeManager::class)->lockBreakeven($trade));

        $this->assertEqualsWithDelta(100.1, $trade->refresh()->current_sl, 1e-9);
        $this->assertTrue($trade->be_locked);
    }

    public function test_live_stop_is_unchanged_when_the_exchange_rejects_the_move(): void
    {
        $this->mock(ExchangeOrders::class, function (MockInterface $mock): void {
            $mock->shouldReceive('replaceStop')->andReturn(false);
        });

        $trade = $this->trade('live');
        app(DynamicTradeManager::class)->lockBreakeven($trade);

        $this->assertEqualsWithDelta(99.0, $trade->refresh()->current_sl, 1e-9, 'The DB must mirror the stop that is really on the exchange');
    }

    public function test_live_trade_stays_open_when_close_is_not_confirmed_flat(): void
    {
        $this->mock(ExchangeOrders::class, function (MockInterface $mock): void {
            $mock->shouldReceive('closeAndConfirmFlat')->once()->andReturn(['flat' => false, 'order_id' => null, 'avg_price' => null, 'message' => 'Close order rejected: -2019 margin insufficient']);
        });

        $trade = $this->trade('live');
        $result = app(DynamicTradeManager::class)->closeTrade($trade, 100.0, 'MANUAL_CLOSE');
        $trade->refresh();

        $this->assertSame('error', $result['status']);
        $this->assertSame('OPEN', $trade->status);
        $this->assertStringContainsString('-2019', $trade->meta['close_failed_reason']);
    }

    public function test_live_trade_closes_once_flat_even_if_fills_are_not_available_yet(): void
    {
        $this->mock(ExchangeOrders::class, function (MockInterface $mock): void {
            $mock->shouldReceive('closeAndConfirmFlat')->once()->andReturn(['flat' => true, 'order_id' => '123', 'avg_price' => 100.8, 'message' => 'Position confirmed flat.']);
        });

        $trade = $this->trade('live');
        $result = app(DynamicTradeManager::class)->closeTrade($trade, 100.0, 'MANUAL_CLOSE');
        $trade->refresh();

        $this->assertSame('closed', $result['status']);
        $this->assertSame('CLOSED', $trade->status);
        $this->assertSame(100.8, $trade->exit_price);
        $this->assertTrue($trade->meta['reconcile_pending']);
    }
}
