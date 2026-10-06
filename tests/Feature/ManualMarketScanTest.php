<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Strategy\MarketScanService;
use Illuminate\Console\Scheduling\Schedule;
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

    public function test_a_scan_killed_mid_way_is_rerun_instead_of_blocking_forever(): void
    {
        $scanner = app(MarketScanService::class);
        $scanner->setManualState(['status' => 'RUNNING', 'index' => 40, 'total' => 150], replace: true);

        $this->assertFalse($scanner->isManualScanQueued(), 'A scan that is still making progress is left alone');
        $this->assertFalse($scanner->requestManualScan());

        $this->travel(MarketScanService::MANUAL_STALE_SECONDS + 10)->seconds();

        $this->assertTrue($scanner->isManualScanQueued(), 'The cron worker restarts a scan that stopped updating');
        $this->assertTrue($scanner->requestManualScan(), 'The button can queue a new scan');
    }

    public function test_status_reports_how_old_the_results_are(): void
    {
        Http::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        Setting::putValue(MarketScanService::MANUAL_RESULTS_KEY, ['scanned_at' => now()->subMinutes(125)->toIso8601String(), 'rows' => []]);

        $this->actingAs($admin)->getJson(route('market-scan.status'))
            ->assertOk()
            ->assertJsonPath('age_minutes', 125)
            ->assertJsonPath('is_running', false);
    }

    public function test_the_manual_scan_is_not_behind_the_scheduler_mutex(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains($e->command, 'crypto:scan --manual'));

        $this->assertNotNull($event);
        $this->assertFalse($event->withoutOverlapping, 'A stuck cache mutex must never silently skip the on-demand scan');
    }
}
