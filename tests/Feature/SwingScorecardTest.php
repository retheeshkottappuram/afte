<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Crypto\BinanceClient;
use App\Services\Strategy\SwingScorecard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class SwingScorecardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Closed candles: alternating long rallies and drops (clean swings), so SuperTrend flips both ways.
     *
     * @return array<string, array<int, float|int>>
     */
    protected function swings(int $n, int $barMs = 900000): array
    {
        $c = ['opens' => [], 'highs' => [], 'lows' => [], 'closes' => [], 'volumes' => [], 'closeTimes' => []];
        $end = intdiv(time() * 1000, $barMs) * $barMs - 1;
        $price = 100.0;
        for ($i = 0; $i < $n; $i++) {
            $leg = intdiv($i, 40) % 2 === 0 ? 1 : -1;
            $open = $price;
            $price *= 1 + $leg * 0.004 + 0.001 * sin($i);
            $c['opens'][] = $open;
            $c['closes'][] = $price;
            $c['highs'][] = max($open, $price) * 1.002;
            $c['lows'][] = min($open, $price) * 0.998;
            $c['volumes'][] = $i % 40 < 12 ? 5000.0 : 1000.0; // volume spike after each turn confirms the flip
            $c['closeTimes'][] = $end - ($n - 1 - $i) * $barMs;
        }

        return $c;
    }

    public function test_trending_swings_score_as_winning_trades(): void
    {
        $score = app(SwingScorecard::class)->score($this->swings(600));

        $this->assertGreaterThan(5, $score['trades']);
        $this->assertGreaterThan(1.0, $score['profit_factor'], json_encode($score));
        $this->assertGreaterThan(0, $score['net_pct']);
        $this->assertGreaterThan(0, $score['late_pct'], 'A flip is confirmed after the turn, never at the extreme');
    }

    public function test_too_few_candles_score_nothing(): void
    {
        $this->assertSame(0, app(SwingScorecard::class)->score($this->swings(40))['trades']);
    }

    public function test_the_chart_compares_every_timeframe_and_caches_it(): void
    {
        Http::fake();
        $calls = 0;
        $market = Mockery::mock(BinanceClient::class);
        $market->shouldReceive('klines')->andReturnUsing(function (string $symbol, string $interval, int $limit) use (&$calls): array {
            $calls++;

            return $this->swings(600, ['15m' => 900000, '30m' => 1800000, '1h' => 3600000, '4h' => 14400000][$interval]);
        });
        $this->app->instance(BinanceClient::class, $market);
        $admin = User::factory()->create(['role' => 'admin']);

        $res = $this->actingAs($admin)->getJson(route('signals.swing_scorecard', ['symbol' => 'SOL']))->assertOk();
        $this->assertSame(['15m', '30m', '1h', '4h'], array_column($res->json('rows'), 'interval'));
        $this->assertSame('SOLUSDT', $res->json('symbol'));
        $this->assertSame('15m', $res->json('bot_interval'));
        $this->assertSame(4, $calls);

        $this->actingAs($admin)->getJson(route('signals.swing_scorecard', ['symbol' => 'SOLUSDT']))->assertOk();
        $this->assertSame(4, $calls, 'Second load comes from the cache');

        $this->actingAs($admin)->get('/signals')->assertSee('id="tf-30m"', false)->assertSee('id="swingScorecard"', false);
    }
}
