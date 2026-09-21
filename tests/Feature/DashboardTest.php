<?php

namespace Tests\Feature;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_terminal_renders(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');
        $response->assertStatus(200);
        $response->assertSee('AFTE');
        $response->assertSee('Binance Futures USDS-M');
        $response->assertSee('Active Open Positions');
    }

    public function test_api_stats_endpoint(): void
    {
        $user = User::factory()->create();

        TradingAccount::create([
            'mode' => 'paper',
            'balance' => 5.0,
            'initial_balance' => 5.0,
        ]);

        $response = $this->actingAs($user)->getJson('/api/stats?mode=paper');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'mode',
            'balance',
            'equity',
            'unrealized_pnl',
            'target_balance',
            'progress_pct',
            'win_rate',
            'stage',
            'kill_switch',
        ]);
        $response->assertJson([
            'mode' => 'paper',
            'balance' => 5.0,
            'target_balance' => 500.0,
        ]);
    }

    public function test_api_positions_endpoint(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/positions?mode=paper');
        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    public function test_api_kill_switch_toggle(): void
    {
        $user = User::factory()->create();

        TradingAccount::create([
            'mode' => 'paper',
            'balance' => 5.0,
            'initial_balance' => 5.0,
            'kill_switch' => false,
        ]);

        $response = $this->actingAs($user)->postJson('/api/kill-switch', ['mode' => 'paper']);
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'kill_switch' => true,
        ]);

        $account = TradingAccount::where('mode', 'paper')->first();
        $this->assertTrue($account->kill_switch);
    }

    public function test_admin_can_toggle_auto_trading(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $account = TradingAccount::create([
            'mode' => 'paper',
            'balance' => 5.0,
            'initial_balance' => 5.0,
            'is_running' => false,
        ]);

        $response = $this->actingAs($admin)->postJson('/api/toggle-auto-trading', ['mode' => 'paper']);
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'is_running' => true,
        ]);

        $account->refresh();
        $this->assertTrue($account->is_running);

        // Toggle back to false
        $response = $this->actingAs($admin)->postJson('/api/toggle-auto-trading', ['mode' => 'paper']);
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'is_running' => false,
        ]);

        $account->refresh();
        $this->assertFalse($account->is_running);
    }

    public function test_non_admin_cannot_toggle_auto_trading(): void
    {
        $trader = User::factory()->create(['role' => 'trader']);

        TradingAccount::create([
            'mode' => 'paper',
            'balance' => 5.0,
            'initial_balance' => 5.0,
            'is_running' => false,
        ]);

        $response = $this->actingAs($trader)->postJson('/api/toggle-auto-trading', ['mode' => 'paper']);
        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
        ]);
    }

    public function test_auto_tick_endpoint_runs_safely(): void
    {
        $user = User::factory()->create();

        TradingAccount::create([
            'mode' => 'paper',
            'balance' => 5.0,
            'initial_balance' => 5.0,
            'is_running' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/api/auto-tick', ['mode' => 'paper']);
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'is_running',
            'managed_positions',
            'closed_positions',
            'scanned_symbols',
        ]);
    }

    public function test_dashboard_renders_auto_trading_button_for_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get('/');
        $response->assertStatus(200);
        $response->assertSee('id="btn-auto-trading"', false);
        $response->assertDontSee('id="badge-auto-trading"', false);
    }

    public function test_dashboard_renders_auto_trading_badge_for_trader(): void
    {
        $trader = User::factory()->create(['role' => 'trader']);

        $response = $this->actingAs($trader)->get('/');
        $response->assertStatus(200);
        $response->assertDontSee('id="btn-auto-trading"', false);
        $response->assertSee('id="badge-auto-trading"', false);
    }

    public function test_dashboard_renders_websocket_status_badge(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');
        $response->assertStatus(200);
        $response->assertSee('id="wss-badge"', false);
        $response->assertSee('WSS:', false);
    }

    public function test_positions_endpoint_preserves_newly_opened_trades(): void
    {
        $user = User::factory()->create();

        $trade = Trade::create([
            'symbol' => 'SOLUSDT',
            'side' => 'LONG',
            'mode' => 'paper',
            'status' => 'OPEN',
            'stage' => 'ENTRY',
            'entry_price' => 150.0,
            'quantity' => 1.0,
            'remaining_quantity' => 1.0,
            'margin_used' => 15.0,
            'leverage' => 10,
            'initial_sl' => 147.0,
            'current_sl' => 147.0,
            'tp1_price' => 153.0,
            'tp2_price' => 156.0,
            'be_locked' => false,
            'tp1_hit' => false,
            'tp2_hit' => false,
            'opened_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/positions?mode=paper');
        $response->assertStatus(200);
        $response->assertJsonFragment(['symbol' => 'SOLUSDT', 'stage' => 'ENTRY']);

        $this->assertEquals('OPEN', $trade->fresh()->status);
    }
}
