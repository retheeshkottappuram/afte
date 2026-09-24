<?php

namespace Tests\Feature;

use App\Models\CryptoSignal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CryptoSignalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/signals');
        $response->assertRedirect('/login');

        $response = $this->get('/alerts');
        $response->assertRedirect('/login');
    }

    public function test_admin_can_view_crypto_signals_dashboard(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get('/signals');
        $response->assertStatus(200);
        $response->assertSee('Signal Monitoring Dashboard');
    }

    public function test_admin_can_view_telegram_alert_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        CryptoSignal::create([
            'symbol' => 'SOLUSDT',
            'market' => 'Binance USDⓈ-M Futures',
            'interval' => '15m',
            'side' => 'BUY',
            'setup_type' => 'TREND',
            'score' => 88,
            'grade' => 'A',
            'entry_price' => 150.25,
            'stop_loss' => 147.00,
            'take_profit_1' => 153.50,
            'take_profit_2' => 156.75,
            'take_profit_3' => 160.00,
            'risk_reward' => '1 : 2.0',
            'rsi' => 58.5,
            'adx' => 28.2,
            'volume_ratio' => 1.45,
            'atr_pct' => 1.8,
            'source' => 'manual_alert',
            'telegram_sent' => true,
            'sent_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/alerts');
        $response->assertStatus(200);
        $response->assertSee('SOLUSDT');
        $response->assertSee('Telegram Alert History');
    }

    public function test_daemon_status_endpoint_returns_json(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->getJson('/daemon/status');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'is_running',
            'sentinel_enabled',
            'stats',
        ]);
    }

    public function test_market_scan_status_endpoint_returns_json(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->getJson('/market-scan/status');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'status',
            'is_running',
            'progress',
            'signals',
        ]);
    }
}
