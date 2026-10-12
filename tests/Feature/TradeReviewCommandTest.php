<?php

namespace Tests\Feature;

use App\Models\CryptoSignal;
use App\Models\Trade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class TradeReviewCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_entry_lateness_excursions_and_result_in_r(): void
    {
        $signal = CryptoSignal::create([
            'symbol' => 'SOLUSDT', 'interval' => '1h', 'side' => 'BUY', 'setup' => 'EARLY_BREAKOUT', 'setup_type' => 'EARLY_BREAKOUT', 'passed_filters' => true,
            'entry_price' => 100.0, 'stop_loss' => 98.0, 'take_profit_1' => 103.0, 'take_profit_2' => 106.0, 'take_profit_3' => 109.0,
            'candle_close_time' => now(), 'source' => 'watcher', 'sent_at' => now(), 'outcome' => 'OPEN', 'features' => ['box_edge' => 99.5],
        ]);
        // Filled at 100.5, 1R = 2.0 below: 0.5R past the box edge, 0.5% slip vs the signal.
        Trade::create([
            'symbol' => 'SOLUSDT', 'setup_tag' => 'EARLY_BREAKOUT', 'side' => 'LONG', 'mode' => 'live', 'status' => 'CLOSED', 'stage' => 'CLOSED',
            'entry_price' => 100.5, 'quantity' => 1.0, 'remaining_quantity' => 0.0, 'margin_used' => 10.0, 'leverage' => 10,
            'initial_sl' => 98.5, 'current_sl' => 98.5, 'tp1_price' => 103.5, 'tp2_price' => 106.5,
            'highest_price' => 101.5, 'lowest_price' => 98.5, 'exit_price' => 98.5, 'exit_reason' => 'STOP_LOSS', 'net_pnl' => -0.24,
            'meta' => ['signal_id' => $signal->id, 'risk_usd' => 0.24, 'grade' => 'B'], 'opened_at' => now()->subHour(), 'closed_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call('trade:review', ['--days' => 7]));
        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/SOLUSDT .* \+0\.50R +\| \+0\.50% +\| 1\.99% +\| \+0\.50R +\| -1\.00R +\| STOP_LOSS +\| -1\.00R/', $output);
        $this->assertStringContainsString('Closed: 1, wins 0 (0%), avg -1.00R', $output);
    }

    public function test_live_breakout_trades_without_a_signal_id_use_the_stored_box_edge_and_the_matching_signal(): void
    {
        CryptoSignal::create([
            'symbol' => 'ADAUSDT', 'interval' => '1h', 'side' => 'SHORT', 'setup' => 'EARLY_BREAKOUT', 'setup_type' => 'EARLY_BREAKOUT', 'passed_filters' => true,
            'entry_price' => 0.25, 'stop_loss' => 0.255, 'take_profit_1' => 0.2425, 'take_profit_2' => 0.235, 'take_profit_3' => 0.2275,
            'candle_close_time' => now(), 'source' => 'watcher', 'sent_at' => now(), 'outcome' => 'OPEN',
        ]);
        // Short filled at 0.249, stop 0.254 (1R = 0.005), box low 0.2505: 0.30R past the box, sold 0.40% below the signal (worse).
        Trade::create([
            'symbol' => 'ADAUSDT', 'setup_tag' => 'EARLY_BREAKOUT', 'side' => 'SHORT', 'mode' => 'live', 'status' => 'OPEN', 'stage' => 'ENTRY',
            'entry_price' => 0.249, 'quantity' => 40.0, 'remaining_quantity' => 40.0, 'margin_used' => 1.0, 'leverage' => 10,
            'initial_sl' => 0.254, 'current_sl' => 0.254, 'tp1_price' => 0.2415, 'tp2_price' => 0.234,
            'meta' => ['breakout_box' => 0.2505, 'risk_usd' => 0.2], 'opened_at' => now(),
        ]);

        Artisan::call('trade:review', ['--mode' => 'live']);

        $this->assertMatchesRegularExpression('/ADAUSDT .* \+0\.30R +\| \+0\.40% /', Artisan::output());
    }

    public function test_it_says_so_when_there_are_no_trades(): void
    {
        $this->artisan('trade:review')->expectsOutput('No trades in the last 7 days.')->assertSuccessful();
    }
}
