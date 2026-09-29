<?php

namespace App\Services\Trading;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Binance\BinanceFuturesClient;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExchangePositionSync
{
    public function __construct(
        protected BinanceFuturesClient $client,
        protected RiskManager $riskManager
    ) {}

    /**
     * Synchronize actual wallet balance and live open positions from Binance exchange.
     * Reconciles DB trades with real Binance positions and auto-closes trades that closed on Binance.
     */
    public function syncLiveAccountAndPositions(TradingAccount $account, string $mode): bool
    {
        if (! in_array($mode, ['live', 'testnet'], true)) {
            return false;
        }

        $client = $this->client->forMode($mode);
        if (! $client->hasCredentials()) {
            return false;
        }

        try {
            // 1. Fetch real wallet balances from Binance
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
                    $realizedPnl = 0.0;
                    $feePaid = 0.0;
                    $exitReason = 'EXCHANGE_CLOSED';

                    try {
                        $userTrades = $client->getUserTrades($dbTrade->symbol, 5);
                        if (! empty($userTrades)) {
                            $latestFill = end($userTrades);
                            $closeMark = (float) ($latestFill['price'] ?? $closeMark);
                            $realizedPnl = (float) ($latestFill['realizedPnl'] ?? 0.0);
                            $feePaid = (float) ($latestFill['commission'] ?? 0.0);
                        } else {
                            $closeMark = $client->getMarkPrice($dbTrade->symbol);
                            $realizedPnl = $dbTrade->calculateUnrealizedPnl($closeMark);
                        }
                    } catch (Throwable) {
                        try {
                            $closeMark = $client->getMarkPrice($dbTrade->symbol);
                            $realizedPnl = $dbTrade->calculateUnrealizedPnl($closeMark);
                        } catch (Throwable) {
                            // ignore
                        }
                    }

                    $dbTrade->status = 'CLOSED';
                    $dbTrade->exit_price = $closeMark;
                    $dbTrade->exit_reason = $exitReason;
                    $dbTrade->realized_pnl = $realizedPnl;
                    $dbTrade->fee_paid = round(($dbTrade->fee_paid ?? 0) + $feePaid, 6);
                    $dbTrade->closed_at = now();
                    $dbTrade->save();

                    $this->riskManager->handleTradeClosed($account, $dbTrade);
                    Log::info("[ExchangePositionSync] Closed position {$dbTrade->symbol} reconciled from Binance exchange (Exit: {$exitReason}, PnL: \${$realizedPnl})");
                }
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('Exchange position sync error: '.$e->getMessage());

            return false;
        }
    }
}
