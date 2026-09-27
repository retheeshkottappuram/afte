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
use App\Services\Trading\MarketEngine;
use App\Services\Trading\OrderExecutor;
use App\Services\Trading\RiskManager;
use App\Services\Trading\SignalEngine;
use App\Services\Trading\TradingDaemonManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
        protected TradingDaemonManager $daemonManager
    ) {}

    /**
     * Display main cyber trading terminal.
     */
    public function index(Request $request): View
    {
        $mode = $request->query('mode')
            ?? $request->cookie('afte_trading_mode')
            ?? session('trading_mode')
            ?? config('trading.mode', 'paper');

        if (! in_array($mode, ['paper', 'testnet', 'shadow', 'live'], true)) {
            $mode = 'paper';
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
        $mode = $request->query('mode')
            ?? session('trading_mode')
            ?? $request->cookie('afte_trading_mode')
            ?? config('trading.mode', 'paper');

        if (! in_array($mode, ['paper', 'testnet', 'shadow', 'live'], true)) {
            $mode = 'paper';
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
        $mode = $request->query('mode', config('trading.mode', 'paper'));
        $client = $this->client->forMode($mode);
        $account = TradingAccount::getForMode($mode);

        // Sync actual open positions directly from Binance
        $this->syncLiveAccountAndPositions($account, $mode);

        // Fetch open exchange-side algo orders (Stop Loss / Take Profit)
        $openAlgoMap = [];
        if (in_array($mode, ['live', 'testnet'], true) && $client->hasCredentials()) {
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
        $mode = $request->query('mode', config('trading.mode', 'paper'));

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
        $mode = $request->query('mode', config('trading.mode', 'paper'));

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
            $symbols = $this->marketEngine->getScannableSymbols();
            $scanSymbols = array_slice($symbols, 0, $limit);

            $results = [];

            foreach ($scanSymbols as $sym) {
                try {
                    $klines = $this->marketEngine->getMultiTimeframeKlines($sym);
                    $eval = $this->signalEngine->evaluate($sym, $klines['base'], $klines['htf1'], $klines['htf2']);

                    if ($eval !== null) {
                        $ai = $this->validator->validate($eval, $klines['base']);

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
                'opportunities' => $results,
                'cached_at' => now()->toIso8601String(),
            ];
        });

        return response()->json($data);
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
        $mode = $request->input('mode', config('trading.mode', 'paper'));
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

        $mode = $request->input('mode', config('trading.mode', 'paper'));
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
     * Automated terminal tick (position management + scanning cycle).
     */
    public function autoTick(Request $request): JsonResponse
    {
        $mode = $request->input('mode', config('trading.mode', 'paper'));
        $tickResult = $this->daemonManager->tickOnce($mode);

        return response()->json(array_merge(['success' => true], $tickResult));
    }

    /**
     * Get live status of the 24/7 trading daemon.
     */
    public function daemonStatus(Request $request): JsonResponse
    {
        $mode = $request->query('mode', config('trading.mode', 'paper'));

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

        $mode = $request->input('mode', config('trading.mode', 'paper'));

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

        $mode = $request->input('mode', config('trading.mode', 'paper'));

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
        $mode = $request->query('mode', config('trading.mode', 'paper'));

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
        if (! in_array($mode, ['live', 'testnet'], true)) {
            return false;
        }

        $client = $this->client->forMode($mode);
        if (! $client->hasCredentials()) {
            return false;
        }

        try {
            // 1. Fetch real wallet balances
            $balances = $client->getBalance();
            $usdtBalance = null;
            $crossUnPnl = 0.0;

            foreach ($balances as $b) {
                if (($b['asset'] ?? '') === 'USDT') {
                    $usdtBalance = (float) ($b['balance'] ?? $b['crossWalletBalance'] ?? 0);
                    $crossUnPnl = (float) ($b['crossUnPnl'] ?? 0);
                    break;
                }
            }

            if ($usdtBalance !== null) {
                $account->balance = round($usdtBalance, 4);
                $account->equity = round($usdtBalance + $crossUnPnl, 4);
                $account->save();
            }

            // 2. Fetch real exchange positions
            $exchangePositions = $client->getPositions();
            $liveSymbolsFound = [];

            foreach ($exchangePositions as $p) {
                $amt = (float) ($p['positionAmt'] ?? 0);
                if ($amt == 0.0) {
                    continue;
                }

                $symbol = $p['symbol'] ?? '';
                if (empty($symbol)) {
                    continue;
                }

                $liveSymbolsFound[] = $symbol;
                $side = $amt > 0 ? 'LONG' : 'SHORT';
                $absQty = abs($amt);
                $entryPrice = (float) ($p['entryPrice'] ?? 0);
                $markPrice = (float) ($p['markPrice'] ?? $entryPrice);
                $leverage = (int) ($p['leverage'] ?? 10);
                if ($leverage <= 0) {
                    $leverage = 10;
                }
                $notional = $absQty * $entryPrice;
                $marginUsed = $leverage > 0 ? round($notional / $leverage, 4) : round($notional, 4);

                $existingTrade = Trade::where('mode', $mode)
                    ->where('symbol', $symbol)
                    ->where('status', 'OPEN')
                    ->first();

                if ($existingTrade) {
                    $existingTrade->remaining_quantity = $absQty;
                    $existingTrade->entry_price = $entryPrice;
                    $existingTrade->leverage = $leverage;
                    $existingTrade->margin_used = $marginUsed;
                    $existingTrade->save();
                } else {
                    $slPct = 0.02;
                    $tp1Pct = (float) config('trading.management.tp1_pct', 1.8) / 100.0;
                    $tp2Pct = (float) config('trading.management.tp2_pct', 3.6) / 100.0;

                    $initialSl = $side === 'LONG'
                        ? round($entryPrice * (1.0 - $slPct), 6)
                        : round($entryPrice * (1.0 + $slPct), 6);
                    $tp1 = $side === 'LONG'
                        ? round($entryPrice * (1.0 + $tp1Pct), 6)
                        : round($entryPrice * (1.0 - $tp1Pct), 6);
                    $tp2 = $side === 'LONG'
                        ? round($entryPrice * (1.0 + $tp2Pct), 6)
                        : round($entryPrice * (1.0 - $tp2Pct), 6);

                    Trade::create([
                        'symbol' => $symbol,
                        'side' => $side,
                        'mode' => $mode,
                        'status' => 'OPEN',
                        'stage' => 'ENTRY',
                        'entry_price' => $entryPrice,
                        'quantity' => $absQty,
                        'remaining_quantity' => $absQty,
                        'margin_used' => $marginUsed,
                        'leverage' => $leverage,
                        'initial_sl' => $initialSl,
                        'current_sl' => $initialSl,
                        'tp1_price' => $tp1,
                        'tp2_price' => $tp2,
                        'be_locked' => false,
                        'tp1_hit' => false,
                        'tp2_hit' => false,
                        'realized_pnl' => 0,
                        'pnl_percent' => 0,
                        'fee_paid' => 0,
                        'opened_at' => now(),
                    ]);
                }
            }

            // 3. Close trades in DB that are no longer active on Binance
            $dbOpenTrades = Trade::where('mode', $mode)
                ->where('status', 'OPEN')
                ->get();

            foreach ($dbOpenTrades as $dbTrade) {
                if (! in_array($dbTrade->symbol, $liveSymbolsFound, true)) {
                    $closeMark = $dbTrade->entry_price;
                    try {
                        $closeMark = $client->getMarkPrice($dbTrade->symbol);
                    } catch (\Throwable) {
                        // ignore
                    }
                    $pnl = $dbTrade->calculateUnrealizedPnl($closeMark);
                    $dbTrade->status = 'CLOSED';
                    $dbTrade->exit_price = $closeMark;
                    $dbTrade->exit_reason = 'EXCHANGE_CLOSED';
                    $dbTrade->realized_pnl = $pnl;
                    $dbTrade->closed_at = now();
                    $dbTrade->save();
                }
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Live sync error: '.$e->getMessage());

            return false;
        }
    }
}
