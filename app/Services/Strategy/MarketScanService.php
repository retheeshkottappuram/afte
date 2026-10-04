<?php

namespace App\Services\Strategy;

use App\Models\CryptoSignal;
use App\Models\Setting;
use App\Models\Trade;
use App\Services\Crypto\CandleSanitizer;
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

    public const MANUAL_RESULTS_KEY = 'manual_scanner_results';

    public const MANUAL_STATE_KEY = 'manual_scan_state';

    public function __construct(
        protected SymbolAnalyzer $analyzer,
        protected SignalLedger $ledger,
        protected SetupStats $stats,
        protected SignalScorer $scorer,
        protected TradeSimulator $simulator,
        protected OpportunityScorer $opportunity,
        protected BreakoutWatcher $watcher
    ) {}

    /**
     * Decimal places that keep a price readable for its magnitude (e.g. 275.02, 1.2345, 0.043341).
     */
    public static function priceDecimals(float $price): int
    {
        $price = abs($price);

        return match (true) {
            $price >= 1000 => 2,
            $price >= 1 => 4,
            $price >= 0.01 => 5,
            default => 7,
        };
    }

    /**
     * On-demand whole-market scan: every crypto perpetual above a small volume floor, showing every
     * setup from the last few candles that is still in play (has not hit its stop or targets yet).
     * Read-only: it never records signals, never trades and never touches the engine's hourly scan.
     *
     * @return array{message: string, rows: array<int, array<string, mixed>>}
     */
    public function runManualScan(string $interval = '1h'): array
    {
        $started = microtime(true);
        $this->setManualState(['status' => 'RUNNING', 'started_at' => now()->toIso8601String(), 'index' => 0, 'total' => 0, 'current_symbol' => 'Loading market list...']);
        $lookbackBars = (int) config('trading.strategy.manual_scan_lookback_bars', 6);
        $universe = $this->analyzer->universe(
            (int) config('trading.strategy.manual_scan_max_symbols', 200),
            (float) config('trading.strategy.manual_scan_min_volume_24h', 5000000.0)
        );
        $symbols = array_keys($universe);

        foreach (Trade::where('status', 'OPEN')->pluck('symbol')->merge(Watchlist::symbols())->unique() as $extraSymbol) {
            if (! in_array($extraSymbol, $symbols, true)) {
                $symbols[] = $extraSymbol;
            }
        }

        $results = $this->analyzer->analyzeMany($symbols, $interval, $universe, lookback: $lookbackBars, progress: function (int $done, int $total, string $symbol): void {
            $this->setManualState(['status' => 'RUNNING', 'index' => $done, 'total' => $total, 'current_symbol' => $symbol]);
        });
        $rows = [];

        foreach ($results as $symbol => $result) {
            $active = $this->activeSignal($result, $interval);
            $row = $this->row($symbol, $result, $universe[$symbol] ?? null, $active, null);
            if ($row['signal'] !== null) {
                $row['signal']['age_minutes'] = max(0, (int) round((now()->timestamp - $active->time) / 60));
            }
            $rows[] = $row;
        }

        usort($rows, fn (array $a, array $b): int => $this->rank($b) <=> $this->rank($a));
        $rows = $this->numberRanks($rows);
        $signalCount = count(array_filter($rows, fn (array $r): bool => $r['signal'] !== null));

        Setting::putValue(self::MANUAL_RESULTS_KEY, [
            'interval' => $interval,
            'scanned_at' => now()->toIso8601String(),
            'duration_s' => round(microtime(true) - $started, 1),
            'universe_size' => count($symbols),
            'lookback_bars' => $lookbackBars,
            'min_volume' => (float) config('trading.strategy.manual_scan_min_volume_24h', 5000000.0),
            'rows' => $rows,
        ]);

        $this->setManualState(['status' => 'COMPLETED', 'index' => count($symbols), 'total' => count($symbols), 'current_symbol' => 'Done', 'finished_at' => now()->toIso8601String()]);

        return ['message' => sprintf('Scanned %d coins, %d active setups from the last %d candles.', count($symbols), $signalCount, $lookbackBars), 'rows' => $rows];
    }

    /**
     * Queue an on-demand scan for the cron-run `crypto:scan --manual` (a web request would time out).
     * Returns false when a scan is already queued or running.
     */
    public function requestManualScan(): bool
    {
        $state = $this->manualState();
        $busySince = strtotime((string) ($state['updated_at'] ?? '')) ?: 0;

        if (in_array($state['status'] ?? 'IDLE', ['QUEUED', 'RUNNING'], true) && now()->timestamp - $busySince < 300) {
            return false;
        }

        $this->setManualState(['status' => 'QUEUED', 'requested_at' => now()->toIso8601String(), 'index' => 0, 'total' => 0, 'current_symbol' => 'Waiting for the background worker (up to 1 minute)...'], replace: true);

        return true;
    }

    public function isManualScanQueued(): bool
    {
        return ($this->manualState()['status'] ?? 'IDLE') === 'QUEUED';
    }

    /**
     * @return array<string, mixed>
     */
    public function manualState(): array
    {
        return (array) Setting::getValue(self::MANUAL_STATE_KEY, ['status' => 'IDLE']);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setManualState(array $values, bool $replace = false): void
    {
        $state = $replace ? [] : $this->manualState();
        Setting::putValue(self::MANUAL_STATE_KEY, array_merge($state, $values, ['updated_at' => now()->toIso8601String()]));
    }

    /**
     * @return array<string, mixed>
     */
    public function latestManualResults(): array
    {
        return (array) Setting::getValue(self::MANUAL_RESULTS_KEY, []);
    }

    /**
     * Newest signal in the scanned window whose trade would still be open (no stop or target hit yet).
     *
     * @param  array<string, mixed>  $result
     */
    protected function activeSignal(array $result, string $interval): ?Signal
    {
        $closed = CandleSanitizer::onlyClosedCandles((array) ($result['candles'] ?? []));

        foreach (array_reverse((array) ($result['signals'] ?? [])) as $signal) {
            /** @var Signal $signal */
            $outcome = $this->simulator->run([
                'side' => $signal->side, 'entry' => $signal->entry, 'sl' => $signal->stopLoss,
                'tp1' => $signal->tp1, 'tp2' => $signal->tp2, 'atr' => $signal->atr, 'time' => $signal->time,
            ], $closed, self::barSeconds($interval));

            if (! $outcome['closed']) {
                return $signal;
            }
        }

        return null;
    }

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
        $currentBar = intdiv(now()->timestamp, self::barSeconds($interval));
        $results = (array) Setting::getValue(self::RESULTS_KEY, []);

        // Wait 30s into the new candle so short-lived kline caches cannot hold the pre-close candle.
        if (now()->timestamp % self::barSeconds($interval) < 30) {
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
        $currentBar = intdiv(now()->timestamp, self::barSeconds($interval));

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
        $watches = [];

        foreach ($results as $symbol => $result) {
            /** @var Signal|null $latest */
            $latest = $result['latest'] ?? null;
            $record = null;

            if (! empty($result['watch'])) {
                $watches[$symbol] = $result['watch'];
            }

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
        $rows = $this->numberRanks($rows);
        $this->watcher->store($interval, $watches);

        Setting::putValue(self::RESULTS_KEY, [
            'interval' => $interval,
            'bar' => $currentBar,
            'scanned_at' => now()->toIso8601String(),
            'duration_s' => round(microtime(true) - $started, 1),
            'universe_size' => count($universe),
            'symbols_scanned' => count($results),
            'fresh_signals' => count($fresh),
            'watching' => count($watches),
            'rows' => $rows,
        ]);

        return ['scanned' => true, 'message' => sprintf('Scanned %d symbols, %d fresh signals, watching %d for an intrabar breakout.', count($results), count($fresh), count($watches)), 'fresh' => $fresh, 'rows' => $rows];
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
            $opportunity = $this->opportunity->score($latest, isset($state['price']) ? (float) $state['price'] : null);
            $row['signal'] = array_merge($latest->toArray(), [
                'opportunity' => $opportunity,
                'score' => $opportunity['score'],
                'price_decimals' => self::priceDecimals($latest->entry),
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
            return ($signal['tradable'] ? 1000 : 500) + (float) ($signal['score'] ?? 0);
        }

        return $row['near'] !== null ? 100 : (float) (($row['quote_volume'] ?? 0) / 1e12);
    }

    /**
     * Give signal rows a 1..n rank in the sorted order and flag the best tradable one.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function numberRanks(array $rows): array
    {
        $rank = 0;
        $topPicked = false;
        foreach ($rows as $index => $row) {
            if ($row['signal'] === null) {
                continue;
            }
            $rows[$index]['signal']['rank'] = ++$rank;
            $isTop = ! $topPicked && $row['signal']['tradable'];
            $rows[$index]['signal']['top_pick'] = $isTop;
            $topPicked = $topPicked || $isTop;
        }

        return $rows;
    }

    /**
     * Let the current candle's scan run again after a crash (at most 3 tries per candle).
     */
    public function releaseScan(string $interval = '1h'): bool
    {
        $currentBar = intdiv(now()->timestamp, self::barSeconds($interval));
        $attempts = (int) Cache::get("market-scan-attempts:{$interval}:{$currentBar}", 0) + 1;
        Cache::put("market-scan-attempts:{$interval}:{$currentBar}", $attempts, self::barSeconds($interval));

        if ($attempts >= 3) {
            return false;
        }

        Cache::forget($this->runningKey($interval, $currentBar));

        return true;
    }

    protected function runningKey(string $interval, int $bar): string
    {
        return "market-scan:{$interval}:{$bar}";
    }
}
