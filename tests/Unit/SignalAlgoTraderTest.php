<?php

namespace Tests\Unit;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Crypto\BinanceClient;
use App\Services\Crypto\SignalEngine;
use App\Services\Notifications\TelegramNotifier;
use App\Services\Trading\DynamicTradeManager;
use App\Services\Trading\OrderExecutor;
use App\Services\Trading\SignalAlgoTrader;
use App\Services\Trading\TradingTargetManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class SignalAlgoTraderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        TradingTargetManager::setActiveCoin('NEARUSDT');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_locks_breakeven_when_gain_reaches_threshold(): void
    {
        $account = TradingAccount::create([
            'mode' => 'paper',
            'initial_balance' => 10.0,
            'balance' => 10.0,
            'equity' => 10.0,
            'peak_equity' => 10.0,
            'is_running' => true,
        ]);

        $trade = Trade::create([
            'symbol' => 'NEARUSDT',
            'side' => 'LONG',
            'mode' => 'paper',
            'status' => 'OPEN',
            'stage' => 'ENTRY',
            'entry_price' => 5.00,
            'quantity' => 1.0,
            'remaining_quantity' => 1.0,
            'margin_used' => 0.50,
            'leverage' => 10,
            'initial_sl' => 4.90,
            'current_sl' => 4.90,
            'stop_distance' => 0.10,
            'tp1_price' => 5.15,
            'tp2_price' => 5.30,
            'be_locked' => false,
            'opened_at' => now(),
        ]);

        $binanceClient = Mockery::mock(BinanceClient::class);
        $signalEngine = Mockery::mock(SignalEngine::class);
        $orderExecutor = Mockery::mock(OrderExecutor::class);
        $tradeManager = app(DynamicTradeManager::class);
        $notifier = Mockery::mock(TelegramNotifier::class);
        $notifier->shouldReceive('notifyBreakevenLocked')->once();
        $notifier->shouldReceive('shouldNotify')->andReturn(false);

        $trader = new SignalAlgoTrader($binanceClient, $signalEngine, $orderExecutor, $tradeManager, $notifier);

        // Price at $5.05 (+1.00% gain, above 0.80% threshold)
        $res = $trader->manageTrendPosition($trade, 5.05);

        $this->assertEquals('managed', $res['status']);
        $trade->refresh();
        $this->assertTrue($trade->be_locked);
        $this->assertEquals('BE_LOCKED', $trade->stage);
        // SL moved to entry * 1.0020 = 5.01
        $this->assertEquals(5.01, $trade->current_sl);
    }

    public function test_it_ratchets_stop_loss_higher_as_profit_grows(): void
    {
        $trade = Trade::create([
            'symbol' => 'NEARUSDT',
            'side' => 'LONG',
            'mode' => 'paper',
            'status' => 'OPEN',
            'stage' => 'BE_LOCKED',
            'entry_price' => 5.00,
            'quantity' => 1.0,
            'remaining_quantity' => 1.0,
            'margin_used' => 0.50,
            'leverage' => 10,
            'initial_sl' => 4.90,
            'current_sl' => 5.01,
            'stop_distance' => 0.10,
            'tp1_price' => 5.15,
            'tp2_price' => 5.30,
            'be_locked' => true,
            'opened_at' => now(),
        ]);

        $binanceClient = Mockery::mock(BinanceClient::class);
        $signalEngine = Mockery::mock(SignalEngine::class);
        $orderExecutor = Mockery::mock(OrderExecutor::class);
        $tradeManager = app(DynamicTradeManager::class);
        $notifier = Mockery::mock(TelegramNotifier::class);

        $trader = new SignalAlgoTrader($binanceClient, $signalEngine, $orderExecutor, $tradeManager, $notifier);

        // Price moves to $5.13 (+2.60% peak gain -> triggers Tier 3 ratchet to +1.50%)
        $trader->manageTrendPosition($trade, 5.13);

        $trade->refresh();
        $this->assertEquals('TRAILING', $trade->stage);
        // 5.00 * 1.0150 = 5.075
        $this->assertEquals(5.075, $trade->current_sl);
    }

    public function test_it_closes_trade_on_hard_stop_loss_breach(): void
    {
        $account = TradingAccount::create([
            'mode' => 'paper',
            'initial_balance' => 10.0,
            'balance' => 10.0,
            'equity' => 10.0,
            'peak_equity' => 10.0,
            'is_running' => true,
        ]);

        $trade = Trade::create([
            'symbol' => 'NEARUSDT',
            'side' => 'LONG',
            'mode' => 'paper',
            'status' => 'OPEN',
            'stage' => 'ENTRY',
            'entry_price' => 5.00,
            'quantity' => 1.0,
            'remaining_quantity' => 1.0,
            'margin_used' => 0.50,
            'leverage' => 10,
            'initial_sl' => 4.90,
            'current_sl' => 4.90,
            'stop_distance' => 0.10,
            'tp1_price' => 5.15,
            'tp2_price' => 5.30,
            'be_locked' => false,
            'opened_at' => now(),
        ]);

        $binanceClient = Mockery::mock(BinanceClient::class);
        $signalEngine = Mockery::mock(SignalEngine::class);
        $orderExecutor = Mockery::mock(OrderExecutor::class);
        $tradeManager = app(DynamicTradeManager::class);
        $notifier = Mockery::mock(TelegramNotifier::class);

        $trader = new SignalAlgoTrader($binanceClient, $signalEngine, $orderExecutor, $tradeManager, $notifier);

        // Price drops to 4.88 (below current_sl 4.90)
        $res = $trader->manageTrendPosition($trade, 4.88);

        $this->assertEquals('closed', $res['status']);
        $trade->refresh();
        $this->assertEquals('CLOSED', $trade->status);
        $this->assertEquals('STOP_LOSS', $trade->exit_reason);
    }

    public function test_it_closes_position_on_chart_reversal_signal_and_enters_reverse_position(): void
    {
        $account = TradingAccount::create([
            'mode' => 'paper',
            'initial_balance' => 20.0,
            'balance' => 20.0,
            'equity' => 20.0,
            'peak_equity' => 20.0,
            'is_running' => true,
        ]);

        $trade = Trade::create([
            'symbol' => 'NEARUSDT',
            'side' => 'LONG',
            'mode' => 'paper',
            'status' => 'OPEN',
            'stage' => 'ENTRY',
            'entry_price' => 5.00,
            'quantity' => 1.0,
            'remaining_quantity' => 1.0,
            'margin_used' => 0.50,
            'leverage' => 10,
            'initial_sl' => 4.90,
            'current_sl' => 4.90,
            'stop_distance' => 0.10,
            'tp1_price' => 5.15,
            'tp2_price' => 5.30,
            'be_locked' => false,
            'opened_at' => now(),
        ]);

        $binanceClient = Mockery::mock(BinanceClient::class);
        $binanceClient->shouldReceive('tickerPrice')->with('NEARUSDT')->andReturn(5.08);

        // Mock chart signal detection with an opposing SELL signal
        $sellMarker = [
            'time' => now()->timestamp,
            'side' => 'SELL',
            'score' => 92,
            'grade' => 'A',
            'entry' => 5.08,
            'sl' => 5.15,
            'tp1' => 4.95,
            'tp2' => 4.85,
            'risk_reward' => '1 : 2.8',
            'setup_type' => 'BREAKDOWN_CONFIRMED',
            'setup_label' => 'BREAKDOWN CONFIRMED',
        ];

        $freshSignal = [
            'symbol' => 'NEARUSDT',
            'interval' => '15m',
            'side' => 'SELL',
            'direction' => 'SHORT',
            'score' => 92,
            'grade' => 'A',
            'price' => 5.08,
            'initial_sl' => 5.15,
            'tp1' => 4.95,
            'tp2' => 4.85,
            'risk_reward' => '1 : 2.8',
            'marker_time' => now()->timestamp,
            'setup_type' => 'BREAKDOWN_CONFIRMED',
            'setup_label' => 'BREAKDOWN CONFIRMED',
            'indicators' => [],
            'raw_marker' => $sellMarker,
        ];

        $signalEngine = Mockery::mock(SignalEngine::class);
        $orderExecutor = Mockery::mock(OrderExecutor::class);
        $orderExecutor->shouldReceive('executeSignal')->once()->andReturn([
            'status' => 'opened',
            'trade' => null,
            'message' => 'SHORT trade opened on NEARUSDT',
        ]);

        $tradeManager = app(DynamicTradeManager::class);
        $notifier = Mockery::mock(TelegramNotifier::class);
        $notifier->shouldReceive('notifyReversalExit')->once();

        $trader = Mockery::mock(SignalAlgoTrader::class, [$binanceClient, $signalEngine, $orderExecutor, $tradeManager, $notifier])->makePartial();
        $trader->shouldReceive('detectSignalOnChart')->with('NEARUSDT')->andReturn($freshSignal);

        $res = $trader->runCycle('paper');

        $this->assertEquals('reversed', $res['status']);
        $this->assertEquals('REVERSED_TO_SHORT', $res['action']);

        $trade->refresh();
        $this->assertEquals('CLOSED', $trade->status);
        $this->assertEquals('REVERSAL_SIGNAL', $trade->exit_reason);
        $this->assertEquals('CLOSED', $trade->stage);
        // Gain: (5.08 - 5.00) * 1.0 = +$0.08
        $this->assertEquals(0.08, $trade->realized_pnl);
    }

    public function test_it_resolves_trading_mode_defaults_to_paper_when_not_live(): void
    {
        config(['trading.mode' => 'paper']);
        $binanceClient = Mockery::mock(BinanceClient::class);
        $signalEngine = Mockery::mock(SignalEngine::class);
        $orderExecutor = Mockery::mock(OrderExecutor::class);
        $tradeManager = app(DynamicTradeManager::class);
        $notifier = Mockery::mock(TelegramNotifier::class);

        $trader = new SignalAlgoTrader($binanceClient, $signalEngine, $orderExecutor, $tradeManager, $notifier);
        $mode = $trader->resolveTradingMode();

        $this->assertEquals('paper', $mode);
    }

    public function test_process_signal_for_execution_triggers_paper_order(): void
    {
        TradingAccount::create([
            'mode' => 'paper',
            'initial_balance' => 100.0,
            'balance' => 100.0,
            'equity' => 100.0,
            'peak_equity' => 100.0,
            'is_running' => true,
        ]);

        $binanceClient = Mockery::mock(BinanceClient::class);
        $signalEngine = Mockery::mock(SignalEngine::class);
        $orderExecutor = Mockery::mock(OrderExecutor::class);
        $tradeManager = app(DynamicTradeManager::class);
        $notifier = Mockery::mock(TelegramNotifier::class);

        $orderExecutor->shouldReceive('executeSignal')->once()->andReturn([
            'status' => 'opened',
            'trade' => null,
            'message' => 'LONG trade placed on BTCUSDT',
        ]);

        $trader = new SignalAlgoTrader($binanceClient, $signalEngine, $orderExecutor, $tradeManager, $notifier);

        $signal = [
            'symbol' => 'BTCUSDT',
            'interval' => '15m',
            'side' => 'BUY',
            'direction' => 'LONG',
            'score' => 95,
            'grade' => 'A',
            'price' => 65000.0,
            'initial_sl' => 64000.0,
            'tp1' => 66500.0,
            'tp2' => 68000.0,
            'risk_reward' => '1 : 2.5',
            'marker_time' => now()->timestamp,
            'setup_type' => 'PULLBACK_VALUE',
        ];

        $res = $trader->processSignalForExecution('BTCUSDT', $signal, 'paper');

        $this->assertEquals('opened', $res['status']);
        $this->assertEquals('paper', $res['mode']);
        $this->assertEquals('OPEN_LONG', $res['action']);
    }
}
