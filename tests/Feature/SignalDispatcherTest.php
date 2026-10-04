<?php

namespace Tests\Feature;

use App\Models\CryptoSignal;
use App\Models\TradingAccount;
use App\Models\User;
use App\Services\Notifications\SignalAlerts;
use App\Services\Strategy\MarketScanService;
use App\Services\Strategy\Signal;
use App\Services\Strategy\SignalLedger;
use App\Services\Strategy\Watchlist;
use App\Services\Trading\TradingDaemonManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class SignalDispatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trading.telegram.enabled' => true,
            'trading.telegram.bot_token' => '123:TEST',
            'trading.telegram.chat_id' => '999',
        ]);
        Watchlist::reset();
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 4242]]),
            '*' => Http::response([]),
        ]);
    }

    protected function signal(array $overrides = []): Signal
    {
        return new Signal(...array_merge([
            'symbol' => 'AVAXUSDT', 'interval' => '1h', 'side' => 'LONG', 'setup' => 'SQUEEZE_BREAKOUT', 'setupLabel' => 'Squeeze Breakout',
            'time' => time() - 60, 'entry' => 30.0, 'stopLoss' => 29.7, 'tp1' => 30.45, 'tp2' => 30.9, 'tp3' => 31.35, 'atr' => 0.25,
            'isShadow' => false, 'filters' => ['regime' => ['pass' => true, 'detail' => '4h up trend']],
            'confluences' => ['Volume 2.3x'], 'features' => ['setup' => 'SQUEEZE_BREAKOUT', 'side' => 1], 'indicators' => ['rsi' => 61.0],
            'grade' => 'B', 'aiProbability' => null,
        ], $overrides));
    }

    protected function telegramMessages(): array
    {
        return Http::recorded(fn (Request $r): bool => str_contains($r->url(), 'api.telegram.org'))->map(fn (array $pair): array => $pair[0]->data())->values()->all();
    }

    public function test_grade_b_tradable_signal_is_sent_once_and_its_message_id_stored(): void
    {
        $signal = $this->signal();
        $record = app(SignalLedger::class)->record($signal);

        $this->assertTrue(app(SignalAlerts::class)->announceSignal($signal, $record, '[PAPER] taken'));
        $this->assertFalse(app(SignalAlerts::class)->announceSignal($signal, $record->fresh(), '[PAPER] taken'), 'No duplicate alert');

        $messages = $this->telegramMessages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('LONG AVAXUSDT', $messages[0]['text']);
        $this->assertStringContainsString('Auto-trader: [PAPER] taken', $messages[0]['text']);
        $this->assertSame('4242', $record->fresh()->telegram_message_id);
        $this->assertTrue($record->fresh()->telegram_sent);
    }

    public function test_grade_c_or_filtered_signals_are_not_sent_for_normal_coins(): void
    {
        $gradeC = $this->signal(['grade' => 'C']);
        $filtered = $this->signal(['symbol' => 'APTUSDT', 'filters' => ['regime' => ['pass' => false, 'detail' => '4h trend unclear']]]);

        $this->assertFalse(app(SignalAlerts::class)->announceSignal($gradeC, app(SignalLedger::class)->record($gradeC), null));
        $this->assertFalse(app(SignalAlerts::class)->announceSignal($filtered, app(SignalLedger::class)->record($filtered), null));
        $this->assertCount(0, $this->telegramMessages());
    }

    public function test_alert_coins_send_every_signal_flagged_when_not_tradable(): void
    {
        Watchlist::add('APTUSDT');
        $filtered = $this->signal(['symbol' => 'APTUSDT', 'grade' => 'C', 'filters' => ['regime' => ['pass' => false, 'detail' => '4h trend unclear']]]);

        $this->assertTrue(app(SignalAlerts::class)->announceSignal($filtered, app(SignalLedger::class)->record($filtered), null));
        $this->assertStringContainsString('NOT tradable', $this->telegramMessages()[0]['text']);
    }

    public function test_alerts_off_switch_stops_signal_alerts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson(route('daemon.stop'))->assertOk();

        $signal = $this->signal();
        $this->assertFalse(app(SignalAlerts::class)->announceSignal($signal, app(SignalLedger::class)->record($signal), null));

        $this->actingAs($admin)->postJson(route('daemon.start'))->assertOk();
        $this->assertTrue(app(SignalAlerts::class)->announceSignal($signal, app(SignalLedger::class)->record($signal)->fresh(), null));
    }

    public function test_result_is_posted_as_a_reply_to_the_original_alert(): void
    {
        $signal = $this->signal();
        $record = app(SignalLedger::class)->record($signal);
        app(SignalAlerts::class)->announceSignal($signal, $record, null);

        $record->fresh()->update(['outcome' => 'TP1', 'r_multiple' => 1.075]);
        app(SignalAlerts::class)->announceOutcome($record->fresh());

        $reply = $this->telegramMessages()[1];
        $this->assertSame(4242, $reply['reply_parameters']['message_id']);
        $this->assertStringContainsString('TP1 hit', $reply['text']);
    }

    public function test_engine_dispatches_alerts_for_fresh_signals_after_a_scan(): void
    {
        TradingAccount::getForMode('paper')->update(['balance' => 100.0, 'peak_equity' => 100.0, 'is_running' => true]);
        $signal = $this->signal();
        $record = app(SignalLedger::class)->record($signal);

        $this->mock(MarketScanService::class, function (MockInterface $mock) use ($signal, $record): void {
            $mock->shouldReceive('isScanDue')->andReturn(true);
            $mock->shouldReceive('latestResults')->andReturn([]);
            $mock->shouldReceive('runScan')->once()->andReturn(['scanned' => true, 'message' => 'Scanned 60 symbols, 1 fresh signals.', 'fresh' => [['signal' => $signal, 'record' => $record]], 'rows' => []]);
            $mock->shouldReceive('markAutoTrade')->once();
        });

        $this->artisan('trade:engine --once')->assertSuccessful();

        $this->assertTrue(CryptoSignal::find($record->id)->telegram_sent);
        $texts = implode("\n---\n", array_column($this->telegramMessages(), 'text'));
        $this->assertStringContainsString('[PAPER] LONG AVAXUSDT</b> opened', $texts, 'The auto-trader took the signal');
        $this->assertStringContainsString('Auto-trader: [PAPER] taken', $texts, 'The signal alert reports the auto-trader decision');
        $this->assertStringContainsString('[PAPER] AVAXUSDT LONG', implode("\n", app(TradingDaemonManager::class)->getRecentLogs()), 'Every decision is written to the live log');
    }

    public function test_dispatcher_status_and_test_alert_endpoints(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->getJson(route('daemon.status'))->assertOk()
            ->assertJsonPath('stats.telegram_configured', true)
            ->assertJsonStructure(['is_running', 'sentinel_enabled', 'stats' => ['engine_state', 'alerts_sent_24h', 'watchlist_count', 'cron_command']]);

        $this->actingAs($admin)->postJson(route('daemon.test-alert'))->assertOk()->assertJsonFragment(['success' => true]);
        $this->assertStringContainsString('AFTE test alert', $this->telegramMessages()[0]['text']);
    }
}
