<?php

namespace Tests\Feature;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Models\User;
use App\Services\Crypto\BinanceClient;
use App\Services\Strategy\BreakoutWatcher;
use App\Services\Trading\BotSwitches;
use App\Services\Trading\DynamicTradeManager;
use App\Services\Trading\LabTrader;
use App\Services\Trading\RiskManager;
use App\Services\Trading\SwingSarTrader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class LabTraderTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array<string, array<int, float|int>>> symbol => 4h candles */
    protected array $candles = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trading.lab.enabled' => true,
            'trading.lab.until' => now()->addDays(7)->toDateTimeString(),
            'trading.swing_sar.symbols' => ['SOLUSDT', 'NEARUSDT', 'XRPUSDT'],
            'trading.telegram.enabled' => false,
        ]);
        Http::fake();
        TradingAccount::create(['mode' => 'paper', 'balance' => 24.0, 'initial_balance' => 24.0, 'equity' => 24.0, 'peak_equity' => 24.0, 'is_running' => true]);

        $market = Mockery::mock(BinanceClient::class);
        $market->shouldReceive('klines')->andReturnUsing(fn (string $symbol): array => $this->candles[$symbol] ?? $this->series(fn (int $i): float => 100.0));
        $market->shouldReceive('tickerPrice')->andReturnUsing(fn (string $symbol): float => (float) end($this->candles[$symbol]['closes']));
        $this->app->instance(BinanceClient::class, $market);
    }

    /**
     * 300 closed 4h candles with close = $price(i), ending at the last full 4h boundary.
     *
     * @return array<string, array<int, float|int>>
     */
    protected function series(callable $price, int $n = 300, float $wick = 0.5): array
    {
        $c = ['opens' => [], 'highs' => [], 'lows' => [], 'closes' => [], 'volumes' => [], 'closeTimes' => []];
        $end = intdiv(time(), 14400) * 14400 * 1000 - 1;
        $prev = $price(0);
        for ($i = 0; $i < $n; $i++) {
            $p = $price($i);
            $c['opens'][] = $prev;
            $c['closes'][] = $p;
            $c['highs'][] = max($prev, $p) + $wick;
            $c['lows'][] = min($prev, $p) - $wick;
            $c['volumes'][] = 1000.0;
            $c['closeTimes'][] = $end - ($n - 1 - $i) * 14400000;
            $prev = $p;
        }

        return $c;
    }

    protected function lab(): LabTrader
    {
        return app(LabTrader::class);
    }

    public function test_sol_momentum_opens_long_in_an_uptrend_sized_to_three_percent_risk_without_a_take_profit(): void
    {
        $this->candles['SOLUSDT'] = $this->series(fn (int $i): float => 100 + $i * 0.3 + sin($i) * 0.5);

        $row = $this->lab()->run('paper', true)[0];

        $this->assertSame('opened', $row['action'], $row['message']);
        $trade = Trade::where('setup_tag', LabTrader::SETUP)->sole();
        $this->assertSame('SOLUSDT', $trade->symbol);
        $this->assertSame('LONG', $trade->side);
        $risk = abs((float) $trade->entry_price - (float) $trade->initial_sl) * (float) $trade->quantity;
        $this->assertEqualsWithDelta(0.72, $risk, 0.15, 'About 3% of $24 lost at the stop');
        $this->assertNull($trade->meta['tp_order'] ?? null);
    }

    public function test_the_stop_trails_up_and_a_turn_in_momentum_closes_the_trade(): void
    {
        $up = fn (int $i): float => 100 + $i * 0.3 + sin($i) * 0.5;
        $this->candles['SOLUSDT'] = $this->series($up);
        $this->lab()->run('paper', true);
        $trade = Trade::where('setup_tag', LabTrader::SETUP)->sole();
        $first = (float) $trade->current_sl;

        // Next 4h candle: higher high -> the chandelier stop moves up.
        $this->candles['SOLUSDT'] = $this->series(fn (int $i): float => $up($i + 1) + ($i === 299 ? 6 : 0));
        $trade->update(['highest_price' => end($this->candles['SOLUSDT']['highs'])]);
        Cache::flush(); // same candle times in this test: let the trader handle them again
        $row = $this->lab()->run('paper', true)[0];
        $this->assertSame('trail', $row['action'], $row['message']);
        $this->assertGreaterThan($first, (float) $trade->fresh()->current_sl);

        // A sharp drop: the 7-day return turns negative -> closed.
        $this->candles['SOLUSDT'] = $this->series(fn (int $i): float => $i < 280 ? $up($i + 2) : $up(280) - ($i - 279) * 3.0);
        $trade->update(['current_sl' => 1.0]); // keep the stop out of the way for this check
        Cache::flush();
        $row = $this->lab()->run('paper', true)[0];
        $this->assertSame('closed', $row['action'], $row['message']);
        $this->assertSame('MOMENTUM_TURNED', $trade->fresh()->exit_reason);
    }

    public function test_near_squeeze_breakout_opens_with_the_trend(): void
    {
        // Rising to 100 (above its EMA200), a tight range, then a close above the range.
        $this->candles['NEARUSDT'] = $this->series(fn (int $i): float => match (true) {
            $i < 250 => 50 + $i * 0.2,
            $i < 299 => 100 + 0.2 * sin($i),
            default => 103.0,
        }, wick: 0.1);

        $rows = collect($this->lab()->run('paper', true))->keyBy('symbol');

        $this->assertSame('opened', $rows['NEARUSDT']['action'], $rows['NEARUSDT']['message']);
        $trade = Trade::where('symbol', 'NEARUSDT')->sole();
        $this->assertSame('LONG', $trade->side);
        $this->assertLessThan(103.0, (float) $trade->initial_sl);
    }

    public function test_no_new_entries_after_the_trial_and_swing_sar_gets_the_coins_back(): void
    {
        $this->assertSame(['XRPUSDT'], SwingSarTrader::symbols(), 'While the trial runs, SOL and NEAR belong to the lab');
        $sar = Trade::create(['symbol' => 'SOLUSDT', 'setup_tag' => SwingSarTrader::SETUP, 'side' => 'LONG', 'mode' => 'paper', 'status' => 'OPEN', 'entry_price' => 100,
            'quantity' => 0.1, 'remaining_quantity' => 0.1, 'margin_used' => 2, 'leverage' => 5, 'initial_sl' => 98, 'current_sl' => 98, 'tp1_price' => 103, 'tp2_price' => 106, 'opened_at' => now()]);
        $this->assertSame(['SOLUSDT', 'XRPUSDT'], SwingSarTrader::symbols(), 'An open Swing SAR trade keeps its coin until it closes');
        $sar->delete();

        config(['trading.lab.until' => now()->subMinute()->toDateTimeString()]);
        $this->candles['SOLUSDT'] = $this->series(fn (int $i): float => 100 + $i * 0.3);
        $row = $this->lab()->run('paper', true)[0];

        $this->assertSame('skipped', $row['action']);
        $this->assertSame(0, Trade::count());
        $this->assertSame(['SOLUSDT', 'NEARUSDT', 'XRPUSDT'], SwingSarTrader::symbols());
    }

    public function test_switched_off_the_lab_opens_nothing_but_keeps_the_coin_of_an_open_trade(): void
    {
        BotSwitches::set('lab', false);
        $this->candles['SOLUSDT'] = $this->series(fn (int $i): float => 100 + $i * 0.3 + sin($i) * 0.5);

        $this->lab()->run('paper', true);

        $this->assertSame(0, Trade::count());
        $this->assertSame(['SOLUSDT', 'NEARUSDT', 'XRPUSDT'], SwingSarTrader::symbols(), 'Off: Swing SAR gets the coins back');

        BotSwitches::set('lab', true);
        Cache::flush();
        $this->lab()->run('paper', true);
        BotSwitches::set('lab', false);
        $this->assertTrue(LabTrader::hasOpenTrades());
        $this->assertSame(['NEARUSDT', 'XRPUSDT'], SwingSarTrader::symbols(), 'The open lab trade keeps its coin until it closes');
    }

    public function test_the_dashboard_switches_need_trading_permission(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->postJson(route('api.bot_switch'), ['bot' => 'lab', 'enabled' => false])->assertForbidden();
        $this->assertTrue(BotSwitches::on('lab'));

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('api.bot_switch'), ['bot' => 'breakout', 'enabled' => false])
            ->assertOk()->assertJsonPath('bot_switches.breakout', false)->assertJsonPath('bot_switches.lab', true);
        $this->assertFalse(BreakoutWatcher::tradingEnabled());

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('api.bot_switch'), ['bot' => 'nope', 'enabled' => true])->assertStatus(422);
    }

    public function test_lab_trades_use_the_swing_slots_not_the_breakout_bots(): void
    {
        $this->candles['SOLUSDT'] = $this->series(fn (int $i): float => 100 + $i * 0.3 + sin($i) * 0.5);
        $this->lab()->run('paper', true);

        $account = TradingAccount::getForMode('paper');
        $this->assertTrue(app(RiskManager::class)->canOpenTrade($account, 'ETHUSDT', 'LONG')['allowed'], 'The $24 breakout slot is still free');
    }

    public function test_the_stop_is_checked_every_tick(): void
    {
        $this->candles['SOLUSDT'] = $this->series(fn (int $i): float => 100 + $i * 0.3 + sin($i) * 0.5);
        $this->lab()->run('paper', true);
        $trade = Trade::where('setup_tag', LabTrader::SETUP)->sole();

        app(DynamicTradeManager::class)->manageTrade($trade, (float) $trade->current_sl - 0.01);

        $this->assertSame('STOP_LOSS', $trade->fresh()->exit_reason);
    }
}
