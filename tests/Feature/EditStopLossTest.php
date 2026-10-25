<?php

namespace Tests\Feature;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Models\User;
use App\Services\Trading\DynamicTradeManager;
use App\Services\Trading\SwingSarTrader;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EditStopLossTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['trading.mode' => 'paper', 'trading.telegram.enabled' => false, 'trading.swing_sar.profit_lock' => []]);
        Http::fake(['*premiumIndex*' => Http::response(['markPrice' => '5.0']), '*' => Http::response([])]);
        TradingAccount::create(['mode' => 'paper', 'balance' => 24.0, 'initial_balance' => 24.0, 'equity' => 24.0, 'peak_equity' => 24.0, 'is_running' => true]);
    }

    protected function trade(array $overrides = []): Trade
    {
        return Trade::create(array_merge([
            'symbol' => 'NEARUSDT', 'setup_tag' => SwingSarTrader::SETUP, 'side' => 'LONG', 'mode' => 'paper', 'status' => 'OPEN', 'stage' => 'ENTRY',
            'entry_price' => 5.0, 'quantity' => 10.0, 'remaining_quantity' => 10.0, 'margin_used' => 10.0, 'leverage' => 5,
            'initial_sl' => 4.85, 'current_sl' => 4.85, 'tp1_price' => 5.2, 'tp2_price' => 5.4,
            'highest_price' => 5.0, 'lowest_price' => 5.0, 'meta' => ['interval' => '15m', 'atr' => 0.06, 'sar_trail' => 4.80], 'opened_at' => Carbon::now()->subHour(),
        ], $overrides));
    }

    protected function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_the_user_sets_the_stop_and_the_dynamic_rules_continue_from_it(): void
    {
        $trade = $this->trade();

        $this->actingAs($this->admin())->postJson(route('api.update_stop_loss'), ['trade_id' => $trade->id, 'stop_loss' => 4.90])
            ->assertOk()->assertJsonPath('success', true);

        $trade->refresh();
        $this->assertEqualsWithDelta(4.90, (float) $trade->current_sl, 1e-9);
        $this->assertEqualsWithDelta(4.90, (float) $trade->meta['manual_sl']['price'], 1e-9);
        $this->assertEqualsWithDelta(4.85, (float) $trade->initial_sl, 1e-9, 'R is unchanged');

        // A pullback never loosens the user's stop; at +1R the runner exit still moves it to breakeven.
        $manager = app(DynamicTradeManager::class);
        $manager->manageTrade($trade->fresh(), 4.95);
        $this->assertEqualsWithDelta(4.90, (float) $trade->fresh()->current_sl, 1e-9);
        $manager->manageTrade($trade->fresh(), 5.16);
        $this->assertGreaterThanOrEqual(5.0, (float) $trade->fresh()->current_sl);
        $this->assertEqualsWithDelta(10.0, (float) $trade->fresh()->remaining_quantity, 1e-9, 'No partials: the winner runs');
    }

    public function test_the_stop_may_be_loosened_within_the_limit(): void
    {
        $trade = $this->trade(['current_sl' => 4.95]);

        $this->actingAs($this->admin())->postJson(route('api.update_stop_loss'), ['trade_id' => $trade->id, 'stop_loss' => 4.80])->assertOk();

        $this->assertEqualsWithDelta(4.80, (float) $trade->fresh()->current_sl, 1e-9);
    }

    public function test_a_stop_on_the_wrong_side_or_too_far_is_rejected(): void
    {
        $trade = $this->trade();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('api.update_stop_loss'), ['trade_id' => $trade->id, 'stop_loss' => 5.10])
            ->assertStatus(422)->assertJsonPath('success', false);
        $this->actingAs($admin)->postJson(route('api.update_stop_loss'), ['trade_id' => $trade->id, 'stop_loss' => 4.50])
            ->assertStatus(422);

        $this->assertEqualsWithDelta(4.85, (float) $trade->fresh()->current_sl, 1e-9);
    }

    public function test_an_auto_breakout_trade_can_be_edited_too(): void
    {
        $trade = $this->trade(['setup_tag' => 'breakout', 'side' => 'SHORT', 'initial_sl' => 5.15, 'current_sl' => 5.15, 'tp1_price' => 4.8, 'tp2_price' => 4.6]);

        $this->actingAs($this->admin())->postJson(route('api.update_stop_loss'), ['trade_id' => $trade->id, 'stop_loss' => 5.05])->assertOk();

        $this->assertEqualsWithDelta(5.05, (float) $trade->fresh()->current_sl, 1e-9);
    }

    public function test_users_without_trading_rights_cannot_edit(): void
    {
        $trade = $this->trade();

        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->postJson(route('api.update_stop_loss'), ['trade_id' => $trade->id, 'stop_loss' => 4.90])
            ->assertForbidden();
        $this->assertEqualsWithDelta(4.85, (float) $trade->fresh()->current_sl, 1e-9);
    }
}
