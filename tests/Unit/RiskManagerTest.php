<?php

namespace Tests\Unit;

use App\Models\TradingAccount;
use App\Services\Trading\RiskManager;
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
        $this->assertEquals(1, $stage1['max_positions']);
        $this->assertEquals(10, $stage1['default_leverage']);

        $seedAccount->balance = 50.0;
        $stage2 = $riskManager->getCompoundingStage($seedAccount);
        $this->assertEquals(2, $stage2['max_positions']);
        $this->assertEquals(7, $stage2['default_leverage']);

        $seedAccount->balance = 250.0;
        $stage3 = $riskManager->getCompoundingStage($seedAccount);
        $this->assertEquals(3, $stage3['max_positions']);
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
}
