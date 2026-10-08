<?php

namespace Tests\Feature;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Models\User;
use App\Services\Notifications\SignalAlerts;
use App\Services\Notifications\TelegramGateway;
use App\Services\Strategy\BreakoutWatcher;
use App\Services\Strategy\MarketScanService;
use App\Services\Strategy\SignalLedger;
use App\Services\Strategy\StrategyEngine;
use App\Services\Strategy\Watchlist;
use App\Services\Trading\DynamicTradeManager;
use App\Services\Trading\EarlyBreakoutGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class BreakoutAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trading.mode' => 'paper',
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
     * @return array<int, array<string, mixed>>
     */
    protected function telegramMessages(): array
    {
        return Http::recorded(fn (Request $r): bool => str_contains($r->url(), 'api.telegram.org'))->map(fn (array $pair): array => $pair[0]->data())->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function watch(string $symbol = 'SOLUSDT', string $side = 'LONG', float $level = 100.0, float $price = 99.6): array
    {
        $now = now()->startOfHour()->getTimestampMs();

        return [
            'symbol' => $symbol, 'interval' => '1h', 'side' => $side, 'level' => $level, 'box_high' => $level / 1.001, 'box_low' => $level * 0.97,
            'atr' => 0.8, 'vol_sma' => 1000.0, 'bar_open_ms' => $now, 'bar_close_ms' => $now + 3_599_999, 'price' => $price,
            'filters' => ['regime' => ['pass' => true, 'detail' => '4h up trend']],
            'indicators' => ['rsi' => 60.0, 'adx' => 26.0, 'volume_ratio' => 1.2, 'atr_pct' => 0.8],
            'confluences' => ['Strong 4h trend'],
            'features' => ['setup' => 'SQUEEZE_BREAKOUT', 'side' => 1],
            'bias' => $side, 'regime_side' => 'LONG',
            'lean' => ['side' => $side, 'position' => $side === 'LONG' ? 0.9 : 0.1, 'drift' => $side === 'LONG' ? 0.05 : -0.05, 'volume_ratio' => $side === 'LONG' ? 1.6 : 0.5],
        ];
    }

    public function test_coiled_coins_are_announced_once_in_one_message(): void
    {
        $alerts = app(SignalAlerts::class);

        $this->assertTrue($alerts->breakoutWatchAlert(['SOLUSDT' => $this->watch(), 'ENAUSDT' => $this->watch('ENAUSDT', 'SHORT', 0.412, 0.4151)]));
        $this->assertFalse($alerts->breakoutWatchAlert(['SOLUSDT' => $this->watch(), 'ENAUSDT' => $this->watch('ENAUSDT', 'SHORT', 0.412, 0.4151)]), 'Same coins are not repeated');

        $messages = $this->telegramMessages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Breakout setups', $messages[0]['text']);
        $this->assertStringContainsString('SOLUSDT', $messages[0]['text']);
        $this->assertStringContainsString('ENAUSDT', $messages[0]['text']);
    }

    public function test_setup_alert_names_one_direction_with_entry_stop_targets_and_reasons(): void
    {
        $watch = $this->watch('SOLUSDT', 'LONG', 100.0, 99.0) + ['levels' => ['entry' => 99.85, 'sl' => 98.5, 'tp1' => 101.9, 'tp2' => 103.9, 'tp3' => 105.9]];

        $this->assertTrue(app(SignalAlerts::class)->breakoutWatchAlert(['SOLUSDT:LONG' => $watch]));

        $text = $this->telegramMessages()[0]['text'];
        $this->assertStringContainsString('🟢 <b>BUY SOLUSDT</b>', $text);
        $this->assertStringContainsString('Entry above <code>99.85', $text);
        $this->assertStringContainsString('Stop <code>98.5', $text);
        $this->assertStringContainsString('TP1 <code>101.9', $text);
        $this->assertStringContainsString('TP2 <code>103.9', $text);
        $this->assertStringContainsString('Why: closing at the box top · higher lows · buy volume 1.6× sell · 4h trend up', $text);
        $this->assertStringNotContainsString('SELL', $text);
    }

    public function test_boxes_without_a_lean_are_not_announced_before_they_break(): void
    {
        $long = ['bias' => null] + $this->watch('SOLUSDT', 'LONG', 100.0, 99.0);
        $short = ['bias' => null] + $this->watch('SOLUSDT', 'SHORT', 97.0, 99.0);

        $this->assertFalse(app(SignalAlerts::class)->breakoutWatchAlert(['SOLUSDT:LONG' => $long, 'SOLUSDT:SHORT' => $short]));
        $this->assertSame([], $this->telegramMessages());
    }

    public function test_no_breakout_alerts_when_alerts_are_switched_off(): void
    {
        Watchlist::setAlertsEnabled(false);

        $this->assertFalse(app(SignalAlerts::class)->breakoutWatchAlert(['SOLUSDT' => $this->watch()]));
        $this->assertSame([], $this->telegramMessages());
    }

    public function test_engine_auto_trades_an_early_breakout_at_half_risk_and_alerts_instantly(): void
    {
        TradingAccount::getForMode('paper')->update(['balance' => 100.0, 'peak_equity' => 100.0, 'is_running' => true]);
        $signal = app(StrategyEngine::class)->intrabarSignal($this->watch(), 100.0, intdiv(now()->startOfHour()->getTimestampMs() + 3_599_999, 1000))->withScore('B', null);
        $record = app(SignalLedger::class)->record($signal, 'watcher');

        $this->mock(MarketScanService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isScanDue')->andReturn(false);
            $mock->shouldReceive('latestResults')->andReturn([]);
        });
        $this->mock(BreakoutWatcher::class, function (MockInterface $mock) use ($signal, $record): void {
            $mock->shouldReceive('check')->once()->andReturn([['signal' => $signal, 'record' => $record, 'level' => 100.0, 'volume_pace' => 2.1]]);
            $mock->shouldReceive('watching')->andReturn([]);
        });

        $this->artisan('trade:engine --once')->assertSuccessful();

        $trade = Trade::where('setup_tag', 'EARLY_BREAKOUT')->first();
        $this->assertNotNull($trade, 'The early breakout was traded');
        $this->assertEqualsWithDelta(1.0, (float) $trade->meta['risk_pct'], 0.05, 'Half the normal 2% risk');
        $this->assertNotNull($trade->meta['breakout_box']);

        $texts = implode("\n---\n", array_column($this->telegramMessages(), 'text'));
        $this->assertStringContainsString('BUY SOLUSDT: BREAKING OUT', $texts);
        $this->assertStringContainsString('TP3', $texts);
        $this->assertStringContainsString('volume 2.1x pace', $texts);
        $this->assertStringContainsString('Auto-trader: [PAPER] taken', $texts);
    }

    public function test_daily_cap_and_open_limit_block_more_early_entries(): void
    {
        $this->trade(['status' => 'OPEN']);
        $this->assertStringContainsString('already 1 open', EarlyBreakoutGuard::blockReason('paper'));

        Trade::query()->update(['status' => 'CLOSED', 'closed_at' => now(), 'net_pnl' => 0.5]);
        foreach (range(1, 6) as $i) {
            $this->trade(['status' => 'CLOSED', 'net_pnl' => 0.5]);
        }
        $this->assertNull(EarlyBreakoutGuard::blockReason('paper'), '7 entries today are still allowed');

        $this->trade(['status' => 'CLOSED', 'net_pnl' => 0.5]);
        $this->assertStringContainsString('daily limit of 8', EarlyBreakoutGuard::blockReason('paper'));
    }

    public function test_early_breakouts_have_no_separate_pause_by_default(): void
    {
        foreach (range(1, 5) as $i) {
            $this->trade(['status' => 'CLOSED', 'net_pnl' => -0.1, 'opened_at' => now()->subDays(2), 'closed_at' => now()->subMinutes($i)]);
        }

        $this->assertNull(EarlyBreakoutGuard::pauseReason('paper'), 'The account-wide 3-loss cooldown handles streaks');
    }

    public function test_five_losses_in_a_row_pause_early_breakouts_until_resumed(): void
    {
        config(['trading.strategy.early_breakout.pause_after_losses' => 5]);
        foreach (range(1, 5) as $i) {
            $this->trade(['status' => 'CLOSED', 'net_pnl' => -0.1, 'opened_at' => now()->subDays(2), 'closed_at' => now()->subMinutes($i)]);
        }

        $this->assertStringContainsString('5 losses in a row', (string) EarlyBreakoutGuard::pauseReason('paper'));

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson(route('market-scan.early-resume'))->assertOk();
        $this->assertNull(EarlyBreakoutGuard::pauseReason('paper'));
    }

    public function test_a_candle_closing_back_inside_the_box_exits_the_early_trade(): void
    {
        $trade = $this->trade(['status' => 'OPEN', 'opened_at' => now()->subHours(2), 'meta' => ['breakout_box' => 100.0, 'interval' => '1h']]);
        $kline = fn (int $openMs, float $close): array => [$openMs, '100.2', '100.6', '99.5', (string) $close, '900', $openMs + 3_599_999];
        $hour = now()->startOfHour()->getTimestampMs();
        Http::swap(new Factory);
        Http::fake([
            '*klines*' => Http::response([$kline($hour - 7_200_000, 100.4), $kline($hour - 3_600_000, 99.8), $kline($hour, 99.9)]),
            '*premiumIndex*' => Http::response(['markPrice' => '99.9']),
            '*' => Http::response([]),
        ]);

        app(DynamicTradeManager::class)->manageTrade($trade);

        $this->assertSame('FAILED_BREAKOUT', $trade->refresh()->exit_reason);
    }

    public function test_alert_log_shows_what_was_sent_and_why_the_rest_was_not(): void
    {
        $engine = app(StrategyEngine::class);
        $sent = app(SignalLedger::class)->record($engine->intrabarSignal($this->watch(), 100.0, now()->timestamp)->withScore('B', null), 'watcher');
        $sent->update(['telegram_sent' => true]);
        $filtered = $engine->intrabarSignal($this->watch('ENAUSDT'), 100.0, now()->timestamp)->withFilter('regime', false, '4h trend unclear');
        app(SignalLedger::class)->record($filtered->withScore('C', null), 'scanner');

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('alerts.index', ['delivery' => 'all']))->assertOk()
            ->assertSee('✓ Sent', false)
            ->assertSee('Not sent')
            ->assertSee('Filters failed');
        $this->actingAs($admin)->get(route('alerts.index'))->assertOk()->assertDontSee('ENAUSDT');
    }

    public function test_telegram_errors_are_recorded_and_shown(): void
    {
        Http::swap(new Factory);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

        $this->assertNull(app(TelegramGateway::class)->send('hello', null, priority: true));

        $health = app(TelegramGateway::class)->health();
        $this->assertStringContainsString('401', (string) $health['problem']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('alerts.index'))
            ->assertOk()->assertSee('Telegram messages are not being delivered');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function trade(array $overrides = []): Trade
    {
        return Trade::create(array_merge([
            'symbol' => 'SOLUSDT', 'setup_tag' => 'EARLY_BREAKOUT', 'side' => 'LONG', 'mode' => 'paper', 'status' => 'OPEN', 'stage' => 'ENTRY',
            'entry_price' => 100.5, 'quantity' => 1.0, 'remaining_quantity' => 1.0, 'margin_used' => 10.0, 'leverage' => 10,
            'initial_sl' => 98.5, 'current_sl' => 98.5, 'tp1_price' => 103.5, 'tp2_price' => 106.5, 'opened_at' => now(),
        ], $overrides));
    }
}
