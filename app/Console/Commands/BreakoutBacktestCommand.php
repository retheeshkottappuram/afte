<?php

namespace App\Console\Commands;

use App\Services\Breakout\BreakoutBacktester;
use App\Services\Breakout\HistoryStore;
use App\Services\Strategy\SymbolAnalyzer;
use Illuminate\Console\Command;
use Throwable;

/**
 * Historical replay of EBD v1 (no look-ahead, costs included), reported per variant and split into
 * chronological train / validation / out-of-sample periods. Downloads are sequential and throttled.
 */
class BreakoutBacktestCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'breakout:backtest
        {symbols?* : Symbols (default: today\'s top N by 24h volume)}
        {--months=12 : History length}
        {--top=50 : Universe size when no symbols are given}
        {--risk=1 : Risk per trade in % for the $ balance simulation}
        {--balance=24 : Starting balance for the $ simulation}
        {--min-net-rr= : Override breakout.risk.min_net_rr (e.g. -99 to see what the cost gate filters out)}
        {--json= : Also write the full report to this path}';

    /**
     * @var string
     */
    protected $description = 'Backtest the EBD v1 breakout engine on Binance 5m/15m/1h history (sequential downloads, disk cache)';

    public function handle(SymbolAnalyzer $analyzer): int
    {
        @ini_set('memory_limit', '1536M');
        @set_time_limit(0);
        if ($this->option('min-net-rr') !== null) {
            config(['breakout.risk.min_net_rr' => (float) $this->option('min-net-rr')]);
        }
        $tester = app(BreakoutBacktester::class);

        $symbols = array_map('strtoupper', (array) $this->argument('symbols'));
        if ($symbols === []) {
            $symbols = array_keys($analyzer->universe((int) $this->option('top'), (float) config('breakout.universe.min_quote_volume_24h')));
        }
        if ($symbols === []) {
            $this->error('No symbols (could not load the futures universe).');

            return self::FAILURE;
        }

        $months = (int) $this->option('months');
        $endMs = intdiv(time(), 86400) * 86400 * 1000 - 1;
        $fromMs = $endMs + 1 - $months * 30 * 86_400_000;
        $warmMs = 3 * 86_400_000;
        $store = (new HistoryStore)->onLog(fn (string $l) => $this->line($l));
        $this->info(sprintf('EBD v1 backtest: %d symbols, %s to %s UTC (5m, with 15m/1h context)', count($symbols), gmdate('Y-m-d', intdiv($fromMs, 1000)), gmdate('Y-m-d', intdiv($endMs, 1000))));

        $trades = [];
        $setups = [];
        $ambiguous = 0;
        $tickRejects = [];
        $errors = [];
        foreach ($symbols as $n => $symbol) {
            try {
                $t0 = microtime(true);
                $c5 = $store->candles($symbol, '5m', $fromMs - $warmMs, $endMs);
                $c15 = $store->candles($symbol, '15m', $fromMs - 10 * 86_400_000, $endMs);
                $c1h = $store->candles($symbol, '1h', $fromMs - 10 * 86_400_000, $endMs);
                $funding = $store->funding($symbol, $fromMs - 86_400_000, $endMs);
                if ($c5->count() < 500) {
                    $errors[$symbol] = 'not enough history';

                    continue;
                }
                $result = $tester->runSymbol($symbol, $c5, $c15, $c1h, $funding, $fromMs, $endMs);
                array_push($trades, ...$result['trades']);
                array_push($setups, ...$result['setups']);
                $ambiguous += $result['ambiguous'];
                foreach ($result['tick_rejects'] as $why => $count) {
                    $tickRejects[$why] = ($tickRejects[$why] ?? 0) + $count;
                }
                $this->line(sprintf('  [%d/%d] %s: %d candles, %d setups, %d trades (%.0fs)', $n + 1, count($symbols), $symbol, $c5->count(), count($result['setups']), count($result['trades']), microtime(true) - $t0));
                unset($c5, $c15, $c1h, $result);
            } catch (Throwable $e) {
                $errors[$symbol] = $e->getMessage();
                $this->warn("  {$symbol}: ".$e->getMessage());
                if (str_contains($e->getMessage(), 'rate limit')) {
                    break;
                }
            }
        }

        $report = $this->report($trades, $setups, $fromMs, $endMs, (float) $this->option('risk') / 100, (float) $this->option('balance'));
        $report['ambiguous_candles'] = $ambiguous;
        arsort($tickRejects);
        $report['early_tick_rejects'] = array_slice($tickRejects, 0, 12, true);
        arsort($tester->skips);
        $report['signals_not_traded'] = $tester->skips;
        $report['errors'] = $errors;
        $report['symbols'] = $symbols;
        $report['config'] = config('breakout');
        $this->render($report);

        $path = $this->option('json') ?: storage_path('app/ebd-backtest/report-'.gmdate('Ymd-His').'.json');
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT));
        $this->info("Full report: {$path}");

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    protected function report(array $trades, array $setups, int $fromMs, int $endMs, float $risk, float $balance): array
    {
        $span = $endMs - $fromMs;
        $periods = [
            'train (first 50%)' => [$fromMs, $fromMs + (int) ($span * 0.5)],
            'validation (next 25%)' => [$fromMs + (int) ($span * 0.5), $fromMs + (int) ($span * 0.75)],
            'out-of-sample (last 25%)' => [$fromMs + (int) ($span * 0.75), $endMs + 1],
        ];
        $sum = fn (array $t): array => BreakoutBacktester::summarize($t, $risk, $balance);
        $by = function (array $list, callable $key) use ($sum): array {
            $groups = [];
            foreach ($list as $t) {
                $groups[(string) $key($t)][] = $t;
            }
            ksort($groups);

            return array_map($sum, $groups);
        };

        $variants = [];
        foreach (['early', 'confirmed', 'retest'] as $v) {
            $list = array_values(array_filter($trades, fn (array $t): bool => $t['variant'] === $v));
            $variants[$v] = [
                'all' => $sum($list),
                'periods' => array_map(fn (array $p): array => $sum(array_values(array_filter($list, fn (array $t): bool => $t['entry_time'] >= $p[0] && $t['entry_time'] < $p[1]))), $periods),
                'by_bucket' => $by($list, fn (array $t): string => $t['bucket']),
                'by_direction' => $by($list, fn (array $t): string => $t['direction']),
                'by_h1' => $by($list, fn (array $t): string => $t['h1_aligned'] ? 'with 1h trend' : 'no 1h alignment'),
                'by_symbol' => $by($list, fn (array $t): string => $t['symbol']),
                'outcomes' => array_count_values(array_column($list, 'outcome')),
            ];
        }

        $broken = array_values(array_filter($setups, fn (array $s): bool => $s['first_type'] !== null));
        $early = array_values(array_filter($setups, fn (array $s): bool => $s['early']));
        $confirmed = array_values(array_filter($setups, fn (array $s): bool => $s['confirmed']));
        $major = array_values(array_filter($broken, fn (array $s): bool => ($s['move_atr'] ?? 0) >= 3));
        $beforeMove = array_filter($major, fn (array $s): bool => $s['detect_extension_atr'] <= $s['move_atr'] / 3);
        $avg = fn (array $xs): ?float => $xs === [] ? null : round(array_sum($xs) / count($xs), 3);

        return [
            'from' => gmdate('Y-m-d', intdiv($fromMs, 1000)),
            'to' => gmdate('Y-m-d', intdiv($endMs, 1000)),
            'variants' => $variants,
            'lifecycle' => [
                'setups' => count($setups),
                'broke_out' => count($broken),
                'early_alerts' => count($early),
                'confirmed' => count($confirmed),
                'extended_first' => count(array_filter($broken, fn (array $s): bool => $s['first_type'] === 'EXTENDED')),
                'false_alert_rate_pct' => $early === [] ? null : round(count(array_filter($early, fn (array $s): bool => ! $s['confirmed'])) / count($early) * 100, 1),
                'failed_after_confirm_pct' => $confirmed === [] ? null : round(count(array_filter($confirmed, fn (array $s): bool => $s['failed'])) / count($confirmed) * 100, 1),
                'avg_detect_extension_atr' => $avg(array_column($broken, 'detect_extension_atr')),
                'major_moves' => count($major),
                'caught_before_most_of_move_pct' => $major === [] ? null : round(count($beforeMove) / count($major) * 100, 1),
            ],
        ];
    }

    protected function render(array $r): void
    {
        $this->newLine();
        $this->info("Results {$r['from']} to {$r['to']} (R = initial risk, fees + slippage + spread + funding included)");
        $row = fn (string $name, array $m): array => [$name, $m['trades'], $m['win_rate'] ?? '-', $m['avg_r'] ?? '-', $m['profit_factor'] ?? '-', $m['net_r'], $m['max_dd_r'], $m['avg_hold_min'] ?? '-', '$'.$m['final_balance'].' / '.$m['max_dd_pct'].'%'];
        $head = ['Slice', 'Trades', 'Win %', 'Avg R', 'PF', 'Net R', 'Max DD R', 'Hold min', '$ (dd)'];
        foreach ($r['variants'] as $variant => $v) {
            $this->line(strtoupper($variant).($variant === 'early' ? ' (price-only: 1m volume pace not available in history)' : ''));
            $rows = [$row('all', $v['all'])];
            foreach ($v['periods'] as $name => $m) {
                $rows[] = $row($name, $m);
            }
            foreach ($v['by_bucket'] as $name => $m) {
                $rows[] = $row('score '.$name, $m);
            }
            foreach ($v['by_direction'] as $name => $m) {
                $rows[] = $row($name, $m);
            }
            foreach ($v['by_h1'] as $name => $m) {
                $rows[] = $row($name, $m);
            }
            $this->table($head, $rows);
        }
        $this->table(['Signals not simulated as trades (reason)', 'Count'], array_map(fn ($k, $v) => [mb_substr((string) $k, 0, 90), $v], array_keys($r['signals_not_traded'] ?? []), $r['signals_not_traded'] ?? []));
        $this->table(['Early crossings not alerted (reason)', 'Count'], array_map(fn ($k, $v) => [mb_substr((string) $k, 0, 90), $v], array_keys($r['early_tick_rejects'] ?? []), $r['early_tick_rejects'] ?? []));
        $this->table(['Lifecycle', 'Value'], array_map(fn ($k, $v) => [$k, $v ?? '-'], array_keys($r['lifecycle']), $r['lifecycle']));
        $this->warn('Limits: early fills are price-only (pace assumed met), crossing time inside a 5m candle unknown (entry-candle stop counts, no target credit), stop-first on same-candle stop/target, default spread, today\'s top coins (survivorship).');
    }
}
