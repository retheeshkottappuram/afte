<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Trade;
use App\Models\TradingAccount;
use App\Models\User;
use App\Services\Strategy\MarketScanService;
use App\Services\Trading\TradingModeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class TradingEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config(['trading.mode' => 'paper', 'trading.telegram.enabled' => false]);
    }

    public function test_switching_to_live_without_api_keys_is_refused(): void
    {
        config(['trading.allow_live_trading' => true, 'trading.binance.api_key' => '', 'trading.binance.api_secret' => '']);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson(route('api.trading_mode'), ['mode' => 'live', 'confirm' => true]);

        $response->assertStatus(422);
        $this->assertStringContainsString('API key', $response->json('message'));
        $this->assertSame('paper', app(TradingModeManager::class)->activeMode());
    }

    public function test_switching_to_live_requires_explicit_confirmation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson(route('api.trading_mode'), ['mode' => 'live']);

        $response->assertStatus(422)->assertJsonFragment(['requires_confirmation' => true]);
    }

    public function test_mode_switch_is_stored_server_side(): void
    {
        Setting::putValue(TradingModeManager::MODE_KEY, 'live');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('api.trading_mode'), ['mode' => 'paper'])->assertOk();

        $this->assertSame('paper', Setting::getValue(TradingModeManager::MODE_KEY));
    }

    public function test_users_without_permission_cannot_change_the_mode(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->postJson(route('api.trading_mode'), ['mode' => 'paper'])->assertForbidden();
    }

    public function test_engine_cycle_manages_trades_and_records_a_heartbeat_in_the_active_mode(): void
    {
        $this->mock(MarketScanService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isScanDue')->andReturn(false);
        });
        TradingAccount::getForMode('paper')->update(['balance' => 100.0, 'peak_equity' => 100.0, 'is_running' => true]);
        $trade = Trade::create([
            'symbol' => 'SOLUSDT', 'side' => 'LONG', 'mode' => 'paper', 'status' => 'OPEN', 'stage' => 'ENTRY',
            'entry_price' => 100.0, 'quantity' => 0.5, 'remaining_quantity' => 0.5, 'margin_used' => 10.0, 'leverage' => 5,
            'initial_sl' => 99.0, 'current_sl' => 99.0, 'tp1_price' => 101.5, 'tp2_price' => 103.0, 'opened_at' => now()->subHours(13),
        ]);
        Cache::put('binance:mark:SOLUSDT', 100.10, 60);

        $this->artisan('trade:engine --once')->assertSuccessful();

        $heartbeat = app(TradingModeManager::class)->heartbeat();
        $this->assertSame('running', $heartbeat['state']);
        $this->assertSame('paper', $heartbeat['details']['mode']);
        $this->assertSame('TIME_STOP', $trade->refresh()->exit_reason, 'Open trades are managed by the cron engine');
    }

    public function test_overlapping_engine_runs_exit_immediately(): void
    {
        $lock = Cache::lock('trade-engine', 70);
        $this->assertTrue($lock->get());

        $this->artisan('trade:engine --once')->expectsOutput('Another engine cycle is running. Exiting.')->assertSuccessful();

        $this->assertSame('never_ran', app(TradingModeManager::class)->heartbeat()['state']);
        $lock->release();
    }

    public function test_engine_reports_live_mode_unavailable_instead_of_silently_trading_paper(): void
    {
        $this->mock(MarketScanService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isScanDue')->andReturn(false);
        });
        config(['trading.allow_live_trading' => false]);
        Setting::putValue(TradingModeManager::MODE_KEY, 'live');

        $this->artisan('trade:engine --once')->assertSuccessful();

        $details = app(TradingModeManager::class)->heartbeat()['details'];
        $this->assertSame('live', $details['mode']);
        $this->assertStringContainsString('Live mode unavailable', $details['paused_reason']);
    }
}
