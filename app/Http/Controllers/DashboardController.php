<?php

namespace App\Http\Controllers;

use App\Models\EquitySnapshot;
use App\Models\Trade;
use App\Models\TradingAccount;
use App\Models\TradingSignal;
use App\Services\Binance\BinanceFuturesClient;
use App\Services\Notifications\TelegramNotifier;
use App\Services\Strategy\MarketScanService;
use App\Services\Strategy\SetupStats;
use App\Services\Trading\BacktestingEngine;
use App\Services\Trading\DynamicTradeManager;
use App\Services\Trading\ExchangePositionSync;
use App\Services\Trading\RiskManager;
use App\Services\Trading\SignalAlgoTrader;
use App\Services\Trading\TradingDaemonManager;
use App\Services\Trading\TradingModeManager;
use App\Services\Trading\TradingTargetManager;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        protected BinanceFuturesClient $client,
        protected DynamicTradeManager $tradeManager,
        protected RiskManager $riskManager,
        protected BacktestingEngine $backtestingEngine,
        protected TradingDaemonManager $daemonManager,
        protected ExchangePositionSync $exchangeSync,
        protected TradingModeManager $modeManager,
        protected MarketScanService $scanner,
        protected SignalAlgoTrader $trader,
        protected SetupStats $setupStats
    ) {}

    /**
     * Mode whose data the dashboard shows. Defaults to the engine's active mode.
     */
    protected function viewMode(Request $request): string
    {
        $mode = $request->input('mode')
            ?? $request->query('mode')
            ?? session('trading_mode')
            ?? $request->cookie('afte_trading_mode')
            ?? $this->modeManager->activeMode();

        return in_array($mode, ['paper', 'live'], true) ? $mode : $this->modeManager->activeMode();
    }

    /**
     * Display main trading terminal.
     */
    public function index(Request $request): View
    {
        $mode = $this->viewMode($request);
        session(['trading_mode' => $mode]);
        cookie()->queue('afte_trading_mode', $mode, 60 * 24 * 30);

        $account = TradingAccount::getForMode($mode);

        return view('dashboard.index', [
            'mode' => $mode,
            'activeMode' => $this->modeManager->activeMode(),
            'account' => $account->fresh(),
            'activeCoin' => TradingTargetManager::getActiveCoin(),
            'activeBase' => TradingTargetManager::getBaseCoin(),
            'availableCoins' => TradingTargetManager::getAvailableCoins(),
            'monitoredCoins' => TradingTargetManager::getMonitoredCoins(),
            'timeframes' => [(string) config('trading.strategy.base_interval', '1h')],
        ]);
    }

    /**
     * Account statistics API.
     */
    public function stats(Request $request): JsonResponse
    {
        $mode = $this->viewMode($request);
        session(['trading_mode' => $mode]);

        $account = TradingAccount::getForMode($mode);
        $client = $this->client->forMode($mode);

        // Sync real balance, equity, and positions from Binance if in live/testnet mode
        $liveSynced = $this->syncLiveAccountAndPositions($account, $mode);
        $account->refresh();

        $openPositions = Trade::where('mode', $mode)
            ->where('status', 'OPEN')
            ->get();

        $totalUnrealizedPnl = 0.0;
        foreach ($openPositions as $pos) {
            try {
                $markPrice = $client->getMarkPrice($pos->symbol);
                $totalUnrealizedPnl += $pos->calculateUnrealizedPnl($markPrice);
            } catch (\Throwable) {
                // Ignore individual mark price failure
            }
        }

        // Live: equity follows every position on the Binance account, including ones opened manually.
        if ($mode === 'live' && $liveSynced) {
            $exchange = $this->exchangeSync->exchangePositions();
            if ($exchange['error'] === null) {
                $totalUnrealizedPnl = (float) $exchange['unrealized_total'];
            }
        }

        $currentEquity = round($account->balance + $totalUnrealizedPnl, 4);
        $account->equity = $currentEquity;
        $account->save();

        $stageInfo = $this->riskManager->getCompoundingStage($account);
        $usedMargin = round((float) $openPositions->sum('margin_used'), 2);
        $availMargin = $this->riskManager->getAvailableBalance($account);
        $balance = round((float) $account->balance, 2);
        $marginUtilizationPct = $balance > 0 ? min(100.0, round(($usedMargin / $balance) * 100, 1)) : 0.0;
        $daemonStatus = $this->daemonManager->status($mode);

        // Stage 1 Seed Milestone Target ($25.00)
        $stage1Target = 25.0;
        $stage1Progress = min(100.0, max(0.0, round(($currentEquity / $stage1Target) * 100, 1)));

        // Cooldown and pause telemetry
        $isCooldownActive = $account->paused_until !== null && $account->paused_until->isFuture();
        $pausedRemainingMinutes = $isCooldownActive ? max(1, (int) ceil(Carbon::now()->diffInSeconds($account->paused_until, false) / 60)) : 0;
        $pausedRemainingHuman = $isCooldownActive ? $account->paused_until->diffForHumans() : null;
        $pausedUntilFormatted = $isCooldownActive ? $account->paused_until->format('h:i A') : null;

        $pausedReason = null;
        if ($account->kill_switch) {
            $pausedReason = 'EMERGENCY HALT: kill switch is active'.($account->pause_reason ? " ({$account->pause_reason})" : '').'. No new trades until it is reset.';
        } elseif ($isCooldownActive) {
            $pausedReason = 'CIRCUIT BREAKER: '.($account->pause_reason ?: 'risk limit reached').". New entries resume {$pausedRemainingHuman} ({$pausedUntilFormatted} UTC). Open trades stay protected.";
        } elseif (! $account->is_running) {
            $pausedReason = 'STANDBY: auto-trading is stopped. Start it to let the engine take new signals.';
        }

        $riskPct = (float) config('trading.sizing.risk_per_trade_pct', 2.0);
        $amountPerTrade = round($balance * $riskPct / 100, 2);
        $activeMode = $this->modeManager->activeMode();

        return response()->json([
            'mode' => $mode,
            'balance' => $balance,
            'equity' => round($currentEquity, 2),
            'unrealized_pnl' => round($totalUnrealizedPnl, 2),
            'initial_balance' => round($account->initial_balance, 2),
            'target_balance' => (float) config('trading.target_capital', 500.0),
            'progress_pct' => $account->target_progress,
            'stage_target' => $stage1Target,
            'stage_progress_pct' => $stage1Progress,
            'win_rate' => $account->win_rate,
            'total_trades' => $account->total_trades,
            'winning_trades' => $account->winning_trades,
            'losing_trades' => $account->losing_trades,
            'consecutive_losses' => $account->consecutive_losses,
            'max_consecutive_losses' => (int) config('trading.circuit_breakers.max_consecutive_losses', 3),
            'is_cooldown_active' => $isCooldownActive,
            'cooldown_remaining_minutes' => $pausedRemainingMinutes,
            'cooldown_remaining_human' => $pausedRemainingHuman,
            'cooldown_until_time' => $pausedUntilFormatted,
            'paused_reason' => $pausedReason,
            'open_positions_count' => $openPositions->count(),
            'used_margin' => $usedMargin,
            'available_margin' => $availMargin,
            'margin_utilization_pct' => $marginUtilizationPct,
            'kill_switch' => $account->kill_switch,
            'is_running' => (bool) $account->is_running,
            'paused_until' => $account->paused_until?->toIso8601String(),
            'can_trade' => $account->canTrade(),
            'stage' => $stageInfo['stage'],
            'max_positions' => $stageInfo['max_positions'],
            'default_leverage' => $stageInfo['default_leverage'],
            'amount_per_trade' => $amountPerTrade,
            'risk_per_trade_pct' => $riskPct,
            'pause_reason' => $account->pause_reason,
            'active_mode' => $activeMode,
            'live_readiness' => $this->modeManager->liveReadiness(),
            'live_synced' => $liveSynced,
            'daemon' => $daemonStatus,
        ]);
    }

    /**
     * Open positions list with real-time mark prices.
     */
    public function positions(Request $request): JsonResponse
    {
        $mode = $this->viewMode($request);
        $client = $this->client->forMode($mode);
        $account = TradingAccount::getForMode($mode);

        // Sync actual open positions directly from Binance
        $this->syncLiveAccountAndPositions($account, $mode);

        // Fetch open exchange-side algo orders (Stop Loss / Take Profit)
        $openAlgoMap = [];
        if ($mode === 'live' && $client->hasCredentials()) {
            try {
                $algoOrders = $client->getOpenAlgoOrders();
                foreach ($algoOrders as $ao) {
                    $sym = $ao['symbol'] ?? '';
                    if ($sym) {
                        $openAlgoMap[$sym][] = $ao;
                    }
                }
            } catch (\Throwable) {
                // Ignore algo order fetch error
            }
        }

        $openPositions = Trade::where('mode', $mode)
            ->where('status', 'OPEN')
            ->orderByDesc('opened_at')
            ->get();

        $formatted = [];
        foreach ($openPositions as $pos) {
            $markPrice = $pos->entry_price;
            try {
                $markPrice = $client->getMarkPrice($pos->symbol);
            } catch (\Exception) {
                // Fallback to entry
            }

            $unrealizedPnl = $pos->calculateUnrealizedPnl($markPrice);
            $roe = $pos->calculateRoe($markPrice);

            $symAlgos = $openAlgoMap[$pos->symbol] ?? [];
            $slRefId = (string) ($pos->meta['sl_order']['id'] ?? '');
            $tpRefId = (string) ($pos->meta['tp_order']['id'] ?? '');
            $slAlgo = collect($symAlgos)->first(fn (array $o): bool => (string) ($o['algoId'] ?? '') === $slRefId) ?? collect($symAlgos)->firstWhere('orderType', 'STOP_MARKET');
            $tpAlgo = collect($symAlgos)->first(fn (array $o): bool => (string) ($o['algoId'] ?? '') === $tpRefId) ?? collect($symAlgos)->firstWhere('orderType', 'TAKE_PROFIT_MARKET');

            $formatted[] = [
                'id' => $pos->id,
                'symbol' => $pos->symbol,
                'side' => $pos->side,
                'stage' => $pos->stage,
                'entry_price' => $pos->entry_price,
                'mark_price' => $markPrice,
                'quantity' => $pos->remaining_quantity,
                'initial_quantity' => $pos->quantity,
                'margin_used' => $pos->margin_used,
                'amount_added' => $pos->amount_added,
                'initial_amount_added' => $pos->initial_amount_added,
                'position_size_usd' => $pos->position_size_usd,
                'leverage' => $pos->leverage,
                'current_sl' => $pos->current_sl,
                'tp1_price' => $pos->tp1_price,
                'tp2_price' => $pos->tp2_price,
                'be_locked' => $pos->be_locked,
                'tp1_hit' => $pos->tp1_hit,
                'tp2_hit' => $pos->tp2_hit,
                'unrealized_pnl' => round($unrealizedPnl, 2),
                'realized_pnl' => round($pos->realized_pnl, 2),
                'roe' => $roe,
                'has_exchange_sl' => $slAlgo !== null,
                'exchange_sl_price' => $slAlgo ? (float) ($slAlgo['triggerPrice'] ?? 0) : null,
                'has_exchange_tp' => $tpAlgo !== null,
                'exchange_tp_price' => $tpAlgo ? (float) ($tpAlgo['triggerPrice'] ?? 0) : null,
                'opened_at' => $pos->opened_at?->diffForHumans(),
                'setup' => $pos->meta['setup_label'] ?? $pos->setup_tag,
                'source' => $pos->meta['source'] ?? 'auto',
                'risk_usd' => $pos->meta['risk_usd'] ?? null,
                'close_failed_reason' => $pos->meta['close_failed_reason'] ?? null,
                'initial_sl' => $pos->initial_sl,
            ];
        }

        return response()->json($formatted);
    }

    /**
     * Recent trading signals with AI verdicts.
     */
    public function signals(Request $request): JsonResponse
    {
        $signals = TradingSignal::orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json($signals);
    }

    /**
     * Trade history list.
     */
    public function history(Request $request): JsonResponse
    {
        $mode = $this->viewMode($request);

        $closedTrades = Trade::where('mode', $mode)
            ->where('status', 'CLOSED')
            ->orderByDesc('closed_at')
            ->limit(30)
            ->get();

        return response()->json($closedTrades);
    }

    /**
     * Equity curve historical points.
     */
    public function equityCurve(Request $request): JsonResponse
    {
        $mode = $this->viewMode($request);

        $snapshots = EquitySnapshot::where('mode', $mode)
            ->orderBy('created_at')
            ->limit(100)
            ->get(['balance', 'equity', 'created_at']);

        return response()->json($snapshots);
    }

    /**
     * Whole-market scanner results, read from the last background scan (page loads never scan).
     * `fresh=1` forces a scan now, for users allowed to trigger scans.
     */
    public function scanMarket(Request $request): JsonResponse
    {
        if ($request->boolean('fresh') && ($request->user()?->isAdmin() || $request->user()?->hasPermission('trigger_scans'))) {
            $this->scanner->requestManualScan();
        }

        // Show whichever is newer: the engine's hourly scan or the last on-demand scan.
        $engine = $this->scanner->latestResults();
        $manual = $this->scanner->latestManualResults();
        $results = ($manual['scanned_at'] ?? '') > ($engine['scanned_at'] ?? '') ? $manual : $engine;
        $rows = (array) ($results['rows'] ?? []);

        $opportunities = [];
        $watchlist = [];

        foreach ($rows as $row) {
            if (! empty($row['signal'])) {
                $opportunities[] = $this->opportunityPayload($row);
            } elseif (! empty($row['near'])) {
                $watchlist[] = [
                    'symbol' => $row['symbol'],
                    'bias' => $row['bias'],
                    'price' => $row['price'],
                    'near' => $row['near'],
                    'reason' => $row['reason'],
                ];
            }
        }

        return response()->json([
            'total_scanned' => (int) ($results['universe_size'] ?? count($rows)),
            'interval' => $results['interval'] ?? config('trading.strategy.base_interval', '1h'),
            'scanned_at' => $results['scanned_at'] ?? null,
            'cached_at' => $results['scanned_at'] ?? null,
            'duration_s' => $results['duration_s'] ?? null,
            'opportunities' => $opportunities,
            'watchlist' => array_slice($watchlist, 0, 25),
            'setup_stats' => $this->setupStats->all(),
            'trends' => [
                'long' => count(array_filter($rows, fn (array $r): bool => ($r['bias'] ?? '') === 'LONG')),
                'short' => count(array_filter($rows, fn (array $r): bool => ($r['bias'] ?? '') === 'SHORT')),
                'none' => count(array_filter($rows, fn (array $r): bool => ($r['bias'] ?? '') === 'NONE')),
            ],
        ]);
    }

    /**
     * Shape a scanner row for the dashboard table.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function opportunityPayload(array $row): array
    {
        $signal = $row['signal'];
        $entry = (float) $signal['entry'];
        $pct = fn (float $price): float => $entry > 0 ? round(abs($price - $entry) / $entry * 100, 2) : 0.0;

        return [
            'id' => $signal['id'] ?? null,
            'symbol' => $row['symbol'],
            'direction' => $signal['side'],
            'price' => $entry,
            'interval' => $signal['interval'],
            'time' => $signal['time'],
            'setup_type' => $signal['setup'],
            'setup_label' => $signal['setup_label'],
            'grade' => $signal['grade'],
            'stars' => $signal['stars'],
            'ai_probability' => $signal['ai_probability'],
            'ai_reasons' => $signal['ai_reasons'],
            'ai_lift' => $signal['ai_lift'] ?? null,
            'score' => $signal['score'] ?? null,
            'score_label' => $signal['opportunity']['label'] ?? null,
            'entry_status' => $signal['opportunity']['entry_status'] ?? null,
            'edge_r' => $signal['opportunity']['edge_r'] ?? null,
            'rank' => $signal['rank'] ?? null,
            'top_pick' => $signal['top_pick'] ?? false,
            'price_decimals' => $signal['price_decimals'] ?? null,
            'sl' => $signal['sl'],
            'sl_pct' => $signal['sl_pct'],
            'tp1' => $signal['tp1'],
            'tp1_pct' => $pct((float) $signal['tp1']),
            'tp2' => $signal['tp2'],
            'tp2_pct' => $pct((float) $signal['tp2']),
            'tp3' => $signal['tp3'],
            'risk_reward' => '1 : '.$signal['risk_reward'],
            'tradable' => $signal['tradable'],
            'is_shadow' => $signal['is_shadow'],
            'filters' => $signal['filters'],
            'failed_filters' => $signal['failed_filters'],
            'confluences' => $signal['confluences'],
            'indicators' => $signal['indicators'],
            'stats' => $signal['stats'] ?? null,
            'stats_30d' => $signal['stats_30d'] ?? null,
            'auto_trade' => $signal['auto_trade'] ?? null,
            'quote_volume' => $row['quote_volume'],
            'funding_rate' => $row['funding_rate'],
            'chart_url' => route('signals.dashboard', ['symbol' => $row['symbol'], 'interval' => $signal['interval'], 'signal_time' => $signal['time']]),
        ];
    }

    /**
     * Manual trade from the scanner or chart. Requires a current strategy signal in that direction;
     * goes through the same risk manager and order executor as the auto-trader, in the active mode.
     */
    public function executeRadarTrade(Request $request): JsonResponse
    {
        $symbol = strtoupper(trim((string) $request->input('symbol')));
        $direction = strtoupper(trim((string) $request->input('direction')));

        if (! in_array($direction, ['LONG', 'SHORT'], true) || $symbol === '') {
            return response()->json(['success' => false, 'message' => 'A symbol and a LONG/SHORT direction are required.'], 422);
        }

        try {
            $result = $this->trader->executeManual($symbol, $direction, $this->modeManager->activeMode());
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Execution error: '.$e->getMessage()], 500);
        }

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'trade' => $result['trade'],
            'mode' => $this->modeManager->activeMode(),
        ], $result['success'] ? 200 : 422);
    }

    /**
     * Move a position's stop to breakeven (exchange stop is replaced first for live trades).
     */
    public function lockBreakeven(Request $request): JsonResponse
    {
        $trade = Trade::findOrFail($request->input('trade_id'));

        if (! $trade->isOpen()) {
            return response()->json(['success' => false, 'message' => 'Trade is not open.'], 400);
        }

        $moved = $this->tradeManager->lockBreakeven($trade);

        return response()->json([
            'success' => $moved,
            'message' => $moved ? "Stop moved to breakeven for {$trade->symbol}." : 'Stop is already at or beyond breakeven, or the exchange rejected the move.',
        ], $moved ? 200 : 422);
    }

    /**
     * Manual market close. A live trade stays OPEN unless Binance confirms the position is flat.
     */
    public function closePosition(Request $request): JsonResponse
    {
        $trade = Trade::find($request->input('trade_id'));

        if (! $trade) {
            return response()->json(['success' => false, 'message' => 'Trade not found.'], 404);
        }

        if (! $trade->isOpen()) {
            return response()->json(['success' => true, 'message' => 'Trade is already closed.']);
        }

        $result = $this->tradeManager->closeTrade($trade, $this->markPrice($trade), 'MANUAL_CLOSE');
        $success = $result['status'] === 'closed';

        return response()->json(['success' => $success, 'message' => $result['message']], $success ? 200 : 502);
    }

    /**
     * Close all open positions in a mode. Reports any position that could not be confirmed closed.
     */
    public function closeAllPositions(Request $request): JsonResponse
    {
        $mode = $this->viewMode($request);
        $closed = 0;
        $failed = [];

        foreach (Trade::where('mode', $mode)->where('status', 'OPEN')->get() as $trade) {
            $result = $this->tradeManager->closeTrade($trade, $this->markPrice($trade), 'MANUAL_CLOSE');
            if ($result['status'] === 'closed') {
                $closed++;
            } else {
                $failed[] = "{$trade->symbol}: {$result['message']}";
            }
        }

        return response()->json([
            'success' => $failed === [],
            'message' => "Closed {$closed} position(s).".($failed !== [] ? ' NOT closed: '.implode('; ', $failed) : ''),
            'closed_count' => $closed,
            'failed' => $failed,
        ], $failed === [] ? 200 : 502);
    }

    /**
     * Current mark price for a trade, falling back to its entry price.
     */
    protected function markPrice(Trade $trade): float
    {
        try {
            return (float) ($this->client->getMarkPrice($trade->symbol) ?: $trade->entry_price);
        } catch (\Throwable) {
            return (float) $trade->entry_price;
        }
    }

    /**
     * Toggle the emergency kill switch. Activating it closes all positions in that mode;
     * deactivating it resets the drawdown reference to the current balance.
     */
    public function toggleKillSwitch(Request $request): JsonResponse
    {
        $mode = $this->viewMode($request);
        $account = TradingAccount::getForMode($mode);
        $account->kill_switch = ! $account->kill_switch;

        if (! $account->kill_switch) {
            $account->peak_equity = $account->balance;
            $account->pause_reason = null;
        }
        $account->save();

        $failed = [];
        if ($account->kill_switch) {
            foreach (Trade::where('mode', $mode)->where('status', 'OPEN')->get() as $trade) {
                $result = $this->tradeManager->closeTrade($trade, $this->markPrice($trade), 'KILL_SWITCH');
                if ($result['status'] !== 'closed') {
                    $failed[] = "{$trade->symbol}: {$result['message']}";
                }
            }
        }

        return response()->json([
            'success' => true,
            'kill_switch' => $account->kill_switch,
            'failed' => $failed,
            'message' => $account->kill_switch
                ? 'Kill switch ACTIVATED. Positions closed.'.($failed !== [] ? ' NOT confirmed closed: '.implode('; ', $failed) : '')
                : 'Kill switch deactivated. Drawdown reference reset to the current balance.',
        ]);
    }

    /**
     * Interactive Backtesting API.
     */
    public function runBacktest(Request $request): JsonResponse
    {
        @set_time_limit(180);
        $symbol = strtoupper($request->input('symbol', 'SOLUSDT'));
        $interval = (string) $request->input('interval', '1h');
        $limit = max(200, min(4320, (int) $request->input('limit', 2160)));
        $balance = (float) $request->input('balance', 5.0);

        try {
            $results = $this->backtestingEngine->run($symbol, $interval, $limit, $balance);

            return response()->json(['success' => true, 'data' => $results]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Start / stop auto-trading in the active mode (admin only). The cron engine reads this flag every cycle.
     */
    public function toggleAutoTrading(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized. Only administrators can start or stop automated trading.'], 403);
        }

        $mode = $this->modeManager->activeMode();
        $account = TradingAccount::getForMode($mode);
        $result = $account->is_running ? $this->daemonManager->stop($mode) : $this->daemonManager->start($mode);
        $account->refresh();

        return response()->json([
            'success' => $result['success'],
            'is_running' => (bool) $account->is_running,
            'can_trade' => $account->canTrade(),
            'mode' => $mode,
            'message' => $result['message'],
            'daemon' => $this->daemonManager->status($mode),
        ], $result['success'] ? 200 : 422);
    }

    /**
     * Switch the engine between paper and live (admin / manage_trading). Live requires working
     * API keys and ALLOW_LIVE_TRADING=true; the request must carry confirm=true.
     */
    public function setTradingMode(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin() && ! $request->user()?->hasPermission('manage_trading')) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to change the trading mode.'], 403);
        }

        $mode = strtolower((string) $request->input('mode'));
        if ($mode === 'live' && ! $request->boolean('confirm')) {
            return response()->json(['success' => false, 'requires_confirmation' => true, 'message' => 'Switching to LIVE places real orders with real money. Confirm to continue.'], 422);
        }

        $result = $this->modeManager->setMode($mode, $request->user()?->email);

        if ($result['success']) {
            session(['trading_mode' => $result['mode']]);
            cookie()->queue('afte_trading_mode', $result['mode'], 60 * 24 * 30);
            app(TelegramNotifier::class)->notifyRiskEvent($result['mode'], 'Trading mode changed', 'Engine now opens new trades in '.strtoupper($result['mode']).' mode (changed by '.($request->user()?->email ?? 'unknown').').');
        }

        return response()->json($result + ['live_readiness' => $this->modeManager->liveReadiness()], $result['success'] ? 200 : 422);
    }

    /**
     * Clear a circuit-breaker pause (admin only).
     */
    public function resumeCooldown(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized. Only administrators can reset the cooldown.'], 403);
        }

        $mode = $this->viewMode($request);
        $account = TradingAccount::getForMode($mode);
        $account->paused_until = null;
        $account->pause_reason = null;
        $account->consecutive_losses = 0;
        $account->is_running = true;
        $account->day_start_equity = $account->balance;
        $account->save();

        return response()->json([
            'success' => true,
            'message' => 'Circuit breaker cleared. Auto-trading resumes on the next engine cycle.',
            'mode' => $mode,
            'can_trade' => $account->canTrade(),
        ]);
    }

    /**
     * Dashboard poller. Web requests never trade: this only reports engine status.
     */
    public function autoTick(Request $request): JsonResponse
    {
        return response()->json(array_merge(['success' => true], $this->daemonManager->tickOnce($this->viewMode($request))));
    }

    /**
     * Get live status of the 24/7 trading daemon.
     */
    public function daemonStatus(Request $request): JsonResponse
    {
        $mode = $this->viewMode($request);

        return response()->json($this->daemonManager->status($mode));
    }

    /**
     * Start the 24/7 background trading daemon (Admin only).
     */
    public function startDaemon(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Administrator access required.'], 403);
        }

        $mode = $this->viewMode($request);

        if ($mode === 'live' && ! config('trading.allow_live_trading', false)) {
            return response()->json([
                'success' => false,
                'message' => 'Live trading daemon cannot be started from this environment.',
            ], 422);
        }

        return response()->json($this->daemonManager->start($mode));
    }

    /**
     * Stop the 24/7 background trading daemon (Admin only).
     */
    public function stopDaemon(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Administrator access required.'], 403);
        }

        $mode = $this->viewMode($request);

        return response()->json($this->daemonManager->stop($mode));
    }

    /**
     * Read recent daemon execution logs.
     */
    public function daemonLogs(Request $request): JsonResponse
    {
        $lines = min(100, max(10, (int) $request->query('lines', 40)));

        return response()->json([
            'success' => true,
            'logs' => $this->daemonManager->getRecentLogs($lines),
        ]);
    }

    /**
     * Unified real-time live sync endpoint for multi-device instant updates.
     */
    public function liveSync(Request $request): JsonResponse
    {
        $mode = $this->viewMode($request);

        $request->merge(['mode' => $mode]);
        session(['trading_mode' => $mode]);
        cookie()->queue('afte_trading_mode', $mode, 60 * 24 * 30);

        $statsData = $this->stats($request)->getData(true);
        $positionsData = $this->positions($request)->getData(true);
        $signalsData = $this->signals($request)->getData(true);
        $historyData = $this->history($request)->getData(true);
        $scannerData = $this->scanMarket($request)->getData(true);

        return response()->json([
            'success' => true,
            'mode' => $mode,
            'timestamp' => now()->toIso8601String(),
            'stats' => $statsData,
            'positions' => $positionsData,
            'signals' => array_slice($signalsData, 0, 10),
            'history' => array_slice($historyData, 0, 15),
            'opportunities' => $scannerData['opportunities'] ?? [],
            'daemon' => $statsData['daemon'] ?? null,
            'exchange' => $this->exchangeSync->exchangePositions(),
        ]);
    }

    /**
     * Synchronize actual wallet balance and live open positions from Binance exchange.
     */
    protected function syncLiveAccountAndPositions(TradingAccount $account, string $mode): bool
    {
        return $this->exchangeSync->syncLiveAccountAndPositions($account, $mode);
    }

    /**
     * Get target trading coin information.
     */
    public function getTradingCoin(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'active_coin' => TradingTargetManager::getActiveCoin(),
            'base' => TradingTargetManager::getBaseCoin(),
            'monitored_coins' => TradingTargetManager::getMonitoredCoins(),
            'available_coins' => TradingTargetManager::getAvailableCoins(),
            'timeframes' => TradingTargetManager::getMonitoredTimeframes(),
        ]);
    }

    /**
     * Update target trading coin.
     */
    public function setTradingCoin(Request $request): JsonResponse
    {
        $raw = (string) $request->input('coin', $request->input('symbol', 'NEARUSDT'));
        if (empty(trim($raw))) {
            return response()->json([
                'success' => false,
                'message' => 'Coin symbol is required.',
            ], 422);
        }

        $activeCoin = TradingTargetManager::setActiveCoin($raw);
        $base = TradingTargetManager::getBaseCoin($activeCoin);

        return response()->json([
            'success' => true,
            'message' => "Target trading asset updated to {$activeCoin} ({$base})! The 24/7 autonomous bot is monitoring at least 5 coins on 15m & 1h SignalAlgo PRO charts.",
            'active_coin' => $activeCoin,
            'base' => $base,
            'monitored_coins' => TradingTargetManager::getMonitoredCoins(),
            'available_coins' => TradingTargetManager::getAvailableCoins(),
        ]);
    }
}
