<?php

namespace Tests\Feature;

use App\Models\BreakoutSetup;
use App\Models\BreakoutSetupEvent;
use App\Models\CryptoSignal;
use App\Services\Breakout\BreakoutStrategyService;
use App\Services\Breakout\SignalLifecycleService as L;
use App\Services\Strategy\BreakoutWatcher;
use App\Services\Strategy\StrategyEngine;
use App\Services\Strategy\Watchlist;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\EbdFixtures;
use Tests\TestCase;

class BreakoutScanTest extends TestCase
{
    use RefreshDatabase;

    protected EbdFixtures $five;

    protected ?int $fiveEnd = null;

    protected float $price = 100.0;

    /** @var array<int, array<int, mixed>> */
    protected array $minuteRows = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trading.telegram.enabled' => true,
            'trading.telegram.bot_token' => '123:TEST',
            'trading.telegram.chat_id' => '999',
            'breakout.risk.min_net_rr' => 0.3,
        ]);
        Watchlist::reset();
        $this->five = EbdFixtures::coiled();

        Http::fake(function (Request $request) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

            return match (true) {
                str_contains($url, 'api.telegram.org') => Http::response(['ok' => true, 'result' => ['message_id' => 77]]),
                str_contains($url, 'ticker/24hr') => Http::response([['symbol' => 'TESTUSDT', 'lastPrice' => (string) $this->price, 'quoteVolume' => '300000000', 'closeTime' => $this->nowMs]]),
                str_contains($url, 'bookTicker') => Http::response([['symbol' => 'TESTUSDT', 'bidPrice' => (string) ($this->price - 0.01), 'askPrice' => (string) ($this->price + 0.01), 'bidQty' => '1', 'askQty' => '1']]),
                str_contains($url, 'klines') => Http::response($this->klines((string) ($q['interval'] ?? '5m'))),
                default => Http::response([]),
            };
        });
    }

    protected int $nowMs = EbdFixtures::T0 + 30_000;

    protected function klines(string $interval): array
    {
        return match ($interval) {
            '5m' => $this->five->raw($this->fiveEnd),
            '15m' => EbdFixtures::coiled()->raw(EbdFixtures::T0 - 1, 900_000),
            '1h' => $this->rising()->raw(EbdFixtures::T0 - 1, 3_600_000),
            '1m' => $this->minuteRows,
            default => [],
        };
    }

    protected function rising(): EbdFixtures
    {
        $f = EbdFixtures::start(90);
        for ($i = 1; $i <= 100; $i++) {
            $f->to(90 + $i * 0.1, 1000);
        }

        return $f;
    }

    protected function scan(int $nowMs, bool $force = false): array
    {
        $this->nowMs = $nowMs;
        Carbon::setTestNow(Carbon::createFromTimestampMsUTC($nowMs));
        Cache::forget('binance:tickers:24hr:futures');
        Cache::forget('binance:tickers:book:futures');
        foreach (['5m:300', '1m:130'] as $suffix) {
            Cache::forget('binance:klines:futures:TESTUSDT:'.$suffix);
        }

        return app(BreakoutStrategyService::class)->run($nowMs, $force);
    }

    protected function telegramTexts(): array
    {
        return collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.telegram.org'))
            ->map(fn ($pair) => (string) ($pair[0]->data()['text'] ?? ''))->values()->all();
    }

    /** Resolved live EBD signals for the coin record gate. */
    protected function record(int $n, float $r, string $side = 'LONG', string $symbol = 'TESTUSDT'): void
    {
        for ($i = 0; $i < $n; $i++) {
            CryptoSignal::create([
                'symbol' => $symbol, 'interval' => '5m', 'side' => $side, 'setup' => 'EBD_CONFIRMED', 'source' => 'ebd', 'is_shadow' => true,
                'entry_price' => 100, 'stop_loss' => 99, 'take_profit_1' => 101.5, 'take_profit_2' => 102, 'take_profit_3' => 102, 'risk_reward' => 1.5,
                'outcome' => $r > 0 ? 'TP2' : 'SL', 'r_multiple' => $r, 'resolved_at' => now(),
                'candle_close_time' => Carbon::createFromTimestampMsUTC(EbdFixtures::T0 - ($i + 10) * 3_600_000),
            ]);
        }
    }

    protected function confirmBar(): void
    {
        $this->five->bar(100.02, 100.225, 100.0, 100.21, 3200);
        $this->fiveEnd = EbdFixtures::T0 - 1 + EbdFixtures::BAR_MS;
        $this->price = 100.21;
    }

    public function test_a_coiled_coin_becomes_one_compression_setup_however_often_the_cron_runs(): void
    {
        $metrics = $this->scan(EbdFixtures::T0 + 30_000);
        $this->assertTrue($metrics['bar_pass']);
        $this->scan(EbdFixtures::T0 + 60_000);
        $this->scan(EbdFixtures::T0 + 61_000, force: true);

        $setup = BreakoutSetup::sole();
        $this->assertSame(L::COMPRESSION, $setup->state);
        $this->assertSame('TESTUSDT:5m:'.(EbdFixtures::T0 - 1), $setup->setup_key);
        $this->assertSame([], $this->telegramTexts(), 'Compression alone is not announced');
        $this->assertSame(1, BreakoutSetup::query()->active()->count());
    }

    public function test_confirmation_is_stored_linked_and_sent_once(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestampMsUTC(EbdFixtures::T0));
        $this->record(4, 1.5);
        $this->record(2, -1.0);
        $this->scan(EbdFixtures::T0 + 30_000);
        $this->confirmBar();

        $this->scan(EbdFixtures::T0 + EbdFixtures::BAR_MS + 30_000);
        $this->scan(EbdFixtures::T0 + EbdFixtures::BAR_MS + 45_000, force: true);
        $this->scan(EbdFixtures::T0 + EbdFixtures::BAR_MS + 60_000, force: true);

        $setup = BreakoutSetup::sole();
        $this->assertSame(L::CONFIRMED, $setup->state);
        $this->assertSame('LONG', $setup->direction);
        $this->assertSame(1, BreakoutSetupEvent::where('event', 'CONFIRMED')->count());
        $texts = $this->telegramTexts();
        $this->assertCount(1, $texts, 'Repeated cron runs never resend');
        $this->assertStringContainsString('CONFIRMED BREAKOUT — LONG', $texts[0]);
        $this->assertStringContainsString('TESTUSDT', $texts[0]);
        $this->assertStringContainsString('Alert only, no order placed', $texts[0]);
        $this->assertStringContainsString('EBD lost, PF 0.67', $texts[0], 'Every entry alert carries the backtest result');
        $this->assertStringContainsString('TEST LONG live 30d: 6 signals, 4 won, PF 3.00', $texts[0], 'The coin record that let it through');

        $signal = CryptoSignal::where('source', 'ebd')->whereNull('resolved_at')->sole();
        $this->assertSame('EBD_CONFIRMED', $signal->setup);
        $this->assertTrue($signal->is_shadow, 'EBD signals are never auto-traded');
        $this->assertSame('ebd', $signal->source);
        $this->assertSame($signal->id, $setup->crypto_signal_id);
        $this->assertSame('77', $setup->telegram_message_id);
    }

    public function test_an_already_recorded_event_is_not_sent_again(): void
    {
        $this->scan(EbdFixtures::T0 + 30_000);
        BreakoutSetupEvent::create(['breakout_setup_id' => BreakoutSetup::sole()->id, 'event' => 'CONFIRMED', 'to_state' => L::CONFIRMED, 'occurred_at' => now(), 'notify' => true]);
        $this->confirmBar();

        $metrics = $this->scan(EbdFixtures::T0 + EbdFixtures::BAR_MS + 30_000);

        $this->assertSame([], $this->telegramTexts());
        $this->assertSame(1, $metrics['duplicates_prevented']);
    }

    public function test_live_price_through_the_trigger_with_volume_pace_sends_an_early_alert(): void
    {
        config(['breakout.notifications.early' => true]);
        $this->scan(EbdFixtures::T0 + 30_000);
        $setup = BreakoutSetup::sole()->toEngine();
        $this->price = $setup['long_trigger'] + 0.1 * $setup['atr'];
        // 1m candles: 20 past 5m candles at 100/minute, then 2 closed minutes at 300 inside the forming candle.
        $first = EbdFixtures::T0 - 20 * EbdFixtures::BAR_MS;
        $volumes = array_merge(array_fill(0, 100, 100.0), [300.0, 300.0, 120.0]);
        foreach ($volumes as $i => $v) {
            $open = $first + $i * 60_000;
            $this->minuteRows[] = [$open, '100', (string) max(100, $this->price), '99.97', '100.05', '1', $open + 59_999, (string) $v, 10, '0', '0', '0'];
        }

        $metrics = $this->scan(EbdFixtures::T0 + 150_000);

        $this->assertSame(1, $metrics['armed']);
        $row = BreakoutSetup::sole();
        $this->assertSame(L::EARLY, $row->state, (string) ($row->state_data['last_check'] ?? ''));
        $this->assertSame('live', $row->detection_mode);
        $this->assertEqualsWithDelta(3.0, (float) $row->rvol_pace, 0.01);
        $texts = $this->telegramTexts();
        $this->assertCount(1, $texts);
        $this->assertStringContainsString('EARLY BREAKOUT WATCH — LONG', $texts[0]);
        $this->assertStringContainsString('Provisional: breakout NOT confirmed', $texts[0]);
        $this->assertSame('EBD_EARLY', CryptoSignal::sole()->setup);
    }

    public function test_without_a_good_coin_record_the_confirmation_is_tracked_but_not_sent(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestampMsUTC(EbdFixtures::T0));
        $this->record(3, 1.5); // too few signals
        $this->record(5, -1.0, 'SHORT'); // the other direction does not count
        $this->scan(EbdFixtures::T0 + 30_000);
        $this->confirmBar();

        $this->scan(EbdFixtures::T0 + EbdFixtures::BAR_MS + 30_000);

        $this->assertSame(L::CONFIRMED, BreakoutSetup::sole()->state);
        $this->assertSame([], $this->telegramTexts());
        $this->assertSame('EBD_CONFIRMED', CryptoSignal::where('source', 'ebd')->whereNull('resolved_at')->sole()->setup, 'Still recorded, so the coin can earn its place');
        $this->assertNull(BreakoutSetup::sole()->telegram_message_id);
    }

    public function test_a_losing_coin_record_blocks_entries(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestampMsUTC(EbdFixtures::T0));
        $this->record(3, 1.0);
        $this->record(4, -1.0); // PF 0.75
        $this->scan(EbdFixtures::T0 + 30_000);
        $this->confirmBar();

        $this->scan(EbdFixtures::T0 + EbdFixtures::BAR_MS + 30_000);

        $this->assertSame([], $this->telegramTexts());
    }

    public function test_early_alerts_are_off_by_default_but_still_recorded(): void
    {
        $this->scan(EbdFixtures::T0 + 30_000);
        $setup = BreakoutSetup::sole()->toEngine();
        $this->price = $setup['long_trigger'] + 0.1 * $setup['atr'];
        $first = EbdFixtures::T0 - 20 * EbdFixtures::BAR_MS;
        foreach (array_merge(array_fill(0, 100, 100.0), [300.0, 300.0, 120.0]) as $i => $v) {
            $open = $first + $i * 60_000;
            $this->minuteRows[] = [$open, '100', (string) max(100, $this->price), '99.97', '100.05', '1', $open + 59_999, (string) $v, 10, '0', '0', '0'];
        }

        $this->scan(EbdFixtures::T0 + 150_000);

        $this->assertSame(L::EARLY, BreakoutSetup::sole()->state);
        $this->assertSame([], $this->telegramTexts());
        $this->assertSame('EBD_EARLY', CryptoSignal::sole()->setup);
    }

    public function test_stale_candles_are_rejected(): void
    {
        $this->fiveEnd = EbdFixtures::T0 - 1 - 2 * EbdFixtures::BAR_MS;

        $metrics = $this->scan(EbdFixtures::T0 + 30_000);

        $this->assertSame(0, BreakoutSetup::count());
        $this->assertStringContainsString('stale', $metrics['rejected']['TESTUSDT']);
    }

    public function test_shadow_mode_stores_events_without_telegram(): void
    {
        config(['breakout.mode' => 'shadow']);
        $this->scan(EbdFixtures::T0 + 30_000);
        $this->confirmBar();
        $this->scan(EbdFixtures::T0 + EbdFixtures::BAR_MS + 30_000);

        $this->assertSame(L::CONFIRMED, BreakoutSetup::sole()->state);
        $this->assertSame([], $this->telegramTexts());
        $this->assertNull(BreakoutSetupEvent::where('event', 'CONFIRMED')->sole()->notified_at);
    }

    public function test_dry_run_stores_nothing(): void
    {
        $this->nowMs = EbdFixtures::T0 + 30_000;
        $metrics = app(BreakoutStrategyService::class)->run($this->nowMs, true, null, true);

        $this->assertTrue($metrics['dry_run']);
        $this->assertSame(0, BreakoutSetup::count());
    }

    public function test_a_second_scan_while_one_runs_is_skipped(): void
    {
        $lock = Cache::lock('ebd-scan', 70);
        $this->assertTrue($lock->get());

        $this->artisan('breakout:scan')->expectsOutputToContain('still running')->assertSuccessful();
        $this->assertSame(0, BreakoutSetup::count());
        $lock->release();
    }

    public function test_the_scheduler_runs_breakout_scan_once_a_minute_and_no_duplicate(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'breakout:scan'));

        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
    }

    public function test_the_legacy_breakout_path_is_off_by_default_and_restorable(): void
    {
        $this->assertTrue(BreakoutStrategyService::enabled());
        $this->assertFalse(BreakoutWatcher::enabled(), 'Old minute watcher off: no EARLY_BREAKOUT trades or alerts');
        $this->assertFalse(app(StrategyEngine::class)->legacyBreakouts(), 'No SQUEEZE_BREAKOUT signals from the 1h engine');

        config(['trading.strategy.breakout_engine' => 'legacy']);
        $this->assertFalse(BreakoutStrategyService::enabled(), 'Rollback switches EBD off');
        $this->assertTrue(BreakoutWatcher::enabled());
        $this->assertTrue(app(StrategyEngine::class)->legacyBreakouts());
    }

    public function test_invalid_configuration_stops_the_scan_with_an_actionable_error(): void
    {
        config(['breakout.compression.bbw_percentile' => 0]);

        $this->artisan('breakout:scan')->expectsOutputToContain('compression.bbw_percentile')->assertFailed();
    }
}
