<?php

namespace App\Http\Controllers;

use App\Models\EquitySnapshot;
use App\Models\Trade;
use App\Models\TradingAccount;
use App\Models\TradingSignal;
use App\Services\AI\SignalValidator;
use App\Services\Binance\BinanceFuturesClient;
use App\Services\Trading\BacktestingEngine;
use App\Services\Trading\DynamicTradeManager;
use App\Services\Trading\ExchangePositionSync;
use App\Services\Trading\MarketEngine;
use App\Services\Trading\OrderExecutor;
use App\Services\Trading\RiskManager;
use App\Services\Trading\SignalEngine;
use App\Services\Trading\TradingDaemonManager;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        protected BinanceFuturesClient $client,
        protected MarketEngine $marketEngine,
        protected SignalEngine $signalEngine,
        protected SignalValidator $validator,
        protected DynamicTradeManager $tradeManager,
        protected OrderExecutor $executor,
        protected RiskManager $riskManager,
        protected BacktestingEngine $backtestingEngine,
        protected TradingDaemonManager $daemonManager,
        protected ExchangePositionSync $exchangeSync
    ) {}

    /**
     * Display main cyber trading terminal.
     */
    public function index(Request $request): View
    {
        $defaultMode = config('trading.mode', 'live');
        $mode = $request->query('mode')
            ?? $request->cookie('afte_trading_mode')
            ?? session('trading_mode')
            ?? $defaultMode;

        if (! in_array($mode, ['paper', 'live'], true)) {
            $mode = $defaultMode;
        }

        session(['trading_mode' => $mode]);
        cookie()->queue('afte_trading_mode', $mode, 60 * 24 * 30);

        $account = TradingAccount::getForMode($mode);
        $this->syncLiveAccountAndPositions($account, $mode);

        return view('dashboard.index', [
            'mode' => $mode,
            'account' => $account->fresh(),
        ]);
    }

    /**
     * Account statistics API.
     */
    public function stats(Request $request): JsonResponse
    {
        $defaultMode = config('trading.mode', 'live');
        $mode = $request->query('mode')
            ?? session('trading_mode')
            ?? $request->cookie('afte_trading_mode')
            ?? $defaultMode;

        if (! in_array($mode, ['paper', 'live'], true)) {
            $mode = $defaultMode;
        }

        session(['trading_mode' => $mode]);
        cookie()->queue('afte_trading_mode', $mode, 60 * 24 * 30);

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
            $pausedReason = 'EMERGENCY HALT: Kill Switch is active. All automated trading is suspended.';
        } elseif ($isCooldownActive) {
            $maxConsecutive = (int) config('trading.circuit_breakers.max_consecutive_losses', 2);
            $pausedReason = "CIRCUIT BREAKER: Auto-trading paused after {$account->consecutive_losses}/{$maxConsecutive} consecutive losses to eliminate revenge trading and protect capital. Cooldown active for next {$pausedRemainingMinutes}m (until {$pausedUntilFormatted}).";
        } elseif (! $account->is_running) {
            $pausedReason = 'STANDBY: Auto-trading is paused. Start Auto Trading to resume automated execution.';
        }

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
            'max_consecutive_losses' => (int) config('trading.circuit_breakers.max_consecutive_losses', 2),
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
            'amount_per_trade' => config('trading.fund_management.amount_per_trade') ?? round(5.20 / ($stageInfo['default_leverage'] ?? 10), 2),
            'live_synced' => $liveSynced,
            'daemon' => $daemonStatus,
        ]);
    }

    /**
     * Open positions list with real-time mark prices.
     */
    public function positions(Request $request): JsonResponse
    {
        $mode = $request->query('mode', config('trading.mode', 'live'));
        if (! in_array($mode, ['paper', 'live'], true)) {
            $mode = 'paper';
        }
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
            $slAlgo = collect($symAlgos)->firstWhere('orderType', 'STOP_MARKET');
            $tpAlgo = collect($symAlgos)->firstWhere('orderType', 'TAKE_PROFIT_MARKET');

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
                'ai_monitor' => $pos->meta['ai_monitor'] ?? null,
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
        $mode = $request->query('mode', config('trading.mode', 'live'));

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
        $mode = $request->query('mode', config('trading.mode', 'live'));

        $snapshots = EquitySnapshot::where('mode', $mode)
            ->orderBy('created_at')
            ->limit(100)
            ->get(['balance', 'equity', 'created_at']);

        return response()->json($snapshots);
    }

    /**
     * On-demand market scan across Binance Futures.
     */
    public function scanMarket(Request $request): JsonResponse
    {
        $limit = min(20, (int) $request->query('limit', 12));
        $fresh = $request->boolean('fresh');
        $cacheKey = "market:scan:results:{$limit}";

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        $data = Cache::remember($cacheKey, 60, function () use ($limit): array {
            $btcTrend = $this->marketEngine->getBtcMarketTrend();
            $btcBase = $this->marketEngine->getBtcBaseKlines();
            $symbols = $this->marketEngine->getScannableSymbols();
            $scanSymbols = array_slice($symbols, 0, $limit);

            $results = [];

            foreach ($scanSymbols as $sym) {
                try {
                    $klines = $this->marketEngine->getMultiTimeframeKlines($sym);
                    $eval = $this->signalEngine->evaluate($sym, $klines['base'], $klines['htf1'], $klines['htf2'], $btcBase);

                    if ($eval !== null) {
                        // Condition 1: Bitcoin Macro Trend Filter
                        if ($eval['direction'] === 'LONG' && ! $btcTrend['allow_long']) {
                            continue;
                        }
                        if ($eval['direction'] === 'SHORT' && ! $btcTrend['allow_short']) {
                            continue;
                        }

                        // Condition 7: Institutional Conviction Score Gate (>= 80)
                        if (($eval['score'] ?? 0) < 80) {
                            continue;
                        }

                        $ai = $this->validator->validate($eval, $klines['base']);

                        // Require AI approval for peak entry accuracy
                        if (! ($ai['approved'] ?? false)) {
                            continue;
                        }

                        $results[] = [
                            'symbol' => $sym,
                            'direction' => $eval['direction'],
                            'price' => $eval['price'],
                            'score' => $eval['score'],
                            'grade' => $eval['grade'],
                            'sl' => $eval['initial_sl'],
                            'tp1' => $eval['tp1'],
                            'tp2' => $eval['tp2'],
                            'indicators' => $eval['indicators'],
                            'ai_approved' => $ai['approved'],
                            'ai_confidence' => $ai['confidence'],
                            'ai_regime' => $ai['regime'],
                            'ai_reason' => $ai['reason'],
                        ];
                    }
                } catch (\Exception) {
                    // Ignore symbol glitch
                }
            }

            // Sort descending by score
            usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

            return [
                'total_scanned' => count($scanSymbols),
                'btc_macro' => [
                    'trend' => $btcTrend['trend'],
                    'btc_price' => $btcTrend['btc_price'],
                    'allow_long' => $btcTrend['allow_long'],
                    'allow_short' => $btcTrend['allow_short'],
                ],
                'opportunities' => $results,
                'cached_at' => now()->toIso8601String(),
            ];
        });

        return response()->json($data);
    }

    /**
     * Instantly execute an approved trade directly from the Breakout Scanner Radar.
     */
    public function executeRadarTrade(Request $request): JsonResponse
    {
        $symbol = strtoupper(trim((string) $request->input('symbol')));
        $direction = strtoupper(trim((string) $request->input('direction')));
        $mode = $request->input('mode', config('trading.mode', 'live'));

        if (! in_array($direction, ['LONG', 'SHORT'], true)) {
            return response()->json(['success' => false, 'message' => 'Invalid trade direction.'], 422);
        }

        if (empty($symbol)) {
            return response()->json(['success' => false, 'message' => 'Trading pair symbol is required.'], 422);
        }

        $account = TradingAccount::getForMode($mode);
        if ($account->kill_switch) {
            return response()->json(['success' => false, 'message' => 'Kill switch is active. Trade cannot be placed.'], 422);
        }

        try {
            $btcBase = $this->marketEngine->getBtcBaseKlines();
            $klines = $this->marketEngine->getMultiTimeframeKlines($symbol);
            $eval = $this->signalEngine->evaluate($symbol, $klines['base'], $klines['htf1'], $klines['htf2'], $btcBase);

            if ($eval === null) {
                $currentPrice = $this->client->forMode($mode)->getMarkPrice($symbol);
                $slPct = (float) config('trading.risk.default_sl_pct', 1.5) / 100.0;
                $tp1Pct = (float) config('trading.risk.default_tp1_pct', 2.0) / 100.0;
                $tp2Pct = (float) config('trading.risk.default_tp2_pct', 4.0) / 100.0;

                $eval = [
                    'symbol' => $symbol,
                    'direction' => $direction,
                    'price' => $currentPrice,
                    'initial_sl' => $direction === 'LONG' ? round($currentPrice * (1.0 - $slPct), 6) : round($currentPrice * (1.0 + $slPct), 6),
                    'tp1' => $direction === 'LONG' ? round($currentPrice * (1.0 + $tp1Pct), 6) : round($currentPrice * (1.0 - $tp1Pct), 6),
                    'tp2' => $direction === 'LONG' ? round($currentPrice * (1.0 + $tp2Pct), 6) : round($currentPrice * (1.0 - $tp2Pct), 6),
                    'score' => 88,
                    'grade' => 'A',
                    'setup' => 'MANUAL_RADAR_TRIGGER',
                    'indicators' => [],
                ];
                $ai = [
                    'approved' => true,
                    'confidence' => 88,
                    'regime' => 'MANUAL_RADAR_TRIGGER',
                    'reason' => 'Executed manually from Breakout Scanner Radar with institutional risk parameters.',
                ];
            } else {
                $eval['direction'] = $direction;
                $ai = $this->validator->validate($eval, $klines['base']);
                $ai['approved'] = true;
            }

            $execResult = $this->executor->executeSignal($eval, $ai, $mode, true);

            if ($execResult['status'] === 'opened' || $execResult['status'] === 'executed') {
                return response()->json([
                    'success' => true,
                    'message' => "Successfully opened {$symbol} {$direction}! Order active on ".strtoupper($mode).' mode.',
                    'trade' => $execResult['trade'],
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $execResult['message'] ?? 'Could not execute trade.',
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Execution error: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Lock Breakeven action on a position.
     */
    public function lockBreakeven(Request $request): JsonResponse
    {
        $tradeId = $request->input('trade_id');
        $trade = Trade::findOrFail($tradeId);

        if (! $trade->isOpen()) {
            return response()->json(['success' => false, 'message' => 'Trade is not open.'], 400);
        }

        $bufferPct = (float) config('trading.management.be_fee_buffer_pct', 0.12);
        $trade->current_sl = $trade->isLong()
            ? round($trade->entry_price * (1.0 + ($bufferPct / 100.0)), 6)
            : round($trade->entry_price * (1.0 - ($bufferPct / 100.0)), 6);

        $trade->be_locked = true;
        if ($trade->stage === 'ENTRY') {
            $trade->stage = 'BE_LOCKED';
        }
        $trade->save();

        return response()->json(['success' => true, 'message' => "Stop Loss moved to Breakeven for {$trade->symbol}."]);
    }

    /**
     * Manual market close of an open position.
     */
    public function closePosition(Request $request): JsonResponse
    {
        $tradeId = $request->input('trade_id');
        $trade = Trade::findOrFail($tradeId);

        if (! $trade->isOpen()) {
            return response()->json(['success' => false, 'message' => 'Trade is not open.'], 400);
        }

        $markPrice = $this->client->getMarkPrice($trade->symbol);
        $res = $this->tradeManager->closeTrade($trade, $markPrice, 'MANUAL_CLOSE');

        return response()->json(['success' => true, 'message' => $res['message']]);
    }

    /**
     * Toggle emergency kill switch.
     */
    public function toggleKillSwitch(Request $request): JsonResponse
    {
        $mode = $request->input('mode', config('trading.mode', 'live'));
        $account = TradingAccount::getForMode($mode);

        $account->kill_switch = ! $account->kill_switch;
        $account->save();

        if ($account->kill_switch) {
            // Liquidate open positions
            $openTrades = Trade::where('mode', $mode)->where('status', 'OPEN')->get();
            foreach ($openTrades as $trade) {
                try {
                    $markPrice = $this->client->getMarkPrice($trade->symbol);
                    $this->tradeManager->closeTrade($trade, $markPrice, 'KILL_SWITCH');
                } catch (\Exception) {
                    $this->tradeManager->closeTrade($trade, $trade->entry_price, 'KILL_SWITCH');
                }
            }
        }

        return response()->json([
            'success' => true,
            'kill_switch' => $account->kill_switch,
            'message' => $account->kill_switch ? 'Emergency Kill Switch ACTIVATED! All trades closed.' : 'Kill Switch deactivated. Trading resumed.',
        ]);
    }

    /**
     * Interactive Backtesting API.
     */
    public function runBacktest(Request $request): JsonResponse
    {
        $symbol = strtoupper($request->input('symbol', 'SOLUSDT'));
        $interval = $request->input('interval', '15m');
        $limit = min(1000, (int) $request->input('limit', 500));
        $balance = (float) $request->input('balance', 5.0);

        try {
            $results = $this->backtestingEngine->run($symbol, $interval, $limit, $balance);

            return response()->json(['success' => true, 'data' => $results]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Toggle automated trading state (Admin only).
     */
    public function toggleAutoTrading(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only administrators can start or stop automated trading.',
            ], 403);
        }

        $mode = $request->input('mode', config('trading.mode', 'live'));

        if ($mode === 'live' && ! config('trading.allow_live_trading', false)) {
            return response()->json([
                'success' => false,
                'message' => 'Live auto-trading is disabled in local development to prevent dual-instance collisions with the live server. Please switch to Paper mode.',
            ], 422);
        }

        $account = TradingAccount::getForMode($mode);

        if ($account->is_running) {
            $daemonResult = $this->daemonManager->stop($mode);
            $stateMsg = 'Auto-Trading PAUSED. Autonomous orders stopped.';
        } else {
            $daemonResult = $this->daemonManager->start($mode);
            $stateMsg = 'Auto-Trading STARTED. 24/7 Autonomous background daemon active.';
        }

        $account->refresh();

        return response()->json([
            'success' => true,
            'is_running' => (bool) $account->is_running,
            'can_trade' => $account->canTrade(),
            'message' => $stateMsg,
            'daemon' => $daemonResult,
        ]);
    }

    /**
     * Reset circuit breaker cooldown and immediately resume auto-trading.
     */
    public function resumeCooldown(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only administrators can reset the cooldown.',
            ], 403);
        }

        $mode = $request->input('mode', session('trading_mode', 'paper'));
        if (! in_array($mode, ['paper', 'live'], true)) {
            $mode = 'paper';
        }

        $account = TradingAccount::getForMode($mode);
        $account->paused_until = null;
        $account->consecutive_losses = 0;
        $account->is_running = true;
        $account->save();

        $daemonResult = null;
        if (! ($mode === 'live' && ! config('trading.allow_live_trading', false))) {
            $daemonResult = $this->daemonManager->start($mode);
        }

        return response()->json([
            'success' => true,
            'message' => 'Circuit breaker cooldown reset successfully. Auto-trading resumed!',
            'mode' => $mode,
            'can_trade' => $account->canTrade(),
            'daemon' => $daemonResult,
        ]);
    }

    /**
     * Automated terminal tick (position management + scanning cycle).
     */
    public function autoTick(Request $request): JsonResponse
    {
        $mode = $request->input('mode', config('trading.mode', 'live'));
        $tickResult = $this->daemonManager->tickOnce($mode);

        return response()->json(array_merge(['success' => true], $tickResult));
    }

    /**
     * Get live status of the 24/7 trading daemon.
     */
    public function daemonStatus(Request $request): JsonResponse
    {
        $mode = $request->query('mode', config('trading.mode', 'live'));

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

        $mode = $request->input('mode', config('trading.mode', 'live'));

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

        $mode = $request->input('mode', config('trading.mode', 'live'));

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
        $mode = $request->query('mode', config('trading.mode', 'live'));

        $statsData = $this->stats($request)->getData(true);
        $positionsData = $this->positions($request)->getData(true);
        $signalsData = $this->signals($request)->getData(true);
        $historyData = $this->history($request)->getData(true);

        return response()->json([
            'success' => true,
            'mode' => $mode,
            'timestamp' => now()->toIso8601String(),
            'stats' => $statsData,
            'positions' => $positionsData,
            'signals' => array_slice($signalsData, 0, 10),
            'history' => array_slice($historyData, 0, 15),
            'daemon' => $statsData['daemon'] ?? null,
        ]);
    }

    /**
     * Synchronize actual wallet balance and live open positions from Binance exchange.
     */
    protected function syncLiveAccountAndPositions(TradingAccount $account, string $mode): bool
    {
        return $this->exchangeSync->syncLiveAccountAndPositions($account, $mode);
    }
}
