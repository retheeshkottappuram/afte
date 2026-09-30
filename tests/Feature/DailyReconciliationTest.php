<?php

namespace Tests\Feature;

use App\Models\EquitySnapshot;
use App\Models\TradingAccount;
use App\Services\Binance\BinanceFuturesClient;
use App\Services\Notifications\TelegramNotifier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DailyReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_paper_mode_reconciliation_command(): void
    {
        TradingAccount::create([
            'mode' => 'paper',
            'balance' => 5.75,
            'initial_balance' => 5.0,
        ]);

        $this->artisan('trade:daily-reconcile', ['--mode' => 'paper', '--days' => 1])
            ->expectsOutputToContain('AFTE Truthful Daily Reconciliation [paper]')
            ->expectsOutputToContain('Paper trading accounting ledger verified')
            ->assertExitCode(0);
    }

    public function test_live_mode_reconciliation_passes_when_discrepancy_under_two_cents(): void
    {
        TradingAccount::create([
            'mode' => 'live',
            'balance' => 4.25,
            'initial_balance' => 5.0,
        ]);

        EquitySnapshot::create([
            'mode' => 'live',
            'balance' => 4.20,
            'equity' => 4.20,
            'created_at' => Carbon::now('UTC')->subDay()->startOfDay(),
        ]);

        $subClient = Mockery::mock(BinanceFuturesClient::class);
        $subClient->shouldReceive('hasCredentials')->andReturn(true);
        $subClient->shouldReceive('getBalance')->andReturn([
            ['asset' => 'USDT', 'balance' => '4.2500', 'crossWalletBalance' => '4.2500', 'crossUnPnl' => '0.0000'],
        ]);

        // Income records sum up to +0.0500: 4.20 + 0.05 = 4.25. Discrepancy is $0.00!
        $subClient->shouldReceive('getIncome')->andReturn([
            ['incomeType' => 'REALIZED_PNL', 'income' => '0.0600'],
            ['incomeType' => 'COMMISSION', 'income' => '-0.0100'],
        ]);

        $binanceClient = Mockery::mock(BinanceFuturesClient::class);
        $binanceClient->shouldReceive('forMode')->with('live')->andReturn($subClient);
        $this->app->instance(BinanceFuturesClient::class, $binanceClient);

        $this->artisan('trade:daily-reconcile', ['--mode' => 'live', '--days' => 1, '--threshold' => 0.02])
            ->expectsOutputToContain('Reconciliation verified')
            ->assertExitCode(0);
    }

    public function test_live_mode_reconciliation_alerts_when_discrepancy_exceeds_two_cents(): void
    {
        TradingAccount::create([
            'mode' => 'live',
            'balance' => 4.25,
            'initial_balance' => 5.0,
        ]);

        $snapshot = new EquitySnapshot([
            'mode' => 'live',
            'balance' => 5.00,
            'equity' => 5.00,
        ]);
        $snapshot->timestamps = false;
        $snapshot->created_at = Carbon::now('UTC')->subDays(2);
        $snapshot->updated_at = Carbon::now('UTC')->subDays(2);
        $snapshot->save();

        $subClient = Mockery::mock(BinanceFuturesClient::class);
        $subClient->shouldReceive('hasCredentials')->andReturn(true);
        // Actual balance is $4.25
        $subClient->shouldReceive('getBalance')->andReturn([
            ['asset' => 'USDT', 'balance' => '4.2500', 'crossWalletBalance' => '4.2500', 'crossUnPnl' => '0.0000'],
        ]);

        // Income reports only +0.05: Expected = 5.00 + 0.05 = 5.05. Actual = 4.25. Discrepancy = $0.80 > $0.02!
        $subClient->shouldReceive('getIncome')->andReturn([
            ['incomeType' => 'REALIZED_PNL', 'income' => '0.0600'],
            ['incomeType' => 'COMMISSION', 'income' => '-0.0100'],
        ]);

        $binanceClient = Mockery::mock(BinanceFuturesClient::class);
        $binanceClient->shouldReceive('forMode')->with('live')->andReturn($subClient);
        $this->app->instance(BinanceFuturesClient::class, $binanceClient);

        $notifier = Mockery::mock(TelegramNotifier::class);
        $notifier->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(function (string $msg): bool {
                return str_contains($msg, 'AFTE Daily Reconciliation Discrepancy') && str_contains($msg, '0.80');
            }));
        $this->app->instance(TelegramNotifier::class, $notifier);

        $this->artisan('trade:daily-reconcile', ['--mode' => 'live', '--days' => 1, '--threshold' => 0.02])
            ->expectsOutputToContain('Alert dispatched')
            ->assertExitCode(1);
    }
}
