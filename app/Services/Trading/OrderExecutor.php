<?php

namespace App\Services\Trading;

use App\Models\SystemLog;
use App\Models\Trade;
use App\Models\TradingAccount;
use App\Models\TradingSignal;
use App\Services\Binance\BinanceFuturesClient;
use App\Services\Notifications\TelegramNotifier;
use App\Services\Strategy\ExitPlan;
use App\Services\Strategy\StrategyEngine;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Opens positions. Live entries are only kept when an exchange-side stop-loss
 * is confirmed; otherwise the position is closed immediately.
 */
class OrderExecutor
{
    public function __construct(
        protected BinanceFuturesClient $client,
        protected RiskManager $riskManager,
        protected TelegramNotifier $notifier,
        protected ExchangeOrders $exchangeOrders
    ) {}

    /**
     * Execute a strategy signal in the given mode.
     *
     * @param  array<string, mixed>  $signal  keys: symbol, direction|side, price, initial_sl, setup, setup_label, interval, grade, score, indicators, ai_probability
     * @return array{status: string, trade: ?Trade, message: string}
     */
    public function executeSignal(array $signal, string $mode = 'paper', bool $isManual = false): array
    {
        $symbol = TradingTargetManager::normalizeSymbol((string) $signal['symbol']);
        $direction = $this->direction($signal);
        $isLong = $direction === 'LONG';
        $referencePrice = (float) ($signal['price'] ?? 0);
        $structuralSl = (float) ($signal['initial_sl'] ?? 0);

        if ($referencePrice <= 0 || $structuralSl <= 0 || ($isLong ? $structuralSl >= $referencePrice : $structuralSl <= $referencePrice)) {
            return $this->rejected("Signal for {$symbol} has an invalid entry/stop-loss.");
        }

        $slPct = abs($referencePrice - $structuralSl) / $referencePrice * 100;
        $minSlPct = (float) config('trading.strategy.min_sl_pct', 0.6);
        $maxSlPct = StrategyEngine::configuredMaxSlPct(isset($signal['setup']) ? (string) $signal['setup'] : null);
        if ($slPct > $maxSlPct + 0.0001) {
            return $this->rejected(sprintf('Stop-loss %.2f%% is wider than the %.2f%% limit. Trade skipped instead of widening risk.', $slPct, $maxSlPct));
        }
        if ($slPct < $minSlPct) {
            $structuralSl = $this->riskManager->calculateAssetProtectionStopLoss($direction, $referencePrice, $structuralSl, isset($signal['setup']) ? (string) $signal['setup'] : null);
        }

        $lock = Cache::lock("trade-entry:{$mode}:{$symbol}", 30);
        if (! $lock->get()) {
            return $this->rejected("Another process is already opening {$symbol}.");
        }

        try {
            $account = TradingAccount::getForMode($mode);
            $canOpen = $this->riskManager->canOpenTrade($account, $symbol, $direction, $isManual);
            if (! $canOpen['allowed']) {
                return $this->rejected($canOpen['reason']);
            }

            $sizing = $this->riskManager->calculatePositionSize($account, $symbol, $referencePrice, $structuralSl);
            if (! $sizing['allowed']) {
                return $this->rejected($sizing['reason']);
            }

            $riskDistance = abs($referencePrice - $structuralSl);

            return $mode === 'live'
                ? $this->openLive($signal, $symbol, $direction, $riskDistance, $sizing, $isManual)
                : $this->openPaper($signal, $symbol, $direction, $referencePrice, $riskDistance, $sizing, $mode, $isManual);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $signal
     * @param  array<string, mixed>  $sizing
     * @return array{status: string, trade: ?Trade, message: string}
     */
    protected function openPaper(array $signal, string $symbol, string $direction, float $referencePrice, float $riskDistance, array $sizing, string $mode, bool $isManual): array
    {
        $slippage = (float) config('trading.exits.paper_slippage', 0.0003);
        $fillPrice = $direction === 'LONG' ? $referencePrice * (1 + $slippage) : $referencePrice * (1 - $slippage);

        $trade = $this->recordTrade($signal, $symbol, $direction, $fillPrice, $riskDistance, $sizing, $mode, null, [], $isManual);

        return ['status' => 'opened', 'trade' => $trade, 'message' => $this->openedMessage($trade, $sizing)];
    }

    /**
     * @param  array<string, mixed>  $signal
     * @param  array<string, mixed>  $sizing
     * @return array{status: string, trade: ?Trade, message: string}
     */
    protected function openLive(array $signal, string $symbol, string $direction, float $riskDistance, array $sizing, bool $isManual): array
    {
        if (! config('trading.allow_live_trading', false)) {
            return $this->rejected('Live trading is disabled on this server (ALLOW_LIVE_TRADING=false).');
        }

        $client = $this->client->forMode('live');
        if (! $client->hasCredentials()) {
            return $this->rejected('Binance API keys are not configured.');
        }

        try {
            $client->setMarginType($symbol, 'ISOLATED');
            $this->setLeverage($client, $symbol, (int) $sizing['leverage']);
        } catch (Throwable $e) {
            return ['status' => 'error', 'trade' => null, 'message' => "Entry aborted, could not set isolated margin/leverage: {$e->getMessage()}"];
        }

        try {
            $order = $client->placeOrder([
                'symbol' => $symbol,
                'side' => $direction === 'LONG' ? 'BUY' : 'SELL',
                'type' => 'MARKET',
                'quantity' => $client->formatQuantity($symbol, (float) $sizing['quantity']),
                'newOrderRespType' => 'RESULT',
            ]);
        } catch (Throwable $e) {
            Log::error("[OrderExecutor] Live entry rejected for {$symbol}: {$e->getMessage()}");

            return ['status' => 'error', 'trade' => null, 'message' => "Binance entry order failed: {$e->getMessage()}"];
        }

        $fillPrice = (float) ($order['avgPrice'] ?? 0);
        if ($fillPrice <= 0) {
            $fillPrice = (float) $client->getMarkPrice($symbol);
        }
        $filledQty = (float) ($order['executedQty'] ?? 0);
        if ($filledQty > 0) {
            $sizing['quantity'] = $filledQty;
            $sizing['margin'] = round($filledQty * $fillPrice / max(1, (int) $sizing['leverage']), 4);
        }

        $exitPlan = ExitPlan::fromConfig();
        $stopLoss = $direction === 'LONG' ? $fillPrice - $riskDistance : $fillPrice + $riskDistance;
        $targets = $exitPlan->targets($direction, $fillPrice, $stopLoss);

        // Exchange-side stop is mandatory: no stop, no position.
        try {
            $slRef = $this->exchangeOrders->placeStop($symbol, $direction, $stopLoss, (float) $sizing['quantity']);
        } catch (Throwable $e) {
            return $this->emergencyExit($symbol, $direction, (float) $sizing['quantity'], $e->getMessage());
        }

        $tpRef = null;
        try {
            $tpQty = $client->formatQuantity($symbol, (float) $sizing['quantity'] * $exitPlan->tp1CloseRatio());
            if ($tpQty > 0) {
                $tpRef = $this->exchangeOrders->placeTakeProfit($symbol, $direction, $targets['tp1'], $tpQty);
            }
        } catch (Throwable $e) {
            Log::warning("[OrderExecutor] TP1 order not placed for {$symbol}; software will book TP1 instead: {$e->getMessage()}");
        }

        $client->clearAccountCache();

        $trade = $this->recordTrade($signal, $symbol, $direction, $fillPrice, $riskDistance, $sizing, 'live', isset($order['orderId']) ? (string) $order['orderId'] : null, [
            'sl_order' => $slRef,
            'tp_order' => $tpRef,
            'binance_sl_algo_id' => $slRef['id'],
            'binance_tp_algo_id' => $tpRef['id'] ?? null,
        ], $isManual);

        return ['status' => 'opened', 'trade' => $trade, 'message' => $this->openedMessage($trade, $sizing)];
    }

    /**
     * Treat "already set" leverage responses as success; any other failure aborts the entry.
     */
    protected function setLeverage(BinanceFuturesClient $client, string $symbol, int $leverage): void
    {
        try {
            $client->setLeverage($symbol, $leverage);
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), '-4028') && ! str_contains($e->getMessage(), 'No need to change')) {
                throw $e;
            }
        }
    }

    /**
     * Close an unprotected position right after entry when its stop could not be placed.
     *
     * @return array{status: string, trade: ?Trade, message: string}
     */
    protected function emergencyExit(string $symbol, string $direction, float $quantity, string $error): array
    {
        $tempTrade = new Trade(['symbol' => $symbol, 'side' => $direction, 'mode' => 'live', 'quantity' => $quantity, 'remaining_quantity' => $quantity, 'meta' => []]);
        $result = $this->exchangeOrders->closeAndConfirmFlat($tempTrade);

        $details = "Stop-loss could not be placed for {$symbol} ({$error}). ".($result['flat']
            ? 'The position was closed immediately.'
            : "EMERGENCY CLOSE FAILED: {$result['message']} Close it manually on Binance now.");

        SystemLog::write('risk', $details, 'error');
        $this->notifier->notifyRiskEvent('live', 'Stop-loss placement failed', $details);

        return ['status' => 'error', 'trade' => null, 'message' => $details];
    }

    /**
     * @param  array<string, mixed>  $signal
     * @param  array<string, mixed>  $sizing
     * @param  array<string, mixed>  $exchangeMeta
     */
    protected function recordTrade(array $signal, string $symbol, string $direction, float $fillPrice, float $riskDistance, array $sizing, string $mode, ?string $orderId, array $exchangeMeta, bool $isManual): Trade
    {
        $stopLoss = $direction === 'LONG' ? $fillPrice - $riskDistance : $fillPrice + $riskDistance;
        $targets = ExitPlan::fromConfig()->targets($direction, $fillPrice, $stopLoss);
        $setup = (string) ($signal['setup'] ?? $signal['setup_type'] ?? 'SIGNAL');
        $grade = (string) ($signal['grade'] ?? 'B');
        $score = (int) ($signal['score'] ?? 0);
        $indicators = (array) ($signal['indicators'] ?? []);

        $trade = Trade::create([
            'symbol' => $symbol,
            'setup_tag' => $setup,
            'btc_trend_1h' => $indicators['btc_regime'] ?? null,
            'side' => $direction,
            'mode' => $mode,
            'status' => 'OPEN',
            'stage' => 'ENTRY',
            'entry_price' => $fillPrice,
            'quantity' => (float) $sizing['quantity'],
            'remaining_quantity' => (float) $sizing['quantity'],
            'margin_used' => (float) $sizing['margin'],
            'leverage' => (int) $sizing['leverage'],
            'initial_sl' => $stopLoss,
            'current_sl' => $stopLoss,
            'stop_distance' => $riskDistance,
            'tp1_price' => $targets['tp1'],
            'tp2_price' => $targets['tp2'],
            'be_locked' => false,
            'tp1_hit' => false,
            'tp2_hit' => false,
            'highest_price' => $fillPrice,
            'lowest_price' => $fillPrice,
            'binance_order_id' => $orderId,
            'meta' => array_merge([
                'setup' => $setup,
                'setup_label' => $signal['setup_label'] ?? $setup,
                'interval' => $signal['interval'] ?? config('trading.strategy.base_interval', '1h'),
                'grade' => $grade,
                'score' => $score,
                'ai_probability' => $signal['ai_probability'] ?? null,
                'atr' => $indicators['atr'] ?? null,
                'risk_usd' => $sizing['risk_usd'] ?? null,
                'risk_pct' => $sizing['risk_pct'] ?? null,
                'signal_id' => $signal['signal_id'] ?? null,
                'source' => $isManual ? 'manual' : 'auto',
            ], $exchangeMeta),
            'opened_at' => Carbon::now(),
        ]);

        TradingSignal::create([
            'symbol' => $symbol,
            'direction' => $direction,
            'score' => $score,
            'grade' => $grade,
            'price' => $fillPrice,
            'timeframe' => (string) ($signal['interval'] ?? config('trading.strategy.base_interval', '1h')),
            'indicators' => $indicators,
            'ai_status' => 'APPROVED',
            'ai_confidence' => (int) round(((float) ($signal['ai_probability'] ?? 0)) * 100),
            'ai_regime' => $setup,
            'ai_reason' => (string) ($signal['setup_label'] ?? $setup),
            'executed' => true,
            'trade_id' => $trade->id,
        ]);

        $this->notifier->notifyTradeOpened($trade, $score, (string) ($signal['setup_label'] ?? $setup));

        return $trade;
    }

    /**
     * @param  array<string, mixed>  $sizing
     */
    protected function openedMessage(Trade $trade, array $sizing): string
    {
        return sprintf(
            '%s %s opened [%s] at %s. Stop %s, TP1 %s. Risk $%s (%s%%), margin $%s at %dx.',
            $trade->side,
            $trade->symbol,
            strtoupper($trade->mode),
            $this->fmt($trade->entry_price),
            $this->fmt($trade->initial_sl),
            $this->fmt($trade->tp1_price),
            $sizing['risk_usd'],
            $sizing['risk_pct'],
            $trade->margin_used,
            $trade->leverage
        );
    }

    /**
     * @param  array<string, mixed>  $signal
     */
    protected function direction(array $signal): string
    {
        $raw = strtoupper((string) ($signal['direction'] ?? $signal['side'] ?? 'LONG'));

        return in_array($raw, ['LONG', 'BUY'], true) ? 'LONG' : 'SHORT';
    }

    protected function fmt(float $price): string
    {
        return rtrim(rtrim(number_format($price, $price >= 1 ? 4 : 8, '.', ''), '0'), '.');
    }

    /**
     * @return array{status: string, trade: null, message: string}
     */
    protected function rejected(string $message): array
    {
        return ['status' => 'rejected', 'trade' => null, 'message' => $message];
    }
}
