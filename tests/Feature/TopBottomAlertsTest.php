<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Trade;
use App\Models\User;
use App\Services\Crypto\Indicators;
use App\Services\Notifications\SignalAlerts;
use App\Services\Strategy\ChartOverlayBuilder;
use App\Services\Strategy\MarketScanService;
use App\Services\Strategy\StrategyEngine;
use App\Services\Strategy\Watchlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Swing BUY/SELL signals (SuperTrend flip on volume): chart labels, Telegram alerts and the live notification feed.
 */
class TopBottomAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trading.telegram.enabled' => true,
            'trading.telegram.bot_token' => '123:TEST',
            'trading.telegram.chat_id' => '999',
        ]);
        Watchlist::reset();
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]]),
            '*' => Http::response([]),
        ]);
    }

    /**
     * 80 candles drifting down from 110 to ~94, then a strong green candle to 96.2 on the given volume.
     *
     * @return array<string, array<int, float|int>>
     */
    protected function downtrendThenRally(float $rallyVolume): array
    {
        $c = ['opens' => [], 'highs' => [], 'lows' => [], 'closes' => [], 'volumes' => [], 'closeTimes' => []];
        for ($i = 0; $i < 80; $i++) {
            $close = 110 - $i * 0.2;
            $c['opens'][] = $close + 0.15;
            $c['highs'][] = $close + 0.3;
            $c['lows'][] = $close - 0.15;
            $c['closes'][] = $close;
            $c['volumes'][] = 1000.0;
            $c['closeTimes'][] = 1_700_000_000_000 + ($i + 1) * 3_600_000 - 1;
        }
        $c['opens'][] = 94.2;
        $c['highs'][] = 96.3;
        $c['lows'][] = 94.0;
        $c['closes'][] = 96.2;
        $c['volumes'][] = $rallyVolume;
        $c['closeTimes'][] = 1_700_000_000_000 + 81 * 3_600_000 - 1;

        return $c;
    }

    public function test_a_supertrend_flip_up_on_volume_is_a_buy_with_the_stop_at_the_supertrend_line(): void
    {
        $c = $this->downtrendThenRally(3000.0);
        $signals = app(StrategyEngine::class)->swingSignals($c);

        $this->assertNotEmpty($signals);
        $last = end($signals);
        $this->assertSame('LONG', $last['side']);
        $this->assertSame(96.2, $last['entry']);
        $this->assertSame(intdiv($c['closeTimes'][80], 1000), $last['time']);
        $line = Indicators::supertrend($c['highs'], $c['lows'], $c['closes'], 10, 3.0)['line'][80];
        $this->assertEqualsWithDelta($line, $last['sl'], 1e-9);
        $this->assertGreaterThan($last['entry'], $last['tp1']);
        $this->assertStringContainsString('SuperTrend(10,3) flipped up on 3.0x volume', $last['reason']);
    }

    public function test_a_flip_without_volume_is_not_a_signal(): void
    {
        $c = $this->downtrendThenRally(1200.0);
        $signals = app(StrategyEngine::class)->swingSignals($c);

        $this->assertSame([], array_filter($signals, fn (array $s): bool => $s['time'] === intdiv(1_700_000_000_000 + 81 * 3_600_000 - 1, 1000)));

        // The chart still labels it, marked unconfirmed (no alert, outside the measured set).
        $chart = app(ChartOverlayBuilder::class)->build($c, [], '1h')['swing_signals'];
        $this->assertSame('LONG', end($chart)['side']);
        $this->assertFalse(end($chart)['confirmed']);
    }

    public function test_the_chart_payload_carries_the_swing_signals(): void
    {
        $c = $this->downtrendThenRally(3000.0);
        // A forming candle after the rally: never labelled.
        foreach (['opens' => 100.0, 'highs' => 100.5, 'lows' => 99.5, 'closes' => 100.2, 'volumes' => 10.0] as $k => $v) {
            $c[$k][] = $v;
        }
        $c['closeTimes'][] = PHP_INT_MAX;

        $payload = app(ChartOverlayBuilder::class)->build($c, [], '1h');

        $this->assertSame('LONG', end($payload['swing_signals'])['side']);
        $this->assertTrue(end($payload['swing_signals'])['confirmed']);
        $this->assertSame(intdiv($c['closeTimes'][80], 1000), end($payload['swing_signals'])['time']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function swing(string $symbol, float $volumeRatio, string $side = 'SHORT', int $time = 1_800_000_000): array
    {
        return ['symbol' => $symbol, 'interval' => '1h', 'side' => $side, 'entry' => 100.2, 'sl' => 102.4, 'tp1' => 96.9, 'tp2' => 93.6, 'tp3' => 90.3,
            'atr' => 1.0, 'reason' => 'SuperTrend(10,3) flipped down on 2.5x volume', 'rsi' => 44.0, 'volume_ratio' => $volumeRatio, 'time' => $time];
    }

    public function test_swing_alerts_are_labelled_capped_deduplicated_and_never_traded(): void
    {
        $alerts = app(SignalAlerts::class);
        $list = [$this->swing('AAAUSDT', 1.6), $this->swing('BBBUSDT', 3.0), $this->swing('CCCUSDT', 2.0), $this->swing('DDDUSDT', 2.5)];

        $this->assertSame(3, $alerts->reversalAlert($list));

        $texts = array_column(Http::recorded(fn (Request $r): bool => str_contains($r->url(), 'api.telegram.org'))->map(fn (array $p): array => $p[0]->data())->values()->all(), 'text');
        $this->assertCount(3, $texts);
        $this->assertStringContainsString('SELL BBBUSDT: swing turn down', $texts[0], 'Strongest volume first');
        $this->assertStringContainsString('Not auto-traded', $texts[0]);
        $this->assertStringContainsString('about breakeven', $texts[0]);
        $this->assertStringNotContainsString('AAAUSDT', implode("\n", $texts), 'Weakest one is over the cap');

        $this->assertSame(0, $alerts->reversalAlert([$this->swing('BBBUSDT', 3.0)]), 'Same coin and side only once per 6 hours');
        $this->assertSame(0, Trade::count());
    }

    public function test_swing_alerts_can_be_switched_off(): void
    {
        config(['trading.strategy.topbottom_alerts' => false]);

        $this->assertSame(0, app(SignalAlerts::class)->reversalAlert([$this->swing('BBBUSDT', 3.0)]));
    }

    public function test_the_chart_page_feed_lists_new_swing_signals_from_the_latest_scan(): void
    {
        Setting::putValue(MarketScanService::RESULTS_KEY, ['reversals' => [
            $this->swing('OLDUSDT', 2.0, 'LONG', 1_800_000_000),
            $this->swing('NEARUSDT', 2.4, 'SHORT', 1_800_003_600),
        ]]);
        $user = User::factory()->create(['role' => 'admin']);

        $json = $this->actingAs($user)->getJson('/signals/recent?since=1800000000')->assertOk()->json();

        $this->assertSame(['NEARUSDT'], array_column($json['signals'], 'symbol'));
        $this->assertSame('SHORT', $json['signals'][0]['side']);
        $this->assertArrayHasKey('now', $json);
        $this->get('/logout');
    }
}
