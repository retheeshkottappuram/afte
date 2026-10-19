<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScannerPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/scanner')->assertRedirect('/login');
    }

    public function test_the_scanner_has_its_own_page_and_menu_entry(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get('/scanner');

        $response->assertOk();
        $response->assertSee('id="btnStartMarketScan"', false);
        $response->assertSee('id="breakoutWatchList"', false);
        $response->assertSee('Market Scanner');
        $response->assertSee(route('signals.dashboard'), false);
    }

    public function test_the_chart_page_no_longer_holds_the_scanner(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get('/signals');

        $response->assertOk();
        $response->assertDontSee('id="btnStartMarketScan"', false);
        $response->assertDontSee('pollMarketScanStatus', false);
        $response->assertSee(route('scanner.index'), false);
        $response->assertSee('id="sap-inspector"', false);
    }
}
