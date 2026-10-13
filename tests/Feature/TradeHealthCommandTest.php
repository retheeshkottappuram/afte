<?php

namespace Tests\Feature;

use App\Models\TradingAccount;
use App\Services\Strategy\Watchlist;
use App\Services\Trading\TradingModeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TradeHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trading.mode' => 'paper',
            'trading.telegram.enabled' => true,
            'trading.telegram.bot_token' => '123:SECRET',
            'trading.telegram.chat_id' => '999',
        ]);
        Watchlist::reset();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 55]])]);
        TradingAccount::getForMode('paper')->update(['balance' => 24.0, 'equity' => 24.0, 'peak_equity' => 24.0, 'is_running' => true]);
        app(TradingModeManager::class)->recordHeartbeat(['last_scan_at' => now()->toIso8601String(), 'last_scan_summary' => 'Scanned 50 symbols, 0 fresh signals.']);
    }

    protected function health(string $args = ''): string
    {
        Artisan::call(trim('trade:health '.$args));

        return Artisan::output();
    }

    public function test_all_clear_says_no_setup_qualified_yet_and_never_prints_the_token(): void
    {
        $out = $this->health();

        $this->assertStringContainsString('Heartbeat: running', $out);
        $this->assertStringContainsString('No setup qualified yet', $out);
        $this->assertStringNotContainsString('SECRET', $out);
    }

    public function test_a_stale_heartbeat_points_at_the_cron(): void
    {
        $this->travel(10)->minutes();

        $this->assertStringContainsString('cron must run "php artisan schedule:run"', $this->health());
    }

    public function test_a_stopped_or_paused_account_is_named(): void
    {
        TradingAccount::getForMode('paper')->update(['is_running' => false]);
        $this->assertStringContainsString('Auto-trading is switched OFF', $this->health());

        TradingAccount::getForMode('paper')->update(['is_running' => true, 'paused_until' => now()->addHours(3), 'pause_reason' => '3 losses in a row']);
        $this->assertStringContainsString('3 losses in a row', $this->health());
    }

    public function test_telegram_off_or_alerts_switched_off_is_named(): void
    {
        config(['trading.telegram.enabled' => false]);
        $this->assertStringContainsString('Telegram is OFF', $this->health());

        config(['trading.telegram.enabled' => true]);
        Watchlist::setAlertsEnabled(false);
        $this->assertStringContainsString('switched OFF on the dashboard', $this->health());
    }

    public function test_send_test_delivers_a_message(): void
    {
        $out = $this->health('--send-test');

        $this->assertStringContainsString('Test message: sent (message id 55)', $out);
        Http::assertSent(fn (Request $r): bool => str_contains((string) ($r->data()['text'] ?? ''), 'AFTE test'));
    }
}
