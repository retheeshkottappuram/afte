<?php

namespace Tests\Feature;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TradeHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_view_history(): void
    {
        $response = $this->get('/history');
        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_history_with_coin_and_pnl(): void
    {
        $user = User::factory()->create();

        TradingAccount::create([
            'mode' => 'paper',
            'balance' => 5.45,
            'initial_balance' => 5.0,
        ]);

        Trade::create([
            'symbol' => 'SOLUSDT',
            'side' => 'LONG',
            'mode' => 'paper',
            'status' => 'CLOSED',
            'stage' => 'CLOSED',
            'entry_price' => 110.0,
            'exit_price' => 112.5,
            'quantity' => 0.5,
            'remaining_quantity' => 0.0,
            'margin_used' => 5.5,
            'leverage' => 10,
            'initial_sl' => 108.5,
            'current_sl' => 110.0,
            'tp1_price' => 112.0,
            'tp2_price' => 114.0,
            'realized_pnl' => 1.25,
            'pnl_percent' => 22.72,
            'fee_paid' => 0.005,
            'exit_reason' => 'TP1_HIT',
            'opened_at' => Carbon::now()->subMinutes(30),
            'closed_at' => Carbon::now()->subMinutes(10),
        ]);

        Trade::create([
            'symbol' => 'BTCUSDT',
            'side' => 'SHORT',
            'mode' => 'paper',
            'status' => 'CLOSED',
            'stage' => 'CLOSED',
            'entry_price' => 65000.0,
            'exit_price' => 65500.0,
            'quantity' => 0.001,
            'remaining_quantity' => 0.0,
            'margin_used' => 6.5,
            'leverage' => 10,
            'initial_sl' => 65500.0,
            'current_sl' => 65500.0,
            'tp1_price' => 64000.0,
            'tp2_price' => 63000.0,
            'realized_pnl' => -0.50,
            'pnl_percent' => -7.69,
            'fee_paid' => 0.006,
            'exit_reason' => 'STOP_LOSS',
            'opened_at' => Carbon::now()->subHours(2),
            'closed_at' => Carbon::now()->subHour(),
        ]);

        $response = $this->actingAs($user)->get('/history?mode=paper');

        $response->assertStatus(200);
        $response->assertSee('Execution Ledger');
        $response->assertSee('SOLUSDT');
        $response->assertSee('BTCUSDT');
        $response->assertSee('+$1.2500');
        $response->assertSee('-$0.5000');
        $response->assertSee('TP1_HIT');
        $response->assertSee('STOP_LOSS');
    }

    public function test_history_filters_by_symbol(): void
    {
        $user = User::factory()->create();

        TradingAccount::create(['mode' => 'paper', 'balance' => 5.0, 'initial_balance' => 5.0]);

        $trade1 = Trade::create([
            'symbol' => 'SOLUSDT',
            'side' => 'LONG',
            'mode' => 'paper',
            'status' => 'CLOSED',
            'entry_price' => 110.0,
            'exit_price' => 112.0,
            'quantity' => 0.5,
            'remaining_quantity' => 0.0,
            'margin_used' => 5.0,
            'leverage' => 10,
            'initial_sl' => 108.0,
            'current_sl' => 110.0,
            'tp1_price' => 112.0,
            'tp2_price' => 114.0,
            'realized_pnl' => 1.0,
            'closed_at' => Carbon::now(),
        ]);

        $trade2 = Trade::create([
            'symbol' => 'DOGEUSDT',
            'side' => 'LONG',
            'mode' => 'paper',
            'status' => 'CLOSED',
            'entry_price' => 0.15,
            'exit_price' => 0.14,
            'quantity' => 50.0,
            'remaining_quantity' => 0.0,
            'margin_used' => 5.0,
            'leverage' => 10,
            'initial_sl' => 0.14,
            'current_sl' => 0.14,
            'tp1_price' => 0.16,
            'tp2_price' => 0.17,
            'realized_pnl' => -0.5,
            'closed_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($user)->get('/history?mode=paper&symbol=SOLUSDT');

        $response->assertStatus(200);
        $response->assertViewHas('trades', function ($trades) use ($trade1, $trade2) {
            return $trades->contains($trade1) && ! $trades->contains($trade2);
        });
    }

    public function test_csv_export(): void
    {
        $user = User::factory()->create();

        Trade::create([
            'symbol' => 'SOLUSDT',
            'side' => 'LONG',
            'mode' => 'paper',
            'status' => 'CLOSED',
            'entry_price' => 110.0,
            'exit_price' => 112.0,
            'quantity' => 0.5,
            'remaining_quantity' => 0.0,
            'margin_used' => 5.0,
            'leverage' => 10,
            'initial_sl' => 108.0,
            'current_sl' => 110.0,
            'tp1_price' => 112.0,
            'tp2_price' => 114.0,
            'realized_pnl' => 1.0,
            'pnl_percent' => 20.0,
            'fee_paid' => 0.005,
            'exit_reason' => 'TP1_HIT',
            'opened_at' => Carbon::now()->subMinutes(15),
            'closed_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($user)->get('/history/export?mode=paper');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
    }
}
