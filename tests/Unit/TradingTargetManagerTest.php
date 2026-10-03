<?php

namespace Tests\Unit;

use App\Services\Trading\TradingTargetManager;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TradingTargetManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_it_defaults_to_btc_usdt(): void
    {
        $coin = TradingTargetManager::getActiveCoin();
        $this->assertEquals('BTCUSDT', $coin);
        $this->assertEquals('BTC', TradingTargetManager::getBaseCoin());
    }

    public function test_it_can_switch_active_coin_and_normalizes(): void
    {
        $switched = TradingTargetManager::setActiveCoin('sol');
        $this->assertEquals('SOLUSDT', $switched);
        $this->assertEquals('SOLUSDT', TradingTargetManager::getActiveCoin());
        $this->assertEquals('SOL', TradingTargetManager::getBaseCoin());

        $switchedDirect = TradingTargetManager::setActiveCoin('BTCUSDT');
        $this->assertEquals('BTCUSDT', $switchedDirect);
        $this->assertEquals('BTCUSDT', TradingTargetManager::getActiveCoin());
    }

    public function test_it_returns_available_coins_with_active_flag(): void
    {
        TradingTargetManager::setActiveCoin('NEARUSDT');
        $coins = TradingTargetManager::getAvailableCoins();

        $this->assertNotEmpty($coins);
        $near = collect($coins)->firstWhere('symbol', 'NEARUSDT');
        $this->assertNotNull($near);
        $this->assertTrue($near['is_active']);

        $btc = collect($coins)->firstWhere('symbol', 'BTCUSDT');
        $this->assertNotNull($btc);
        $this->assertFalse($btc['is_active']);
    }

    public function test_it_returns_monitored_timeframes(): void
    {
        $timeframes = TradingTargetManager::getMonitoredTimeframes();
        $this->assertContains('15m', $timeframes);
        $this->assertContains('1h', $timeframes);
    }

    public function test_it_returns_at_least_5_monitored_coins(): void
    {
        $monitored = TradingTargetManager::getMonitoredCoins();
        $this->assertIsArray($monitored);
        $this->assertGreaterThanOrEqual(5, count($monitored));
        $this->assertContains('BTCUSDT', $monitored);
        $this->assertContains('ETHUSDT', $monitored);
        $this->assertContains('SOLUSDT', $monitored);
    }

    public function test_it_allows_all_monitored_coins_when_single_coin_strict_is_disabled(): void
    {
        config(['trading.single_coin_strict' => false]);
        TradingTargetManager::setActiveCoin('NEARUSDT');

        $this->assertTrue(TradingTargetManager::isCoinAllowed('NEARUSDT'));
        $this->assertTrue(TradingTargetManager::isCoinAllowed('BTCUSDT'));
        $this->assertTrue(TradingTargetManager::isCoinAllowed('SOLUSDT'));
    }

    public function test_it_can_add_and_remove_monitored_coins(): void
    {
        TradingTargetManager::addMonitoredCoin('AVAXUSDT');
        $this->assertContains('AVAXUSDT', TradingTargetManager::getMonitoredCoins());

        TradingTargetManager::removeMonitoredCoin('AVAXUSDT');
        $this->assertNotContains('AVAXUSDT', TradingTargetManager::getMonitoredCoins());
        $this->assertGreaterThanOrEqual(5, count(TradingTargetManager::getMonitoredCoins()));
    }

    public function test_it_allows_any_valid_usdt_perpetual_when_not_in_strict_mode(): void
    {
        config(['trading.single_coin_strict' => false]);

        $this->assertTrue(TradingTargetManager::isCoinAllowed('DOGEUSDT'));
        $this->assertTrue(TradingTargetManager::isCoinAllowed('PEPEUSDT'));
        $this->assertTrue(TradingTargetManager::isCoinAllowed('TRUMPUSDT'));

        // Empty or blacklisted tokens
        $this->assertFalse(TradingTargetManager::isCoinAllowed(''));
        $this->assertFalse(TradingTargetManager::isCoinAllowed('GRAMUSDT'));
    }

    public function test_it_retrieves_radar_coins_and_includes_in_all_target_coins(): void
    {
        Cache::put('trading:radar_opportunities', [
            ['symbol' => 'RENDERUSDT', 'score' => 90, 'direction' => 'LONG'],
            ['symbol' => 'INJUSDT', 'score' => 88, 'direction' => 'SHORT'],
        ], now()->addMinutes(10));

        $radarCoins = TradingTargetManager::getRadarCoins();
        $this->assertContains('RENDERUSDT', $radarCoins);
        $this->assertContains('INJUSDT', $radarCoins);

        $allTargets = TradingTargetManager::getAllTargetCoins();
        $this->assertContains('RENDERUSDT', $allTargets);
        $this->assertContains('INJUSDT', $allTargets);
        $this->assertContains('BTCUSDT', $allTargets);
    }
}
