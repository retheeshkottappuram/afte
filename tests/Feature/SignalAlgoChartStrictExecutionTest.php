<?php

namespace Tests\Feature;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Models\User;
use App\Services\Trading\SignalAlgoTrader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SignalAlgoChartStrictExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['trading.mode' => 'paper']);
    }

    public function test_trade_is_placed_when_signalalgo_pro_chart_marker_is_detected(): void
    {
        $account = TradingAccount::getForMode('paper');
        $account->update(['balance' => 100.0, 'equity' => 100.0, 'is_running' => true, 'kill_switch' => false]);

        $freshMarker = [
            'symbol' => 'BTCUSDT',
            'interval' => '15m',
            'side' => 'BUY',
            'direction' => 'LONG',
            'score' => 92,
            'grade' => 'A',
            'price' => 64500.00,
            'initial_sl' => 63855.00,
            'tp1' => 65370.75,
            'tp2' => 66306.00,
            'tp3' => 67402.50,
            'risk_reward' => '1 : 2.8',
            'marker_time' => now()->timestamp - 120, // 2 minutes ago (fresh)
            'setup_type' => 'BREAKOUT_START',
            'setup_label' => 'BREAKOUT DIRECTION START',
            'indicators' => [
                'rsi' => 62.5,
                'adx' => 28.4,
                'atr_pct' => 1.2,
                'volume_ratio' => 1.8,
                'rs_ratio' => 1.15,
            ],
        ];

        /** @var SignalAlgoTrader $trader */
        $trader = app(SignalAlgoTrader::class);
        $result = $trader->processSignalForExecution('BTCUSDT', $freshMarker, 'paper');

        $this->assertEquals('opened', $result['status']);
        $this->assertEquals('OPEN_LONG', $result['action']);

        // Verify the created trade directly mirrors the SignalAlgo Pro chart marker
        $trade = Trade::where('symbol', 'BTCUSDT')->where('status', 'OPEN')->first();
        $this->assertNotNull($trade);
        $this->assertEquals('LONG', $trade->side);
        $this->assertEquals(64500.00, (float) $trade->entry_price);
        $this->assertEquals(63855.00, (float) $trade->initial_sl);
        $this->assertEquals(63855.00, (float) $trade->current_sl);
        $this->assertEquals(65370.75, (float) $trade->tp1_price);
        $this->assertEquals(66306.00, (float) $trade->tp2_price);
    }

    public function test_no_trade_is_placed_when_signalalgo_pro_conditions_are_not_met_in_radar(): void
    {
        $user = User::factory()->create();
        $account = TradingAccount::getForMode('paper');
        $account->update(['balance' => 100.0, 'is_running' => true, 'kill_switch' => false]);

        // Attempt to execute a trade via radar without a valid SignalAlgo Pro chart signal
        $response = $this->actingAs($user)->postJson(route('api.execute_radar_trade'), [
            'symbol' => 'BTCUSDT',
            'direction' => 'LONG',
            'mode' => 'paper',
        ]);

        // Must reject trade because SignalAlgo Pro chart signal was not found or verified
        $this->assertTrue(in_array($response->status(), [422, 500], true));
        $this->assertFalse($response->json('success'));
        $this->assertStringContainsString('SignalAlgo Pro', $response->json('message'));

        // No trade should exist in the database
        $this->assertDatabaseMissing('trades', [
            'symbol' => 'BTCUSDT',
            'status' => 'OPEN',
        ]);
    }

    public function test_active_trade_is_returned_to_chart_api_with_exact_levels(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $account = TradingAccount::getForMode('paper');
        $account->update(['balance' => 100.0, 'is_running' => true, 'kill_switch' => false]);

        $trade = Trade::create([
            'symbol' => 'BTCUSDT',
            'setup_tag' => 'SIGNALALGO_PRO',
            'side' => 'LONG',
            'mode' => 'paper',
            'status' => 'OPEN',
            'stage' => 'ENTRY',
            'entry_price' => 65000.00,
            'quantity' => 0.01,
            'remaining_quantity' => 0.01,
            'margin_used' => 65.00,
            'leverage' => 10,
            'initial_sl' => 64200.00,
            'current_sl' => 64200.00,
            'stop_distance' => 800.00,
            'tp1_price' => 66080.00,
            'tp2_price' => 67240.00,
            'be_locked' => false,
            'tp1_hit' => false,
            'tp2_hit' => false,
            'meta' => [
                'score' => 95,
                'grade' => 'A',
                'ai_reason' => 'Verified SignalAlgo PRO™ 15m Chart Signal: BREAKOUT DIRECTION START (Score: 95/100)',
            ],
            'opened_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson(route('signals.analyze', [
            'symbol' => 'BTCUSDT',
            'interval' => '15m',
        ]));

        $response->assertOk();
        $activeTrade = $response->json('active_trade');
        $this->assertNotNull($activeTrade);
        $this->assertEquals($trade->id, $activeTrade['id']);
        $this->assertEquals(65000.00, $activeTrade['entry_price']);
        $this->assertEquals(64200.00, $activeTrade['current_sl']);
        $this->assertEquals(66080.00, $activeTrade['tp1_price']);
        $this->assertEquals(67240.00, $activeTrade['tp2_price']);
        $this->assertEquals('LONG', $activeTrade['side']);
    }
}
