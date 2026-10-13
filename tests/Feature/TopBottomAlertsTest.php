<?php

namespace Tests\Feature;

use App\Models\Trade;
use App\Services\Notifications\SignalAlerts;
use App\Services\Strategy\StrategyEngine;
use App\Services\Strategy\Watchlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

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
     * 60 calm candles oscillating around 100 (range ~1), volume 1000.
     *
     * @return array<string, array<int, float|int>>
     */
    protected function candles(): array
    {
        $c = ['opens' => [], 'highs' => [], 'lows' => [], 'closes' => [], 'volumes' => [], 'closeTimes' => []];
        for ($i = 0; $i < 60; $i++) {
            $close = 100 + ($i % 2 ? 0.3 : -0.3);
            $c['opens'][] = 100.0;
            $c['highs'][] = max(100.0, $close) + 0.2;
            $c['lows'][] = min(100.0, $close) - 0.2;
            $c['closes'][] = $close;
            $c['volumes'][] = 1000.0;
            $c['closeTimes'][] = 1_800_000_000_000 + ($i + 1) * 3_600_000 - 1;
        }

        return $c;
    }

    /**
     * @param  array<string, array<int, float|int>>  $c
     * @return array<string, mixed>|null
     */
    protected function alertAtLast(array $c): ?array
    {
        $engine = app(StrategyEngine::class);
        $series = (new ReflectionMethod($engine, 'computeSeries'))->invoke($engine, $c);

        return $engine->reversalAlertAt(count($c['closes']) - 1, $c, $series);
    }

    public function test_a_rejected_new_high_on_volume_is_a_possible_top_with_the_stop_above_the_wick(): void
    {
        $c = $this->candles();
        // Last candle spikes to a new 24h high (102) and closes near its low (100.2) on 2.5x volume.
        $c['opens'][59] = 100.5;
        $c['highs'][59] = 102.0;
        $c['lows'][59] = 100.0;
        $c['closes'][59] = 100.2;
        $c['volumes'][59] = 2500.0;

        $alert = $this->alertAtLast($c);

        $this->assertNotNull($alert);
        $this->assertSame('SHORT', $alert['side']);
        $this->assertSame(100.2, $alert['entry']);
        $this->assertGreaterThan(102.0, $alert['sl']);
        $this->assertLessThan($alert['entry'], $alert['tp1']);
        $this->assertStringContainsString('New 24h high rejected', $alert['reason']);
    }

    public function test_rsi_turning_up_from_oversold_is_a_possible_bottom(): void
    {
        $c = $this->candles();
        // A steady slide drives RSI under 30, then a green candle turns it up.
        for ($i = 40; $i < 59; $i++) {
            $p = 100 - ($i - 39) * 0.8;
            [$c['opens'][$i], $c['closes'][$i], $c['highs'][$i], $c['lows'][$i]] = [$p + 0.6, $p, $p + 0.7, $p - 0.1];
        }
        $p = $c['closes'][58];
        [$c['opens'][59], $c['closes'][59], $c['highs'][59], $c['lows'][59]] = [$p, $p + 0.3, $p + 0.35, $p - 0.05];

        $alert = $this->alertAtLast($c);

        $this->assertNotNull($alert);
        $this->assertSame('LONG', $alert['side']);
        $this->assertStringContainsString('RSI turned up', $alert['reason']);
        $this->assertLessThan($alert['entry'], $alert['sl']);
    }

    public function test_an_ordinary_candle_in_the_middle_of_the_range_is_not_an_alert(): void
    {
        $this->assertNull($this->alertAtLast($this->candles()));
    }

    /**
     * @return array<string, mixed>
     */
    protected function reversal(string $symbol, float $volumeRatio, string $side = 'SHORT'): array
    {
        return ['symbol' => $symbol, 'interval' => '1h', 'side' => $side, 'entry' => 100.2, 'sl' => 102.4, 'tp1' => 96.9, 'tp2' => 93.6, 'tp3' => 90.3,
            'atr' => 1.0, 'reason' => 'New 24h high rejected (closed in the bottom 10% of the candle) on 2.5x volume', 'rsi' => 64.0, 'volume_ratio' => $volumeRatio];
    }

    public function test_top_bottom_alerts_are_labelled_capped_deduplicated_and_never_traded(): void
    {
        $alerts = app(SignalAlerts::class);
        $list = [$this->reversal('AAAUSDT', 1.6), $this->reversal('BBBUSDT', 3.0), $this->reversal('CCCUSDT', 2.0), $this->reversal('DDDUSDT', 2.5)];

        $this->assertSame(3, $alerts->reversalAlert($list));

        $texts = array_column(Http::recorded(fn (Request $r): bool => str_contains($r->url(), 'api.telegram.org'))->map(fn (array $p): array => $p[0]->data())->values()->all(), 'text');
        $this->assertCount(3, $texts);
        $this->assertStringContainsString('SELL BBBUSDT: possible top', $texts[0], 'Strongest volume first');
        $this->assertStringContainsString('Not auto-traded', $texts[0]);
        $this->assertStringContainsString('1 in 3', $texts[0]);
        $this->assertStringNotContainsString('AAAUSDT', implode("\n", $texts), 'Weakest one is over the cap');

        $this->assertSame(0, $alerts->reversalAlert([$this->reversal('BBBUSDT', 3.0)]), 'Same coin and side only once per 6 hours');
        $this->assertSame(0, Trade::count());
    }

    public function test_top_bottom_alerts_can_be_switched_off(): void
    {
        config(['trading.strategy.topbottom_alerts' => false]);

        $this->assertSame(0, app(SignalAlerts::class)->reversalAlert([$this->reversal('BBBUSDT', 3.0)]));
    }
}
