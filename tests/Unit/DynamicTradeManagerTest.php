<?php

namespace Tests\Unit;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Trading\DynamicTradeManager;
use App\Services\Trading\ExchangeOrders;
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
     * SOL long from 100 with the stop at 99 (R = 1), TP1 101.5, TP2 103.
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

    public function test_breakeven_plus_fees_at_one_r(): void
    {
        $trade = $this->trade();

        app(DynamicTradeManager::class)->manageTrade($trade, 101.0);
        $trade->refresh();

        $this->assertTrue($trade->be_locked);
        $this->assertEqualsWithDelta(100.1, $trade->current_sl, 1e-9);
        $this->assertSame('BE_LOCKED', $trade->stage);
        $this->assertSame('OPEN', $trade->status);
    }

    public function test_tp1_books_half_and_locks_profit(): void
    {
        $trade = $this->trade();

        app(DynamicTradeManager::class)->manageTrade($trade, 101.6);
        $trade->refresh();

        $this->assertTrue($trade->tp1_hit);
        $this->assertSame('TP1_HIT', $trade->stage);
        $this->assertEqualsWithDelta(0.25, $trade->remaining_quantity, 1e-9);
        $this->assertEqualsWithDelta(100.5, $trade->current_sl, 1e-9);
        $this->assertEqualsWithDelta(0.375, $trade->meta['partial_gross'], 1e-9, '0.25 SOL booked at +1.5');
        $this->assertGreaterThan(0, $trade->realized_pnl);
    }

    public function test_partial_profit_is_included_when_the_runner_closes(): void
    {
        $trade = $this->trade();
        $manager = app(DynamicTradeManager::class);

        $manager->manageTrade($trade, 101.6);
        $manager->manageTrade($trade->refresh(), 100.45);
        $trade->refresh();

        $this->assertSame('CLOSED', $trade->status);
        $this->assertSame('TRAILING_STOP', $trade->exit_reason);
        // 0.25 x 1.5 on TP1 + 0.25 x 0.45 on the runner, minus fees
        $this->assertEqualsWithDelta(0.375 + 0.1125, $trade->gross_pnl, 1e-6);
        $this->assertGreaterThan(0.4, $trade->net_pnl);
        $this->assertEqualsWithDelta(100.0 + $trade->net_pnl, TradingAccount::getForMode('paper')->balance, 1e-6);
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

    public function test_time_stop_closes_a_trade_without_progress(): void
    {
        $trade = $this->trade(overrides: ['opened_at' => Carbon::now()->subHours(13)]);

        app(DynamicTradeManager::class)->manageTrade($trade, 100.2);

        $this->assertSame('TIME_STOP', $trade->refresh()->exit_reason);
    }

    public function test_live_stop_move_uses_new_then_cancel_replacement(): void
    {
        $this->mock(ExchangeOrders::class, function (MockInterface $mock): void {
            $mock->shouldReceive('replaceStop')->once()->withArgs(fn (Trade $t, float $price): bool => abs($price - 100.1) < 1e-9)->andReturn(true);
        });

        $trade = $this->trade('live');
        app(DynamicTradeManager::class)->manageTrade($trade, 101.0);

        $this->assertEqualsWithDelta(100.1, $trade->refresh()->current_sl, 1e-9);
    }

    public function test_live_stop_is_unchanged_when_the_exchange_rejects_the_move(): void
    {
        $this->mock(ExchangeOrders::class, function (MockInterface $mock): void {
            $mock->shouldReceive('replaceStop')->andReturn(false);
        });

        $trade = $this->trade('live');
        app(DynamicTradeManager::class)->manageTrade($trade, 101.0);

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
