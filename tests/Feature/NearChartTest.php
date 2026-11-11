<?php

namespace Tests\Feature;

use App\Models\Trade;
use App\Models\User;
use App\Services\Crypto\BinanceClient;
use App\Services\Trading\NearBreakoutTrader;
use App\Services\Trading\NearChart;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class NearChartTest extends TestCase
{
    use RefreshDatabase;

    /** 2026-10-20 06:01 UTC. */
    protected const NOW = 1792476060000;

    protected const H = 3_600_000;

    protected int $n = 1960;

    /** @var array<string, array<int, float|int>> */
    protected array $hourly = [];

    /** @var array<string, array<int, float|int>> */
    protected array $fiveMin = [];

    /** @var array<int, array{0: float, 1: float}> bar index => [open, close] */
    protected array $bars = [];

    /** @var array<int, array<int, array{0: float, 1: float, 2: float, 3: float}>> bar index => its 5m candles */
    protected array $paths = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::createFromTimestampMsUTC(self::NOW));
        Http::fake();
        config(['trading.telegram.enabled' => false]);

        // B: a quiet-volatility breakout that exits on a close below EMA50 three candles later.
        $b = $this->n - 100;
        $this->bars[$b] = [5.0, 5.05];
        $this->bars[$b + 1] = [5.05, 5.05];
        $this->bars[$b + 2] = [5.05, 5.05];
        $this->bars[$b + 3] = [5.05, 4.90];
        $this->paths[$b + 3] = [[5.05, 5.05, 4.90, 4.90]];
        // B2: a breakout whose trail arms in the next hour and is hit on the 5m path in the hour after.
        $b2 = $this->n - 60;
        $this->bars[$b2] = [5.0, 5.10];
        $this->bars[$b2 + 1] = [5.10, 5.20];
        $this->paths[$b2 + 1] = [[5.10, 5.20, 5.10, 5.20]];
        $this->bars[$b2 + 2] = [5.20, 5.16];
        $this->paths[$b2 + 2] = [[5.20, 5.20, 5.15, 5.15], [5.15, 5.16, 5.15, 5.16]];
        $this->build();

        $market = Mockery::mock(BinanceClient::class);
        $market->shouldReceive('klinesRange')->with('NEARUSDT', '1h', Mockery::any(), Mockery::any())->atMost()->once()->andReturnUsing(fn (): array => $this->hourly);
        $market->shouldReceive('klinesRange')->with('NEARUSDT', '5m', Mockery::any(), Mockery::any())->atMost()->once()->andReturnUsing(fn (): array => $this->fiveMin);
        $market->shouldReceive('klines')->andReturnUsing(fn (): array => [
            'opens' => [5.0], 'highs' => [5.01], 'lows' => [4.99], 'closes' => [5.0], 'volumes' => [10.0],
            'openTimes' => [$this->openTime($this->n)], 'closeTimes' => [$this->openTime($this->n) + self::H - 1],
        ]);
        $this->app->instance(BinanceClient::class, $market);
    }

    protected function openTime(int $i): int
    {
        $lastClose = intdiv(self::NOW, self::H) * self::H - 1;

        return $lastClose + 1 - ($this->n - $i) * self::H;
    }

    protected function build(): void
    {
        $h = ['opens' => [], 'highs' => [], 'lows' => [], 'closes' => [], 'volumes' => [], 'openTimes' => [], 'closeTimes' => []];
        $m = $h;
        for ($i = 0; $i < $this->n; $i++) {
            [$o, $c] = $this->bars[$i] ?? [5.0, 5.0];
            $r = $i < $this->n - 300 ? 0.10 : 0.04;
            $path = $this->paths[$i] ?? [[$o, max($o, $c), min($o, $c), $c]];
            $hi = max(max($o, $c) + $r / 2, ...array_column($path, 1));
            $lo = min(min($o, $c) - $r / 2, ...array_column($path, 2));
            foreach ([$o, $hi, $lo, $c, 1000.0, $this->openTime($i), $this->openTime($i) + self::H - 1] as $j => $v) {
                $h[array_keys($h)[$j]][] = $v;
            }
            if ($i >= $this->n - NearChart::WINDOW) {
                foreach ($path as $q => [$po, $ph, $pl, $pc]) {
                    foreach ([$po, $ph, $pl, $pc, 10.0, $this->openTime($i) + $q * 300_000, $this->openTime($i) + ($q + 1) * 300_000 - 1] as $j => $v) {
                        $m[array_keys($m)[$j]][] = $v;
                    }
                }
            }
        }
        [$this->hourly, $this->fiveMin] = [$h, $m];
    }

    protected function time(int $i): int
    {
        return intdiv($this->openTime($i), 1000);
    }

    public function test_the_chart_replays_the_script_signals_with_ema_and_trail_exits(): void
    {
        $data = app(NearChart::class)->payload(self::NOW);

        $this->assertCount(NearChart::WINDOW, $data['candles']);
        $this->assertNotEmpty($data['ema']);
        $this->assertNotEmpty($data['level']);
        $this->assertSame('5m', $data['trail_source']);

        $marks = collect($data['markers'])->map(fn (array $m): string => $m['time'].' '.$m['text'])->all();
        $b = $this->n - 100;
        $b2 = $this->n - 60;
        $this->assertSame($this->time($b).' BUY 100%', $marks[0]);
        $this->assertStringStartsWith($this->time($b + 3).' EXIT EMA', $marks[1]);
        $this->assertSame($this->time($b2).' BUY 100%', $marks[2]);
        $this->assertStringStartsWith($this->time($b2 + 2).' EXIT trail +', $marks[3]);
        $this->assertCount(4, $marks);
        $this->assertSame(2, $data['stats']['trades']);

        $trailExit = collect($data['signals'])->firstWhere('reason', 'trail hit');
        $this->assertLessThan(5.2, $trailExit['price']);
        $this->assertGreaterThan(5.19, $trailExit['price'], 'Exit d below the 5.20 high, not at the 1h close');
        $this->assertNotEmpty(collect($data['trail'])->filter(fn (array $p): bool => isset($p['value'])), 'The armed trail is drawn');
    }

    public function test_the_signals_match_the_bot_evaluation_on_every_candle(): void
    {
        $rows = NearBreakoutTrader::series($this->hourly, $this->n - NearChart::WINDOW);
        foreach (range($this->n - NearChart::WINDOW, $this->n - 1, 7) as $k) {
            $e = NearBreakoutTrader::evaluate(array_map(fn (array $v): array => array_slice($v, 0, $k + 1), $this->hourly));
            $this->assertSame($rows[$k]['entry'], $e['entry'], "bar {$k}");
            $this->assertSame($rows[$k]['rank'], $e['rank'], "bar {$k}");
        }
        $this->assertTrue($rows[$this->n - 100]['entry']);
    }

    public function test_bot_trades_are_marked_and_binance_is_read_once_an_hour(): void
    {
        $opened = $this->time($this->n - 100) + 3600 + 40;
        Trade::create([
            'symbol' => 'NEARUSDT', 'setup_tag' => NearBreakoutTrader::SETUP, 'side' => 'LONG', 'mode' => 'live', 'status' => 'CLOSED', 'stage' => 'ENTRY',
            'entry_price' => 5.05, 'exit_price' => 4.9, 'quantity' => 4, 'remaining_quantity' => 0, 'margin_used' => 20, 'leverage' => 1,
            'initial_sl' => 4.9, 'current_sl' => 4.9, 'tp1_price' => 0, 'tp2_price' => 0, 'net_pnl' => -0.62, 'exit_reason' => 'EMA_EXIT',
            'opened_at' => Carbon::createFromTimestampUTC($opened), 'closed_at' => Carbon::createFromTimestampUTC($this->time($this->n - 97) + 3600 + 40),
        ]);

        $chart = app(NearChart::class);
        $chart->payload(self::NOW);
        $data = $chart->payload(self::NOW + 30_000);

        $this->assertSame($this->time($this->n - 100), $data['trade_markers'][0]['time'], 'Bot buy on the signal candle');
        $this->assertSame('LIVE bot buy', $data['trade_markers'][0]['text']);
        $this->assertStringContainsString('bot exit -$0.62', $data['trade_markers'][1]['text']);
        $this->assertSame('EMA_EXIT', $data['bot_trades'][0]['reason']);
        $this->assertNotNull($data['forming']);
    }

    public function test_the_page_and_data_need_a_login_and_the_signals_permission(): void
    {
        $this->get('/chart')->assertRedirect('/login');

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/chart')->assertOk()->assertSee('SignalAlgo', false)->assertSee('signalalgo-near.js', false);
        $this->actingAs($admin)->getJson('/api/chart/near')->assertOk()->assertJsonStructure(['candles', 'markers', 'signals', 'stats', 'next', 'trade_markers']);

        $user = User::factory()->create(['role' => 'user']);
        DB::table('role_permissions')->where('role', 'user')->delete();
        $this->actingAs($user)->get('/chart')->assertForbidden();
    }
}
