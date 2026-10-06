<?php

namespace Tests\Unit;

use App\Console\Commands\DiagnoseTradingCommand;
use PHPUnit\Framework\TestCase;

class DiagnoseDecisionTest extends TestCase
{
    public function test_decisions_are_grouped_without_numbers_and_mode_tags(): void
    {
        $this->assertSame('TAKEN', DiagnoseTradingCommand::decision('[LIVE] taken', true));
        $this->assertSame('skipped: Max open positions reached', DiagnoseTradingCommand::decision('[LIVE] skipped: Max open positions reached (1).', true));
        $this->assertSame('skipped: Filters failed', DiagnoseTradingCommand::decision('[PAPER] skipped: Filters failed: ATR too low', true));
        $this->assertSame('(filters failed: never an entry)', DiagnoseTradingCommand::decision(null, false));
        $this->assertStringContainsString('not handled by the engine', DiagnoseTradingCommand::decision(null, true));
    }
}
