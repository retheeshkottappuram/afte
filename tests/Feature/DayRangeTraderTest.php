<?php

namespace Tests\Feature;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Crypto\BinanceClient;
use App\Services\Trading\BotSwitches;
use App\Services\Trading\DayRangeTrader;
use App\Services\Trading\DynamicTradeManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class DayRangeTraderTest extends TestCase
{
    use RefreshDatabase;

    /** 2026-10-20 06:00 UTC */
    protected const NOW = 1792476000000;

    /** @var array<string, float> symbol => last price */
    protected array $prices = [];

    /** @var array<string, float> symbol => daily step (+ uptrend, - downtrend) */
    protected array $trend = ['NEARUSDT' => 0.05, 'SUIUSDT' => -0.05];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::createFromTimestampMsUTC(self::NOW));
        config([
            'trading.day_range.enabled' => true,
            'trading.day_range.symbols' => ['NEARUSDT', 'SUIUSDT'],
            'trading.telegram.enabled' => true,
            'trading.telegram.bot_token' => '123:SECRET',
            'trading.telegram.chat_id' => '999',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9]]), '*' => Http::response([])]);
        TradingAccount::create(['mode' => 'paper', 'balance' => 24.0, 'initial_balance' => 24.0, 'equity' => 24.0, 'peak_equity' => 24.0, 'is_running' => true]);

        $market = Mockery::mock(BinanceClient::class);
        $market->shouldReceive('klines')->andReturnUsing(fn (string $symbol): array => $this->daily($this->trend[$symbol] ?? 0.0));
        $market->shouldReceive('get24hrTickers')->andReturnUsing(fn (): array => array_map(fn (string $s, float $p): array => ['symbol' => $s, 'lastPrice' => (string) $p], array_keys($this->prices), $this->prices));
        $this->app->instance(BinanceClient::class, $market);
    }

    /**
     * 40 closed daily candles around $5 (range 0.40 each) plus today's forming candle opening at the last close.
     *
     * @return array<string, array<int, float|int>>
     */
    protected function daily(float $step): array
    {
        $c = ['opens' => [], 'highs' => [], 'lows' => [], 'closes' => [], 'volumes' => [], 'closeTimes' => []];
        $todayStart = intdiv(self::NOW, 86_400_000) * 86_400_000;
        $price = 5.0;
        for ($i = 40; $i >= 0; $i--) {
            $open = $price;
            $close = $i === 0 ? $open : $open + $step;
            $c['opens'][] = $open;
            $c['closes'][] = $close;
            $c['highs'][] = max($open, $close) + 0.2;
            $c['lows'][] = min($open, $close) - 0.2;
            $c['volumes'][] = 1000.0;
            $c['closeTimes'][] = $todayStart - $i * 86_400_000 + 86_400_000 - 1;
            $price = $close;
        }

        return $c;
    }

    protected function trader(): DayRangeTrader
    {
        return app(DayRangeTrader::class);
    }

    public function test_levels_are_k_daily_ranges_below_the_open_only_in_an_uptrend(): void
    {
        $near = $this->trader()->levels('NEARUSDT', self::NOW);
        $sui = $this->trader()->levels('SUIUSDT', self::NOW);

        // Open = 5 + 40 x 0.05 = 7.00; average daily range = 0.05 + 0.40 = 0.45.
        $this->assertTrue($near['ok']);
        $this->assertEqualsWithDelta(7.0, $near['open'], 1e-9);
        $this->assertEqualsWithDelta(0.45, $near['adr'], 1e-9);
        $this->assertEqualsWithDelta(7.0 - 0.8 * 0.45, $near['buy'], 1e-3);
        $this->assertEqualsWithDelta($near['buy'] - 0.8 * 0.45, $near['sl'], 1e-3);
        $this->assertEqualsWithDelta($near['buy'] + 0.6 * 0.45, $near['target'], 1e-3);
        $this->assertFalse($sui['ok']);
        $this->assertStringContainsString('no daily uptrend', $sui['reason']);
    }

    public function test_the_levels_go_to_telegram_once_a_day(): void
    {
        $this->trader()->plan(self::NOW);
        $this->trader()->plan(self::NOW + 60_000);

        $texts = collect(Http::recorded())->map(fn (array $p): string => (string) ($p[0]->data()['text'] ?? ''))->filter(fn (string $t): bool => str_contains($t, 'Day dip BUY levels'));
        $this->assertCount(1, $texts);
        $this->assertStringContainsString('NEARUSDT', $texts->first());
        $this->assertStringNotContainsString('SUIUSDT', $texts->first(), 'Downtrend coins get no level');
        $this->assertStringContainsString('unproven', $texts->first());
    }

    public function test_the_bot_buys_only_when_the_price_reaches_the_level_at_one_percent_risk(): void
    {
        $this->prices = ['NEARUSDT' => 6.80]; // above the 6.64 level
        $this->assertSame([], $this->trader()->check('paper', true, self::NOW));

        $this->prices = ['NEARUSDT' => 6.63];
        $rows = $this->trader()->check('paper', true, self::NOW + 15_000);

        $this->assertSame('opened', $rows[0]['action'], $rows[0]['message']);
        $trade = Trade::sole();
        $this->assertSame(DayRangeTrader::SETUP, $trade->setup_tag);
        $this->assertSame('LONG', $trade->side);
        $risk = abs((float) $trade->entry_price - (float) $trade->initial_sl) * (float) $trade->quantity;
        $this->assertEqualsWithDelta(0.24, $risk, 0.03, '1% of $24');
        $this->assertEqualsWithDelta(6.91, (float) $trade->meta['day_target'], 1e-3);

        $this->assertSame([], $this->trader()->check('paper', true, self::NOW + 30_000), 'Once per coin per day');
    }

    public function test_no_entry_when_paused_or_switched_off(): void
    {
        $this->prices = ['NEARUSDT' => 6.63];
        $this->assertSame([], $this->trader()->check('paper', false, self::NOW));

        BotSwitches::set('dayrange', false);
        $this->assertSame([], $this->trader()->check('paper', true, self::NOW));
        $this->assertSame(0, Trade::count());
    }

    public function test_at_most_two_day_trades_are_open(): void
    {
        config(['trading.day_range.symbols' => ['NEARUSDT', 'ADAUSDT', 'XRPUSDT'], 'trading.sizing.max_positions' => [PHP_INT_MAX => 5]]);
        $this->trend = ['NEARUSDT' => 0.05, 'ADAUSDT' => 0.05, 'XRPUSDT' => 0.05];
        $this->prices = ['NEARUSDT' => 6.63, 'ADAUSDT' => 6.63, 'XRPUSDT' => 6.63];

        $this->trader()->check('paper', true, self::NOW);

        $this->assertLessThanOrEqual(2, Trade::where('setup_tag', DayRangeTrader::SETUP)->count());
    }

    public function test_the_trade_exits_at_the_target_the_stop_or_the_day_end(): void
    {
        $this->prices = ['NEARUSDT' => 6.63];
        $this->trader()->check('paper', true, self::NOW);
        $manager = app(DynamicTradeManager::class);
        $trade = Trade::sole();

        $manager->manageTrade($trade, 6.80);
        $this->assertSame('OPEN', $trade->fresh()->status);

        $manager->manageTrade($trade->fresh(), 6.92);
        $this->assertSame('TAKE_PROFIT', $trade->fresh()->exit_reason);
    }

    public function test_an_open_day_trade_is_closed_at_23_55_utc(): void
    {
        $this->prices = ['NEARUSDT' => 6.63];
        $this->trader()->check('paper', true, self::NOW);
        $trade = Trade::sole();

        Carbon::setTestNow(Carbon::createFromTimestampMsUTC(intdiv(self::NOW, 86_400_000) * 86_400_000 + 86_400_000 - 120_000));
        app(DynamicTradeManager::class)->manageTrade($trade, 6.70);

        $this->assertSame('DAY_END', $trade->fresh()->exit_reason);
    }
}
