<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Strategy\MarketScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class ManualMarketScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_button_queues_the_scan_instead_of_running_it_in_the_request(): void
    {
        Http::fake();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('market-scan.start'))->assertOk()->assertJsonFragment(['status' => 'RUNNING']);

        $status = $this->actingAs($admin)->getJson(route('market-scan.status'))->assertOk();
        $this->assertTrue($status->json('is_running'));
        $this->assertTrue(app(MarketScanService::class)->isManualScanQueued());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/klines'));
    }

    public function test_a_second_click_does_not_queue_a_duplicate_scan(): void
    {
        $scanner = app(MarketScanService::class);

        $this->assertTrue($scanner->requestManualScan());
        $this->assertFalse($scanner->requestManualScan());
    }

    public function test_cron_command_only_scans_when_a_scan_was_requested(): void
    {
        $this->mock(MarketScanService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isManualScanQueued')->twice()->andReturn(false, true);
            $mock->shouldReceive('runManualScan')->once()->andReturn(['message' => 'Scanned 150 coins, 12 active setups from the last 6 candles.', 'rows' => []]);
        });

        $this->artisan('crypto:scan --manual')->assertSuccessful();
        $this->artisan('crypto:scan --manual')->expectsOutput('Scanned 150 coins, 12 active setups from the last 6 candles.')->assertSuccessful();
    }

    public function test_manual_scan_does_not_block_the_engines_hourly_scan(): void
    {
        $scanner = app(MarketScanService::class);
        $scanner->setManualState(['status' => 'COMPLETED'], replace: true);

        $this->travelTo(now()->startOfHour()->addMinutes(2));

        $this->assertTrue($scanner->isScanDue('1h'), 'The engine scan stays due regardless of manual scans');
    }
}
