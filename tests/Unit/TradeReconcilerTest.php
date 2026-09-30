<?php

namespace Tests\Unit;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Binance\BinanceFuturesClient;
use App\Services\Notifications\TelegramNotifier;
use App\Services\Trading\RiskManager;
use App\Services\Trading\TradeReconciler;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class TradeReconcilerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_paper_trade_reconciliation_calculates_realistic_fees_and_mae_mfe(): void
    {
        TradingAccount::create([
            'mode' => 'paper',
            'balance' => 5.0,
            'initial_balance' => 5.0,
        ]);

        $client = Mockery::mock(BinanceFuturesClient::class);
        $riskManager = app(RiskManager::class);
        $notifier = Mockery::mock(TelegramNotifier::class);
        $notifier->shouldReceive('notifyTradeClosed')->once();

        $reconciler = new TradeReconciler($client, $riskManager, $notifier);

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
            'initial_sl' => 98.0,
            'current_sl' => 98.0,
            'tp1_price' => 102.0,
            'tp2_price' => 104.0,
            'highest_price' => 103.0,
            'lowest_price' => 99.0,
            'opened_at' => Carbon::now()->subMinutes(20),
        ]);

        $trade->exit_price = 102.0;
        $reconciled = $reconciler->reconcileClosedTrade($trade, null, 'TAKE_PROFIT_1');

        $this->assertEquals('CLOSED', $reconciled->status);
        $this->assertEquals(102.0, $reconciled->exit_price);
        $this->assertEquals('TAKE_PROFIT_1', $reconciled->exit_reason);

        // Gross PnL: (102.0 - 100.0) * 0.5 = 1.0000
        $this->assertEquals(1.0, $reconciled->gross_pnl);

        // Entry Notional: 0.5 * 100 = 50. Entry fee at 0.05% = 0.025
        // Exit Notional: 0.5 * 102 = 51. Exit fee at 0.05% = 0.0255
        // Total round trip commission = 0.0505
        $this->assertGreaterThan(0.0, $reconciled->commission);
        $this->assertEquals($reconciled->commission, $reconciled->fee_paid);

        // Net PnL = Gross PnL - Commission
        $expectedNet = round(1.0 - $reconciled->commission, 4);
        $this->assertEquals($expectedNet, $reconciled->net_pnl);
        $this->assertEquals($expectedNet, $reconciled->realized_pnl);

        // Stop distance: abs(100.0 - 98.0) = 2.0
        $this->assertEquals(2.0, $reconciled->stop_distance);

        // Long MAE: (100 - 99) * 0.5 = 0.50. MFE: (103 - 100) * 0.5 = 1.50
        $this->assertEquals(0.50, $reconciled->mae);
        $this->assertEquals(1.50, $reconciled->mfe);
    }

    public function test_live_trade_reconciliation_sources_true_fills_and_funding_from_binance(): void
    {
        TradingAccount::create([
            'mode' => 'live',
            'balance' => 4.5,
            'initial_balance' => 5.0,
        ]);

        $subClient = Mockery::mock(BinanceFuturesClient::class);
        $subClient->shouldReceive('hasCredentials')->andReturn(true);

        // Mock userTrades: 1 entry fill and 2 closing fills (order 8899)
        $subClient->shouldReceive('getUserTrades')
            ->andReturn([
                [
                    'id' => 101,
                    'orderId' => 7700,
                    'symbol' => 'DOGEUSDT',
                    'side' => 'BUY',
                    'price' => '0.10000',
                    'qty' => '50.0',
                    'realizedPnl' => '0.0',
                    'commission' => '0.0025',
                    'time' => Carbon::now()->subMinutes(10)->timestamp * 1000,
                ],
                [
                    'id' => 201,
                    'orderId' => 8899,
                    'symbol' => 'DOGEUSDT',
                    'side' => 'SELL',
                    'price' => '0.10200',
                    'qty' => '25.0',
                    'realizedPnl' => '0.0500',
                    'commission' => '0.0013',
                    'time' => Carbon::now()->timestamp * 1000,
                ],
                [
                    'id' => 202,
                    'orderId' => 8899,
                    'symbol' => 'DOGEUSDT',
                    'side' => 'SELL',
                    'price' => '0.10250',
                    'qty' => '25.0',
                    'realizedPnl' => '0.0625',
                    'commission' => '0.0013',
                    'time' => Carbon::now()->timestamp * 1000,
                ],
            ]);

        // Mock funding fees from /fapi/v1/income
        $subClient->shouldReceive('getIncome')
            ->andReturn([
                [
                    'incomeType' => 'FUNDING_FEE',
                    'income' => '-0.0008',
                    'time' => Carbon::now()->subMinutes(5)->timestamp * 1000,
                ],
            ]);

        // Mock getOrder for order 8899
        $subClient->shouldReceive('getOrder')
            ->with('DOGEUSDT', 8899)
            ->andReturn([
                'orderId' => 8899,
                'type' => 'TAKE_PROFIT_MARKET',
                'reduceOnly' => true,
            ]);

        $client = Mockery::mock(BinanceFuturesClient::class);
        $client->shouldReceive('forMode')->with('live')->andReturn($subClient);

        $riskManager = app(RiskManager::class);
        $notifier = Mockery::mock(TelegramNotifier::class);
        $notifier->shouldReceive('notifyTradeClosed')->once();

        $reconciler = new TradeReconciler($client, $riskManager, $notifier);

        $trade = Trade::create([
            'symbol' => 'DOGEUSDT',
            'side' => 'LONG',
            'mode' => 'live',
            'status' => 'OPEN',
            'stage' => 'ENTRY',
            'entry_price' => 0.10000,
            'quantity' => 50.0,
            'remaining_quantity' => 50.0,
            'margin_used' => 0.50,
            'leverage' => 10,
            'initial_sl' => 0.09800,
            'current_sl' => 0.09800,
            'tp1_price' => 0.10225,
            'tp2_price' => 0.10400,
            'binance_order_id' => '7700',
            'meta' => [
                'binance_tp_algo_id' => '8899',
            ],
            'opened_at' => Carbon::now()->subMinutes(10),
        ]);

        $reconciled = $reconciler->reconcileClosedTrade($trade, 8899, 'EXCHANGE_CLOSED');

        $this->assertEquals('CLOSED', $reconciled->status);
        // Weighted Exit Price: (25 * 0.10200 + 25 * 0.10250) / 50 = 0.10225
        $this->assertEquals(0.10225, $reconciled->exit_price);

        // Classified reason: TAKE_PROFIT (matched algo order & TP type)
        $this->assertEquals('TAKE_PROFIT', $reconciled->exit_reason);

        // Gross Realized PnL: 0.0500 + 0.0625 = 0.1125
        $this->assertEquals(0.1125, $reconciled->gross_pnl);

        // Total Commission: 0.0025 (entry) + 0.0013 + 0.0013 (exit) = 0.0051
        $this->assertEquals(0.0051, $reconciled->commission);

        // Funding Fee: -0.0008
        $this->assertEquals(-0.0008, $reconciled->funding_fee);

        // Net PnL = 0.1125 - 0.0051 + (-0.0008) = 0.1066
        $this->assertEquals(0.1066, $reconciled->net_pnl);
        $this->assertEquals(0.1066, $reconciled->realized_pnl);

        // Binance Trade IDs captured
        $this->assertContains('101', $reconciled->binance_trade_ids);
        $this->assertContains('201', $reconciled->binance_trade_ids);
        $this->assertContains('202', $reconciled->binance_trade_ids);
    }

    public function test_live_trade_reconciliation_fails_loudly_when_no_exchange_fills_found(): void
    {
        TradingAccount::create([
            'mode' => 'live',
            'balance' => 4.5,
            'initial_balance' => 5.0,
        ]);

        $subClient = Mockery::mock(BinanceFuturesClient::class);
        $subClient->shouldReceive('hasCredentials')->andReturn(true);
        $subClient->shouldReceive('getUserTrades')->andReturn([]);

        $client = Mockery::mock(BinanceFuturesClient::class);
        $client->shouldReceive('forMode')->with('live')->andReturn($subClient);

        $riskManager = app(RiskManager::class);
        $notifier = Mockery::mock(TelegramNotifier::class);

        $reconciler = new TradeReconciler($client, $riskManager, $notifier);

        $trade = Trade::create([
            'symbol' => 'BNBUSDT',
            'side' => 'LONG',
            'mode' => 'live',
            'status' => 'OPEN',
            'stage' => 'ENTRY',
            'entry_price' => 500.0,
            'quantity' => 0.01,
            'remaining_quantity' => 0.01,
            'margin_used' => 0.50,
            'leverage' => 10,
            'initial_sl' => 490.0,
            'current_sl' => 490.0,
            'tp1_price' => 510.0,
            'tp2_price' => 520.0,
            'opened_at' => Carbon::now()->subMinutes(10),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No user trades returned from Binance');

        $reconciler->reconcileClosedTrade($trade, null, 'EXCHANGE_CLOSED');
    }

    public function test_classification_correctly_identifies_stop_loss_hit(): void
    {
        $fapi = Mockery::mock(BinanceFuturesClient::class);
        $fapi->shouldReceive('getOrder')->andReturn([
            'type' => 'STOP_MARKET',
            'origType' => 'STOP_MARKET',
        ]);

        $reconciler = new TradeReconciler(
            Mockery::mock(BinanceFuturesClient::class),
            app(RiskManager::class),
            Mockery::mock(TelegramNotifier::class)
        );

        $trade = new Trade([
            'symbol' => 'SOLUSDT',
            'side' => 'LONG',
            'entry_price' => 100.0,
            'current_sl' => 98.0,
            'tp1_price' => 103.0,
            'tp2_price' => 106.0,
        ]);

        $reason = $reconciler->classifyExitReason($trade, 9999, 97.95, 'EXCHANGE_CLOSED', $fapi);
        $this->assertEquals('STOP_LOSS', $reason);
    }
}
