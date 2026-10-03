<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Trading\TradingTargetManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TradingCoinApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_authenticated_user_can_get_trading_coin(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(route('api.trading_coin.get'));

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'active_coin' => 'BTCUSDT',
                'base' => 'BTC',
            ])
            ->assertJsonStructure([
                'success',
                'active_coin',
                'base',
                'available_coins',
                'timeframes',
            ]);
    }

    public function test_admin_can_set_trading_coin(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)->postJson(route('api.trading_coin.set'), [
            'coin' => 'sol',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'active_coin' => 'SOLUSDT',
                'base' => 'SOL',
            ]);

        $this->assertEquals('SOLUSDT', TradingTargetManager::getActiveCoin());
    }

    public function test_set_trading_coin_requires_valid_symbol(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)->postJson(route('api.trading_coin.set'), [
            'coin' => '   ',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_viewer_cannot_set_trading_coin(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->postJson(route('api.trading_coin.set'), ['coin' => 'sol'])->assertForbidden();
    }
}
