<?php

namespace App\Services\Trading;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Models\TradingSignal;
use App\Services\Binance\BinanceFuturesClient;
use App\Services\Notifications\TelegramNotifier;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class OrderExecutor
{
    public function __construct(
        protected BinanceFuturesClient $client,
        protected RiskManager $riskManager,
        protected TelegramNotifier $notifier
    ) {}

    /**
     * Execute an approved trading signal.
     *
     * @param  array<string, mixed>  $signal
     * @param  array{approved: bool, confidence: int, regime: string, reason: string}  $aiResult
     * @return array{status: string, trade: ?Trade, message: string}
     */
    public function executeSignal(array $signal, array $aiResult, string $mode = 'paper', bool $isManual = false): array
    {
        $account = TradingAccount::getForMode($mode);
        $symbol = TradingTargetManager::normalizeSymbol((string) $signal['symbol']);
        $score = (int) $signal['score'];

        // 0. Strict single-coin strategy gate: Block any coin other than the user's selected coin
        if (config('trading.single_coin_strict', true) && ! TradingTargetManager::isCoinAllowed($symbol)) {
            $activeCoin = TradingTargetManager::getActiveCoin();
            Log::warning("OrderExecutor: Blocked trade for {$symbol}. Active single-coin strategy is locked to {$activeCoin}.");

            return [
                'status' => 'rejected',
                'trade' => null,
                'message' => "Trading is strictly restricted to selected coin ({$activeCoin}). Trades on {$symbol} are not allowed.",
            ];
        }

        // 1. Verify Risk Engine permission
        $canOpen = $this->riskManager->canOpenTrade($account, $symbol, $score, $isManual);
        if (! $canOpen['allowed']) {
            return ['status' => 'rejected', 'trade' => null, 'message' => $canOpen['reason']];
        }

        // 2. Calculate safe position sizing compliant with Binance minNotional ($5)
        $sizing = $this->riskManager->calculatePositionSize(
            $account,
            $symbol,
            (float) $signal['price'],
            (float) $signal['initial_sl']
        );

        if (! $sizing['allowed']) {
            return ['status' => 'rejected', 'trade' => null, 'message' => $sizing['reason']];
        }

        $entryPrice = (float) $signal['price'];
        $quantity = (float) $sizing['quantity'];
        $margin = (float) $sizing['margin'];
        $leverage = (int) $sizing['leverage'];
        $side = $signal['direction'];
        $binanceOrderId = null;

        // 3. Live Execution
        if ($mode === 'live') {
            if (! config('trading.allow_live_trading', false)) {
                Log::warning("OrderExecutor: Blocked LIVE trade for {$symbol}. Live order execution is disabled in this environment (allow_live_trading is false).");

                return [
                    'status' => 'rejected',
                    'trade' => null,
                    'message' => 'Live order execution is disabled in this environment to prevent collisions with the production server.',
                ];
            }

            try {
                $client = $this->client->forMode($mode);
                try {
                    $client->setMarginType($symbol, 'ISOLATED');
                } catch (\Throwable) {
                    // Ignored if margin type cannot be adjusted or is already isolated
                }
                try {
                    $client->setLeverage($symbol, $leverage);
                } catch (\Throwable) {
                    // Ignored if leverage is already set
                }

                $binanceSide = $side === 'LONG' ? 'BUY' : 'SELL';
                $orderResult = $client->placeOrder([
                    'symbol' => $symbol,
                    'side' => $binanceSide,
                    'type' => 'MARKET',
                    'quantity' => $client->formatQuantity($symbol, $quantity),
                ]);

                $binanceOrderId = (string) ($orderResult['orderId'] ?? null);
                if (isset($orderResult['avgPrice']) && (float) $orderResult['avgPrice'] > 0) {
                    $entryPrice = (float) $orderResult['avgPrice'];
                }

                // Place Native Exchange Stop Loss & Take Profit on Binance via Algo Orders API
                $slAlgoId = null;
                $tpAlgoId = null;
                $closeSide = $side === 'LONG' ? 'SELL' : 'BUY';

                try {
                    $slRes = $client->placeStopLoss($symbol, $closeSide, (float) $signal['initial_sl'], $quantity, true);
                    $slAlgoId = (string) ($slRes['algoId'] ?? null);
                } catch (\Throwable $slEx) {
                    Log::warning("Failed to place native SL on Binance for {$symbol}: {$slEx->getMessage()}");
                }

                try {
                    $tp1Ratio = (float) config('trading.management.tp1_close_ratio', 0.33);
                    $tpQty = $client->formatQuantity($symbol, $quantity * $tp1Ratio);
                    if ($tpQty > 0) {
                        $tpRes = $client->placeTakeProfit($symbol, $closeSide, (float) $signal['tp1'], $tpQty, false);
                        $tpAlgoId = (string) ($tpRes['algoId'] ?? null);
                    }
                } catch (\Throwable $tpEx) {
                    Log::warning("Failed to place native TP on Binance for {$symbol}: {$tpEx->getMessage()}");
                }

                $client->clearAccountCache();
            } catch (\Exception $e) {
                Log::error("Failed to place live order on Binance: {$e->getMessage()}");

                return ['status' => 'error', 'trade' => null, 'message' => "Binance order failed: {$e->getMessage()}"];
            }
        }

        $setupTag = $aiResult['regime'] ?? ($signal['grade'] ?? 'STANDARD');
        $stopDistance = round(abs($entryPrice - (float) $signal['initial_sl']), 8);
        $btcTrend = $signal['indicators']['btc_trend'] ?? null;
        if (! $btcTrend) {
            try {
                $btcTrend = app(MarketEngine::class)->getBtcMarketTrend()['trend'] ?? 'NEUTRAL';
            } catch (\Throwable) {
                $btcTrend = 'NEUTRAL';
            }
        }

        // 4. Create Trade Record
        $trade = Trade::create([
            'symbol' => $symbol,
            'setup_tag' => $setupTag,
            'btc_trend_1h' => $btcTrend,
            'side' => $side,
            'mode' => $mode,
            'status' => 'OPEN',
            'stage' => 'ENTRY',
            'entry_price' => $entryPrice,
            'quantity' => $quantity,
            'remaining_quantity' => $quantity,
            'margin_used' => $margin,
            'leverage' => $leverage,
            'initial_sl' => (float) $signal['initial_sl'],
            'current_sl' => (float) $signal['initial_sl'],
            'stop_distance' => $stopDistance,
            'tp1_price' => (float) $signal['tp1'],
            'tp2_price' => (float) $signal['tp2'],
            'be_locked' => false,
            'tp1_hit' => false,
            'tp2_hit' => false,
            'binance_order_id' => $binanceOrderId,
            'meta' => [
                'score' => $score,
                'grade' => $signal['grade'],
                'ai_confidence' => $aiResult['confidence'],
                'ai_regime' => $aiResult['regime'],
                'ai_reason' => $aiResult['reason'],
                'atr' => $signal['indicators']['atr'] ?? null,
                'btc_trend_1h' => $btcTrend,
                'binance_sl_algo_id' => $slAlgoId ?? null,
                'binance_tp_algo_id' => $tpAlgoId ?? null,
            ],
            'opened_at' => Carbon::now(),
        ]);

        // 5. Update or link TradingSignal
        TradingSignal::create([
            'symbol' => $symbol,
            'direction' => $side,
            'score' => $score,
            'grade' => $signal['grade'],
            'price' => $entryPrice,
            'timeframe' => config('trading.scanner.base_interval', '15m'),
            'indicators' => $signal['indicators'],
            'ai_status' => 'APPROVED',
            'ai_confidence' => $aiResult['confidence'],
            'ai_regime' => $aiResult['regime'],
            'ai_reason' => $aiResult['reason'],
            'executed' => true,
            'trade_id' => $trade->id,
        ]);

        // 6. Send notification
        $this->notifier->notifyTradeOpened($trade, $score, $aiResult['reason']);

        return [
            'status' => 'opened',
            'trade' => $trade,
            'message' => "{$side} trade on {$symbol} opened successfully. Amount Added: \${$margin} USDT margin ({$leverage}x leverage, \${$sizing['notional']} position size).",
        ];
    }
}
