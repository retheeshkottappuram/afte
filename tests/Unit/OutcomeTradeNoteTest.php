<?php

namespace Tests\Unit;

use App\Services\Notifications\SignalAlerts;
use PHPUnit\Framework\TestCase;

class OutcomeTradeNoteTest extends TestCase
{
    public function test_outcome_reply_says_whether_the_bot_traded_the_signal(): void
    {
        $this->assertStringContainsString('The bot traded this signal', SignalAlerts::outcomeTradeNote('[LIVE] taken'));
        $this->assertSame(
            '📊 Signal result only: the bot did not trade it (Max open positions reached (1).).',
            SignalAlerts::outcomeTradeNote('[LIVE] skipped: Max open positions reached (1).')
        );
        $this->assertStringContainsString('not an auto-trade candidate', SignalAlerts::outcomeTradeNote(null));
    }
}
