<?php

namespace Tests\Feature;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Models\User;
use App\Services\Crypto\BinanceClient;
use App\Services\Strategy\StrategyEngine;
use App\Services\Strategy\Watchlist;
use App\Services\Trading\DynamicTradeManager;
use App\Services\Trading\RiskManager;
use App\Services\Trading\SwingSarTrader;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class SwingSarTraderTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>> */
    protected array $flips = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trading.swing_sar.enabled' => true,
            'trading.swing_sar.symbols' => ['NEARUSDT'],
            'trading.swing_sar.require_4h_trend' => false,
            'trading.swing_sar.stop_buffer_atr' => 0.0,
            // The ladder / trail tests run without the ROE profit lock; the lock has its own tests.
            'trading.swing_sar.profit_lock' => [],
            'trading.telegram.enabled' => true,
            'trading.telegram.bot_token' => '123:SECRET',
            'trading.telegram.chat_id' => '999',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]]), '*' => Http::response([])]);
        TradingAccount::create(['mode' => 'paper', 'balance' => 24.0, 'initial_balance' => 24.0, 'equity' => 24.0, 'peak_equity' => 24.0, 'is_running' => true]);

        $market = Mockery::mock(BinanceClient::class);
        $market->shouldReceive('klines')->andReturnUsing(fn (string $symbol, string $interval, int $limit): array => $this->candles($limit));
        $market->shouldReceive('tickerPrice')->andReturn(5.0);
        $this->app->instance(BinanceClient::class, $market);

        $engine = Mockery::mock(StrategyEngine::class)->makePartial();
        $engine->shouldReceive('swingSignals')->andReturnUsing(fn (): array => $this->flips);
        $this->app->instance(StrategyEngine::class, $engine);
    }

    /**
     * Closed hourly candles around $5 ending at the last full hour.
     *
     * @return array<string, array<int, float|int>>
     */
    protected function candles(int $limit): array
    {
        $c = ['opens' => [], 'highs' => [], 'lows' => [], 'closes' => [], 'volumes' => [], 'closeTimes' => []];
        $lastClose = intdiv(time(), 3600) * 3600 * 1000 - 1;
        for ($i = 0; $i < $limit; $i++) {
            $price = 5.0 + 0.05 * sin($i / 5);
            $c['opens'][] = $price;
            $c['highs'][] = $price + 0.03;
            $c['lows'][] = $price - 0.03;
            $c['closes'][] = $price;
            $c['volumes'][] = 1000.0;
            $c['closeTimes'][] = $lastClose - ($limit - 1 - $i) * 3600000;
        }

        return $c;
    }

    protected function flip(string $side, bool $confirmed = true, float $sl = 4.85): array
    {
        return ['side' => $side, 'entry' => 5.0, 'sl' => $sl, 'tp1' => 5.2, 'tp2' => 5.4, 'tp3' => 5.6, 'atr' => 0.06, 'reason' => 'flip',
            'rsi' => 55.0, 'volume_ratio' => $confirmed ? 2.0 : 1.1, 'time' => time(), 'confirmed' => $confirmed, 'sl_pct' => abs(5.0 - $sl) / 5.0 * 100];
    }

    protected function sarTrade(string $side = 'LONG', array $overrides = []): Trade
    {
        return Trade::create(array_merge([
            'symbol' => 'NEARUSDT', 'setup_tag' => SwingSarTrader::SETUP, 'side' => $side, 'mode' => 'paper', 'status' => 'OPEN', 'stage' => 'ENTRY',
            'entry_price' => 5.0, 'quantity' => 10.0, 'remaining_quantity' => 10.0, 'margin_used' => 10.0, 'leverage' => 5,
            'initial_sl' => $side === 'LONG' ? 4.85 : 5.15, 'current_sl' => $side === 'LONG' ? 4.85 : 5.15, 'tp1_price' => 5.2, 'tp2_price' => 5.4,
            'highest_price' => 5.0, 'lowest_price' => 5.0, 'meta' => ['interval' => '1h', 'atr' => 0.06], 'opened_at' => Carbon::now()->subHours(5),
        ], $overrides));
    }

    protected function runSar(bool $entriesAllowed = true): array
    {
        return app(SwingSarTrader::class)->run('paper', $entriesAllowed);
    }

    public function test_a_confirmed_flip_opens_a_trade_sized_from_half_the_fund(): void
    {
        $this->flips = [$this->flip('LONG')];

        $row = $this->runSar()[0];

        $this->assertSame('opened', $row['action'], $row['message']);
        $trade = Trade::where('setup_tag', SwingSarTrader::SETUP)->sole();
        $this->assertSame('LONG', $trade->side);
        $this->assertSame(5, (int) $trade->leverage);
        // $24 x 50% / 4 slots = $3 margin.
        $this->assertEqualsWithDelta(3.0, (float) $trade->margin_used, 0.2);
        $text = implode("\n", array_map(fn (array $pair): string => (string) ($pair[0]->data()['text'] ?? ''), Http::recorded()->all()));
        $this->assertStringContainsString('opposite swing flip', $text);
        $this->assertStringContainsString('Stop loss: −$', $text);
        $this->assertStringContainsString('the stop moves to', $text);
    }

    public function test_an_opposite_confirmed_flip_closes_and_reverses(): void
    {
        $long = $this->sarTrade('LONG');
        $this->flips = [$this->flip('SHORT', sl: 5.15)];

        $row = $this->runSar()[0];

        $this->assertSame('reversed', $row['action'], $row['message']);
        $this->assertSame('CLOSED', $long->fresh()->status);
        $this->assertSame('OPPOSITE_SIGNAL', $long->fresh()->exit_reason);
        $this->assertSame('SHORT', Trade::where('status', 'OPEN')->where('setup_tag', SwingSarTrader::SETUP)->sole()->side);
    }

    public function test_with_confirmed_only_an_unconfirmed_flip_closes_but_never_opens(): void
    {
        config(['trading.swing_sar.confirmed_only' => true]);
        $long = $this->sarTrade('LONG');
        $this->flips = [$this->flip('SHORT', confirmed: false, sl: 5.15)];

        $row = $this->runSar()[0];

        $this->assertSame('closed', $row['action']);
        $this->assertSame('CLOSED', $long->fresh()->status);
        $this->assertSame(0, Trade::where('status', 'OPEN')->count());
        Http::assertSent(fn (Request $r): bool => str_contains((string) ($r->data()['text'] ?? ''), 'No new trade'));
    }

    public function test_by_default_an_unconfirmed_flip_is_skipped_silently(): void
    {
        $this->flips = [$this->flip('LONG', confirmed: false)];

        $row = $this->runSar()[0];

        $this->assertSame('skipped', $row['action'], $row['message']);
        $this->assertSame(0, Trade::count());
        Http::assertNotSent(fn (Request $r): bool => str_contains((string) ($r->data()['text'] ?? ''), 'not traded'));
    }

    public function test_with_confirmed_only_off_every_flip_trades(): void
    {
        config(['trading.swing_sar.confirmed_only' => false]);
        $this->flips = [$this->flip('LONG', confirmed: false)];

        $row = $this->runSar()[0];

        $this->assertSame('opened', $row['action'], $row['message']);
        Http::assertSent(fn (Request $r): bool => str_contains((string) ($r->data()['text'] ?? ''), 'unconfirmed flip'));
    }

    public function test_a_wide_stop_is_capped_at_two_percent(): void
    {
        $this->flips = [$this->flip('LONG', sl: 4.5)]; // 10% away

        $this->assertSame('opened', $this->runSar()[0]['action']);

        $trade = Trade::where('setup_tag', SwingSarTrader::SETUP)->sole();
        $this->assertEqualsWithDelta(2.0, ((float) $trade->entry_price - (float) $trade->initial_sl) / (float) $trade->entry_price * 100, 0.05);
    }

    public function test_a_skipped_flip_is_reported_on_telegram(): void
    {
        $this->flips = [$this->flip('SHORT', sl: 5.15)];

        $this->assertSame('skipped', $this->runSar(entriesAllowed: false)[0]['action']);

        Http::assertSent(fn (Request $r): bool => str_contains((string) ($r->data()['text'] ?? ''), 'not traded'));
    }

    public function test_tp1_books_half_moves_the_stop_to_breakeven_and_the_flip_reverses_the_rest(): void
    {
        config(['trading.swing_sar.take_profit' => true, 'trading.swing_sar.ladder' => [], 'trading.swing_sar.partials' => [], 'trading.swing_sar.trail_from_r' => 0.0]);
        $trade = $this->sarTrade('LONG');
        $manager = app(DynamicTradeManager::class);

        $manager->manageTrade($trade, 5.21);
        $trade->refresh();
        $this->assertTrue((bool) $trade->tp1_hit);
        $this->assertEqualsWithDelta(5.0, (float) $trade->remaining_quantity, 1e-9);
        $this->assertGreaterThan(5.0, (float) $trade->current_sl);
        $this->assertLessThan(5.01, (float) $trade->current_sl);
        $this->assertGreaterThan(0, (float) $trade->realized_pnl);

        $this->flips = [$this->flip('SHORT', sl: 5.15)];
        $this->assertSame('reversed', $this->runSar()[0]['action']);
        $this->assertSame('OPPOSITE_SIGNAL', $trade->fresh()->exit_reason);
    }

    public function test_partials_are_booked_and_the_stop_steps_up_then_trails(): void
    {
        $trade = $this->sarTrade('LONG'); // entry 5.00, stop 4.85 (1R = 0.15), qty 10, ATR 0.06
        $manager = app(DynamicTradeManager::class);

        $manager->manageTrade($trade, 5.10);
        $this->assertEqualsWithDelta(4.85, (float) $trade->fresh()->current_sl, 1e-9, 'Below +0.75R nothing moves');
        $this->assertEqualsWithDelta(10.0, (float) $trade->fresh()->remaining_quantity, 1e-9);

        // +0.8R: a third is booked and the stop goes to -0.25R.
        $manager->manageTrade($trade->fresh(), 5.12);
        $trade->refresh();
        $this->assertEqualsWithDelta(6.7, (float) $trade->remaining_quantity, 0.05);
        $this->assertGreaterThan(0, (float) $trade->realized_pnl);
        $this->assertEqualsWithDelta(5.0 - 0.25 * 0.15, (float) $trade->current_sl, 1e-6);
        Http::assertSent(fn (Request $r): bool => str_contains((string) ($r->data()['text'] ?? ''), 'at +0.75R'));

        // A pullback never loosens the stop and never books twice.
        $manager->manageTrade($trade->fresh(), 5.05);
        $manager->manageTrade($trade->fresh(), 5.12);
        $this->assertEqualsWithDelta(6.7, (float) $trade->fresh()->remaining_quantity, 0.05);
        $this->assertEqualsWithDelta(4.9625, (float) $trade->fresh()->current_sl, 1e-6);

        // +1.6R: the second third is booked, the stop trails 1.5 ATR behind the best price (above the +0.5R step).
        $manager->manageTrade($trade->fresh(), 5.24);
        $trade->refresh();
        $this->assertEqualsWithDelta(3.4, (float) $trade->remaining_quantity, 0.05);
        $this->assertEqualsWithDelta(5.24 - 1.5 * 0.06, (float) $trade->current_sl, 1e-6);
        $this->assertTrue((bool) $trade->be_locked);

        // The last third closes on the trailing stop; the whole trade made money.
        $manager->manageTrade($trade->fresh(), 5.14);
        $this->assertSame('TRAILING_STOP', $trade->fresh()->exit_reason);
        $this->assertGreaterThan(0, (float) $trade->fresh()->net_pnl);
    }

    public function test_a_short_books_a_third_and_steps_its_stop_down(): void
    {
        $trade = $this->sarTrade('SHORT'); // entry 5.00, stop 5.15

        app(DynamicTradeManager::class)->manageTrade($trade, 4.80); // +1.33R

        $trade->refresh();
        $this->assertEqualsWithDelta(6.7, (float) $trade->remaining_quantity, 0.05);
        $this->assertEqualsWithDelta(5.0 + 0.25 * 0.15, (float) $trade->current_sl, 1e-6);
    }

    public function test_the_open_message_lists_the_bookings_and_stop_steps(): void
    {
        $this->flips = [$this->flip('LONG')];

        $this->runSar();

        $text = implode("\n", array_map(fn (array $pair): string => (string) ($pair[0]->data()['text'] ?? ''), Http::recorded()->all()));
        $this->assertStringContainsString('Book 33% at', $text);
        $this->assertStringContainsString('At +0.75R the stop moves to', $text);
    }

    public function test_by_default_the_swing_coins_are_the_monitored_coins_and_the_chart_buttons(): void
    {
        config(['trading.swing_sar.symbols' => ['WATCHLIST']]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('daemon.monitored-coins.add'), ['symbol' => 'link'])->assertOk();
        $this->actingAs($admin)->postJson(route('daemon.monitored-coins.remove'), ['symbol' => 'BTCUSDT'])->assertOk();

        $this->assertSame(Watchlist::symbols(), SwingSarTrader::symbols());
        $this->assertContains('LINKUSDT', SwingSarTrader::symbols());
        $this->assertNotContains('BTCUSDT', SwingSarTrader::symbols());

        $page = $this->actingAs($admin)->get('/signals')->assertOk();
        $page->assertSee('id="chart-btn-LINKUSDT"', false);
        $page->assertDontSee('id="chart-btn-BTCUSDT"', false);
        $page->assertSee('id="chartAddCoinInput"', false);
    }

    public function test_four_swing_trades_may_run_the_same_way(): void
    {
        $risk = app(RiskManager::class);
        $account = TradingAccount::getForMode('paper');
        foreach (['NEARUSDT', 'SUIUSDT', 'DOGEUSDT'] as $symbol) {
            $this->sarTrade('LONG', ['symbol' => $symbol, 'margin_used' => 3.0]);
        }

        $this->assertTrue($risk->canOpenTrade($account, 'XRPUSDT', 'LONG', setup: SwingSarTrader::SETUP)['allowed']);
        $this->sarTrade('LONG', ['symbol' => 'XRPUSDT', 'margin_used' => 3.0]);
        $this->assertFalse($risk->canOpenTrade($account, 'ADAUSDT', 'SHORT', setup: SwingSarTrader::SETUP)['allowed'], 'All 4 slots in use');
    }

    public function test_sar_runs_once_per_closed_fifteen_minute_candle(): void
    {
        $this->assertSame(900, SwingSarTrader::intervalSeconds());
        config(['trading.swing_sar.interval' => '1h']);
        $this->assertSame(3600, SwingSarTrader::intervalSeconds());
    }

    public function test_the_breakout_bot_only_uses_the_other_half_of_the_fund(): void
    {
        $risk = app(RiskManager::class);
        $account = TradingAccount::getForMode('paper');
        $this->assertEqualsWithDelta(12.0, $risk->breakoutMarginBudget($account), 1e-9);

        // SAR margin does not count against the breakout half; open breakout margin does.
        $this->sarTrade('LONG', ['margin_used' => 6.0]);
        Trade::create(['symbol' => 'SOLUSDT', 'side' => 'LONG', 'mode' => 'paper', 'status' => 'OPEN', 'entry_price' => 100, 'quantity' => 0.6,
            'remaining_quantity' => 0.6, 'margin_used' => 12.0, 'leverage' => 5, 'initial_sl' => 99, 'current_sl' => 99, 'tp1_price' => 101.5, 'tp2_price' => 103, 'opened_at' => now()]);
        $this->assertEqualsWithDelta(0.0, $risk->breakoutMarginBudget($account), 1e-9);

        $market = app(BinanceClient::class);
        $market->shouldReceive('getExchangeInfo')->andReturn([]);
        $market->shouldReceive('getMinNotional')->andReturn(5.0);
        $market->shouldReceive('formatQuantity')->andReturnUsing(fn (string $s, float $q): float => round($q, 3));
        $sizing = $risk->calculatePositionSize($account, 'ETHUSDT', 100.0, 99.0);
        $this->assertFalse($sizing['allowed']);
        $this->assertStringContainsString('breakout half of the fund', $sizing['reason']);
        $this->assertTrue($risk->calculatePositionSize($account, 'ETHUSDT', 100.0, 99.0, isManual: true)['allowed'], 'Manual trades are not limited by the split');

        // Without Swing SAR there is no split.
        config(['trading.swing_sar.enabled' => false]);
        $this->assertNull($risk->breakoutMarginBudget($account));
    }

    public function test_the_coins_can_follow_the_monitored_alert_coins(): void
    {
        config(['trading.swing_sar.symbols' => ['WATCHLIST']]);
        Watchlist::add('NEARUSDT');

        $this->assertSame(Watchlist::symbols(), SwingSarTrader::symbols());
        $this->assertContains('NEARUSDT', SwingSarTrader::symbols());
    }

    public function test_sar_check_lists_each_chart_flip_and_why_it_was_not_traded(): void
    {
        config(['trading.swing_sar.max_open' => 2]);
        $this->flips = [array_merge($this->flip('LONG'), ['time' => time() - 600])];
        $this->sarTrade('LONG', ['symbol' => 'SUIUSDT', 'opened_at' => Carbon::now()->subHours(3)]);
        $this->sarTrade('SHORT', ['symbol' => 'DOGEUSDT', 'opened_at' => Carbon::now()->subHours(3)]);

        $this->artisan('trade:sar-check', ['--hours' => 24])
            ->expectsOutputToContain('15m candles')
            ->expectsOutputToContain('skipped: all 2 SAR slots were in use')
            ->assertSuccessful();
    }

    public function test_stopped_auto_trading_still_closes_on_the_flip(): void
    {
        $long = $this->sarTrade('LONG');
        $this->flips = [$this->flip('SHORT', sl: 5.15)];

        $this->runSar(entriesAllowed: false);

        $this->assertSame('CLOSED', $long->fresh()->status);
        $this->assertSame(0, Trade::where('status', 'OPEN')->count());
    }

    public function test_each_candle_is_handled_once(): void
    {
        $this->flips = [$this->flip('LONG')];
        $this->runSar();
        Trade::query()->update(['status' => 'CLOSED']);

        $this->assertSame('none', $this->runSar()[0]['action']);
        $this->assertSame(0, Trade::where('status', 'OPEN')->count());
    }

    public function test_the_stop_trails_the_line_forward_only_and_skips_the_normal_exit_plan(): void
    {
        $trade = $this->sarTrade('LONG', ['meta' => ['interval' => '1h', 'atr' => 0.06, 'sar_trail' => 4.95]]);
        $manager = app(DynamicTradeManager::class);

        // With profit booking and the breakeven trail off, +2R only moves the stop to the line.
        config(['trading.swing_sar.take_profit' => false, 'trading.swing_sar.ladder' => [], 'trading.swing_sar.partials' => [], 'trading.swing_sar.trail_from_r' => 0.0]);
        $manager->manageTrade($trade, 5.30);
        $trade->refresh();
        $this->assertEqualsWithDelta(4.95, (float) $trade->current_sl, 1e-9);
        $this->assertFalse((bool) $trade->tp1_hit);
        $this->assertSame('OPEN', $trade->status);

        // A lower line never loosens the stop.
        $trade->update(['meta' => ['interval' => '1h', 'atr' => 0.06, 'sar_trail' => 4.90]]);
        $manager->manageTrade($trade->fresh(), 5.30);
        $this->assertEqualsWithDelta(4.95, (float) $trade->fresh()->current_sl, 1e-9);

        // No time stop after 12h without progress.
        $trade->update(['opened_at' => Carbon::now()->subHours(20)]);
        $manager->manageTrade($trade->fresh(), 5.0);
        $this->assertSame('OPEN', $trade->fresh()->status);

        // Price through the trailed stop closes it.
        $manager->manageTrade($trade->fresh(), 4.94);
        $this->assertSame('TRAILING_STOP', $trade->fresh()->exit_reason);
    }

    public function test_from_ten_percent_roe_the_stop_locks_half_of_the_peak_gain_and_follows_new_highs(): void
    {
        config(['trading.swing_sar.profit_lock' => [[10, 0.50], [20, 0.65], [30, 0.75]], 'trading.swing_sar.partials' => [], 'trading.swing_sar.ladder' => [], 'trading.swing_sar.trail_from_r' => 0.0]);
        $trade = $this->sarTrade('LONG'); // entry 5.00, 5x: +2% price = 10% ROE
        $manager = app(DynamicTradeManager::class);

        $manager->manageTrade($trade, 5.09); // ROE 9%: nothing yet
        $this->assertEqualsWithDelta(4.85, (float) $trade->fresh()->current_sl, 1e-9);

        $manager->manageTrade($trade->fresh(), 5.11); // ROE 11%: keep 50% of +0.11
        $this->assertEqualsWithDelta(5.055, (float) $trade->fresh()->current_sl, 1e-9);
        $this->assertTrue((bool) $trade->fresh()->be_locked);

        $manager->manageTrade($trade->fresh(), 5.22); // ROE 22%: keep 65% of +0.22
        $this->assertEqualsWithDelta(5.143, (float) $trade->fresh()->current_sl, 1e-9);

        $manager->manageTrade($trade->fresh(), 5.16); // pullback: the stop stays
        $this->assertEqualsWithDelta(5.143, (float) $trade->fresh()->current_sl, 1e-9);

        // Drop to the locked stop: the rest closes in profit.
        $manager->manageTrade($trade->fresh(), 5.14);
        $this->assertSame('TRAILING_STOP', $trade->fresh()->exit_reason);
        $this->assertGreaterThan(0, (float) $trade->fresh()->net_pnl);

        // Spike and sudden fall between two checks (stop not moved yet): booked at once, still in profit.
        $spiked = $this->sarTrade('LONG', ['highest_price' => 5.22]);
        $manager->manageTrade($spiked, 5.12);
        $this->assertSame('PROFIT_LOCK', $spiked->fresh()->exit_reason);
        $this->assertGreaterThan(0, (float) $spiked->fresh()->net_pnl);
    }

    public function test_after_ten_percent_roe_the_stop_trails_the_last_swing_low(): void
    {
        config(['trading.swing_sar.profit_lock' => [[10, 0.50]], 'trading.swing_sar.partials' => [], 'trading.swing_sar.ladder' => [], 'trading.swing_sar.trail_from_r' => 0.0]);
        $trade = $this->sarTrade('LONG', ['meta' => ['interval' => '15m', 'atr' => 0.06, 'sar_swing_low' => 5.16]]);
        $manager = app(DynamicTradeManager::class);

        $manager->manageTrade($trade, 5.08); // ROE 8%: the swing low is not used yet
        $this->assertEqualsWithDelta(4.85, (float) $trade->fresh()->current_sl, 1e-9);

        $manager->manageTrade($trade->fresh(), 5.20); // ROE 20%: swing low 5.16 - 0.1 ATR beats 50% of the gain (5.10)
        $this->assertEqualsWithDelta(5.154, (float) $trade->fresh()->current_sl, 1e-9);
    }

    public function test_a_closed_candle_stores_the_last_swing_low_and_high(): void
    {
        $trade = $this->sarTrade('LONG');
        $this->flips = [];

        $this->runSar();

        $meta = $trade->fresh()->meta;
        $this->assertArrayHasKey('sar_swing_low', $meta);
        $this->assertLessThan((float) $meta['sar_swing_high'], (float) $meta['sar_swing_low']);
    }

    public function test_the_chart_lists_the_bots_trades_on_the_coin(): void
    {
        config(['trading.mode' => 'paper']);
        $admin = User::factory()->create(['role' => 'admin']);
        $mine = $this->sarTrade('LONG');
        $this->sarTrade('LONG', ['symbol' => 'SUIUSDT']);
        $this->sarTrade('LONG', ['mode' => 'live']);

        $res = $this->actingAs($admin)->getJson(route('signals.chart_trades', ['symbol' => 'NEARUSDT']))->assertOk();

        $this->assertSame([$mine->id], array_column($res->json('trades'), 'id'));
        $this->actingAs($admin)->get('/signals')->assertSee('id="sapToggle-trades"', false)
            ->assertSee('fetch(`/signals/chart-trades?symbol=', false)
            ->assertDontSee(route('signals.chart_trades'), false);
    }

    public function test_sar_trades_have_their_own_slots(): void
    {
        config(['trading.swing_sar.max_open' => 2]);
        $risk = app(RiskManager::class);
        $account = TradingAccount::getForMode('paper');
        Trade::create(['symbol' => 'SOLUSDT', 'side' => 'LONG', 'mode' => 'paper', 'status' => 'OPEN', 'entry_price' => 100, 'quantity' => 0.1,
            'remaining_quantity' => 0.1, 'margin_used' => 2, 'leverage' => 5, 'initial_sl' => 99, 'current_sl' => 99, 'tp1_price' => 101.5, 'tp2_price' => 103, 'opened_at' => now()]);

        // $24 account: the main bot's single slot is taken, a SAR entry is still allowed.
        $this->assertFalse($risk->canOpenTrade($account, 'ETHUSDT', 'LONG')['allowed']);
        $this->assertTrue($risk->canOpenTrade($account, 'NEARUSDT', 'LONG', setup: SwingSarTrader::SETUP)['allowed']);

        // Two SAR trades fill the SAR slots without touching the main bot's.
        $this->sarTrade('LONG');
        $this->sarTrade('SHORT', ['symbol' => 'SUIUSDT']);
        $this->assertFalse($risk->canOpenTrade($account, 'DOGEUSDT', 'SHORT', setup: SwingSarTrader::SETUP)['allowed']);
    }
}
