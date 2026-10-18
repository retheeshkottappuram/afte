<?php

namespace Tests\Feature;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Crypto\BinanceClient;
use App\Services\Strategy\StrategyEngine;
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

    public function test_a_confirmed_flip_opens_a_fixed_ten_dollar_trade_without_take_profit(): void
    {
        $this->flips = [$this->flip('LONG')];

        $row = $this->runSar()[0];

        $this->assertSame('opened', $row['action'], $row['message']);
        $trade = Trade::where('setup_tag', SwingSarTrader::SETUP)->sole();
        $this->assertSame('LONG', $trade->side);
        $this->assertSame(5, (int) $trade->leverage);
        $this->assertEqualsWithDelta(10.0, (float) $trade->margin_used, 0.2);
        Http::assertSent(fn (Request $r): bool => str_contains((string) ($r->data()['text'] ?? ''), 'opposite swing flip'));
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

    public function test_an_unconfirmed_flip_closes_but_never_opens(): void
    {
        $long = $this->sarTrade('LONG');
        $this->flips = [$this->flip('SHORT', confirmed: false, sl: 5.15)];

        $row = $this->runSar()[0];

        $this->assertSame('closed', $row['action']);
        $this->assertSame('CLOSED', $long->fresh()->status);
        $this->assertSame(0, Trade::where('status', 'OPEN')->count());
        Http::assertSent(fn (Request $r): bool => str_contains((string) ($r->data()['text'] ?? ''), 'No new trade'));
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

        // +2R would book TP1 / breakeven on a normal trade; a SAR trade only moves its stop to the line.
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
