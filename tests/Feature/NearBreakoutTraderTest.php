<?php

namespace Tests\Feature;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Crypto\BinanceClient;
use App\Services\Trading\BotSwitches;
use App\Services\Trading\NearBreakoutTrader;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class NearBreakoutTraderTest extends TestCase
{
    use RefreshDatabase;

    /** 2026-10-20 06:01 UTC: the 05:00-06:00 candle has just closed. */
    protected const NOW = 1792476060000;

    /** @var array<string, array<int, float|int>> */
    protected array $candles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::createFromTimestampMsUTC(self::NOW));
        config([
            'trading.telegram.enabled' => true,
            'trading.telegram.bot_token' => '123:SECRET',
            'trading.telegram.chat_id' => '999',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9]]), '*' => Http::response([])]);
        Cache::put('binance:futures:exchange_info:live', ['NEARUSDT' => [
            'symbol' => 'NEARUSDT', 'pricePrecision' => 4, 'quantityPrecision' => 1, 'minNotional' => 5.0, 'stepSize' => 0.1, 'tickSize' => 0.001,
        ]], 3600);
        TradingAccount::create(['mode' => 'paper', 'balance' => 24.0, 'initial_balance' => 24.0, 'equity' => 24.0, 'peak_equity' => 24.0, 'is_running' => true]);

        $market = Mockery::mock(BinanceClient::class);
        $market->shouldReceive('klines')->andReturnUsing(fn (): array => $this->candles);
        $this->app->instance(BinanceClient::class, $market);
    }

    /**
     * 1500 closed 1h candles flat at $5 plus the forming one. Candle ranges: $history for the older candles (an
     * array cycles in blocks of 100), $recent for the last 80; the last two closes are $prevClose and $close.
     *
     * @param  float|array<int, float>  $history
     */
    protected function series(float|array $history, float $recent, float $lastOpen = 5.0, float $close = 5.05, float $prevClose = 5.0): void
    {
        $c = ['opens' => [], 'highs' => [], 'lows' => [], 'closes' => [], 'volumes' => [], 'closeTimes' => []];
        $lastClose = intdiv(self::NOW, 3_600_000) * 3_600_000 - 1;
        $n = 1500;
        for ($i = 0; $i <= $n; $i++) {
            $range = $i >= $n - 80 ? $recent : (is_array($history) ? $history[intdiv($i, 100) % count($history)] : $history);
            [$o, $cl] = match (true) {
                $i === $n - 2 => [5.0, $prevClose],
                $i >= $n - 1 => [$lastOpen, $close],
                default => [5.0, 5.0],
            };
            $c['opens'][] = $o;
            $c['closes'][] = $cl;
            $c['highs'][] = max($o, $cl) + $range / 2;
            $c['lows'][] = min($o, $cl) - $range / 2;
            $c['volumes'][] = 1000.0;
            $c['closeTimes'][] = $lastClose - ($n - 1 - $i) * 3_600_000;
        }
        $this->candles = $c;
    }

    protected function trader(): NearBreakoutTrader
    {
        return app(NearBreakoutTrader::class);
    }

    /**
     * @return array<int, string>
     */
    protected function telegramTexts(): array
    {
        return collect(Http::recorded())->map(fn (array $p): string => (string) ($p[0]->data()['text'] ?? ''))->filter()->values()->all();
    }

    public function test_quiet_volatility_breakout_buys_the_whole_balance_at_1x(): void
    {
        $this->series(0.10, 0.04);

        $row = $this->trader()->run('paper', true, self::NOW);

        $this->assertSame('opened', $row['action'], $row['message']);
        $trade = Trade::sole();
        $this->assertSame(NearBreakoutTrader::SETUP, $trade->setup_tag);
        $this->assertSame('LONG', $trade->side);
        $this->assertSame(1, (int) $trade->leverage);
        $this->assertEqualsWithDelta(24.0 * 0.98, (float) $trade->quantity * (float) $trade->entry_price, 0.6, '100% of the balance (less the fee buffer)');
        $this->assertSame(1.0, (float) $trade->meta['size_mult']);
        $this->assertLessThanOrEqual(20, (float) $trade->meta['rank']);
        $atr = (float) $trade->meta['atr'];
        $this->assertEqualsWithDelta(0.10 * $atr, (float) $trade->meta['trail_d'], 1e-12);
        $this->assertEqualsWithDelta(5.05 - 3 * $atr, (float) $trade->initial_sl, 0.01, 'Safety stop 3 ATR below the signal close');
        $this->assertEqualsWithDelta(0.0, (float) $trade->tp1_price, 1e-12);
    }

    public function test_middle_volatility_buys_half_the_balance(): void
    {
        $this->series([0.04, 0.10], 0.07);

        $this->assertSame('opened', $this->trader()->run('paper', true, self::NOW)['action']);

        $trade = Trade::sole();
        $this->assertSame(0.5, (float) $trade->meta['size_mult']);
        $this->assertEqualsWithDelta(24.0 * 0.5 * 0.98, (float) $trade->quantity * (float) $trade->entry_price, 0.6);
    }

    public function test_high_volatility_rank_blocks_the_entry(): void
    {
        $this->series(0.04, 0.10);

        $e = NearBreakoutTrader::evaluate(array_map(fn (array $v): array => array_slice($v, 0, 1500), $this->candles));
        $row = $this->trader()->run('paper', true, self::NOW);

        $this->assertGreaterThanOrEqual(80, $e['rank']);
        $this->assertSame(0.0, $e['size']);
        $this->assertSame('none', $row['action']);
        $this->assertStringContainsString('volatility rank', $row['message']);
        $this->assertSame(0, Trade::count());
    }

    public function test_no_entry_without_a_fresh_crossover_or_on_a_red_candle(): void
    {
        $this->series(0.10, 0.04, close: 5.06, prevClose: 5.05); // the previous candle already broke out
        $this->assertStringContainsString('crossed above the 12-candle high', $this->trader()->run('paper', true, self::NOW)['message']);

        Cache::flush();
        $this->series(0.10, 0.04, lastOpen: 5.10, close: 5.05);
        $this->assertStringContainsString('not green candle', $this->trader()->run('paper', true, self::NOW)['message']);

        $this->assertSame(0, Trade::count());
    }

    public function test_paused_or_switched_off_skips_the_entry_and_says_why(): void
    {
        $this->series(0.10, 0.04);
        BotSwitches::set('near', false);

        $row = $this->trader()->run('paper', false, self::NOW);

        $this->assertSame('skipped', $row['action']);
        $this->assertSame(0, Trade::count());
        $this->assertStringContainsString('NEAR bot switch is off', implode("\n", $this->telegramTexts()));
    }

    public function test_a_close_below_the_ema_exits_the_open_trade(): void
    {
        $this->series(0.10, 0.04);
        $this->trader()->run('paper', true, self::NOW);
        $trade = Trade::sole();

        $next = self::NOW + 3_600_000;
        Carbon::setTestNow(Carbon::createFromTimestampMsUTC($next));
        $this->series(0.10, 0.04, lastOpen: 5.0, close: 4.90);
        $this->candles['closeTimes'] = array_map(fn (int $t): int => $t + 3_600_000, $this->candles['closeTimes']);
        Cache::put('binance:mark:NEARUSDT', 4.90, 60);

        $row = $this->trader()->run('paper', true, $next);

        $this->assertSame('closed', $row['action'], $row['message']);
        $this->assertSame('EMA_EXIT', $trade->fresh()->exit_reason);
    }

    public function test_each_candle_is_handled_once_and_a_missing_candle_is_retried(): void
    {
        $this->series(0.10, 0.04);
        $this->candles = array_map(fn (array $v): array => array_slice($v, 0, -2), $this->candles); // the last closed candle is missing

        $this->assertStringContainsString('not available yet', $this->trader()->run('paper', true, self::NOW)['message']);

        $this->series(0.10, 0.04);
        $this->assertSame('opened', $this->trader()->run('paper', true, self::NOW)['action']);
        $this->assertSame('Candle already handled.', $this->trader()->run('paper', true, self::NOW)['message']);
        $this->assertSame(1, Trade::count());
        $this->assertSame('opened', Cache::get(NearBreakoutTrader::LAST_KEY)['action'], 'The dashboard sees the last decision');
    }
}
