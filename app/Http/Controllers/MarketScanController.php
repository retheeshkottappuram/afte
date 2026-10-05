<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\TradingAccount;
use App\Services\Crypto\BinanceClient;
use App\Services\Strategy\BreakoutWatcher;
use App\Services\Strategy\MarketScanService;
use App\Services\Strategy\OpportunityScorer;
use App\Services\Strategy\StrategyEngine;
use App\Services\Strategy\Watchlist;
use App\Services\Trading\EarlyBreakoutGuard;
use App\Services\Trading\TradingModeManager;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Whole-market scan for the chart page. The background engine scans after every candle close;
 * "Run scan" here forces an immediate scan in the request (nothing is spawned).
 */
class MarketScanController extends Controller
{
    public function __construct(
        protected MarketScanService $scanner,
        protected OpportunityScorer $opportunity,
        protected BinanceClient $market,
        protected TradingModeManager $modeManager
    ) {}

    public function start(): JsonResponse
    {
        if (! $this->scanner->requestManualScan()) {
            return response()->json(['success' => true, 'message' => 'A scan is already in progress. Results will appear here.', 'status' => 'RUNNING']);
        }

        return response()->json(['success' => true, 'message' => 'Whole-market scan queued. It starts within a minute and takes about a minute; progress shows below.', 'status' => 'RUNNING']);
    }

    /**
     * Coins coiled for a breakout on the current candle, with live distance to their trigger,
     * plus the early-breakout auto-trader status (limits, pause).
     */
    public function watch(BreakoutWatcher $watcher, StrategyEngine $engine): JsonResponse
    {
        $prices = $this->livePrices();
        $mode = $this->modeManager->activeMode();
        $coins = [];

        foreach ($watcher->watching() as $symbol => $watch) {
            $price = $prices[$symbol] ?? (float) ($watch['price'] ?? 0);
            $trigger = $engine->triggerPrice($watch);
            $isLong = $watch['side'] === 'LONG';
            $coins[] = [
                'symbol' => $symbol,
                'side' => $watch['side'],
                'level' => round((float) $watch['level'], MarketScanService::priceDecimals((float) $watch['level'])),
                'trigger' => round($trigger, MarketScanService::priceDecimals($trigger)),
                'price' => $price,
                'price_decimals' => MarketScanService::priceDecimals($price ?: (float) $watch['level']),
                'distance_pct' => $price > 0 ? round(($isLong ? $trigger - $price : $price - $trigger) / $price * 100, 2) : null,
                'triggered' => $price > 0 && ($isLong ? $price >= $trigger : $price <= $trigger),
            ];
        }
        usort($coins, fn (array $a, array $b): int => ($a['distance_pct'] ?? 99) <=> ($b['distance_pct'] ?? 99));

        $state = (array) Setting::getValue(BreakoutWatcher::WATCH_KEY, []);

        return response()->json([
            'coins' => $coins,
            'candle_closes_at' => isset($state['bar_close_ms']) ? Carbon::createFromTimestampMs((int) $state['bar_close_ms'])->toIso8601String() : null,
            'early_breakout' => [
                'alerts' => BreakoutWatcher::alertsEnabled(),
                'auto_trade' => BreakoutWatcher::tradingEnabled(),
                'mode' => $mode,
                'blocked' => BreakoutWatcher::tradingEnabled() ? EarlyBreakoutGuard::blockReason($mode) : null,
                'paused' => EarlyBreakoutGuard::pauseReason($mode),
                'risk_pct' => (float) config('trading.strategy.early_breakout.risk_pct', 1.0),
                'max_per_day' => (int) config('trading.strategy.early_breakout.max_per_day', 3),
            ],
        ]);
    }

