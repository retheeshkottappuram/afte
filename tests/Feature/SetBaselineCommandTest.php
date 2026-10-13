<?php

namespace Tests\Feature;

use App\Models\TradingAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetBaselineCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sets_the_starting_balance_and_resets_the_peak(): void
    {
        TradingAccount::getForMode('live')->update(['initial_balance' => 5.0, 'balance' => 24.3, 'equity' => 24.3, 'peak_equity' => 6.1]);

        $this->artisan('trade:set-baseline', ['amount' => 24, '--mode' => 'live'])->assertSuccessful();

        $account = TradingAccount::getForMode('live')->fresh();
        $this->assertSame(24.0, (float) $account->initial_balance);
        $this->assertSame(24.3, (float) $account->peak_equity);
    }

    public function test_it_rejects_a_bad_amount_or_mode(): void
    {
        $this->artisan('trade:set-baseline', ['amount' => 0])->assertFailed();
        $this->artisan('trade:set-baseline', ['amount' => 24, '--mode' => 'demo'])->assertFailed();
    }

    public function test_progress_and_milestones_start_from_the_account_baseline(): void
    {
        $account = TradingAccount::getForMode('live');
        $account->update(['initial_balance' => 24.0, 'balance' => 37.0, 'equity' => 37.0]);

        $this->assertSame(['from' => 24.0, 'to' => 50.0, 'pct' => 50.0], $account->milestone());
        $this->assertSame(['from' => 100.0, 'to' => 250.0, 'pct' => 20.0], $account->milestone(130.0));
        $this->assertSame(['from' => 250.0, 'to' => 500.0, 'pct' => 100.0], $account->milestone(600.0));
        $this->assertEqualsWithDelta(13 / 476 * 100, $account->target_progress, 0.01);
    }
}
