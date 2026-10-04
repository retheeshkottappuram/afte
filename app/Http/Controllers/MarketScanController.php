<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\Strategy\MarketScanService;
use App\Services\Strategy\StrategyEngine;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

/**
 * Whole-market scan for the chart page. The background engine scans after every candle close;
 * "Run scan" here forces an immediate scan in the request (nothing is spawned).
 */
class MarketScanController extends Controller
{
    public function __construct(protected MarketScanService $scanner) {}

    public function start(): JsonResponse
    {
        if (! $this->scanner->requestManualScan()) {
            return response()->json(['success' => true, 'message' => 'A scan is already in progress. Results will appear here.', 'status' => 'RUNNING']);
        }

        return response()->json(['success' => true, 'message' => 'Whole-market scan queued. It starts within a minute and takes about a minute; progress shows below.', 'status' => 'RUNNING']);
    }

    public function stop(): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Scans run to completion in a few seconds; nothing to stop.', 'status' => 'COMPLETED']);
    }

    public function status(): JsonResponse
    {
        $state = $this->scanner->manualState();
        $busy = in_array($state['status'] ?? 'IDLE', ['QUEUED', 'RUNNING'], true);
        $results = $this->scanner->latestManualResults();
        $rows = (array) ($results['rows'] ?? []);
        $signals = array_values(array_map(fn (array $row): array => $this->card($row), array_filter($rows, fn (array $r): bool => ! empty($r['signal']))));
        $near = array_values(array_filter($rows, fn (array $r): bool => empty($r['signal']) && ! empty($r['near'])));
        $coins = fn (string $bias): array => array_values(array_map(fn (array $r): string => str_replace('USDT', '', $r['symbol']), array_filter($rows, fn (array $r): bool => $r['bias'] === $bias)));

        $log = $results === [] ? 'No on-demand scan yet. Press "Run Whole-Market Scan".' : implode("\n", array_filter([
            'Scanned '.count($rows).' crypto USDT perpetuals (24h volume >= $'.number_format(($results['min_volume'] ?? 5e6) / 1e6).'M) on '.($results['interval'] ?? '1h').' in '.($results['duration_s'] ?? '?').'s.',
            'Active setups from the last '.($results['lookback_bars'] ?? 6).' closed candles (stop/targets not hit yet): '.count($signals).'.',
            $signals === [] ? 'No setup is in play right now. That is normal: most hours have no valid entry, and no trade beats a bad trade.' : null,
            'Near a setup (watch these): '.($near === [] ? 'none' : implode(', ', array_map(fn (array $r): string => str_replace('USDT', '', $r['symbol']).' ('.$r['near']['detail'].')', array_slice($near, 0, 12)))),
            'Uptrend ('.count($coins('LONG')).'): '.implode(', ', array_slice($coins('LONG'), 0, 30)),
            'Downtrend ('.count($coins('SHORT')).'): '.implode(', ', array_slice($coins('SHORT'), 0, 30)),
            'No clear trend: '.count($coins('NONE')).' coins.',
            'Coins below $50M daily volume are shown but flagged not tradable for the auto-trader. [COMPLETED]',
        ]));

        if ($busy) {
            $log = ($state['status'] === 'QUEUED' ? 'Scan queued. Waiting for the background worker (runs every minute via cron)...' : 'Scanning the whole market...')
                .'

Previous results:
'.$log;
        } elseif (($state['status'] ?? '') === 'FAILED') {
            $log = 'Last scan failed: '.($state['error'] ?? 'unknown error').'

'.$log;
        }

        $total = (int) ($state['total'] ?? 0);
        $index = (int) ($state['index'] ?? 0);

        return response()->json([
            'success' => true,
            'status' => $busy ? 'RUNNING' : ($results === [] ? 'IDLE' : 'COMPLETED'),
            'is_running' => $busy,
            'progress' => $busy
                ? ['current_symbol' => $state['current_symbol'] ?? '...', 'index' => $index, 'total' => $total, 'percent' => $total > 0 ? (int) round($index / $total * 100) : 0, 'signals_found' => count($signals)]
                : ['current_symbol' => 'Done', 'index' => count($rows), 'total' => count($rows), 'percent' => 100, 'signals_found' => count($signals)],
            'signals' => $signals,
            'total_signals' => count($signals),
            'log_tail' => $log,
            'started_at' => isset($results['scanned_at']) ? Carbon::parse($results['scanned_at'])->setTimezone('Asia/Kolkata')->format('H:i:s \I\S\T') : null,
        ]);
    }

    public function clear(): JsonResponse
    {
        Setting::putValue(MarketScanService::MANUAL_RESULTS_KEY, []);

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
            'age_minutes' => $s['age_minutes'] ?? max(0, (int) round((time() - (int) $s['time']) / 60)),
            'detailed_reasoning' => [
                'market_structure' => $filters,
                'volume_ignition' => 'Volume '.($s['indicators']['volume_ratio'] ?? '?').'x average. '.($s['confluences'] !== [] ? 'Confluence: '.implode(', ', $s['confluences']).'.' : 'No extra confluence.'),
                'trend_momentum' => 'RSI '.($s['indicators']['rsi'] ?? '?').', ADX '.($s['indicators']['adx'] ?? '?').'. Track record: '.$record,
                'execution_strategy' => 'Stop at '.$s['sl'].'. Book half at TP1 (1.5R) and move the stop to +0.5R; trail the rest at 1.5x ATR. Breakeven at +1R. Exit if not +0.5R after 12h.'.($s['auto_trade'] ? ' Auto-trader: '.$s['auto_trade'] : ''),
            ],
        ];
    }
}
