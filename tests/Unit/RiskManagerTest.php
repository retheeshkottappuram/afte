<?php

namespace Tests\Unit;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Trading\RiskManager;
use App\Services\Trading\TradingTargetManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiskManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_compounding_stages_classification(): void
    {
        $riskManager = app(RiskManager::class);

        $seedAccount = TradingAccount::create([
            'mode' => 'paper',
            'balance' => 5.0,
            'initial_balance' => 5.0,
        ]);
        $stage1 = $riskManager->getCompoundingStage($seedAccount);
        $this->assertEquals(3, $stage1['max_positions']);
        $this->assertEquals(10, $stage1['default_leverage']);

        $seedAccount->balance = 50.0;
        $stage2 = $riskManager->getCompoundingStage($seedAccount);
        $this->assertEquals(3, $stage2['max_positions']);
        $this->assertEquals(8, $stage2['default_leverage']);

        $seedAccount->balance = 250.0;
        $stage3 = $riskManager->getCompoundingStage($seedAccount);
        $this->assertEquals(4, $stage3['max_positions']);
        $this->assertEquals(5, $stage3['default_leverage']);
    }

    public function test_binance_minimum_notional_compliance(): void
    {
        $riskManager = app(RiskManager::class);

        $account = TradingAccount::create([
            'mode' => 'paper',
            'balance' => 5.0,
            'initial_balance' => 5.0,
        ]);

        $sizing = $riskManager->calculatePositionSize($account, 'SOLUSDT', 150.0, 148.0);

        $this->assertTrue($sizing['allowed']);
        $this->assertGreaterThanOrEqual(5.0, $sizing['notional'], 'Position notional must meet Binance $5.00 minNotional');
        $this->assertLessThanOrEqual($account->balance, $sizing['margin'], 'Margin required must not exceed account balance');
        $this->assertEquals(10, $sizing['leverage']);
    }

    public function test_circuit_breaker_activates_on_consecutive_losses(): void
    {
        $riskManager = app(RiskManager::class);

        $account = TradingAccount::create([
            'mode' => 'paper',
            'balance' => 5.0,
            'initial_balance' => 5.0,
            'consecutive_losses' => 2,
            'paused_until' => Carbon::now()->addHours(2),
        ]);

        $canOpen = $riskManager->canOpenTrade($account, 'BTCUSDT', 90);
        $this->assertFalse($canOpen['allowed']);
        $this->assertStringContainsString('Circuit breaker', $canOpen['reason']);
    }

    public function test_emergency_kill_switch_halts_trading(): void
    {
        $riskManager = app(RiskManager::class);

        $account = TradingAccount::create([
            'mode' => 'paper',
            'balance' => 5.0,
            'initial_balance' => 5.0,
            'kill_switch' => true,
        ]);

        $canOpen = $riskManager->canOpenTrade($account, 'SOLUSDT', 95);
        $this->assertFalse($canOpen['allowed']);
        $this->assertStringContainsString('Kill Switch', $canOpen['reason']);
    }

    public function test_sizing_returns_amount_added_and_trade_accessor(): void
    {
        $riskManager = app(RiskManager::class);

        $account = TradingAccount::create([
            'mode' => 'paper',
            'balance' => 5.0,
            'initial_balance' => 5.0,
        ]);

        $sizing = $riskManager->calculatePositionSize($account, 'SOLUSDT', 150.0, 147.0);

        $this->assertArrayHasKey('amount_added', $sizing);
        $this->assertGreaterThan(0, $sizing['amount_added']);
        $this->assertEquals($sizing['margin'], $sizing['amount_added']);

        $trade = Trade::create([
            'symbol' => 'SOLUSDT',
            'side' => 'LONG',
            'mode' => 'paper',
            'status' => 'OPEN',
            'stage' => 'ENTRY',
            'entry_price' => 150.0,
            'quantity' => 0.04,
            'remaining_quantity' => 0.04,
            'margin_used' => 0.60,
            'leverage' => 10,
            'initial_sl' => 147.0,
            'current_sl' => 147.0,
            'tp1_price' => 153.0,
            'tp2_price' => 156.0,
            'opened_at' => now(),
        ]);

        $this->assertEquals(0.60, $trade->amount_added);
        $this->assertEquals(6.00, $trade->position_size_usd);
    }

    public function test_stage_1_excludes_heavy_coins(): void
    {
        $riskManager = app(RiskManager::class);

        $account = TradingAccount::create([
            'mode' => 'paper',
            'balance' => 5.0,
            'initial_balance' => 5.0,
            'is_running' => true,
        ]);

        $canOpenBtc = $riskManager->canOpenTrade($account, 'BTCUSDT', 95);
        $this->assertFalse($canOpenBtc['allowed']);
        $this->assertStringContainsString('excluded', $canOpenBtc['reason']);

        $canOpenEth = $riskManager->canOpenTrade($account, 'ETHUSDT', 95);
        $this->assertFalse($canOpenEth['allowed']);
        $this->assertStringContainsString('excluded', $canOpenEth['reason']);

        TradingTargetManager::setActiveCoin('SUIUSDT');
        $canOpenSui = $riskManager->canOpenTrade($account, 'SUIUSDT', 95);
        $this->assertTrue($canOpenSui['allowed']);

        // Non-target coin must be strictly blocked
        $canOpenNear = $riskManager->canOpenTrade($account, 'NEARUSDT', 95);
        $this->assertFalse($canOpenNear['allowed']);
        $this->assertStringContainsString('Trading is strictly restricted', $canOpenNear['reason']);
    }

    public function test_single_coin_utilizes_at_least_50_percent_of_available_fund(): void
    {
        config(['trading.single_coin_strict' => true]);
        config(['trading.fund_management.single_coin_fund_percent' => 50.0]);
        config(['trading.fund_management.amount_per_trade' => null]);

        $riskManager = app(RiskManager::class);

        $account = TradingAccount::create([
            'mode' => 'paper',
            'balance' => 10.0,
            'initial_balance' => 10.0,
            'is_running' => true,
        ]);

        $sizing = $riskManager->calculatePositionSize($account, 'NEARUSDT', 2.50, 2.46);

        $this->assertTrue($sizing['allowed']);
        $this->assertGreaterThanOrEqual(5.00, $sizing['margin'], 'Single-coin position must utilize at least 50% of available funds ($5.00 of $10.00)');
        $this->assertLessThanOrEqual(7.50, $sizing['margin'], 'Single-coin position margin must not exceed 75% safety cap');
        $this->assertGreaterThanOrEqual(50.0, $sizing['notional'], 'Position notional at 10x leverage must be at least $50.00');
    }

    public function test_asset_protection_stop_loss_clamping(): void
    {
        $riskManager = app(RiskManager::class);

        // 1. Long: Dangerously wide SL (5% away) clamped to max 1.60%
        $wideLongSl = $riskManager->calculateAssetProtectionStopLoss('LONG', 100.0, 95.0);
        $this->assertEquals(98.40, $wideLongSl, 'Wide Long SL must be clamped to 1.6% max distance for asset protection');

        // 2. Long: Too tight SL (0.2% away) clamped to min 0.80%
        $tightLongSl = $riskManager->calculateAssetProtectionStopLoss('LONG', 100.0, 99.80);
        $this->assertEquals(99.20, $tightLongSl, 'Tight Long SL must be clamped to 0.8% min distance');

        // 3. Long: Inverted SL (above entry price) healed to safe default (1.25%)
        $invertedLongSl = $riskManager->calculateAssetProtectionStopLoss('LONG', 100.0, 105.0);
        $this->assertEquals(98.75, $invertedLongSl, 'Inverted Long SL must be healed to default 1.25% distance');

        // 4. Short: Dangerously wide SL (5% away) clamped to max 1.60%
        $wideShortSl = $riskManager->calculateAssetProtectionStopLoss('SHORT', 100.0, 105.0);
        $this->assertEquals(101.60, $wideShortSl, 'Wide Short SL must be clamped to 1.6% max distance');

        // 5. Short: Inverted SL (below entry price) healed to safe default (1.25%)
        $invertedShortSl = $riskManager->calculateAssetProtectionStopLoss('SHORT', 100.0, 95.0);
        $this->assertEquals(101.25, $invertedShortSl, 'Inverted Short SL must be healed to default 1.25% distance');
    }
}
