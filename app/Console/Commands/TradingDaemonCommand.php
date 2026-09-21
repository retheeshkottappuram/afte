<?php

namespace App\Console\Commands;

use App\Models\EquitySnapshot;
use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\AI\SignalValidator;
use App\Services\Trading\DynamicTradeManager;
use App\Services\Trading\MarketEngine;
use App\Services\Trading\OrderExecutor;
use App\Services\Trading\SignalEngine;
use Illuminate\Console\Command;

class TradingDaemonCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trade:daemon
                            {--mode= : Override mode (paper, testnet, live)}
                            {--interval=4 : Seconds between position management cycles}
                            {--scan-interval=45 : Seconds between market scanner cycles}
                            {--start : Activate auto-trading state}
                            {--once : Run a single loop iteration and exit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Autonomous trading daemon: continuous position management & breakout scanner';

    /**
     * Execute the console command.
     */
    public function handle(
        MarketEngine $marketEngine,
        SignalEngine $signalEngine,
        SignalValidator $validator,
        DynamicTradeManager $tradeManager,
        OrderExecutor $executor
    ): int {
        $mode = (string) ($this->option('mode') ?: config('trading.mode', 'paper'));
        $interval = max(1, (int) $this->option('interval'));
        $scanInterval = max(10, (int) $this->option('scan-interval'));
        $runOnce = (bool) $this->option('once');

        $account = TradingAccount::getForMode($mode);

        if ($this->option('start')) {
            $account->update(['is_running' => true]);
            $account->refresh();
        }

        $statusStr = $account->is_running ? 'ACTIVE' : 'PAUSED';
        $this->info('🤖 AFTE Autonomous Daemon started in ['.strtoupper($mode)."] mode. Status: [{$statusStr}]");
        $this->line("Seed Capital: \${$account->initial_balance} | Current Balance: \${$account->balance} | Target: \$500.00");
        $this->line("Management loop: {$interval}s | Scan loop: {$scanInterval}s. Press Ctrl+C to stop.");

        $lastScanTime = 0;
        $lastSnapshotTime = 0;

        while (true) {
            $account->refresh();

            if ($account->kill_switch) {
                $this->error('Emergency Kill Switch is ACTIVE. Daemon pausing execution.');
                if ($runOnce) {
                    break;
                }
                sleep(10);

                continue;
            }

            // 1. High-Frequency Active Position Management Loop
            $openTrades = Trade::where('mode', $mode)
                ->where('status', 'OPEN')
                ->get();

            foreach ($openTrades as $trade) {
                $result = $tradeManager->manageTrade($trade);
                if ($result['status'] === 'closed') {
                    $this->warn("Trade Closed: {$result['message']}");
                }
            }

            // 2. Scheduled Market Scanner Cycle
            $now = time();
            if ($now - $lastScanTime >= $scanInterval) {
                $lastScanTime = $now;

                if ($account->canTrade()) {
                    $this->line('['.date('H:i:s').'] Scanning markets for high-conviction breakout setups...');
                    $symbols = $marketEngine->getScannableSymbols();

                    foreach (array_slice($symbols, 0, 15) as $sym) {
                        try {
                            $klines = $marketEngine->getMultiTimeframeKlines($sym);
                            $eval = $signalEngine->evaluate($sym, $klines['base'], $klines['htf1'], $klines['htf2']);

                            if ($eval !== null && $eval['score'] >= 82) {
                                $ai = $validator->validate($eval, $klines['base']);

                                if ($ai['approved']) {
                                    $this->info("⚡ Qualified Setup Found on {$sym} ({$eval['direction']}) - Score: {$eval['score']}/100");
                                    $execResult = $executor->executeSignal($eval, $ai, $mode);

                                    if ($execResult['status'] === 'opened') {
                                        $this->info("✅ Order Executed: {$execResult['message']}");
                                        break; // In Stage 1 ($5-$25), 1 position at a time is enforced
                                    } else {
                                        $this->line("Execution Notice: {$execResult['message']}");
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            // Log and continue scanning
                        }
                    }
                } else {
                    if (! $account->is_running) {
                        $this->line('['.date('H:i:s').'] Auto-trading is PAUSED via dashboard. Waiting for start command...');
                    } elseif ($account->paused_until !== null && $account->paused_until->isFuture()) {
                        $this->warn('['.date('H:i:s')."] Circuit breaker cooldown active until {$account->paused_until->format('H:i:s')}");
                    }
                }
            }

            // 3. Periodic Equity Snapshot (every 5 minutes)
            if ($now - $lastSnapshotTime >= 300) {
                $lastSnapshotTime = $now;
                EquitySnapshot::create([
                    'mode' => $mode,
                    'balance' => $account->balance,
                    'equity' => $account->balance,
                    'open_positions' => $openTrades->count(),
                ]);
            }

            if ($runOnce) {
                break;
            }

            sleep($interval);
        }

        return self::SUCCESS;
    }
}
