<?php

namespace App\Services\Trading;

use App\Models\CryptoSignal;
use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Crypto\BinanceClient;
use App\Services\Strategy\OpportunityScorer;
use App\Services\Strategy\Signal;
use App\Services\Strategy\SignalScorer;
use App\Services\Strategy\SymbolAnalyzer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Auto-trader: acts on fresh StrategyEngine signals from the market scan.
 *
 *  - New entries only in the active mode, only for signals the scorer approves.
 *  - Skips entries when price has already drifted away from the signal candle close.
 *  - An opposite core signal closes the open trade; it never flips into a new one in the same cycle.
 *  - Manual chart trades go through the same signal check, risk manager and order executor.
 */
class SignalAlgoTrader
{
    /** Max distance (in R) between the signal close and the current price for an entry. */
    public const MAX_ENTRY_DRIFT_R = 0.3;

    public function __construct(
        protected BinanceClient $market,
        protected OrderExecutor $orderExecutor,
        protected DynamicTradeManager $tradeManager,
        protected SignalScorer $scorer,
        protected SymbolAnalyzer $analyzer,
        protected TradingModeManager $modeManager,
        protected OpportunityScorer $opportunity
    ) {}

    /**
     * Act on fresh signals from a market scan.
     *
     * @param  array<int, array{signal: Signal, record: CryptoSignal}>  $fresh
     * @return array<int, array{symbol: string, side: string, status: string, message: string}>
     */
    public function processFreshSignals(array $fresh, string $mode, bool $entriesAllowed): array
    {
        $decisions = [];

        // Best candidates first, so limited position slots go to the highest-quality signals.
        usort($fresh, fn (array $a, array $b): int => $this->priority($b['signal']) <=> $this->priority($a['signal']));

        foreach ($fresh as ['signal' => $signal, 'record' => $record]) {
            try {
                $decision = $this->handleSignal($signal, $mode, $entriesAllowed);
            } catch (Throwable $e) {
                Log::error("[SignalAlgoTrader] {$signal->symbol}: {$e->getMessage()}");
                $decision = ['status' => 'error', 'message' => $e->getMessage()];
            }

            $label = $this->statusLabel($mode, $decision);
            // A signal the minute watcher already handled keeps its original decision.
            if ($decision['status'] !== 'duplicate') {
                $record->update(['auto_trade_status' => mb_substr($label, 0, 120)]);
            }
            $decisions[] = ['symbol' => $signal->symbol, 'side' => $signal->side, 'status' => $decision['status'], 'message' => $label];
        }

        return $decisions;
    }

    /**
     * @return array{status: string, message: string}
     */
    public function handleSignal(Signal $signal, string $mode, bool $entriesAllowed): array
    {
        if (! Cache::add("signal-handled:{$mode}:{$signal->symbol}:{$signal->time}:{$signal->side}", true, now()->addHours(6))) {
            return ['status' => 'duplicate', 'message' => 'Already processed.'];
        }

        // Opposite core signal: close any open trade on this symbol (both modes), no flip.
        $exitMessage = null;
        if ($signal->isTradable()) {
            foreach (Trade::where('symbol', $signal->symbol)->where('status', 'OPEN')->get() as $openTrade) {
                if ($openTrade->side !== $signal->side) {
                    $price = (float) $this->market->tickerPrice($signal->symbol) ?: $signal->entry;
                    $result = $this->tradeManager->closeTrade($openTrade, $price, 'OPPOSITE_SIGNAL');
                    $exitMessage = "Closed {$openTrade->side} on opposite signal: {$result['message']}";
                }
            }
        }

        if ($exitMessage !== null) {
            return ['status' => 'exited', 'message' => $exitMessage];
        }

        if (! $entriesAllowed) {
            return ['status' => 'skipped', 'message' => 'Auto-trading is stopped.'];
        }

        $decision = $this->scorer->autoTradeDecision($signal, $mode);
        if (! $decision['allowed']) {
            return ['status' => 'skipped', 'message' => $decision['reason']];
        }

        $payload = $this->entryPayload($signal);
        if ($payload === null) {
            return ['status' => 'skipped', 'message' => 'Price moved too far from the signal candle. Entry missed.'];
        }

        $result = $this->orderExecutor->executeSignal($payload, $mode);

        return ['status' => $result['status'] === 'opened' ? 'taken' : 'skipped', 'message' => $result['message']];
    }

