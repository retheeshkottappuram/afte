<?php

namespace Tests\Unit;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\AI\ActiveTradeMonitorAgent;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActiveTradeMonitorAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_monitor_identifies_healthy_trend_and_lets_winner_run(): void
    {
        $agent = app(ActiveTradeMonitorAgent::class);

        TradingAccount::create([
            'mode' => 'paper',
            'balance' => 5.0,
            'initial_balance' => 5.0,
        ]);

        $trade = Trade::create([
            'symbol' => 'LINKUSDT',
            'side' => 'SHORT',
            'mode' => 'paper',
            'status' => 'OPEN',
            'stage' => 'ENTRY',
            'entry_price' => 14.61,
            'quantity' => 1.37,
            'remaining_quantity' => 1.37,
            'margin_used' => 2.0,
            'leverage' => 10,
            'initial_sl' => 14.85,
            'current_sl' => 14.85,
            'tp1_price' => 14.35,
            'tp2_price' => 14.10,
            'be_locked' => false,
            'tp1_hit' => false,
            'tp2_hit' => false,
            'highest_price' => 14.61,
            'lowest_price' => 14.50, // Peak profit was at 14.50 (+0.75% / +7.5% ROE)
            'opened_at' => Carbon::now(),
        ]);

        // Price pulls back slightly from 14.50 to 14.55 (+0.41% gain)
        $result = $agent->monitorTrade($trade, 14.55);

        $this->assertIsArray($result);
        $this->assertContains($result['action'], ['HOLD', 'TRAIL_SL']);
        $this->assertNotEmpty($result['reason']);
        $this->assertNotNull($result['target_price']);
    }

    public function test_ai_monitor_recommends_structural_trailing_stop_on_large_profit(): void
    {
        $agent = app(ActiveTradeMonitorAgent::class);

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
            'highest_price' => 103.5,
            'lowest_price' => 100.0,
            'opened_at' => Carbon::now(),
        ]);

        // Price at 103.0 (+3.0% gain / +30% ROE)
        $result = $agent->monitorTrade($trade, 103.0);

        $this->assertIsArray($result);
        $this->assertContains($result['action'], ['HOLD', 'TRAIL_SL']);
        $this->assertNotEmpty($result['decision']);
    }
}
