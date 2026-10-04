<?php

namespace Tests\Feature;

use App\Models\CryptoSignal;
use App\Services\Strategy\BreakoutWatcher;
use App\Services\Strategy\StrategyEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BreakoutWatcherTest extends TestCase
{
    use RefreshDatabase;

    protected int $barOpenMs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfHour()->addMinutes(30));
        $this->barOpenMs = now()->startOfHour()->getTimestampMs();
    }

    /**
     * @return array<string, mixed>
     */
    protected function watch(float $atr = 1.0): array
    {
        return [
            'symbol' => 'SOLUSDT', 'interval' => '1h', 'side' => 'LONG', 'level' => 100.0, 'atr' => $atr, 'vol_sma' => 1000.0,
            'bar_open_ms' => $this->barOpenMs, 'bar_close_ms' => $this->barOpenMs + 3_600_000 - 1,
            'filters' => ['regime' => ['pass' => true, 'detail' => '4h up trend'], 'btc_macro' => ['pass' => true, 'detail' => 'BTC neutral'], 'volatility' => ['pass' => true, 'detail' => 'ATR 1.00%']],
            'indicators' => ['rsi' => 62.0, 'adx' => 24.0, 'volume_ratio' => 1.0, 'atr_pct' => 1.0],
            'confluences' => ['Strong 4h trend'],
            'features' => ['setup' => 'SQUEEZE_BREAKOUT', 'side' => 1, 'sl_pct' => 1.2],
        ];
    }

    protected function fakeMarket(float $price, float $volumeSoFar): void
    {
        $kline = fn (int $openMs, float $high, float $low, float $volume): array => [$openMs, '99.6', (string) $high, (string) $low, (string) $price, (string) $volume, $openMs + 3_599_999];

        Http::fake([
            '*ticker/24hr*' => Http::response([['symbol' => 'SOLUSDT', 'lastPrice' => (string) $price, 'quoteVolume' => '900000000']]),
            '*klines*' => Http::response([
                $kline($this->barOpenMs - 3_600_000, 99.9, 99.2, 800),
                $kline($this->barOpenMs, max($price, 100.6), 99.5, $volumeSoFar),
            ]),
            '*' => Http::response([]),
        ]);
    }

    public function test_price_through_the_box_with_volume_on_pace_triggers_a_breakout_signal(): void
    {
        $this->fakeMarket(100.5, 900); // half the hour gone: 1.5x average pace needs 750
        app(BreakoutWatcher::class)->store('1h', ['SOLUSDT' => $this->watch()]);

        $fresh = app(BreakoutWatcher::class)->check();

        $this->assertCount(1, $fresh);
        $signal = $fresh[0]['signal'];
        $this->assertSame('SQUEEZE_BREAKOUT', $signal->setup);
        $this->assertSame(100.5, $signal->entry, 'Entry is the live price, not the candle close');
        $this->assertSame(intdiv($this->barOpenMs + 3_600_000 - 1, 1000), $signal->time, 'Same key as the close signal of this candle');
        $this->assertTrue($signal->isTradable());
        $this->assertSame('watcher', CryptoSignal::first()->source);
        $this->assertSame([], app(BreakoutWatcher::class)->watching(), 'A coin triggers once per candle');
    }

    public function test_no_signal_without_volume_or_when_price_is_back_inside_the_box(): void
    {
        $this->fakeMarket(100.5, 300);
        app(BreakoutWatcher::class)->store('1h', ['SOLUSDT' => $this->watch()]);
        $this->assertSame([], app(BreakoutWatcher::class)->check(), 'Breakout without volume is ignored');

        $this->fakeMarket(99.8, 5000);
        $this->assertSame([], app(BreakoutWatcher::class)->check(), 'Price below the level is not a breakout');
        $this->assertCount(1, app(BreakoutWatcher::class)->watching());
    }

    public function test_watch_list_expires_with_its_candle(): void
    {
        app(BreakoutWatcher::class)->store('1h', ['SOLUSDT' => $this->watch()]);

        $this->travelTo(now()->addHour());

        $this->assertSame([], app(BreakoutWatcher::class)->watching());
    }

    public function test_breakouts_allow_a_wider_stop_than_other_setups(): void
    {
        $this->assertSame(4.0, StrategyEngine::configuredMaxSlPct('SQUEEZE_BREAKOUT'));
        $this->assertSame(1.8, StrategyEngine::configuredMaxSlPct('TREND_PULLBACK'));

        // ATR 3.0 at 100 gives a 3.6% stop (1.2 x ATR), like the QNT breakout the old 1.8% cap skipped.
        $signal = app(StrategyEngine::class)->intrabarSignal($this->watch(3.0), 100.0, now()->timestamp);
        $this->assertEqualsWithDelta(3.6, $signal->slPct(), 1e-9);
        $this->assertTrue($signal->filters['stop_width']['pass']);
        $this->assertSame('Stop 3.60% (max 4.00%)', $signal->filters['stop_width']['detail']);

        $tooWide = app(StrategyEngine::class)->intrabarSignal($this->watch(4.0), 100.0, now()->timestamp);
        $this->assertFalse($tooWide->filters['stop_width']['pass'], 'A 4.8% stop is still too wide');
    }
}
