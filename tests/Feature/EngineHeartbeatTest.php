<?php

namespace Tests\Feature;

use App\Models\TradingAccount;
use App\Services\Strategy\MarketScanService;
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
    }

    public function test_heartbeat_keeps_the_last_scan_between_runs_without_a_scan(): void
    {
        $this->mock(MarketScanService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isScanDue')->andReturn(false);
            $mock->shouldReceive('latestResults')->andReturn(['scanned_at' => '2026-10-04T09:02:09+00:00', 'symbols_scanned' => 45, 'fresh_signals' => 4]);
        });

        $this->artisan('trade:engine --once')->assertSuccessful();
        $this->artisan('trade:engine --once')->assertSuccessful();

        $status = app(TradingDaemonManager::class)->status();
        $this->assertSame('2026-10-04T09:02:09+00:00', $status['last_scan_at']);
        $this->assertSame('Scanned 45 symbols, 4 fresh signals.', $status['last_scan_summary']);
        $this->assertNotNull($status['next_scan_at']);
    }

    public function test_a_failed_scan_is_released_for_a_retry_and_trade_management_continues(): void
    {
        $this->mock(MarketScanService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isScanDue')->andReturn(true);
            $mock->shouldReceive('latestResults')->andReturn([]);
            $mock->shouldReceive('runScan')->once()->andThrow(new RuntimeException('fopen(cache file): Failed to open stream'));
            $mock->shouldReceive('releaseScan')->once()->andReturn(true);
        });

        $this->artisan('trade:engine --once')->assertSuccessful();

        $details = app(TradingModeManager::class)->heartbeat()['details'];
        $this->assertSame('running', app(TradingModeManager::class)->heartbeat()['state'], 'The run finished and wrote its heartbeat');
        $this->assertStringContainsString('Scan failed: fopen', $details['last_error']);
        $this->assertNotNull($details['last_error_at']);
    }
}