    /**
     * Resume early-breakout auto-trading after a losing-streak pause.
     */
    public function resumeEarly(): JsonResponse
    {
        EarlyBreakoutGuard::resume();

        return response()->json(['success' => true, 'message' => 'Early Breakout auto-trading resumed. The losing streak count starts again from now.']);
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
        $prices = $this->livePrices();
        $watchlist = Watchlist::symbols();
        $signals = array_values(array_map(fn (array $row): array => $this->card($row, $prices[$row['symbol']] ?? null, $watchlist), array_filter($rows, fn (array $r): bool => ! empty($r['signal']))));
        $signals = $this->rankCards($signals);
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
            'account' => $this->accountSummary(),
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
     * Shape a scanner row for the result cards, refreshed with the live price.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $watchlist
     * @return array<string, mixed>
     */
    protected function card(array $row, ?float $livePrice, array $watchlist): array
    {
        $s = $row['signal'];
        $entry = (float) $s['entry'];
        $risk = abs($entry - (float) $s['sl']);
        $direction = $s['side'] === 'LONG' ? 1 : -1;
        $decimals = (int) ($s['price_decimals'] ?? MarketScanService::priceDecimals($entry));
        $round = fn (float $price): float => round($price, $decimals);
        $pct = fn (float $price): float => $entry > 0 ? round(abs($price - $entry) / $entry * 100, 2) : 0.0;

        // Refresh the entry part of the score with the live price.
        $now = $livePrice ?? (isset($row['price']) ? (float) $row['price'] : null);
        $drift = ($now !== null && $risk > 0) ? ($now - $entry) * $direction / $risk : null;
        $opportunity = (array) ($s['opportunity'] ?? []);
        $breakdown = (array) ($opportunity['breakdown'] ?? []);
        $breakdown['entry'] = round($this->opportunity->entryPoints($drift), 1);
        $score = (int) round(max(0, min(100, array_sum($breakdown))));
        $stats = (array) ($s['stats'] ?? []);

        return [
            'symbol' => $row['symbol'],
            'interval' => $s['interval'],
            'side' => $s['order_side'],
            'direction' => $s['side'],
            'setup_type' => $s['setup'],
            'setup_label' => StrategyEngine::SETUP_LABELS[$s['setup']] ?? $s['setup_label'],
            'is_shadow' => (bool) $s['is_shadow'],
            'tradable' => (bool) $s['tradable'],
            'grade' => $s['grade'],
            'score' => $score,
            'score_label' => OpportunityScorer::label($score),
            'score_breakdown' => $breakdown,
            'edge_r' => $opportunity['edge_r'] ?? ($stats['expectancy'] ?? null),
            'time' => (int) $s['time'],
            'age_minutes' => $s['age_minutes'] ?? max(0, (int) round((now()->timestamp - (int) $s['time']) / 60)),
            'price_decimals' => $decimals,
            'entry' => $round($entry),
            'now_price' => $now !== null ? $round($now) : null,
            'drift_r' => $drift !== null ? round($drift, 2) : null,
            'entry_status' => $this->opportunity->entryStatus($drift),
            'sl' => $round((float) $s['sl']),
            'sl_pct' => $pct((float) $s['sl']),
            'tp1' => $round((float) $s['tp1']),
            'tp1_pct' => $pct((float) $s['tp1']),
            'tp2' => $round((float) $s['tp2']),
            'tp2_pct' => $pct((float) $s['tp2']),
            'filters' => $s['filters'],
            'failed_filters' => array_values((array) $s['failed_filters']),
            'confluences' => $s['confluences'],
            'stats' => [
                'win_rate' => $stats['win_rate'] ?? null,
                'expectancy' => $stats['expectancy'] ?? null,
                'n' => $stats['n'] ?? 0,
                'source' => $stats['source'] ?? null,
            ],
            'indicators' => [
                'rsi' => $s['indicators']['rsi'] ?? null,
                'adx' => $s['indicators']['adx'] ?? null,
                'volume_ratio' => $s['indicators']['volume_ratio'] ?? null,
                'atr_pct' => $s['indicators']['atr_pct'] ?? null,
            ],
            'auto_trade' => $this->scanner->autoTradeVerdict($s),
            'watched' => in_array($row['symbol'], $watchlist, true),
        ];
    }

    /**
     * Order cards by tradable first, then score, and number them 1..n with one top pick.
     *
     * @param  array<int, array<string, mixed>>  $cards
     * @return array<int, array<string, mixed>>
     */
    protected function rankCards(array $cards): array
    {
        usort($cards, fn (array $a, array $b): int => [$b['tradable'], $b['score']] <=> [$a['tradable'], $a['score']]);

        $topPicked = false;
        foreach ($cards as $index => $card) {
            $cards[$index]['rank'] = $index + 1;
            $isTop = ! $topPicked && $card['tradable'] && $card['entry_status'] === 'Enter now';
            $cards[$index]['top_pick'] = $isTop;
            $topPicked = $topPicked || $isTop;
        }

        return $cards;
    }

    /**
     * Last traded prices for all symbols (24h ticker, cached ~20s).
     *
     * @return array<string, float>
     */
    protected function livePrices(): array
    {
        try {
            $prices = [];
            foreach ($this->market->get24hrTickers() as $ticker) {
                $prices[strtoupper((string) ($ticker['symbol'] ?? ''))] = (float) ($ticker['lastPrice'] ?? 0);
            }

            return array_filter($prices, fn (float $p): bool => $p > 0);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Active mode, balance and risk per trade, so cards can show $ risk and targets.
     *
     * @return array{mode: string, balance: float, risk_pct: float}
     */
    protected function accountSummary(): array
    {
        $mode = $this->modeManager->activeMode();

        return [
            'mode' => $mode,
            'balance' => round((float) TradingAccount::getForMode($mode)->balance, 2),
            'risk_pct' => (float) config('trading.sizing.risk_per_trade_pct', 2.0),
            'fee_rate' => (float) config('trading.exits.fee_rate', 0.0005),
        ];
    }
}
