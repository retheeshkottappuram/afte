<?php

namespace App\Services\Trading;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Binance\BinanceFuturesClient;
use App\Services\Crypto\BinanceClient;
use App\Services\Crypto\SignalEngine;
use App\Services\Crypto\SignalRecorder;
use App\Services\Notifications\TelegramNotifier;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SignalAlgoTrader
{
    public function __construct(
        protected BinanceClient $binanceClient,
        protected SignalEngine $signalEngine,
        protected OrderExecutor $orderExecutor,
        protected DynamicTradeManager $tradeManager,
        protected TelegramNotifier $notifier
    ) {}

    /**
     * Execute a complete SignalAlgo PRO autonomous cycle on the target coin.
     *
     * @return array{status: string, message: string, symbol: string, action: string, trade_id: ?int}
     */
    public function runCycle(string $mode = 'paper'): array
    {
        $symbol = TradingTargetManager::getActiveCoin();
        $account = TradingAccount::getForMode($mode);

        if ($account->kill_switch) {
            return [
                'status' => 'halted',
                'message' => 'Kill Switch is active.',
                'symbol' => $symbol,
                'action' => 'NONE',
                'trade_id' => null,
            ];
        }

        // 1. Manage any existing open position for this target coin first
        $openTrade = Trade::where('mode', $mode)
            ->where('symbol', $symbol)
            ->where('status', 'OPEN')
            ->first();

        $currentPrice = 0.0;
        try {
            $currentPrice = (float) $this->binanceClient->tickerPrice($symbol);
        } catch (Throwable $e) {
            Log::debug("SignalAlgoTrader: Could not fetch live price for {$symbol}: {$e->getMessage()}");
        }

        // 2. Scan the SignalAlgo PRO chart (15m and 1h) for fresh printed signals
        $freshSignal = $this->detectSignalOnChart($symbol);
        if ($currentPrice <= 0.0 && $freshSignal !== null && isset($freshSignal['price'])) {
            $currentPrice = (float) $freshSignal['price'];
        }

        // 3. Reversal & Entry Decision Matrix
        if ($freshSignal !== null) {
            $signalDirection = $freshSignal['direction']; // 'LONG' or 'SHORT'
            $signalSide = $freshSignal['side'];           // 'BUY' or 'SELL'
            $markerTime = $freshSignal['marker_time'];
            $timeframe = $freshSignal['interval'];
            $dedupKey = "trading:signal_processed:{$symbol}:{$timeframe}:{$markerTime}:{$signalSide}";

            // CASE A: Reversal Exit
            // If we have an active open trade in the OPPOSITE direction, exit trade immediately on reversal!
            if ($openTrade !== null && $openTrade->side !== $signalDirection) {
                if (! Cache::has($dedupKey)) {
                    Cache::put($dedupKey, true, now()->addHours(6));

                    $exitPrice = $currentPrice > 0 ? $currentPrice : (float) $freshSignal['price'];
                    $closedTrade = $this->closeTradeOnReversal($openTrade, $exitPrice, $freshSignal);

                    // Immediately open the new reverse trade
                    $execResult = $this->executeChartEntry($symbol, $freshSignal, $mode);

                    return [
                        'status' => 'reversed',
                        'message' => "Reversed position on {$symbol} from {$openTrade->side} to {$signalDirection} ({$freshSignal['setup_label']}).",
                        'symbol' => $symbol,
                        'action' => "REVERSED_TO_{$signalDirection}",
                        'trade_id' => $execResult['trade']?->id ?? null,
                    ];
                }
            }

            // CASE B: New Trade Entry (No position open)
            if ($openTrade === null && $account->canTrade()) {
                if (! Cache::has($dedupKey)) {
                    Cache::put($dedupKey, true, now()->addHours(6));

                    $execResult = $this->executeChartEntry($symbol, $freshSignal, $mode);
                    if (($execResult['status'] ?? '') === 'opened') {
                        return [
                            'status' => 'opened',
                            'message' => "Opened {$signalDirection} on {$symbol} from {$timeframe} SignalAlgo PRO chart.",
                            'symbol' => $symbol,
                            'action' => "OPEN_{$signalDirection}",
                            'trade_id' => $execResult['trade']?->id ?? null,
                        ];
                    }
                }
            }

            // CASE C: Same Direction Signal (Position already running)
            if ($openTrade !== null && $openTrade->side === $signalDirection) {
                // If the fresh signal suggests a more defensive SL, ratchet it
                $markerSl = (float) $freshSignal['initial_sl'];
                if ($openTrade->isLong() && $markerSl > $openTrade->current_sl) {
                    $openTrade->current_sl = $markerSl;
                    $openTrade->save();
                } elseif ($openTrade->isShort() && $markerSl < $openTrade->current_sl) {
                    $openTrade->current_sl = $markerSl;
                    $openTrade->save();
                }
            }
        }

        // 4. Continue active trend following & trailing stop management for existing position
        if ($openTrade !== null) {
            $manageRes = $this->manageTrendPosition($openTrade, $currentPrice);

            return [
                'status' => $manageRes['status'],
                'message' => $manageRes['message'],
                'symbol' => $symbol,
                'action' => 'MANAGE',
                'trade_id' => $openTrade->id,
            ];
        }

        return [
            'status' => 'monitoring',
            'message' => "Monitoring {$symbol} on 15m & 1h SignalAlgo PRO chart. No active position.",
            'symbol' => $symbol,
            'action' => 'MONITORING',
            'trade_id' => null,
        ];
    }

    /**
     * Detect fresh printed signals on the 15m and 1h SignalAlgo PRO chart.
     *
     * @return array<string, mixed>|null
     */
    public function detectSignalOnChart(string $symbol): ?array
    {
        $intervals = TradingTargetManager::getMonitoredTimeframes(); // ['15m', '1h']

        foreach ($intervals as $interval) {
            try {
                $htf1 = $interval === '15m' ? '1h' : '4h';
                $htf2 = $interval === '15m' ? '4h' : '1d';

                $multiKlines = $this->binanceClient->fetchMultiTimeframeKlines(
                    $symbol,
                    $interval,
                    $htf1,
                    $htf2,
                    320,
                    260
                );

                $baseCandles = $multiKlines['base'];
                $htf1Candles = $multiKlines['htf1'];
                $htf2Candles = $multiKlines['htf2'];

                if (empty($baseCandles['closes'] ?? [])) {
                    continue;
                }

                $btcCandles = ($symbol === 'BTCUSDT') ? $baseCandles : $this->binanceClient->klines('BTCUSDT', $interval, 320);

                // Run the exact evaluation engine that produces the chart markers
                $history = $this->signalEngine->evaluateHistory($baseCandles, $htf1Candles, $htf2Candles, 140, $btcCandles);
                $markers = $history['markers'] ?? [];

                if (empty($markers)) {
                    continue;
                }

                // Sync markers into database for chart UI and ledger
                SignalRecorder::syncMarkers(
                    $symbol,
                    $interval,
                    $markers,
                    $this->binanceClient->getMarketLabel(),
                    dispatchTelegram: false
                );

                $latestMarker = end($markers);
                $markerTime = (int) ($latestMarker['time'] ?? 0);
                $markerScore = (int) ($latestMarker['score'] ?? 0);
                $markerSide = strtoupper((string) ($latestMarker['side'] ?? 'BUY'));

                if ($markerScore < 80) {
                    continue;
                }

                // Check candle freshness: signal must be recent (within the last 1.5 candle periods)
                $candleAgeSeconds = now()->timestamp - $markerTime;
                $maxFreshnessSeconds = $interval === '15m' ? 1800 : 4500; // 30m for 15m, 75m for 1h

                if ($candleAgeSeconds >= -60 && $candleAgeSeconds <= $maxFreshnessSeconds) {
                    return [
                        'symbol' => $symbol,
                        'interval' => $interval,
                        'side' => $markerSide,
                        'direction' => $markerSide === 'BUY' ? 'LONG' : 'SHORT',
                        'score' => $markerScore,
                        'grade' => (string) ($latestMarker['grade'] ?? 'A'),
                        'price' => (float) ($latestMarker['entry'] ?? 0.0),
                        'initial_sl' => (float) ($latestMarker['sl'] ?? 0.0),
                        'tp1' => (float) ($latestMarker['tp1'] ?? 0.0),
                        'tp2' => (float) ($latestMarker['tp2'] ?? 0.0),
                        'tp3' => (float) ($latestMarker['tp3'] ?? 0.0),
                        'risk_reward' => (string) ($latestMarker['risk_reward'] ?? '1 : 2.8'),
                        'marker_time' => $markerTime,
                        'setup_type' => (string) ($latestMarker['setup_type'] ?? 'BREAKOUT_CONFIRMED'),
                        'setup_label' => (string) ($latestMarker['setup_label'] ?? 'SIGNALALGO PRO SIGNAL'),
                        'indicators' => [
                            'rsi' => $latestMarker['rsi'] ?? 50,
                            'adx' => $latestMarker['adx'] ?? 20,
                            'atr_pct' => $latestMarker['atr_pct'] ?? 1.5,
                            'volume_ratio' => $latestMarker['volume_ratio'] ?? 1.0,
                            'rs_ratio' => $latestMarker['rs_ratio'] ?? 1.0,
                        ],
                        'raw_marker' => $latestMarker,
                    ];
                }
            } catch (Throwable $e) {
                Log::debug("SignalAlgoTrader detection error on {$symbol} ({$interval}): {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Close an existing open position due to a chart reversal signal.
     */
    protected function closeTradeOnReversal(Trade $trade, float $exitPrice, array $newSignal): Trade
    {
        $closeSide = $trade->isLong() ? 'SELL' : 'BUY';

        // 1. Live Exchange Exit
        if ($trade->mode === 'live') {
            try {
                $client = app(BinanceFuturesClient::class)->forMode($trade->mode);
                if ($client->hasCredentials()) {
                    $client->cancelAllOpenOrders($trade->symbol);
                    $client->placeOrder([
                        'symbol' => $trade->symbol,
                        'side' => $closeSide,
                        'type' => 'MARKET',
                        'quantity' => $client->formatQuantity($trade->symbol, $trade->remaining_quantity),
                        'reduceOnly' => 'true',
                    ]);
                }
            } catch (Throwable $e) {
                Log::error("Failed to execute live reversal close on Binance for {$trade->symbol}: {$e->getMessage()}");
            }
        }

        // 2. Calculate Realized PnL
        $pnl = $trade->isLong()
            ? ($exitPrice - $trade->entry_price) * $trade->remaining_quantity
            : ($trade->entry_price - $exitPrice) * $trade->remaining_quantity;

        $pnlPercent = $trade->entry_price > 0 ? round(($pnl / ($trade->margin_used ?: 1.0)) * 100, 2) : 0.0;

        $trade->update([
            'status' => 'CLOSED',
            'stage' => 'CLOSED',
            'exit_price' => $exitPrice,
            'exit_reason' => 'REVERSAL_SIGNAL',
            'realized_pnl' => round($trade->realized_pnl + $pnl, 4),
            'pnl_percent' => $pnlPercent,
            'remaining_quantity' => 0,
            'closed_at' => Carbon::now(),
        ]);

        // 3. Update account balance
        $account = TradingAccount::getForMode($trade->mode);
        $account->balance = round($account->balance + $pnl, 4);
        $account->equity = $account->balance;
        if ($account->equity > $account->peak_equity) {
            $account->peak_equity = $account->equity;
        }
        $account->total_trades++;
        if ($pnl >= 0) {
            $account->winning_trades++;
            $account->consecutive_wins++;
            $account->consecutive_losses = 0;
        } else {
            $account->losing_trades++;
            $account->consecutive_losses++;
            $account->consecutive_wins = 0;
        }
        $account->save();

        // 4. Dispatch Telegram Notification for Reversal Exit
        $this->notifier->notifyReversalExit(
            trade: $trade,
            reverseSide: $newSignal['side'],
            exitPrice: $exitPrice,
            pnl: round($pnl, 4),
            newDirection: $newSignal['direction']
        );

        Log::info("Reversal exit executed on {$trade->symbol}: {$trade->side} closed at \${$exitPrice}, PnL: \${$pnl}. Flipping to {$newSignal['direction']}.");

        return $trade;
    }

    /**
     * Execute an entry order based on a verified SignalAlgo PRO chart signal.
     *
     * @return array{status: string, trade: ?Trade, message: string}
     */
    protected function executeChartEntry(string $symbol, array $signal, string $mode): array
    {
        $aiResult = [
            'approved' => true,
            'confidence' => $signal['score'],
            'regime' => 'SIGNALALGO_PRO',
            'reason' => "Verified SignalAlgo PRO™ {$signal['interval']} Chart Signal: {$signal['setup_label']} (Score: {$signal['score']}/100, Grade {$signal['grade']})",
        ];

        $execResult = $this->orderExecutor->executeSignal($signal, $aiResult, $mode, isManual: false);

        if (($execResult['status'] ?? '') === 'opened' && $execResult['trade'] !== null) {
            /** @var Trade $trade */
            $trade = $execResult['trade'];

            // Dispatch dedicated SignalAlgo PRO Telegram notification
            $this->notifier->notifySignalAlgoEntry($trade, $signal, $signal['interval']);
        }

        return $execResult;
    }

    /**
     * Dynamic Trend Following & Ratchet SL Management for an active open position.
     * Follows the coin continuously while bullish (Long) or bearish (Short), letting profits run.
     *
     * @return array{status: string, message: string}
     */
    public function manageTrendPosition(Trade $trade, float $currentPrice): array
    {
        if (! $trade->isOpen()) {
            return ['status' => 'ignored', 'message' => 'Trade is not open.'];
        }

        if ($currentPrice <= 0) {
            return ['status' => 'ignored', 'message' => 'Invalid price.'];
        }

        // Update high / low peaks
        if ($trade->highest_price === null || $currentPrice > $trade->highest_price) {
            $trade->highest_price = $currentPrice;
        }
        if ($trade->lowest_price === null || $currentPrice < $trade->lowest_price) {
            $trade->lowest_price = $currentPrice;
        }

        // 1. Check Hard Stop Loss Breach
        $isSlBreached = $trade->isLong()
            ? ($currentPrice <= $trade->current_sl)
            : ($currentPrice >= $trade->current_sl);

        if ($isSlBreached) {
            $reason = $trade->stage === 'TRAILING' ? 'TRAILING_STOP' : ($trade->be_locked ? 'BREAKEVEN_STOP' : 'STOP_LOSS');
            $closeRes = $this->tradeManager->closeTrade($trade, $currentPrice, $reason);

            return ['status' => 'closed', 'message' => "SL hit at \${$currentPrice} ({$reason})"];
        }

        // 2. Calculate Current Gain % from Entry
        $gainPct = $trade->isLong()
            ? (($currentPrice - $trade->entry_price) / $trade->entry_price) * 100.0
            : (($trade->entry_price - $currentPrice) / $trade->entry_price) * 100.0;

        $peakGainPct = $trade->isLong()
            ? ((($trade->highest_price ?? $currentPrice) - $trade->entry_price) / $trade->entry_price) * 100.0
            : (($trade->entry_price - ($trade->lowest_price ?? $currentPrice)) / $trade->entry_price) * 100.0;

        // 3. Profit-Following Stepped Ratchet SL:
        // As long as the coin is bullish / trending in our favor, permanently advance SL to lock profits:
        $updatedSl = false;

        // Tier 4: Peak Gain >= +4.00% (+40% ROE) -> Ratchet SL to +2.50% net profit
        if ($peakGainPct >= 4.00) {
            $targetSl = $trade->isLong() ? $trade->entry_price * 1.0250 : $trade->entry_price * 0.9750;
            $this->ratchetStopLoss($trade, $targetSl);
            $trade->stage = 'TRAILING';
            $updatedSl = true;
        }
        // Tier 3: Peak Gain >= +2.50% (+25% ROE) -> Ratchet SL to +1.50% net profit
        elseif ($peakGainPct >= 2.50) {
            $targetSl = $trade->isLong() ? $trade->entry_price * 1.0150 : $trade->entry_price * 0.9850;
            $this->ratchetStopLoss($trade, $targetSl);
            $trade->stage = 'TRAILING';
            $updatedSl = true;
        }
        // Tier 2: Peak Gain >= +1.50% (+15% ROE) -> Ratchet SL to +0.60% net profit
        elseif ($peakGainPct >= 1.50) {
            $targetSl = $trade->isLong() ? $trade->entry_price * 1.0060 : $trade->entry_price * 0.9940;
            $this->ratchetStopLoss($trade, $targetSl);
            $updatedSl = true;
        }
        // Tier 1: Gain >= +0.80% (+8% ROE) -> Lock Breakeven + 0.20% fee buffer (Risk-Free Trade)
        elseif ($gainPct >= 0.80 && ! $trade->be_locked) {
            $targetSl = $trade->isLong() ? $trade->entry_price * 1.0020 : $trade->entry_price * 0.9980;
            $trade->be_locked = true;
            $trade->stage = 'BE_LOCKED';
            $this->ratchetStopLoss($trade, $targetSl);
            $this->notifier->notifyBreakevenLocked($trade, $currentPrice);
            $updatedSl = true;
        }

        // 4. Dynamic Continuous Trailing Stop on Extended Winners (Gain >= +3.00%)
        // Trails 1.2% behind the peak high/low to ride massive trend moves
        if ($peakGainPct >= 3.00) {
            $trailBuffer = 0.012; // 1.2%
            if ($trade->isLong()) {
                $dynamicSl = round(($trade->highest_price ?? $currentPrice) * (1.0 - $trailBuffer), 6);
                $this->ratchetStopLoss($trade, $dynamicSl);
            } else {
                $dynamicSl = round(($trade->lowest_price ?? $currentPrice) * (1.0 + $trailBuffer), 6);
                $this->ratchetStopLoss($trade, $dynamicSl);
            }
        }

        $trade->save();

        return [
            'status' => 'managed',
            'message' => "{$trade->symbol} {$trade->side} active at \${$currentPrice} (Gain: +".round($gainPct, 2).'%, SL: $'.$trade->current_sl.')',
        ];
    }

    /**
     * Ratchet Stop Loss in profitable direction only (upwards for Long, downwards for Short).
     */
    protected function ratchetStopLoss(Trade $trade, float $newSl): void
    {
        $newSl = round($newSl, 6);

        if ($trade->isLong()) {
            if ($newSl > $trade->current_sl) {
                $trade->current_sl = $newSl;
                $this->updateExchangeStopOrder($trade, $newSl);
            }
        } else {
            if ($newSl < $trade->current_sl) {
                $trade->current_sl = $newSl;
                $this->updateExchangeStopOrder($trade, $newSl);
            }
        }
    }

    /**
     * Update native exchange stop loss order on Binance Futures if live mode.
     */
    protected function updateExchangeStopOrder(Trade $trade, float $stopPrice): void
    {
        if ($trade->mode !== 'live') {
            return;
        }

        try {
            $client = app(BinanceFuturesClient::class)->forMode($trade->mode);
            if ($client->hasCredentials()) {
                $closeSide = $trade->isLong() ? 'SELL' : 'BUY';
                $client->placeStopLoss($trade->symbol, $closeSide, $stopPrice, $trade->remaining_quantity, true);
            }
        } catch (Throwable $e) {
            Log::debug("Notice: Exchange SL update on Binance for {$trade->symbol}: {$e->getMessage()}");
        }
    }
}
