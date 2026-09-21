<?php

namespace App\Services\Trading;

use App\Services\AI\SignalValidator;
use App\Services\Binance\BinanceFuturesClient;

class BacktestingEngine
{
    public function __construct(
        protected BinanceFuturesClient $client,
        protected SignalEngine $signalEngine,
        protected SignalValidator $validator
    ) {}

    /**
     * Run backtest on historical Binance data.
     *
     * @return array{
     *     symbol: string,
     *     interval: string,
     *     total_candles: int,
     *     initial_balance: float,
     *     final_balance: float,
     *     net_profit: float,
     *     net_profit_pct: float,
     *     total_trades: int,
     *     wins: int,
     *     losses: int,
     *     win_rate: float,
     *     profit_factor: float,
     *     max_drawdown_pct: float,
     *     trades: array<int, array<string, mixed>>
     * }
     */
    public function run(
        string $symbol = 'SOLUSDT',
        string $interval = '15m',
        int $limit = 600,
        float $initialBalance = 5.0
    ): array {
        $candles = $this->client->klines($symbol, $interval, $limit);
        $count = count($candles['closes']);

        $balance = $initialBalance;
        $peakBalance = $initialBalance;
        $maxDrawdown = 0.0;
        $trades = [];
        $grossProfit = 0.0;
        $grossLoss = 0.0;

        $inTrade = false;
        $activeTrade = null;

        // Iterate historically bar by bar (starting after warm-up bars for 200 EMA)
        $startIdx = min(50, (int) ($count * 0.2));

        for ($idx = $startIdx; $idx < $count - 1; $idx++) {
            $slice = [
                'opens' => array_slice($candles['opens'], 0, $idx + 1),
                'highs' => array_slice($candles['highs'], 0, $idx + 1),
                'lows' => array_slice($candles['lows'], 0, $idx + 1),
                'closes' => array_slice($candles['closes'], 0, $idx + 1),
                'volumes' => array_slice($candles['volumes'], 0, $idx + 1),
                'closeTimes' => array_slice($candles['closeTimes'], 0, $idx + 1),
            ];

            $currentHigh = $candles['highs'][$idx];
            $currentLow = $candles['lows'][$idx];
            $currentClose = $candles['closes'][$idx];

            // 1. If currently in a trade, simulate dynamic trade management
            if ($inTrade && $activeTrade !== null) {
                $isLong = $activeTrade['side'] === 'LONG';

                // Check Stop Loss
                $slHit = $isLong
                    ? ($currentLow <= $activeTrade['current_sl'])
                    : ($currentHigh >= $activeTrade['current_sl']);

                if ($slHit) {
                    $exitPrice = $activeTrade['current_sl'];
                    $pnl = $isLong
                        ? ($exitPrice - $activeTrade['entry_price']) * $activeTrade['remaining_qty']
                        : ($activeTrade['entry_price'] - $exitPrice) * $activeTrade['remaining_qty'];

                    $totalPnl = round($activeTrade['realized_pnl'] + $pnl, 4);
                    $fee = round($activeTrade['notional'] * 0.0005 * 2, 4);
                    $netPnl = round($totalPnl - $fee, 4);

                    $balance += $netPnl;
                    if ($balance > $peakBalance) {
                        $peakBalance = $balance;
                    }
                    $dd = (($peakBalance - $balance) / $peakBalance) * 100.0;
                    if ($dd > $maxDrawdown) {
                        $maxDrawdown = $dd;
                    }

                    if ($netPnl >= 0) {
                        $grossProfit += $netPnl;
                    } else {
                        $grossLoss += abs($netPnl);
                    }

                    $trades[] = [
                        'side' => $activeTrade['side'],
                        'entry_price' => $activeTrade['entry_price'],
                        'exit_price' => $exitPrice,
                        'net_pnl' => $netPnl,
                        'pnl_pct' => round(($netPnl / max(0.1, $activeTrade['margin'])) * 100, 2),
                        'exit_reason' => $activeTrade['be_locked'] ? 'BREAKEVEN' : 'STOP_LOSS',
                        'time' => date('Y-m-d H:i', (int) ($candles['closeTimes'][$idx] / 1000)),
                    ];

                    $inTrade = false;
                    $activeTrade = null;

                    continue;
                }

                // Check Breakeven Protection Trigger (+1.0%)
                $gainPct = $isLong
                    ? (($currentHigh - $activeTrade['entry_price']) / $activeTrade['entry_price']) * 100.0
                    : (($activeTrade['entry_price'] - $currentLow) / $activeTrade['entry_price']) * 100.0;

                if (! $activeTrade['be_locked'] && $gainPct >= 1.0) {
                    $activeTrade['be_locked'] = true;
                    $activeTrade['current_sl'] = $isLong
                        ? $activeTrade['entry_price'] * 1.0012
                        : $activeTrade['entry_price'] * 0.9988;
                }

                // Check TP1 (+2.0%)
                $tp1Hit = $isLong
                    ? ($currentHigh >= $activeTrade['tp1_price'])
                    : ($currentLow <= $activeTrade['tp1_price']);

                if (! $activeTrade['tp1_hit'] && $tp1Hit) {
                    $closeQty = $activeTrade['total_qty'] * 0.33;
                    $partialPnl = $isLong
                        ? ($activeTrade['tp1_price'] - $activeTrade['entry_price']) * $closeQty
                        : ($activeTrade['entry_price'] - $activeTrade['tp1_price']) * $closeQty;

                    $activeTrade['realized_pnl'] += $partialPnl;
                    $activeTrade['remaining_qty'] -= $closeQty;
                    $activeTrade['tp1_hit'] = true;
                    $activeTrade['be_locked'] = true;
                }

                // Check TP2 (+4.0%)
                $tp2Hit = $isLong
                    ? ($currentHigh >= $activeTrade['tp2_price'])
                    : ($currentLow <= $activeTrade['tp2_price']);

                if ($activeTrade['tp1_hit'] && ! $activeTrade['tp2_hit'] && $tp2Hit) {
                    $closeQty = $activeTrade['total_qty'] * 0.33;
                    $partialPnl = $isLong
                        ? ($activeTrade['tp2_price'] - $activeTrade['entry_price']) * $closeQty
                        : ($activeTrade['entry_price'] - $activeTrade['tp2_price']) * $closeQty;

                    $activeTrade['realized_pnl'] += $partialPnl;
                    $activeTrade['remaining_qty'] -= $closeQty;
                    $activeTrade['tp2_hit'] = true;
                    // Move SL to TP1
                    $activeTrade['current_sl'] = $activeTrade['tp1_price'];
                }

                continue;
            }

            // 2. Scan for new signal
            $eval = $this->signalEngine->evaluate($symbol, $slice);
            if ($eval === null || $eval['score'] < 82) {
                continue;
            }

            $ai = $this->validator->validateHeuristically($eval, $slice);
            if (! $ai['approved']) {
                continue;
            }

            // Size position
            $leverage = 10;
            $notional = max(5.2, $balance * 5.0); // 5x-10x leverage simulation
            $margin = $notional / $leverage;
            $qty = $notional / $eval['price'];

            $activeTrade = [
                'side' => $eval['direction'],
                'entry_price' => $eval['price'],
                'total_qty' => $qty,
                'remaining_qty' => $qty,
                'margin' => $margin,
                'notional' => $notional,
                'initial_sl' => $eval['initial_sl'],
                'current_sl' => $eval['initial_sl'],
                'tp1_price' => $eval['tp1'],
                'tp2_price' => $eval['tp2'],
                'be_locked' => false,
                'tp1_hit' => false,
                'tp2_hit' => false,
                'realized_pnl' => 0.0,
            ];
            $inTrade = true;
        }

        $wins = count(array_filter($trades, fn ($t) => $t['net_pnl'] > 0));
        $losses = count(array_filter($trades, fn ($t) => $t['net_pnl'] <= 0));
        $totalTrades = count($trades);
        $winRate = $totalTrades > 0 ? round(($wins / $totalTrades) * 100, 2) : 0.0;
        $profitFactor = $grossLoss > 0 ? round($grossProfit / $grossLoss, 2) : ($grossProfit > 0 ? 99.9 : 0.0);
        $netProfit = round($balance - $initialBalance, 4);
        $netProfitPct = round(($netProfit / $initialBalance) * 100, 2);

        return [
            'symbol' => $symbol,
            'interval' => $interval,
            'total_candles' => $count,
            'initial_balance' => $initialBalance,
            'final_balance' => round($balance, 2),
            'net_profit' => $netProfit,
            'net_profit_pct' => $netProfitPct,
            'total_trades' => $totalTrades,
            'wins' => $wins,
            'losses' => $losses,
            'win_rate' => $winRate,
            'profit_factor' => $profitFactor,
            'max_drawdown_pct' => round($maxDrawdown, 2),
            'trades' => array_slice(array_reverse($trades), 0, 20),
        ];
    }
}
