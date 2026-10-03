<?php

namespace Tests\Unit;

use App\Models\CryptoSignal;
use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Strategy\Signal;
use App\Services\Strategy\SignalLedger;
use App\Services\Trading\SignalAlgoTrader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SignalAlgoTraderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TradingAccount::create(['mode' => 'paper', 'balance' => 100.0, 'initial_balance' => 100.0, 'equity' => 100.0, 'peak_equity' => 100.0, 'is_running' => true]);
    }

    protected function signal(array $overrides = []): Signal
    {
        $args = array_merge([
            'symbol' => 'SOLUSDT', 'interval' => '1h', 'side' => 'LONG', 'setup' => 'TREND_PULLBACK', 'setupLabel' => 'Trend Pullback',
            'time' => time() - 120, 'entry' => 150.0, 'stopLoss' => 148.5, 'tp1' => 152.25, 'tp2' => 154.5, 'tp3' => 156.75, 'atr' => 1.2,
            'isShadow' => false,
            'filters' => ['regime' => ['pass' => true, 'detail' => '4h up trend'], 'btc_macro' => ['pass' => true, 'detail' => 'BTC firm']],
            'confluences' => ['Volume 2.1x'], 'features' => ['setup' => 'TREND_PULLBACK', 'side' => 1], 'indicators' => ['rsi' => 55.0, 'regime' => 'LONG'],
            'grade' => 'B', 'aiProbability' => null,
        ], $overrides);

        return new Signal(...$args);
    }

    /**
     * @return array<int, array{signal: Signal, record: CryptoSignal}>
     */
    protected function fresh(Signal $signal): array
    {
        return [['signal' => $signal, 'record' => app(SignalLedger::class)->record($signal)]];
    }

    public function test_approved_signal_opens_a_paper_trade_and_records_the_decision(): void
    {
        Http::fake();
        $fresh = $this->fresh($this->signal());

        $decisions = app(SignalAlgoTrader::class)->processFreshSignals($fresh, 'paper', entriesAllowed: true);

        $this->assertSame('taken', $decisions[0]['status'], $decisions[0]['message']);
        $this->assertSame(1, Trade::where('mode', 'paper')->where('status', 'OPEN')->count());
        $this->assertSame('[PAPER] taken', $fresh[0]['record']->fresh()->auto_trade_status);
    }

    public function test_signal_failing_a_filter_is_skipped_with_the_reason(): void
    {
        Http::fake();
        $signal = $this->signal(['filters' => ['regime' => ['pass' => false, 'detail' => '4h down trend']]]);

        $decisions = app(SignalAlgoTrader::class)->processFreshSignals($this->fresh($signal), 'paper', true);

        $this->assertSame('skipped', $decisions[0]['status']);
        $this->assertStringContainsString('4h down trend', $decisions[0]['message']);
        $this->assertSame(0, Trade::count());
    }

    public function test_shadow_setups_are_tracked_but_not_traded(): void
    {
        Http::fake();
        $signal = $this->signal(['setup' => 'EMA_CROSS', 'setupLabel' => 'EMA 9/21 Cross', 'isShadow' => true]);

        $decision = app(SignalAlgoTrader::class)->handleSignal($signal, 'paper', true);

        $this->assertSame('skipped', $decision['status']);
        $this->assertStringContainsString('Shadow setup', $decision['message']);
    }

    public function test_no_entries_while_auto_trading_is_stopped(): void
    {
        Http::fake();

        $decision = app(SignalAlgoTrader::class)->handleSignal($this->signal(), 'paper', entriesAllowed: false);

        $this->assertSame('skipped', $decision['status']);
        $this->assertSame(0, Trade::count());
    }

    public function test_the_same_signal_is_only_acted_on_once(): void
    {
        Http::fake();
        $trader = app(SignalAlgoTrader::class);

        $first = $trader->handleSignal($this->signal(), 'paper', true);
        $second = $trader->handleSignal($this->signal(), 'paper', true);

        $this->assertSame('taken', $first['status']);
        $this->assertSame('duplicate', $second['status']);
        $this->assertSame(1, Trade::count());
    }

    public function test_opposite_signal_closes_the_open_trade_without_flipping(): void
    {
        Http::fake();
        $trader = app(SignalAlgoTrader::class);
        $trader->handleSignal($this->signal(), 'paper', true);

        $short = $this->signal(['side' => 'SHORT', 'stopLoss' => 151.5, 'tp1' => 147.75, 'tp2' => 145.5, 'tp3' => 143.25, 'time' => time() - 60, 'indicators' => ['regime' => 'SHORT']]);
        $decision = $trader->handleSignal($short, 'paper', true);

        $this->assertSame('exited', $decision['status']);
        $this->assertSame(0, Trade::where('status', 'OPEN')->count());
        $this->assertSame('OPPOSITE_SIGNAL', Trade::first()->exit_reason);
    }

    public function test_entry_is_skipped_when_price_already_ran_away(): void
    {
        Http::fake(['*/ticker/price*' => Http::response(['symbol' => 'SOLUSDT', 'price' => '151.20'])]);

        $decision = app(SignalAlgoTrader::class)->handleSignal($this->signal(), 'paper', true);

        $this->assertSame('skipped', $decision['status']);
        $this->assertStringContainsString('Entry missed', $decision['message']);
    }

    public function test_live_mode_only_trades_proven_setups(): void
    {
        Http::fake();
        config(['trading.allow_live_trading' => true]);
        TradingAccount::create(['mode' => 'live', 'balance' => 100.0, 'initial_balance' => 100.0, 'peak_equity' => 100.0, 'is_running' => true]);

        $decision = app(SignalAlgoTrader::class)->handleSignal($this->signal(), 'live', true);

        $this->assertSame('skipped', $decision['status']);
        $this->assertStringContainsString('not yet proven', $decision['message']);
    }
}
