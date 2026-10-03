<?php

namespace App\Console\Commands;

use App\Models\CryptoSignal;
use App\Services\Crypto\BinanceClient;
use App\Services\Notifications\SignalAlerts;
use App\Services\Strategy\MarketScanService;
use App\Services\Strategy\SetupStats;
use App\Services\Strategy\TradeSimulator;
use Illuminate\Console\Command;
use Throwable;

/**
 * Tracks what happened after every recorded signal by replaying it through the exit plan
 * on 5-minute candles. This is the source of the measured win rates shown to users.
 */
class ResolveSignalsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'crypto:resolve-signals {--limit=200 : Max open signals to check per run}';

    /**
     * @var string
     */
    protected $description = 'Resolve open strategy signals (TP1 / TP2 / SL / BE / EXPIRED) and record their R-multiple';

    public function handle(BinanceClient $market, TradeSimulator $simulator, SignalAlerts $alerts, SetupStats $stats): int
    {
        $open = CryptoSignal::query()
            ->whereNotNull('setup')
            ->where('outcome', 'OPEN')
            ->where('source', '!=', 'backtest')
            ->where('candle_close_time', '<=', now()->subMinutes(5))
            ->orderBy('candle_close_time')
            ->limit((int) $this->option('limit'))
            ->get();

        $resolved = 0;

        foreach ($open as $signal) {
            try {
                $closeTs = $signal->candle_close_time->timestamp;
                $minutes = max(10, (int) ceil((time() - $closeTs) / 60));
                $candles = $market->klines($signal->symbol, '5m', min(1000, intdiv($minutes, 5) + 3));

                $result = $simulator->run([
                    'side' => $signal->isBuy() ? 'LONG' : 'SHORT',
                    'entry' => (float) $signal->entry_price,
                    'sl' => (float) $signal->stop_loss,
                    'tp1' => (float) $signal->take_profit_1,
                    'tp2' => (float) $signal->take_profit_2,
                    'atr' => (float) ($signal->features['atr'] ?? 0),
                    'time' => $closeTs,
                ], $candles, MarketScanService::barSeconds($signal->interval));

                $signal->mfe_r = $result['mfe_r'];
                $signal->mae_r = $result['mae_r'];

                if ($result['closed']) {
                    $signal->outcome = $result['outcome'];
                    $signal->r_multiple = $result['r_multiple'];
                    $signal->resolved_at = now();
                    $resolved++;
                }

                $signal->save();

                if ($result['closed']) {
                    $alerts->announceOutcome($signal);
                }
            } catch (Throwable $e) {
                $this->warn("{$signal->symbol} #{$signal->id}: {$e->getMessage()}");
            }
        }

        if ($resolved > 0) {
            $stats->flush();
        }

        $this->info("Checked {$open->count()} open signals, resolved {$resolved}.");

        return self::SUCCESS;
    }
}
