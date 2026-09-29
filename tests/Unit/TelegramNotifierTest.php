<?php

namespace Tests\Unit;

use App\Models\Trade;
use App\Services\Notifications\TelegramNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramNotifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('trading.telegram.enabled', true);
        Config::set('trading.telegram.bot_token', '123456:FAKE_TOKEN');
        Config::set('trading.telegram.chat_id', '987654321');
        Config::set('trading.telegram.live_only', true);
    }

    public function test_telegram_does_not_send_notifications_for_paper_trades(): void
    {
        Http::fake();

        $notifier = new TelegramNotifier;

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
            'be_locked' => false,
            'tp1_hit' => false,
            'tp2_hit' => false,
            'opened_at' => now(),
        ]);

        $notifier->notifyTradeOpened($trade, 95, 'High volume breakout');
        $notifier->notifyBreakevenLocked($trade, 151.0);
        $notifier->notifyTp1Hit($trade, 0.02, 0.06);
        $notifier->notifyTp2Hit($trade, 0.01, 0.06);
        $notifier->notifyTradeClosed($trade, 5.20);

        Http::assertNothingSent();
    }

    public function test_telegram_sends_notifications_for_live_trades(): void
    {
        Http::fake();

        $notifier = new TelegramNotifier;

        $trade = Trade::create([
            'symbol' => 'SOLUSDT',
            'side' => 'LONG',
            'mode' => 'live',
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
            'be_locked' => false,
            'tp1_hit' => false,
            'tp2_hit' => false,
            'opened_at' => now(),
        ]);

        $notifier->notifyTradeOpened($trade, 95, 'High volume breakout');

        Http::assertSentCount(1);
    }
}
