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
        protected BacktestingEngine $backtestingEngine
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

        return view('dashboard.index', [
            'mode' => $mode,
            'account' => $account,
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

        $totalUnrealizedPnl = 0.0;
        $liveSynced = false;

        // In live or testnet mode with valid API keys, sync real balance & equity from Binance
        if (in_array($mode, ['live', 'testnet'], true) && $client->hasCredentials()) {
            try {
                $balances = $client->getBalance();
                foreach ($balances as $b) {
                    if (($b['asset'] ?? '') === 'USDT') {
                        $liveBalance = (float) ($b['balance'] ?? $b['crossWalletBalance'] ?? $account->balance);
                        $totalUnrealizedPnl = (float) ($b['crossUnPnl'] ?? 0.0);

                        $account->balance = $liveBalance;
                        $account->equity = round($liveBalance + $totalUnrealizedPnl, 4);
                        if ($account->initial_balance <= 0 || $account->initial_balance === 5.0) {
                            $account->initial_balance = $liveBalance;
                        }
                        $account->save();
                        $liveSynced = true;
                        break;
                    }
                }
            } catch (\Exception) {
                // If API/network error, fallback to local database values
            }
        }

        $openPositions = Trade::where('mode', $mode)
            ->where('status', 'OPEN')
            ->get();

        if (! $liveSynced) {
            foreach ($openPositions as $pos) {
                try {
                    $markPrice = $client->getMarkPrice($pos->symbol);
                    $totalUnrealizedPnl += $pos->calculateUnrealizedPnl($markPrice);
                } catch (\Exception) {
                    // Ignore individual mark price failure
                }
            }
            $currentEquity = round($account->balance + $totalUnrealizedPnl, 4);
        } else {
            $currentEquity = round($account->equity, 4);
        }

        $stageInfo = $this->riskManager->getCompoundingStage($account);

        return response()->json([
            'mode' => $mode,
            'balance' => round($account->balance, 2),
            'equity' => round($currentEquity, 2),
            'unrealized_pnl' => round($totalUnrealizedPnl, 2),
            'initial_balance' => round($account->initial_balance, 2),
            'target_balance' => (float) config('trading.target_capital', 500.0),
            'progress_pct' => $account->target_progress,
            'win_rate' => $account->win_rate,
            'total_trades' => $account->total_trades,
            'winning_trades' => $account->winning_trades,
            'losing_trades' => $account->losing_trades,
            'consecutive_losses' => $account->consecutive_losses,
            'open_positions_count' => $openPositions->count(),
            'kill_switch' => $account->kill_switch,
            'is_running' => (bool) $account->is_running,
            'paused_until' => $account->paused_until?->toIso8601String(),
            'can_trade' => $account->canTrade(),
            'stage' => $stageInfo['stage'],
            'max_positions' => $stageInfo['max_positions'],
            'default_leverage' => $stageInfo['default_leverage'],
            'live_synced' => $liveSynced,
        ]);
    }

    /**
     * Open positions list with real-time mark prices.
     */
    public function positions(Request $request): JsonResponse
    {
        $mode = $request->query('mode', config('trading.mode', 'paper'));
        $client = $this->client->forMode($mode);

        // For live or testnet mode, sync actual open positions directly from Binance
        if (in_array($mode, ['live', 'testnet'], true) && $client->hasCredentials()) {
            try {
                $binancePositions = array_filter(
                    $client->getPositions(),
                    fn ($p) => (float) ($p['positionAmt'] ?? 0) != 0
                );

                $liveSymbols = [];
                foreach ($binancePositions as $bp) {
                    $sym = $bp['symbol'];
                    $liveSymbols[] = $sym;
                    $amt = (float) $bp['positionAmt'];
                    $side = $amt > 0 ? 'LONG' : 'SHORT';
                    $entryPrice = (float) $bp['entryPrice'];
                    $leverage = (int) ($bp['leverage'] ?? 10);
                    $qty = abs($amt);
                    $notional = (float) ($bp['notional'] ?? ($qty * $entryPrice));
                    $margin = $leverage > 0 ? round($notional / $leverage, 4) : $notional;

                    $trade = Trade::where('mode', $mode)
                        ->where('symbol', $sym)
                        ->where('status', 'OPEN')
                        ->first();

                    if (! $trade) {
                        Trade::create([
                            'symbol' => $sym,
                            'side' => $side,
                            'mode' => $mode,
                            'status' => 'OPEN',
                            'stage' => 'ENTRY',
                            'entry_price' => $entryPrice,
                            'quantity' => $qty,
                            'remaining_quantity' => $qty,
                            'margin_used' => $margin,
                            'leverage' => $leverage,
                            'initial_sl' => $side === 'LONG' ? round($entryPrice * 0.98, 6) : round($entryPrice * 1.02, 6),
                            'current_sl' => $side === 'LONG' ? round($entryPrice * 0.98, 6) : round($entryPrice * 1.02, 6),
                            'tp1_price' => $side === 'LONG' ? round($entryPrice * 1.02, 6) : round($entryPrice * 0.98, 6),
                            'tp2_price' => $side === 'LONG' ? round($entryPrice * 1.04, 6) : round($entryPrice * 0.96, 6),
                            'be_locked' => false,
                            'tp1_hit' => false,
                            'tp2_hit' => false,
                            'opened_at' => now(),
                        ]);
                    } else {
                        $trade->remaining_quantity = $qty;
                        $trade->save();
                    }
                }

                // If a position was closed directly on Binance, reflect it locally (grace period of 60s for new trades)
                $localOpen = Trade::where('mode', $mode)->where('status', 'OPEN')->get();
                foreach ($localOpen as $localTrade) {
                    $isRecent = $localTrade->created_at && $localTrade->created_at->diffInSeconds(now()) < 60;
                    if (! $isRecent && ! in_array($localTrade->symbol, $liveSymbols, true)) {
                        $localTrade->status = 'CLOSED';
                        $localTrade->closed_at = now();
                        $localTrade->exit_reason = 'EXCHANGE_OR_MANUAL_CLOSE';
                        $localTrade->save();
                    }
                }
            } catch (\Exception) {
                // If Binance API error, fallback to local trades
            }
        }

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

        $account->is_running = ! $account->is_running;
        $account->save();

        $stateMsg = $account->is_running
            ? 'Auto-Trading STARTED. Autonomous scanner and order execution active.'
            : 'Auto-Trading STOPPED. Automated orders paused.';

        return response()->json([
            'success' => true,
            'is_running' => $account->is_running,
            'can_trade' => $account->canTrade(),
            'message' => $stateMsg,
        ]);
    }

    /**
     * Automated terminal tick (position management + scanning cycle).
     */
    public function autoTick(Request $request): JsonResponse
    {
        $mode = $request->input('mode', config('trading.mode', 'paper'));
        $account = TradingAccount::getForMode($mode);

        // 1. Position management always protects existing trades
        $openTrades = Trade::where('mode', $mode)->where('status', 'OPEN')->get();
        $managedCount = 0;
        $closedTrades = [];

        foreach ($openTrades as $trade) {
            $res = $this->tradeManager->manageTrade($trade);
            $managedCount++;
            if ($res['status'] === 'closed') {
                $closedTrades[] = "{$trade->symbol} closed ({$res['message']})";
            }
        }

        // 2. If auto trading is active, scan and execute qualified setups
        $scannedCount = 0;
        $openedTrade = null;

        if ($account->canTrade()) {
            $symbols = $this->marketEngine->getScannableSymbols();
            $candidates = array_slice($symbols, 0, 10);

            foreach ($candidates as $sym) {
                $scannedCount++;
                try {
                    $klines = $this->marketEngine->getMultiTimeframeKlines($sym);
                    $eval = $this->signalEngine->evaluate($sym, $klines['base'], $klines['htf1'], $klines['htf2']);

                    if ($eval !== null && $eval['score'] >= 82) {
                        $ai = $this->validator->validate($eval, $klines['base']);

                        if ($ai['approved']) {
                            $execResult = $this->executor->executeSignal($eval, $ai, $mode);
                            if ($execResult['status'] === 'opened') {
                                $openedTrade = "{$sym} {$eval['direction']} opened!";
                                break;
                            }
                        }
                    }
                } catch (\Exception) {
                    // Ignore transient errors per symbol
                }
            }
        }

        return response()->json([
            'success' => true,
            'is_running' => (bool) $account->is_running,
            'managed_positions' => $managedCount,
            'closed_positions' => $closedTrades,
            'scanned_symbols' => $scannedCount,
            'opened_trade' => $openedTrade,
        ]);
    }
}
