<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\Strategy\MarketScanService;
use App\Services\Strategy\StrategyEngine;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Whole-market scan for the chart page. The background engine scans after every candle close;
 * "Run scan" here forces an immediate scan in the request (nothing is spawned).
 */
class MarketScanController extends Controller
{
    public function __construct(protected MarketScanService $scanner) {}

    public function start(): JsonResponse
    {
        @set_time_limit(180);

        try {
            $result = $this->scanner->runScan((string) config('trading.strategy.base_interval', '1h'), force: true);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Scan failed: '.$e->getMessage()], 500);
        }

        return response()->json(['success' => true, 'message' => $result['message'], 'status' => 'COMPLETED']);
    }

    public function stop(): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Scans run to completion in a few seconds; nothing to stop.', 'status' => 'COMPLETED']);
    }

    public function status(): JsonResponse
    {
        $results = $this->scanner->latestResults();
        $rows = (array) ($results['rows'] ?? []);
        $signals = array_values(array_map(fn (array $row): array => $this->card($row), array_filter($rows, fn (array $r): bool => ! empty($r['signal']))));
        $total = (int) ($results['universe_size'] ?? count($rows));

        $log = $results === [] ? 'No scan yet. The engine scans after each candle close, or press Run Scan.' : implode("\n", [
            'Scanned '.count($rows)." symbols ({$total} liquid USDT perpetuals + watchlist/open trades) on ".($results['interval'] ?? '1h').'.',
            'Signals on the last closed candle: '.count($signals).'.',
            'Uptrend: '.count(array_filter($rows, fn (array $r): bool => $r['bias'] === 'LONG')).
            ' · Downtrend: '.count(array_filter($rows, fn (array $r): bool => $r['bias'] === 'SHORT')).
            ' · No trend: '.count(array_filter($rows, fn (array $r): bool => $r['bias'] === 'NONE')),
            'Duration: '.($results['duration_s'] ?? '?').'s. [COMPLETED]',
        ]);

        return response()->json([
            'success' => true,
            'status' => $results === [] ? 'IDLE' : 'COMPLETED',
            'is_running' => false,
            'progress' => ['current_symbol' => 'Done', 'index' => count($rows), 'total' => count($rows), 'percent' => 100, 'signals_found' => count($signals)],
            'signals' => $signals,
            'total_signals' => count($signals),
            'log_tail' => $log,
            'started_at' => isset($results['scanned_at']) ? Carbon::parse($results['scanned_at'])->setTimezone('Asia/Kolkata')->format('H:i:s \I\S\T') : null,
        ]);
    }

    public function clear(): JsonResponse
    {
        Setting::putValue(MarketScanService::RESULTS_KEY, []);

        return response()->json(['success' => true, 'message' => 'Scan results cleared.', 'status' => 'IDLE']);
    }

    /**
     * Shape a scanner row for the existing signal cards.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function card(array $row): array
    {
        $s = $row['signal'];
        $entry = (float) $s['entry'];
        $pct = fn (float $price): float => $entry > 0 ? round(abs($price - $entry) / $entry * 100, 2) : 0.0;
        $stats = $s['stats'] ?? [];
        $filters = collect($s['filters'])->map(fn (array $f, string $name): string => ($f['pass'] ? '✓ ' : '✗ ').str_replace('_', ' ', $name).': '.$f['detail'])->implode(' · ');
        $record = ($stats['n'] ?? 0) > 0
            ? sprintf('%s%% win, %+.2fR avg over %d signals (90d, %s).', $stats['win_rate'], $stats['expectancy'], $stats['n'], $stats['source'])
            : 'No track record yet.';

        return [
            'symbol' => $row['symbol'],
            'side' => $s['order_side'],
            'grade' => $s['grade'],
            'score' => $s['ai_probability'] !== null ? (int) round($s['ai_probability'] * 100) : null,
            'setup_type' => $s['setup'],
            'setup_label' => StrategyEngine::SETUP_LABELS[$s['setup']] ?? $s['setup_label'],
            'entry' => $entry,
            'sl' => $s['sl'],
            'sl_pct' => $s['sl_pct'],
            'tp1' => $s['tp1'],
            'tp1_pct' => $pct((float) $s['tp1']),
            'tp2' => $s['tp2'],
            'tp2_pct' => $pct((float) $s['tp2']),
            'tp3' => $s['tp3'],
            'tp3_pct' => $pct((float) $s['tp3']),
            'risk_reward' => '1 : '.$s['risk_reward'],
            'tradable' => $s['tradable'],
            'trade_type' => 'SWING TRADE',
            'trade_horizon' => 'Up to 48h (time stop 12h)',
            'recommended_leverage' => 'Risk-sized ('.config('trading.sizing.risk_per_trade_pct', 2.0).'%)',
            'support' => $s['side'] === 'LONG' ? $s['sl'] : null,
            'resistance' => $s['side'] === 'SHORT' ? $s['sl'] : null,
            'volume_ratio' => $s['indicators']['volume_ratio'] ?? null,
            'rsi' => $s['indicators']['rsi'] ?? null,
            'age_minutes' => max(0, (int) round((time() - (int) $s['time']) / 60)),
            'detailed_reasoning' => [
                'market_structure' => $filters,
                'volume_ignition' => 'Volume '.($s['indicators']['volume_ratio'] ?? '?').'x average. '.($s['confluences'] !== [] ? 'Confluence: '.implode(', ', $s['confluences']).'.' : 'No extra confluence.'),
                'trend_momentum' => 'RSI '.($s['indicators']['rsi'] ?? '?').', ADX '.($s['indicators']['adx'] ?? '?').'. Track record: '.$record,
                'execution_strategy' => 'Stop at '.$s['sl'].'. Book half at TP1 (1.5R) and move the stop to +0.5R; trail the rest at 1.5x ATR. Breakeven at +1R. Exit if not +0.5R after 12h.'.($s['auto_trade'] ? ' Auto-trader: '.$s['auto_trade'] : ''),
            ],
        ];
    }
}
