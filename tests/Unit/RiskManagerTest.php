<?php

namespace Tests\Unit;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Trading\RiskManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RiskManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(); // exchange info falls back to the built-in lot sizes
    }

    protected function account(float $balance, array $overrides = []): TradingAccount
    {
        return TradingAccount::create(array_merge([
            'mode' => 'paper',
            'balance' => $balance,
            'initial_balance' => $balance,
            'equity' => $balance,
            'peak_equity' => $balance,
            'is_running' => true,
        ], $overrides));
    }

    protected function openTrade(string $symbol, string $side, float $margin = 1.0): Trade
    {
        return Trade::create([
            'symbol' => $symbol, 'side' => $side, 'mode' => 'paper', 'status' => 'OPEN', 'stage' => 'ENTRY',
            'entry_price' => 100.0, 'quantity' => 0.1, 'remaining_quantity' => 0.1, 'margin_used' => $margin, 'leverage' => 5,
            'initial_sl' => 99.0, 'current_sl' => 99.0, 'tp1_price' => 101.5, 'tp2_price' => 103.0, 'opened_at' => now(),
        ]);
    }

    public function test_max_positions_scale_with_equity(): void
    {
        $risk = app(RiskManager::class);

        $this->assertSame(1, $risk->maxPositions(5.0));
        $this->assertSame(2, $risk->maxPositions(50.0));
        $this->assertSame(3, $risk->maxPositions(500.0));
    }

    public function test_position_is_sized_to_two_percent_risk_at_the_stop(): void
    {
        $sizing = app(RiskManager::class)->calculatePositionSize($this->account(100.0), 'SOLUSDT', 150.0, 148.5);

        $this->assertTrue($sizing['allowed']);
        $this->assertEqualsWithDelta(1.33, $sizing['quantity'], 1e-9);
        $this->assertEqualsWithDelta(1.995, $sizing['risk_usd'], 1e-9);
        $this->assertSame(5, $sizing['leverage']);
        $this->assertLessThanOrEqual(2.0, $sizing['risk_pct']);
    }

    public function test_five_dollar_account_can_trade_within_the_risk_limit(): void
    {
        $sizing = app(RiskManager::class)->calculatePositionSize($this->account(5.0), 'SOLUSDT', 150.0, 148.5);

        $this->assertTrue($sizing['allowed']);
        $this->assertGreaterThanOrEqual(5.0, $sizing['notional'], 'Must meet the Binance minimum order');
        $this->assertLessThanOrEqual(3.0, $sizing['risk_pct']);
        $this->assertLessThan(5.0, $sizing['margin']);
    }

    public function test_small_account_may_risk_up_to_five_percent_for_a_wide_breakout_stop(): void
    {
        // $3.95 balance, 2.5% stop: the $5 minimum order risks ~3.8%, inside the 5% small-account cap.
        $allowed = app(RiskManager::class)->calculatePositionSize($this->account(3.95), 'SOLUSDT', 150.0, 146.25);
        $this->assertTrue($allowed['allowed']);
        $this->assertGreaterThan(3.0, $allowed['risk_pct']);
        $this->assertLessThanOrEqual(5.0, $allowed['risk_pct']);

        // 4% stop: the minimum order would risk ~6%, above the cap.
        $this->assertFalse(app(RiskManager::class)->calculatePositionSize($this->account(3.95, ['mode' => 'live']), 'SOLUSDT', 150.0, 144.0)['allowed']);
    }

    public function test_rejects_when_the_minimum_order_would_risk_too_much(): void
    {
        $sizing = app(RiskManager::class)->calculatePositionSize($this->account(5.0), 'BTCUSDT', 60000.0, 59400.0);

        $this->assertFalse($sizing['allowed']);
        $this->assertStringContainsString('Stop too wide for this account size', $sizing['reason']);
    }

    public function test_one_position_at_a_time_below_twenty_five_dollars(): void
    {
        $account = $this->account(5.0);
        $this->openTrade('ETHUSDT', 'LONG', 0.5);

        $result = app(RiskManager::class)->canOpenTrade($account, 'SOLUSDT', 'LONG');

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('Max open positions', $result['reason']);
    }

    public function test_correlation_limit_on_same_side_positions(): void
    {
        $account = $this->account(500.0);
        $this->openTrade('ETHUSDT', 'LONG');
        $this->openTrade('SOLUSDT', 'LONG');

        $risk = app(RiskManager::class);
        $this->assertFalse($risk->canOpenTrade($account, 'XRPUSDT', 'LONG')['allowed']);
        $this->assertTrue($risk->canOpenTrade($account, 'XRPUSDT', 'SHORT')['allowed']);
    }

    public function test_daily_loss_limit_pauses_entries_until_next_utc_day(): void
    {
        $account = $this->account(93.0, ['day_start_equity' => 100.0, 'day_start_date' => Carbon::now('UTC')->startOfDay()]);

        $result = app(RiskManager::class)->canOpenTrade($account, 'SOLUSDT', 'LONG');
        $account->refresh();

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('daily loss limit', $result['reason']);
        $this->assertTrue($account->paused_until->equalTo(Carbon::now('UTC')->addDay()->startOfDay()));
    }

    public function test_three_losses_in_a_row_pause_entries_for_twelve_hours(): void
    {
        config(['trading.circuit_breakers.max_consecutive_losses' => 3, 'trading.circuit_breakers.loss_cooldown_minutes' => 720]);
        $account = $this->account(100.0);
        $risk = app(RiskManager::class);

        foreach ([1, 2, 3] as $n) {
            $trade = $this->openTrade("COIN{$n}USDT", 'LONG');
            $trade->update(['status' => 'CLOSED', 'realized_pnl' => -0.5]);
            $risk->handleTradeClosed($account, $trade);
        }

        $account->refresh();
        $this->assertSame(3, $account->consecutive_losses);
        $this->assertNotNull($account->paused_until);
        $this->assertEqualsWithDelta(12 * 60, now()->diffInMinutes($account->paused_until), 1);
        $this->assertFalse($risk->canOpenTrade($account, 'SOLUSDT', 'LONG')['allowed']);
    }

    public function test_drawdown_from_peak_engages_the_kill_switch(): void
    {
        $account = $this->account(69.0, ['peak_equity' => 100.0]);

        $result = app(RiskManager::class)->canOpenTrade($account, 'SOLUSDT', 'LONG');

        $this->assertFalse($result['allowed']);
        $this->assertTrue($account->refresh()->kill_switch);
    }

    public function test_kill_switch_and_stopped_engine_block_auto_entries(): void
    {
        $risk = app(RiskManager::class);

        $this->assertFalse($risk->canOpenTrade($this->account(50.0, ['kill_switch' => true]), 'SOLUSDT', 'LONG')['allowed']);

        $stopped = TradingAccount::create(['mode' => 'live', 'balance' => 50.0, 'initial_balance' => 50.0, 'peak_equity' => 50.0, 'is_running' => false]);
        config(['trading.allow_live_trading' => true]);
        $this->assertFalse($risk->canOpenTrade($stopped, 'SOLUSDT', 'LONG')['allowed']);
        $this->assertTrue($risk->canOpenTrade($stopped, 'SOLUSDT', 'LONG', isManual: true)['allowed'], 'Manual trades do not need auto-trading to be running');
    }

    public function test_fallback_stop_is_clamped_into_the_allowed_band(): void
    {
        $risk = app(RiskManager::class);

        $this->assertEqualsWithDelta(98.2, $risk->calculateAssetProtectionStopLoss('LONG', 100.0, 97.0), 1e-9);
        $this->assertEqualsWithDelta(99.4, $risk->calculateAssetProtectionStopLoss('LONG', 100.0, 99.8), 1e-9);
        $this->assertEqualsWithDelta(101.8, $risk->calculateAssetProtectionStopLoss('SHORT', 100.0, 104.0), 1e-9);
        $this->assertEqualsWithDelta(98.75, $risk->calculateAssetProtectionStopLoss('LONG', 100.0, 101.0), 1e-9, 'Wrong-side stop falls back to the default distance');
    }
}
