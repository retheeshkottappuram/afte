<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Strategy\MarketScanService;
use App\Services\Strategy\OpportunityScorer;
use App\Services\Strategy\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScanResultCardsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A stored scanner row, shaped like MarketScanService::row() output.
     *
     * @return array<string, mixed>
     */
    protected function row(string $symbol, bool $tradable, float $entry): array
    {
        $signal = new Signal(
            symbol: $symbol, interval: '1h', side: 'LONG', setup: 'SQUEEZE_BREAKOUT', setupLabel: 'Squeeze Breakout',
            time: now()->timestamp - 1800, entry: $entry, stopLoss: $entry * 0.99, tp1: $entry * 1.015, tp2: $entry * 1.03, tp3: $entry * 1.045, atr: $entry * 0.008,
            isShadow: false, filters: ['regime' => ['pass' => $tradable, 'detail' => $tradable ? '4h up trend' : '4h trend unclear']],
            confluences: ['Volume 2.1x'], features: ['setup' => 'SQUEEZE_BREAKOUT', 'side' => 1], indicators: ['rsi' => 61.2, 'adx' => 27.0, 'volume_ratio' => 2.1, 'atr_pct' => 0.8],
            grade: 'B', aiProbability: null,
        );
        $opportunity = app(OpportunityScorer::class)->score($signal, $entry);

        return [
            'symbol' => $symbol, 'price' => $entry, 'bias' => 'LONG', 'near' => null,
            'signal' => array_merge($signal->toArray(), [
                'opportunity' => $opportunity, 'score' => $opportunity['score'], 'price_decimals' => MarketScanService::priceDecimals($entry),
                'stats' => ['n' => 0, 'win_rate' => null, 'expectancy' => null, 'source' => 'live'], 'auto_trade' => null, 'age_minutes' => 30,
            ]),
        ];
    }

    public function test_status_returns_ranked_readable_cards_with_live_entry_status(): void
    {
        Http::fake(['*ticker/24hr*' => Http::response([
            ['symbol' => 'QNTUSDT', 'lastPrice' => '275.10'],
            ['symbol' => 'AINUSDT', 'lastPrice' => '0.04339'],
        ]), '*' => Http::response([])]);

        Setting::putValue(MarketScanService::MANUAL_RESULTS_KEY, [
            'scanned_at' => now()->toIso8601String(), 'interval' => '1h', 'rows' => [
                $this->row('AINUSDT', false, 0.0433412345678),
                $this->row('QNTUSDT', true, 275.0212345678),
            ],
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $cards = $this->actingAs($admin)->getJson(route('market-scan.status'))->assertOk()->json('signals');

        $this->assertSame('QNTUSDT', $cards[0]['symbol'], 'Tradable signals rank first');
        $this->assertSame(1, $cards[0]['rank']);
        $this->assertTrue($cards[0]['top_pick']);
        $this->assertSame('Enter now', $cards[0]['entry_status']);
        $this->assertSame(275.0212, $cards[0]['entry'], 'Prices are rounded for their magnitude');
        $this->assertSame(275.1, $cards[0]['now_price']);
        $this->assertIsInt($cards[0]['score']);
        $this->assertArrayHasKey('entry', $cards[0]['score_breakdown']);

        $this->assertSame(2, $cards[1]['rank']);
        $this->assertFalse($cards[1]['top_pick']);
        $this->assertSame(0.04334, $cards[1]['entry']);
        $this->assertSame(['4h trend unclear'], $cards[1]['failed_filters']);
    }
}
