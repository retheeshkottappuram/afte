<?php

namespace Tests\Feature;

use App\Models\CryptoSignal;
use App\Services\Trading\TradingDaemonManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EngineDecisionSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function signal(string $status, string $source = 'scanner', int $hoursAgo = 1): void
    {
        static $i = 0;
        $i++;

        CryptoSignal::create([
            'symbol' => "COIN{$i}USDT", 'interval' => '1h', 'side' => 'BUY', 'setup' => 'SQUEEZE_BREAKOUT', 'setup_type' => 'SQUEEZE_BREAKOUT', 'passed_filters' => true,
            'entry_price' => 1, 'stop_loss' => 0.98, 'take_profit_1' => 1.03, 'take_profit_2' => 1.06, 'take_profit_3' => 1.09,
            'candle_close_time' => now()->subHours($hoursAgo), 'source' => $source, 'sent_at' => now()->subHours($hoursAgo), 'outcome' => 'OPEN',
            'auto_trade_status' => $status,
        ]);
    }

    public function test_summary_counts_taken_and_groups_skip_reasons(): void
    {
        $this->signal('[LIVE] taken');
        $this->signal('[LIVE] skipped: Max open positions reached (1).');
        $this->signal('[LIVE] skipped: Max open positions reached (2).');
        $this->signal('[LIVE] skipped: Grade C is below the auto-trade threshold.');
        $this->signal('[LIVE] skipped: Max open positions reached (1).', 'backtest');
        $this->signal('[LIVE] taken', 'scanner', 30);

        $summary = app(TradingDaemonManager::class)->decisionSummary();

        $this->assertSame(4, $summary['signals']);
        $this->assertSame(1, $summary['taken']);
        $this->assertSame('Max open positions reached', $summary['top_reason']);
        $this->assertSame(2, $summary['top_reason_count']);
    }

    public function test_summary_is_part_of_engine_status(): void
    {
        $this->assertArrayHasKey('decisions_24h', app(TradingDaemonManager::class)->status('paper'));
    }
}
