<?php

namespace Tests\Unit;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Binance\BinanceFuturesClient;
use App\Services\Trading\ExchangeOrders;
use App\Services\Trading\OrderExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class OrderExecutorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    protected function signal(array $overrides = []): array
    {
        return array_merge([
            'symbol' => 'SOLUSDT',
            'direction' => 'LONG',
            'price' => 150.0,
            'initial_sl' => 148.5, // 1% stop
            'setup' => 'TREND_PULLBACK',
            'setup_label' => 'Trend Pullback',
            'interval' => '1h',
            'grade' => 'B',
            'score' => 60,
            'indicators' => ['atr' => 1.2],
        ], $overrides);
    }

    protected function account(string $mode, float $balance = 100.0): TradingAccount
    {
        return TradingAccount::create(['mode' => $mode, 'balance' => $balance, 'initial_balance' => $balance, 'equity' => $balance, 'peak_equity' => $balance, 'is_running' => true]);
    }

    protected function enableLive(): void
    {
        config([
            'trading.allow_live_trading' => true,
            'trading.binance.api_key' => 'test-key',
            'trading.binance.api_secret' => 'test-secret',
        ]);
    }

    public function test_paper_entry_is_risk_sized_with_exit_plan_targets(): void
    {
        Http::fake();
        $this->account('paper');

        $result = app(OrderExecutor::class)->executeSignal($this->signal(), 'paper');
        $trade = $result['trade'];

        $this->assertSame('opened', $result['status']);
        $this->assertEqualsWithDelta(150.0 * 1.0003, $trade->entry_price, 1e-9, 'Paper fills include slippage');
        $this->assertEqualsWithDelta(1.5, $trade->entry_price - $trade->initial_sl, 1e-9, 'Stop distance is preserved from the signal');
        $this->assertEqualsWithDelta($trade->entry_price + 2.25, $trade->tp1_price, 1e-9);
        $this->assertEqualsWithDelta(1.33, $trade->quantity, 1e-9, '2% of $100 at a $1.50 stop');
        $this->assertSame('TREND_PULLBACK', $trade->meta['setup']);
        $this->assertSame('auto', $trade->meta['source']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'signature=') || $request->method() !== 'GET');
    }

    public function test_rejects_a_stop_wider_than_the_limit_instead_of_widening_risk(): void
    {
        Http::fake();
        $this->account('paper');

        $result = app(OrderExecutor::class)->executeSignal($this->signal(['initial_sl' => 145.0]), 'paper');

        $this->assertSame('rejected', $result['status']);
        $this->assertStringContainsString('wider than', $result['message']);
        $this->assertSame(0, Trade::count());
    }

    public function test_live_entry_is_closed_immediately_when_the_stop_cannot_be_placed(): void
    {
        $this->enableLive();
        $this->account('live');
        Http::fake([
            '*/fapi/v1/order' => Http::response(['orderId' => 77, 'avgPrice' => '150.10', 'executedQty' => '1.33']),
            '*/fapi/v2/balance' => Http::response([['asset' => 'USDT', 'availableBalance' => '100']]),
            '*' => Http::response([]),
        ]);

        $this->mock(ExchangeOrders::class, function (MockInterface $mock): void {
            $mock->shouldReceive('placeStop')->once()->andThrow(new RuntimeException('Could not place protective stop'));
            $mock->shouldReceive('closeAndConfirmFlat')->once()->andReturn(['flat' => true, 'order_id' => '78', 'avg_price' => 150.0, 'message' => 'Position confirmed flat.']);
        });

        $result = app(OrderExecutor::class)->executeSignal($this->signal(), 'live');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('closed immediately', $result['message']);
        $this->assertSame(0, Trade::count(), 'An unprotected position is never recorded as an open trade');
    }

    public function test_live_entry_records_exchange_stop_and_take_profit_ids(): void
    {
        $this->enableLive();
        $this->account('live');
        Http::fake([
            '*/fapi/v1/order' => Http::response(['orderId' => 77, 'avgPrice' => '150.10', 'executedQty' => '1.33']),
            '*/fapi/v2/balance' => Http::response([['asset' => 'USDT', 'availableBalance' => '100']]),
            '*' => Http::response([]),
        ]);

        $this->mock(ExchangeOrders::class, function (MockInterface $mock): void {
            $mock->shouldReceive('placeStop')->once()->withArgs(fn (string $s, string $side, float $stop, float $qty): bool => abs($stop - 148.6) < 1e-9 && abs($qty - 1.33) < 1e-9)->andReturn(['id' => 'sl-1', 'kind' => 'algo']);
            $mock->shouldReceive('placeTakeProfit')->once()->andReturn(['id' => 'tp-1', 'kind' => 'algo']);
        });

        $result = app(OrderExecutor::class)->executeSignal($this->signal(), 'live');
        $trade = $result['trade'];

        $this->assertSame('opened', $result['status']);
        $this->assertSame(150.10, $trade->entry_price, 'The real fill price is used');
        $this->assertSame('sl-1', $trade->meta['sl_order']['id']);
        $this->assertSame('tp-1', $trade->meta['tp_order']['id']);
        $this->assertSame('77', $trade->binance_order_id);
    }

    public function test_leverage_error_aborts_the_entry_before_any_order(): void
    {
        $this->enableLive();
        $this->account('live');
        Http::fake([
            '*/fapi/v1/leverage' => Http::response(['code' => -4300, 'msg' => 'Leverage not allowed'], 400),
            '*/fapi/v2/balance' => Http::response([['asset' => 'USDT', 'availableBalance' => '100']]),
            '*' => Http::response([]),
        ]);

        $result = app(OrderExecutor::class)->executeSignal($this->signal(), 'live');

        $this->assertSame('error', $result['status']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/fapi/v1/order'));
    }

    public function test_paper_client_can_never_place_real_orders(): void
    {
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PAPER');

        (new BinanceFuturesClient('paper'))->placeOrder(['symbol' => 'SOLUSDT', 'side' => 'BUY', 'type' => 'MARKET', 'quantity' => 1]);
    }
}
