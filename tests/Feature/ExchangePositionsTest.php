<?php

namespace Tests\Feature;

use App\Models\Trade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExchangePositionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trading.mode' => 'paper',
            'trading.telegram.enabled' => false,
            'trading.binance.api_key' => 'test-key',
            'trading.binance.api_secret' => 'test-secret',
        ]);
    }

    protected function fakeBinance(float $amount = 1.0): void
    {
        Http::fake([
            '*positionRisk*' => Http::response([
                ['symbol' => 'SOLUSDT', 'positionAmt' => (string) $amount, 'entryPrice' => '120.00', 'markPrice' => '123.00', 'unRealizedProfit' => (string) (3 * $amount), 'liquidationPrice' => '100.00', 'leverage' => '10', 'marginType' => 'cross', 'notional' => (string) (123 * $amount), 'positionSide' => 'BOTH'],
                ['symbol' => 'ETHUSDT', 'positionAmt' => '0', 'entryPrice' => '0', 'markPrice' => '3000', 'unRealizedProfit' => '0', 'leverage' => '5', 'marginType' => 'cross', 'positionSide' => 'BOTH'],
            ]),
            '*openAlgoOrders*' => Http::response([
                ['algoId' => 7, 'symbol' => 'SOLUSDT', 'orderType' => 'STOP_MARKET', 'triggerPrice' => '117.50'],
                ['algoId' => 8, 'symbol' => 'SOLUSDT', 'orderType' => 'TAKE_PROFIT_MARKET', 'triggerPrice' => '130.00'],
            ]),
            '*premiumIndex*' => Http::response(['symbol' => 'SOLUSDT', 'markPrice' => '123.00']),
            '*fapi/v2/balance*' => Http::response([['asset' => 'USDT', 'balance' => '4.67', 'crossUnPnl' => '3.00']]),
            '*openOrders*' => Http::response([]),
            '*listenKey*' => Http::response(['listenKey' => 'lk-123']),
            '*fapi/v1/order?*' => Http::response(['orderId' => 1, 'avgPrice' => '123.10', 'status' => 'FILLED']),
            '*fapi/v1/order' => Http::response(['orderId' => 1, 'avgPrice' => '123.10', 'status' => 'FILLED']),
            '*' => Http::response([]),
        ]);
    }

    public function test_binance_positions_are_listed_even_in_paper_view_with_live_numbers_and_protection(): void
    {
        $this->fakeBinance();
        $admin = User::factory()->create(['role' => 'admin']);

        $exchange = $this->actingAs($admin)->getJson(route('api.live_sync', ['mode' => 'paper']))->assertOk()->json('exchange');

        $this->assertTrue($exchange['available']);
        $this->assertCount(1, $exchange['positions'], 'Flat symbols are skipped');
        $position = $exchange['positions'][0];
        $this->assertSame('SOLUSDT', $position['symbol']);
        $this->assertSame('LONG', $position['side']);
        $this->assertSame('manual', $position['managed_by']);
        $this->assertSame(117.5, $position['sl']);
        $this->assertEquals(25, $position['roe_pct'], '$3 on $12 initial margin (1 x 120 / 10x)');
        $this->assertEqualsWithDelta(18.7, $position['liquidation_distance_pct'], 0.01);
    }

    public function test_position_opened_by_the_bot_is_flagged_as_bot(): void
    {
        $this->fakeBinance();
        $trade = $this->trade('live');
        $admin = User::factory()->create(['role' => 'admin']);

        $position = $this->actingAs($admin)->getJson(route('api.live_sync', ['mode' => 'paper']))->json('exchange.positions.0');

        $this->assertSame('bot', $position['managed_by']);
        $this->assertSame($trade->id, $position['trade_id']);
    }

    public function test_listen_key_for_the_realtime_stream_requires_trading_permission(): void
    {
        $this->fakeBinance();

        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson(route('api.stream.listen_key'))
            ->assertOk()->assertJsonPath('listen_key', 'lk-123');
        $this->actingAs(User::factory()->create(['role' => 'viewer']))->postJson(route('api.stream.listen_key'))->assertForbidden();
    }

    public function test_booking_half_of_a_manual_position_sends_a_reduce_only_market_order(): void
    {
        $this->fakeBinance();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('api.exchange.close'), ['symbol' => 'SOLUSDT', 'side' => 'LONG', 'percent' => 50])
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('filled_qty', 0.5);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && str_contains($r->url(), '/fapi/v1/order')
            && $r['side'] === 'SELL' && $r['type'] === 'MARKET' && (float) $r['quantity'] === 0.5 && $r['reduceOnly'] === 'true');
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'allOpenOrders'));
    }

    public function test_closing_a_manual_position_fully_cancels_its_leftover_orders(): void
    {
        $this->fakeBinance();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('api.exchange.close'), ['symbol' => 'SOLUSDT', 'percent' => 100])->assertOk();

        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && str_contains($r->url(), '/fapi/v1/order') && (float) $r['quantity'] === 1.0);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_contains($r->url(), 'allOpenOrders'));
    }

    public function test_booking_half_of_a_paper_bot_trade_keeps_the_rest_running(): void
    {
        Http::fake(['*' => Http::response([])]);
        $trade = $this->trade('paper');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('api.exchange.close'), ['trade_id' => $trade->id, 'percent' => 50])->assertOk();

        $trade->refresh();
        $this->assertSame('OPEN', $trade->status);
        $this->assertEqualsWithDelta(0.5, (float) $trade->remaining_quantity, 1e-9);
        $this->assertNotNull($trade->meta['partial_qty'] ?? null);
    }

    public function test_live_equity_includes_manual_positions_and_the_payload_carries_wallet_and_equity(): void
    {
        $this->fakeBinance();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->assertEquals(7.67, $this->actingAs($admin)->getJson(route('api.stats', ['mode' => 'live']))->assertOk()->json('equity'), 'Wallet 4.67 + manual SOL +3.00');

        $exchange = $this->actingAs($admin)->getJson(route('api.live_sync', ['mode' => 'paper']))->json('exchange');
        $this->assertEquals(4.67, $exchange['wallet_balance']);
        $this->assertEquals(3, $exchange['unrealized_total']);
        $this->assertEquals(7.67, $exchange['equity']);
    }

    public function test_moving_the_stop_replaces_only_the_stop_order(): void
    {
        $this->fakeBinance();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('api.exchange.protect'), ['symbol' => 'SOLUSDT', 'side' => 'LONG', 'sl' => 118.25])
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('sl', 118.25);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_contains($r->url(), '/fapi/v1/algoOrder') && str_contains($r->url(), 'algoId=7'));
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_contains($r->url(), 'algoId=8'));
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && str_contains($r->url(), '/fapi/v1/algoOrder')
            && $r['type'] === 'STOP_MARKET' && $r['side'] === 'SELL' && $r['closePosition'] === 'true' && (float) $r['triggerPrice'] === 118.25);
    }

    public function test_a_stop_on_the_wrong_side_of_the_price_is_refused_before_touching_orders(): void
    {
        $this->fakeBinance();
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson(route('api.exchange.protect'), ['symbol' => 'SOLUSDT', 'side' => 'LONG', 'sl' => 125]);

        $response->assertStatus(422);
        $this->assertStringContainsString('must be below the mark price', $response->json('message'));
        Http::assertNotSent(fn (Request $r): bool => in_array($r->method(), ['POST', 'DELETE'], true) && str_contains($r->url(), 'algoOrder'));
    }

    public function test_null_removes_the_take_profit(): void
    {
        $this->fakeBinance();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('api.exchange.protect'), ['symbol' => 'SOLUSDT', 'side' => 'LONG', 'tp' => null])
            ->assertOk()->assertJsonPath('tp', null);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_contains($r->url(), 'algoId=8'));
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_contains($r->url(), 'algoId=7'));
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST' && str_contains($r->url(), '/fapi/v1/algoOrder'));
    }

    public function test_bot_trades_keep_their_own_stop_management(): void
    {
        $this->fakeBinance();
        $this->trade('live');

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('api.exchange.protect'), ['symbol' => 'SOLUSDT', 'side' => 'LONG', 'sl' => 118])->assertStatus(409);
        $this->actingAs(User::factory()->create(['role' => 'viewer']))
            ->postJson(route('api.exchange.protect'), ['symbol' => 'SOLUSDT', 'side' => 'LONG', 'sl' => 118])->assertForbidden();
    }

    public function test_viewers_cannot_close_positions(): void
    {
        $this->fakeBinance();

        $this->actingAs(User::factory()->create(['role' => 'viewer']))
            ->postJson(route('api.exchange.close'), ['symbol' => 'SOLUSDT', 'percent' => 100])->assertForbidden();
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/fapi/v1/order'));
    }

    protected function trade(string $mode): Trade
    {
        return Trade::create([
            'symbol' => 'SOLUSDT', 'side' => 'LONG', 'mode' => $mode, 'status' => 'OPEN', 'stage' => 'ENTRY',
            'entry_price' => 120.0, 'quantity' => 1.0, 'remaining_quantity' => 1.0, 'margin_used' => 12.0, 'leverage' => 10,
            'initial_sl' => 117.5, 'current_sl' => 117.5, 'tp1_price' => 123.75, 'tp2_price' => 127.5, 'opened_at' => now(),
        ]);
    }
}