    /**
     * Manual entry from the chart / scanner: requires a current tradable signal in that direction.
     *
     * @return array{success: bool, message: string, trade: ?Trade}
     */
    public function executeManual(string $symbol, string $direction, ?string $mode = null): array
    {
        $symbol = TradingTargetManager::normalizeSymbol($symbol);
        $direction = strtoupper($direction) === 'SHORT' ? 'SHORT' : 'LONG';
        $mode ??= $this->modeManager->activeMode();

        $account = TradingAccount::getForMode($mode);
        if ($account->kill_switch) {
            return ['success' => false, 'message' => 'Kill switch is active. Trade cannot be placed.', 'trade' => null];
        }

        $analysis = $this->analyzer->analyze($symbol, (string) config('trading.strategy.base_interval', '1h'), lookback: 3);
        $signal = $this->recentSignal($analysis['signals'], $direction);

        if ($signal === null) {
            return ['success' => false, 'message' => "No current {$direction} signal on {$symbol}. Manual trades follow the same strategy signals as the auto-trader.", 'trade' => null];
        }

        if (! $signal->isTradable()) {
            return ['success' => false, 'message' => 'Signal did not pass: '.implode('; ', $signal->failedFilters()), 'trade' => null];
        }

        $payload = $this->entryPayload($signal);
        if ($payload === null) {
            return ['success' => false, 'message' => 'Price moved too far from the signal candle. Entry missed; wait for the next setup.', 'trade' => null];
        }

        $result = $this->orderExecutor->executeSignal($payload, $mode, isManual: true);

        return ['success' => $result['status'] === 'opened', 'message' => $result['message'], 'trade' => $result['trade']];
    }

    /**
     * Order payload at the current price with the signal's stop distance; null if price drifted too far.
     *
     * @return array<string, mixed>|null
     */
    protected function entryPayload(Signal $signal): ?array
    {
        $risk = abs($signal->entry - $signal->stopLoss);
        $price = 0.0;

        try {
            $price = (float) $this->market->tickerPrice($signal->symbol);
        } catch (Throwable) {
            // fall back to the signal close below
        }
        if ($price <= 0) {
            $price = $signal->entry;
        }

        if ($risk <= 0 || abs($price - $signal->entry) > self::MAX_ENTRY_DRIFT_R * $risk) {
            return null;
        }

        // Entry at the current price would be past the stop or target: skip.
        $direction = $signal->isLong() ? 1 : -1;
        if (($price - $signal->stopLoss) * $direction <= 0 || ($signal->tp1 - $price) * $direction <= 0) {
            return null;
        }

        $payload = $signal->toOrderPayload();
        $payload['price'] = $price;
        $payload['initial_sl'] = $price - $direction * $risk;

        return $payload;
    }

    /**
     * Latest signal in a direction printed within the last two closed candles.
     *
     * @param  array<int, Signal>  $signals
     */
    protected function recentSignal(array $signals, string $direction): ?Signal
    {
        $maxAge = 2 * 3600 + 300;

        foreach (array_reverse($signals) as $signal) {
            if ($signal->side === $direction && time() - $signal->time <= $maxAge) {
                return $signal;
            }
        }

        return null;
    }

    protected function priority(Signal $signal): float
    {
        return (float) $this->opportunity->score($signal, $signal->entry)['score'];
    }

    /**
     * @param  array{status: string, message: string}  $decision
     */
    protected function statusLabel(string $mode, array $decision): string
    {
        $tag = '['.strtoupper($mode).'] ';

        return match ($decision['status']) {
            'taken' => $tag.'taken',
            'exited' => $tag.$decision['message'],
            'duplicate' => $tag.'already handled',
            default => $tag.'skipped: '.$decision['message'],
        };
    }
}
