<?php

namespace Tests\Feature;

use App\Models\TradingAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TradingPauseBarTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_paused_account_is_reported_on_the_signals_page_and_can_be_resumed(): void
    {
        config(['trading.mode' => 'paper']);
        $admin = User::factory()->create(['role' => 'admin']);
        $account = TradingAccount::getForMode('paper');
        $account->update(['balance' => 22.5, 'is_running' => true, 'day_start_equity' => 24.0,
            'paused_until' => now()->addHours(5), 'pause_reason' => 'daily loss limit hit (-6.25%)']);

        $status = $this->actingAs($admin)->getJson(route('daemon.status'))->assertOk();
        $this->assertSame('paused', $status->json('stats.trading.kind'));
        $this->assertStringContainsString('daily loss limit hit', $status->json('stats.trading.reason'));
        $this->actingAs($admin)->get('/signals')->assertSee('id="tradingPauseBar"', false)->assertSee('Reset & Resume Auto-Trading', false);

        $this->actingAs($admin)->postJson(route('api.resume_cooldown'), ['mode' => 'paper'])->assertOk()->assertJsonPath('success', true);

        $account->refresh();
        $this->assertNull($account->paused_until);
        $this->assertEqualsWithDelta(22.5, (float) $account->day_start_equity, 1e-9, 'The daily loss stop counts again from now');
        $this->assertNull($this->actingAs($admin)->getJson(route('daemon.status'))->json('stats.trading'));
    }

    public function test_stopped_auto_trading_is_reported(): void
    {
        config(['trading.mode' => 'paper']);
        $admin = User::factory()->create(['role' => 'admin']);
        TradingAccount::getForMode('paper')->update(['is_running' => false]);

        $this->assertSame('stopped', $this->actingAs($admin)->getJson(route('daemon.status'))->json('stats.trading.kind'));
    }
}
