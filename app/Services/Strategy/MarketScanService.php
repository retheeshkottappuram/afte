<?php

namespace App\Services\Strategy;

use App\Models\CryptoSignal;
use App\Models\Setting;
use App\Models\Trade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Whole-market scanner: runs the StrategyEngine over the liquid universe once per closed
 * candle, records fresh signals and stores scanner rows for the dashboard (page loads never
 * hit Binance for scanning).
 */
class MarketScanService
{
    public const RESULTS_KEY = 'scanner_results';

    public function __construct(
        protected SymbolAnalyzer $analyzer,
        protected SignalLedger $ledger,
        protected SetupStats $stats,
        protected SignalScorer $scorer
    ) {}

    public static function barSeconds(string $interval): int
    {
        return match ($interval) {
            '15m' => 900,
            '4h' => 14400,
            default => 3600,
        };
    }

    /**
     * True when a new candle has closed since the last completed scan.
     */
    public function isScanDue(string $interval = '1h'): bool
    {
        $currentBar = intdiv(time(), self::barSeconds($interval));
        $results = (array) Setting::getValue(self::RESULTS_KEY, []);

        // Wait 30s into the new candle so short-lived kline caches cannot hold the pre-close candle.
        if (time() % self::barSeconds($interval) < 30) {
            return false;
        }

        return (int) ($results['bar'] ?? 0) !== $currentBar && ! Cache::has($this->runningKey($interval, $currentBar));
    }

    /**
     * Scan the market. Returns fresh signals (printed on the last closed candle) with their ledger rows.
     *
     * @return array{scanned: bool, message: string, fresh: array<int, array{signal: Signal, record: CryptoSignal}>, rows: array<int, array<string, mixed>>}
     */
    public function runScan(string $interval = '1h', bool $force = false): array
    {
        $currentBar = intdiv(time(), self::barSeconds($interval));

        if (! $force && ! Cache::add($this->runningKey($interval, $currentBar), true, self::barSeconds($interval))) {
            return ['scanned' => false, 'message' => 'Scan for this candle already ran.', 'fresh' => [], 'rows' => []];
        }

        $started = microtime(true);
        $universe = $this->analyzer->universe();
        $symbols = array_keys($universe);

        // Open trades (for opposite-signal exits) and the "Alert me" watchlist are always scanned.
        foreach (Trade::where('status', 'OPEN')->pluck('symbol')->merge(Watchlist::symbols())->unique() as $extraSymbol) {
            if (! in_array($extraSymbol, $symbols, true)) {
                $symbols[] = $extraSymbol;
            }
        }

        $results = $this->analyzer->analyzeMany($symbols, $interval, $universe, lookback: 3);
        $rows = [];
        $fresh = [];

        foreach ($results as $symbol => $result) {
            /** @var Signal|null $latest */
            $latest = $result['latest'] ?? null;
            $record = null;

            if ($latest !== null) {
                try {
                    $record = $this->ledger->record($latest, 'scanner');
                    $fresh[] = ['signal' => $latest, 'record' => $record];
                } catch (Throwable $e) {
                    Log::warning("[MarketScan] Could not record {$symbol} signal: {$e->getMessage()}");
                }
            }

            $rows[] = $this->row($symbol, $result, $universe[$symbol] ?? null, $latest, $record);
        }

        usort($rows, fn (array $a, array $b): int => $this->rank($b) <=> $this->rank($a));

        Setting::putValue(self::RESULTS_KEY, [
            'interval' => $interval,
            'bar' => $currentBar,
            'scanned_at' => now()->toIso8601String(),
            'duration_s' => round(microtime(true) - $started, 1),
            'universe_size' => count($universe),
            'rows' => $rows,
        ]);

        return ['scanned' => true, 'message' => sprintf('Scanned %d symbols, %d fresh signals.', count($results), count($fresh)), 'fresh' => $fresh, 'rows' => $rows];
    }

    /**
     * Update the auto-trader decision for a symbol in the stored scanner rows.
     */
    public function markAutoTrade(string $symbol, string $status): void
    {
        $results = (array) Setting::getValue(self::RESULTS_KEY, []);
        foreach ($results['rows'] ?? [] as $index => $row) {
            if ($row['symbol'] === $symbol && isset($row['signal'])) {
                $results['rows'][$index]['signal']['auto_trade'] = $status;
            }
        }
        Setting::putValue(self::RESULTS_KEY, $results);
    }

    /**
     * @return array<string, mixed>
     */
    public function latestResults(): array
    {
        return (array) Setting::getValue(self::RESULTS_KEY, []);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>|null  $universeRow
     * @return array<string, mixed>
     */
    protected function row(string $symbol, array $result, ?array $universeRow, ?Signal $latest, ?CryptoSignal $record): array
    {
        $state = (array) ($result['state'] ?? []);
        $row = [
            'symbol' => $symbol,
            'price' => $state['price'] ?? null,
            'quote_volume' => $universeRow['quote_volume'] ?? null,
            'funding_rate' => $universeRow['funding_rate'] ?? null,
            'bias' => $state['bias'] ?? 'NONE',
            'status' => $state['status'] ?? 'WAIT',
            'reason' => $state['reason'] ?? ($result['error'] ?? ''),
            'near' => $state['near'] ?? null,
            'checklist' => $state['checklist'] ?? [],
            'signal' => null,
        ];

        if ($latest !== null) {
            $stats = $this->stats->forSetup($latest->setup, null, 90);
            $row['signal'] = array_merge($latest->toArray(), [
                'id' => $record?->id,
                'ai_lift' => $this->scorer->aiLift($latest),
                'stats' => $stats,
                'stats_30d' => $this->stats->forSetup($latest->setup, null, 30),
                'auto_trade' => $record?->auto_trade_status,
            ]);
        }

        return $row;
    }

    /**
     * Sort order: tradable signals first (by expected R / grade), then shadow signals, then the watchlist.
     *
     * @param  array<string, mixed>  $row
     */
    protected function rank(array $row): float
    {
        $signal = $row['signal'];
        if ($signal !== null) {
            $gradeScore = ['A' => 3, 'B' => 2, 'C' => 1][$signal['grade']] ?? 0;

            return ($signal['tradable'] ? 1000 : 500) + $gradeScore * 10 + (float) ($signal['ai_lift'] ?? 1.0);
        }

        return $row['near'] !== null ? 100 : (float) (($row['quote_volume'] ?? 0) / 1e12);
    }

    protected function runningKey(string $interval, int $bar): string
    {
        return "market-scan:{$interval}:{$bar}";
    }
}
