<?php

namespace App\Console\Commands;

use App\Services\AI\SignalValidator;
use App\Services\Trading\MarketEngine;
use App\Services\Trading\SignalEngine;
use Illuminate\Console\Command;

class ScanMarketCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trade:scan {--limit=15 : Number of top liquid symbols to scan}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan Binance Futures market for high-confluence breakout setups with AI confirmation';

    /**
     * Execute the console command.
     */
    public function handle(MarketEngine $marketEngine, SignalEngine $signalEngine, SignalValidator $validator): int
    {
        $this->info('🚀 Initiating Binance Futures Market Scan...');
        $symbols = $marketEngine->getScannableSymbols();
        $limit = (int) $this->option('limit');
        $scanSymbols = array_slice($symbols, 0, $limit);

        $this->line('Scanning '.count($scanSymbols).' active perpetual pairs...');

        $tableData = [];
        $opportunityCount = 0;

        $bar = $this->output->createProgressBar(count($scanSymbols));
        $bar->start();

        foreach ($scanSymbols as $sym) {
            try {
                $klines = $marketEngine->getMultiTimeframeKlines($sym);
                $eval = $signalEngine->evaluate($sym, $klines['base'], $klines['htf1'], $klines['htf2']);

                if ($eval !== null) {
                    $ai = $validator->validate($eval, $klines['base']);

                    $tableData[] = [
                        $sym,
                        $eval['direction'] === 'LONG' ? '🟢 LONG' : '🔴 SHORT',
                        '$'.$eval['price'],
                        "{$eval['score']}/100 ({$eval['grade']})",
                        $eval['indicators']['rsi'] ?? '-',
                        ($eval['indicators']['volume_ratio'] ?? '1.0').'x',
                        $ai['approved'] ? '✅ APPROVED' : '❌ REJECTED',
                        $ai['confidence'].'%',
                        $ai['regime'],
                    ];

                    if ($ai['approved']) {
                        $opportunityCount++;
                    }
                }
            } catch (\Exception $e) {
                // Ignore individual symbol network glitches during scan
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if (empty($tableData)) {
            $this->warn('No setup scored >= 80 at this moment. Bot remains patient; capital preserved.');

            return self::SUCCESS;
        }

        $this->table(
            ['Symbol', 'Direction', 'Price', 'Score', 'RSI', 'Volume', 'AI Decision', 'Confidence', 'Regime'],
            $tableData
        );

        $this->info("Scan completed: Found {$opportunityCount} AI-approved trade setups.");

        return self::SUCCESS;
    }
}
