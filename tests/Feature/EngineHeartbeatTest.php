<?php

namespace Tests\Feature;

use App\Models\TradingAccount;
use App\Services\Trading\NearBreakoutTrader;
use App\Services\Trading\TradingDaemonManager;
use App\Services\Trading\TradingModeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class EngineHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config(['trading.mode' => 'paper', 'trading.telegram.enabled' => false]);
        TradingAccount::getForMode('paper')->update(['balance' => 100.0, 'peak_equity' => 100.0, 'is_running' => true]);
        $this->travelTo(now()->setTime(10, 5));
    }

    public function test_heartbeat_keeps_the_last_candle_decision_between_runs(): void
    {
        $this->mock(NearBreakoutTrader::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->andReturn(['action' => 'none', 'message' => 'No entry: not green candle.'], ['action' => 'none', 'message' => 'Candle already handled.']);
        });
        app(TradingModeManager::class)->recordHeartbeat(['last_scan_at' => '2026-10-04T09:00:30+00:00', 'last_scan_summary' => 'NEAR 1h: No entry: not green candle.']);

        $this->artisan('trade:engine --once')->assertSuccessful();

        $status = app(TradingDaemonManager::class)->status();
        $this->assertSame('2026-10-04T09:00:30+00:00', $status['last_scan_at']);
        $this->assertSame('NEAR 1h: No entry: not green candle.', $status['last_scan_summary']);
        $this->assertNotNull($status['next_scan_at']);
    }

    public function test_a_failed_near_pass_is_recorded_and_trade_management_continues(): void
    {
        $this->mock(NearBreakoutTrader::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->once()->andThrow(new RuntimeException('HTTP 503 from Binance'));
        });

        $this->artisan('trade:engine --once')->assertSuccessful();

        $details = app(TradingModeManager::class)->heartbeat()['details'];
        $this->assertSame('running', app(TradingModeManager::class)->heartbeat()['state'], 'The run finished and wrote its heartbeat');
        $this->assertStringContainsString('NEAR breakout: HTTP 503', $details['last_error']);
        $this->assertNotNull($details['last_error_at']);
    }
}
