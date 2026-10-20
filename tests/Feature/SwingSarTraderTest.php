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
        // $24 x 50% / 2 slots = $6 margin.
        $this->assertEqualsWithDelta(6.0, (float) $trade->margin_used, 0.2);
        $text = implode("\n", array_map(fn (array $pair): string => (string) ($pair[0]->data()['text'] ?? ''), Http::recorded()->all()));
        $this->assertStringContainsString('opposite swing flip', $text);
        $this->assertStringContainsString('Stop loss: −$', $text);
        $this->assertStringContainsString('the stop moves to breakeven', $text);
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
        config(['trading.swing_sar.take_profit' => true, 'trading.swing_sar.breakeven_r' => 0.0]);
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

    public function test_breakeven_at_one_r_then_the_stop_trails_the_best_price(): void
    {
        $trade = $this->sarTrade('LONG'); // entry 5.00, stop 4.85 (1R = 0.15), ATR 0.06
        $manager = app(DynamicTradeManager::class);

        $manager->manageTrade($trade, 5.10);
        $this->assertEqualsWithDelta(4.85, (float) $trade->fresh()->current_sl, 1e-9, 'Below +1R nothing moves');

        $manager->manageTrade($trade->fresh(), 5.16);
        $trade->refresh();
        $this->assertTrue((bool) $trade->be_locked);
        $this->assertEqualsWithDelta(5.16 - 1.5 * 0.06, (float) $trade->current_sl, 1e-6);
        Http::assertSent(fn (Request $r): bool => str_contains((string) ($r->data()['text'] ?? ''), 'breakeven'));

        $manager->manageTrade($trade->fresh(), 5.10);
        $this->assertEqualsWithDelta(5.07, (float) $trade->fresh()->current_sl, 1e-6, 'A pullback never loosens the stop');

        $manager->manageTrade($trade->fresh(), 5.40);
        $this->assertEqualsWithDelta(5.31, (float) $trade->fresh()->current_sl, 1e-6);

        $manager->manageTrade($trade->fresh(), 5.30);
        $this->assertSame('TRAILING_STOP', $trade->fresh()->exit_reason);
        $this->assertGreaterThan(0, (float) $trade->fresh()->net_pnl);
    }

    public function test_a_short_locks_breakeven_and_trails_down(): void
    {
        $trade = $this->sarTrade('SHORT'); // entry 5.00, stop 5.15

        app(DynamicTradeManager::class)->manageTrade($trade, 4.80);

        $this->assertEqualsWithDelta(4.80 + 1.5 * 0.06, (float) $trade->fresh()->current_sl, 1e-6);
    }

    public function test_the_saved_coin_list_wins_over_the_config(): void
    {
        $this->assertSame(['NEARUSDT'], SwingSarTrader::symbols());

        SwingSarTrader::saveSymbols(['sui', 'NEARUSDT']);

        $this->assertSame(['SUIUSDT', 'NEARUSDT'], SwingSarTrader::symbols());
    }

    public function test_swing_coins_can_be_added_and_removed_from_the_signals_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('daemon.swing-coins.add'), ['symbol' => 'sui'])
            ->assertOk()->assertJsonPath('coins', ['NEARUSDT', 'SUIUSDT']);
        $this->actingAs($admin)->postJson(route('daemon.swing-coins.remove'), ['symbol' => 'NEARUSDT'])
            ->assertOk()->assertJsonPath('coins', ['SUIUSDT']);
        $this->actingAs($admin)->getJson(route('daemon.swing-coins'))
            ->assertOk()->assertJsonPath('coins', ['SUIUSDT'])->assertJsonPath('interval', '15m')->assertJsonPath('confirmed_only', true);
        $this->actingAs($admin)->get('/signals')->assertSee('Swing Auto-Trade Coins');
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
        config(['trading.swing_sar.take_profit' => false, 'trading.swing_sar.breakeven_r' => 0.0]);
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

    public function test_sar_trades_have_their_own_slots(): void
    {
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
