<?php

namespace Tests\Feature;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\StrategyEngineTest;

class SignalAlgoChartStrictExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['trading.mode' => 'paper', 'trading.telegram.enabled' => false]);
        TradingAccount::getForMode('paper')->update(['balance' => 100.0, 'equity' => 100.0, 'peak_equity' => 100.0, 'is_running' => true]);
    }

    /**
     * Fake Binance: klines are a synthetic walk ending at the current time (flat when $flat is true).
     */
    protected function fakeBinance(bool $flat = false): void
    {
        Http::fake(function (Request $request) use ($flat) {
            if (! str_contains($request->url(), '/klines')) {
                return Http::response([]);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $limit = (int) ($query['limit'] ?? 300);
            $seconds = ['15m' => 900, '1h' => 3600, '4h' => 14400, '1d' => 86400][$query['interval'] ?? '1h'] ?? 3600;
            $walk = StrategyEngineTest::randomWalk($limit, 5);
            $lastClose = intdiv(time(), $seconds) * $seconds; // the newest candle closes now-ish (still forming)

            $rows = [];
            for ($i = 0; $i < $limit; $i++) {
                $closeTime = ($lastClose - ($limit - 1 - $i) * $seconds + $seconds) * 1000 - 1;
                $price = $flat ? 100.0 : $walk['closes'][$i];
                $rows[] = [
                    $closeTime - $seconds * 1000 + 1,
                    (string) ($flat ? 100.0 : $walk['opens'][$i]),
                    (string) ($flat ? 100.05 : $walk['highs'][$i]),
                    (string) ($flat ? 99.95 : $walk['lows'][$i]),
                    (string) $price,
                    (string) $walk['volumes'][$i],
                    $closeTime,
                ];
            }

            return Http::response($rows);
        });
    }

    public function test_chart_api_returns_overlays_and_inspector_and_never_trades(): void
    {
        $this->fakeBinance();
        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)->getJson(route('dashboard.analyze', ['symbol' => 'SOLUSDT', 'interval' => '1h']));

        $response->assertOk()->assertJsonStructure([
            'success', 'symbol', 'interval', 'mode', 'price',
            'candles', 'ema9', 'ema21', 'ema50', 'ema200', 'trend_ribbon', 'levels', 'markers', 'signal_history', 'stats_strip',
            'state' => ['bias', 'status', 'reason', 'checklist'],
            'mtf', 'all_setup_stats', 'monitored_coins',
        ]);
        $this->assertCount(4, $response->json('mtf'));
        $this->assertNotEmpty($response->json('trend_ribbon'));
        $this->assertSame(0, Trade::count(), 'Viewing a chart must never place orders');
    }

    public function test_manual_trade_is_refused_without_a_current_strategy_signal(): void
    {
        $this->fakeBinance(flat: true);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson(route('api.execute_radar_trade'), ['symbol' => 'BTCUSDT', 'direction' => 'LONG']);

        $response->assertStatus(422);
        $this->assertFalse($response->json('success'));
        $this->assertStringContainsString('No current LONG signal', $response->json('message'));
        $this->assertDatabaseMissing('trades', ['symbol' => 'BTCUSDT', 'status' => 'OPEN']);
    }

    public function test_active_trade_is_returned_to_chart_api_with_exact_levels(): void
    {
        $this->fakeBinance();
        $user = User::factory()->create(['role' => 'admin']);

        $trade = Trade::create([
            'symbol' => 'BTCUSDT', 'setup_tag' => 'TREND_PULLBACK', 'side' => 'LONG', 'mode' => 'paper', 'status' => 'OPEN', 'stage' => 'ENTRY',
            'entry_price' => 65000.00, 'quantity' => 0.01, 'remaining_quantity' => 0.01, 'margin_used' => 65.00, 'leverage' => 10,
            'initial_sl' => 64200.00, 'current_sl' => 64200.00, 'tp1_price' => 66200.00, 'tp2_price' => 67400.00, 'opened_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson(route('dashboard.analyze', ['symbol' => 'BTCUSDT', 'interval' => '1h']));

        $response->assertOk();
        $this->assertSame($trade->id, $response->json('active_trade.id'));
        $this->assertEquals(64200.00, $response->json('active_trade.current_sl'));
        $this->assertEquals(66200.00, $response->json('active_trade.tp1_price'));
    }
}
