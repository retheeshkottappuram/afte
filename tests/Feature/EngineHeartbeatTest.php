<?php

namespace Tests\Feature;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Trading\DynamicTradeManager;
use App\Services\Trading\NearBreakoutTrader;
use App\Services\Trading\TradingDaemonManager;
use App\Services\Trading\TradingModeManager;
use ErrorException;
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
        $this->assertStringContainsString('[near breakout] HTTP 503', $details['last_error']);
        $this->assertNotNull($details['last_error_at']);
    }

    public function test_a_cache_file_error_in_one_trade_does_not_stop_the_rest_of_the_pass(): void
    {
        $make = fn (string $symbol): Trade => Trade::create([
            'symbol' => $symbol, 'side' => 'LONG', 'mode' => 'paper', 'status' => 'OPEN', 'stage' => 'ENTRY',
            'entry_price' => 100.0, 'quantity' => 0.5, 'remaining_quantity' => 0.5, 'margin_used' => 10.0, 'leverage' => 1,
            'initial_sl' => 99.0, 'current_sl' => 99.0, 'tp1_price' => 0, 'tp2_price' => 0, 'opened_at' => now(),
        ]);
        $broken = $make('SOLUSDT');
        $make('NEARUSDT');
        $this->mock(DynamicTradeManager::class, function (MockInterface $mock) use ($broken): void {
            $mock->shouldReceive('manageTrade')->twice()->andReturnUsing(function (Trade $t) use ($broken): array {
                if ($t->id === $broken->id) {
                    throw new ErrorException('fopen(/storage/framework/cache/data/0a/d3/0ad3): Failed to open stream: No such file or directory');
                }

                return ['status' => 'managed', 'message' => 'ok'];
            });
        });
        $this->mock(NearBreakoutTrader::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->once()->andReturn(['action' => 'none', 'message' => 'No entry.']);
        });

        $this->artisan('trade:engine --once')->assertSuccessful();

        $beat = app(TradingModeManager::class)->heartbeat();
        $this->assertSame('running', $beat['state'], 'The heartbeat is still written');
        $this->assertSame(1, $beat['details']['managed'], 'The other trade was managed');
        $this->assertStringContainsString("[manage #{$broken->id}] fopen(", $beat['details']['last_error']);
        $this->assertStringContainsString("ERROR [manage #{$broken->id}] fopen(", implode("\n", app(TradingDaemonManager::class)->getRecentLogs(20)));
    }
}
