<?php

namespace App\Services\Trading;

use App\Models\SystemLog;
use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Binance\BinanceFuturesClient;
use App\Services\Notifications\TelegramNotifier;
use App\Services\Strategy\StrategyEngine;
use Carbon\Carbon;

/**
 * Hard risk rules. Risk per trade is defined by the stop-loss distance, never by leverage.
 *
 *  - 2% of equity at risk per trade (min-notional trades allowed up to 3% on small accounts).
 *  - Max positions scale with equity (1 below $25, 2 below $100, 3 above).
 *  - Daily loss stop: -6% of start-of-day equity blocks entries until the next UTC day.
 *  - Losing streak: 3 losses in a row pauses entries for 12 hours.
 *  - Drawdown kill: -30% from peak equity engages the kill switch (manual reset).
 */
class RiskManager
{
    public function __construct(
        protected BinanceFuturesClient $client,
        protected ?TelegramNotifier $notifier = null
    ) {
        $this->notifier ??= app(TelegramNotifier::class);
    }

    /**
     * Describe the current account tier (used by the dashboard).
     *
     * @return array{stage: string, max_positions: int, default_leverage: int, max_risk_pct: float, min_score: int}
     */
    public function getCompoundingStage(TradingAccount $account): array
    {
        $equity = (float) $account->balance;
        $label = match (true) {
            $equity < 25.0 => 'Tier 1 (under $25)',
            $equity < 100.0 => 'Tier 2 ($25-$100)',
            default => 'Tier 3 ($100+)',
        };

        return [
            'stage' => $label,
            'max_positions' => $this->maxPositions($equity),
            'default_leverage' => (int) config('trading.sizing.max_leverage', 10),
            'max_risk_pct' => (float) config('trading.sizing.risk_per_trade_pct', 2.0),
            'min_score' => 0,
        ];
    }

    public function maxPositions(float $equity): int
    {
        foreach ((array) config('trading.sizing.max_positions', [25 => 1, 100 => 2, PHP_INT_MAX => 3]) as $ceiling => $max) {
            if ($equity < (float) $ceiling) {
                return (int) $max;
            }
        }

        return 1;
    }

    /**
     * Get real-time available margin balance in USD.
     */
    public function getAvailableBalance(TradingAccount $account): float
    {
        if ($account->mode === 'live' && $this->client->hasCredentials()) {
            try {
                $balances = $this->client->forMode('live')->getBalance();
                foreach ($balances as $b) {
                    if (($b['asset'] ?? '') === 'USDT') {
                        return round((float) ($b['availableBalance'] ?? $b['crossWalletBalance'] ?? 0.0), 4);
                    }
                }
            } catch (\Throwable) {
                // Fall back to the local ledger on transient API errors
            }
        }

        $openMargin = (float) Trade::where('mode', $account->mode)
            ->where('status', 'OPEN')
            ->sum('margin_used');

        return max(0.0, round($account->balance - $openMargin, 4));
    }

    /**
     * Verify whether a new trade may be opened.
     *
     * @return array{allowed: bool, reason: string}
     */
    public function canOpenTrade(TradingAccount $account, string $symbol, ?string $side = null, bool $isManual = false): array
    {
        if ($account->mode === 'live' && ! config('trading.allow_live_trading', false)) {
            return ['allowed' => false, 'reason' => 'Live trading is disabled on this server (ALLOW_LIVE_TRADING=false).'];
        }

        if ($account->kill_switch) {
            return ['allowed' => false, 'reason' => 'Emergency kill switch is active. Trading halted until it is reset.'];
        }

        $this->rollTradingDay($account);

        if ($this->checkDrawdownKill($account)) {
            return ['allowed' => false, 'reason' => 'Drawdown limit reached: kill switch engaged.'];
        }

        if ($account->paused_until !== null && $account->paused_until->isFuture()) {
            $reason = $account->pause_reason ?: 'Circuit breaker active';

            return ['allowed' => false, 'reason' => "Circuit breaker: {$reason}. Paused until {$account->paused_until->toDateTimeString()} UTC."];
        }

        if ($this->checkDailyLossStop($account)) {
            return ['allowed' => false, 'reason' => "Circuit breaker: {$account->pause_reason}."];
        }

        if (! $isManual && ! $account->is_running) {
            return ['allowed' => false, 'reason' => 'Auto-trading is paused. Start the engine to take new signals.'];
        }

        $openTrades = Trade::where('mode', $account->mode)->where('status', 'OPEN')->get();

        if ($openTrades->contains(fn (Trade $t): bool => $t->symbol === strtoupper($symbol))) {
            return ['allowed' => false, 'reason' => "A trade on {$symbol} is already open."];
        }

        $maxPositions = $this->maxPositions((float) $account->balance);
        if ($openTrades->count() >= $maxPositions) {
            return ['allowed' => false, 'reason' => "Max open positions ({$maxPositions}) reached for this account size."];
        }

        if ($side !== null) {
            $sameSide = $openTrades->where('side', strtoupper($side))->count();
            $maxSameSide = (int) config('trading.sizing.max_same_side', 2);
            if ($sameSide >= $maxSameSide) {
                return ['allowed' => false, 'reason' => "Already {$sameSide} {$side} positions open (correlation limit)."];
            }
        }

        $availMargin = $this->getAvailableBalance($account);
        $minRequired = (float) config('trading.fund_management.min_available_margin', 0.50);
        if ($availMargin < $minRequired) {
            return ['allowed' => false, 'reason' => "Insufficient free margin (\${$availMargin})."];
        }

        return ['allowed' => true, 'reason' => 'Risk checks passed.'];
    }

    /**
     * Fallback stop when a signal carries none, or when it is on the wrong side of entry.
     * Clamps an existing stop into the configured distance band.
     */
    public function calculateAssetProtectionStopLoss(string $direction, float $entryPrice, ?float $proposedSl = null, ?string $setup = null): float
    {
        if ($entryPrice <= 0) {
            return 0.0;
        }

        $isLong = in_array(strtoupper($direction), ['LONG', 'BUY'], true);
        $sign = $isLong ? -1 : 1;
        $minPct = (float) config('trading.strategy.min_sl_pct', 0.6);
        $maxPct = StrategyEngine::configuredMaxSlPct($setup);
        $defaultPct = (float) config('trading.risk.default_sl_distance_pct', 1.25);

        $atPct = fn (float $pct): float => $entryPrice * (1.0 + $sign * $pct / 100.0);

        if ($proposedSl === null || $proposedSl <= 0 || ($isLong ? $proposedSl >= $entryPrice : $proposedSl <= $entryPrice)) {
            return $atPct($defaultPct);
        }

        $distancePct = abs($entryPrice - $proposedSl) / $entryPrice * 100.0;

        return match (true) {
            $distancePct < $minPct => $atPct($minPct),
            $distancePct > $maxPct => $atPct($maxPct),
            default => $proposedSl,
        };
    }

    /**
     * Size a position from equity at risk and stop distance.
     *
     * @return array{allowed: bool, quantity: float, margin: float, amount_added: float, leverage: int, risk_usd: float, risk_pct: float, notional: float, reason: string}
     */
    public function calculatePositionSize(TradingAccount $account, string $symbol, float $entryPrice, float $slPrice, ?float $riskPctOverride = null): array
    {
        $reject = fn (string $reason): array => [
            'allowed' => false, 'quantity' => 0.0, 'margin' => 0.0, 'amount_added' => 0.0, 'leverage' => 0,
            'risk_usd' => 0.0, 'risk_pct' => 0.0, 'notional' => 0.0, 'reason' => $reason,
        ];

        $slDistance = abs($entryPrice - $slPrice);
        if ($entryPrice <= 0 || $slDistance <= 0) {
            return $reject('Invalid entry or stop-loss price.');
        }

        $equity = max(0.0, (float) $account->balance);
        $riskPct = $riskPctOverride ?? (float) config('trading.sizing.risk_per_trade_pct', 2.0);
        $smallAccountMaxRiskPct = (float) config('trading.sizing.small_account_max_risk_pct', 5.0);
        $maxLeverage = (int) config('trading.sizing.max_leverage', 10);
        $maxMarginPct = (float) config('trading.sizing.max_margin_pct', 90.0) / 100.0;

        $info = $this->client->getExchangeInfo()[$symbol] ?? null;
        $step = (float) ($info['stepSize'] ?? 0.0);
        $precision = (int) ($info['quantityPrecision'] ?? 3);
        $minNotional = max(5.0, $this->client->getMinNotional($symbol)) * 1.01;

        $quantity = $this->client->formatQuantity($symbol, ($equity * $riskPct / 100.0) / $slDistance);

        if ($quantity * $entryPrice < $minNotional) {
            // Smallest tradable size, rounded UP to the lot step
            $minQty = $step > 0
                ? round(ceil(($minNotional / $entryPrice) / $step) * $step, $precision)
                : round($minNotional / $entryPrice, $precision);
            $riskAtMin = $minQty * $slDistance;

            if ($riskAtMin > $equity * $smallAccountMaxRiskPct / 100.0) {
                $riskAtMinPct = $equity > 0 ? round($riskAtMin / $equity * 100, 1) : 100;

                return $reject("Minimum order size would risk {$riskAtMinPct}% of equity (limit {$smallAccountMaxRiskPct}%). Stop too wide for this account size.");
            }

            $quantity = $minQty;
        }

        if ($quantity <= 0) {
            return $reject('Position size rounds to zero at this lot size.');
        }

        $notional = $quantity * $entryPrice;
        $availMargin = $this->getAvailableBalance($account);
        $usableMargin = $availMargin * $maxMarginPct;

        if ($usableMargin <= 0) {
            return $reject('No free margin available.');
        }

        // Use at least 5x so margin is left for other positions; liquidation (~9% at 10x) stays beyond the widest stop.
        $leverage = (int) max(min(5, $maxLeverage), ceil($notional / $usableMargin));
        if ($leverage > $maxLeverage) {
            return $reject("Free margin \${$availMargin} cannot fund a \$".round($notional, 2)." position at {$maxLeverage}x.");
        }

        $margin = round($notional / $leverage, 4);
        $riskUsd = round($quantity * $slDistance, 4);

        return [
            'allowed' => true,
            'quantity' => $quantity,
            'margin' => $margin,
            'amount_added' => $margin,
            'leverage' => $leverage,
            'risk_usd' => $riskUsd,
            'risk_pct' => $equity > 0 ? round($riskUsd / $equity * 100, 2) : 0.0,
            'notional' => round($notional, 2),
            'reason' => 'Sized by stop distance and equity at risk.',
        ];
    }

    /**
     * Update account statistics and circuit breakers after a trade closes.
     */
    public function handleTradeClosed(TradingAccount $account, Trade $trade): void
    {
        $account->refresh();
        $this->rollTradingDay($account);
        $pnl = (float) $trade->realized_pnl;

        $account->balance = round($account->balance + $pnl, 4);
        $account->equity = $account->balance;
        $account->total_trades += 1;
        $account->peak_equity = max((float) $account->peak_equity, $account->balance);

        if ($pnl > 0) {
            $account->winning_trades += 1;
            $account->consecutive_wins += 1;
            $account->consecutive_losses = 0;
        } else {
            $account->losing_trades += 1;
            $account->consecutive_wins = 0;

            // Fee-only scratches do not count towards the losing streak
            if ($pnl < -0.015) {
                $account->consecutive_losses += 1;
            }

            $maxConsecutive = (int) config('trading.circuit_breakers.max_consecutive_losses', 3);
            if ($account->consecutive_losses >= $maxConsecutive) {
                $minutes = (int) config('trading.circuit_breakers.loss_cooldown_minutes', 720);
                $this->pause($account, Carbon::now()->addMinutes($minutes), "{$account->consecutive_losses} losses in a row");
            }
        }

        $account->save();

        $this->checkDailyLossStop($account);
        $this->checkDrawdownKill($account);
    }

    /**
     * Set the start-of-day equity reference at the first check of each UTC day.
     */
    public function rollTradingDay(TradingAccount $account): void
    {
        $today = Carbon::now('UTC')->startOfDay();

        if ($account->day_start_date === null || ! $account->day_start_date->isSameDay($today)) {
            $account->day_start_date = $today;
            $account->day_start_equity = (float) $account->balance;
            $account->save();
        }
    }

    /**
     * Engage the daily loss stop when today's loss exceeds the limit.
     */
    protected function checkDailyLossStop(TradingAccount $account): bool
    {
        $start = (float) ($account->day_start_equity ?? 0);
        $limitPct = (float) config('trading.circuit_breakers.max_daily_loss_pct', 6.0);

        if ($start <= 0 || $account->balance > $start * (1 - $limitPct / 100.0)) {
            return false;
        }

        if ($account->paused_until === null || $account->paused_until->isPast()) {
            $lossPct = round(($start - $account->balance) / $start * 100, 2);
            $this->pause($account, Carbon::now('UTC')->addDay()->startOfDay(), "daily loss limit hit (-{$lossPct}%)");
            $account->save();
        }

        return true;
    }

    /**
     * Engage the kill switch when equity falls too far from its peak.
     */
    protected function checkDrawdownKill(TradingAccount $account): bool
    {
        $peak = (float) $account->peak_equity;
        $limitPct = (float) config('trading.circuit_breakers.max_drawdown_pct', 30.0);

        if ($peak <= 0 || $account->balance > $peak * (1 - $limitPct / 100.0)) {
            return false;
        }

        if (! $account->kill_switch) {
            $account->kill_switch = true;
            $account->pause_reason = "drawdown -{$limitPct}% from peak \${$peak}";
            $account->save();

            SystemLog::write('risk', "[{$account->mode}] Kill switch engaged: {$account->pause_reason}.", 'error');
            $this->notifier?->notifyRiskEvent($account->mode, 'Kill switch engaged', "Equity \${$account->balance} is {$limitPct}% below peak \${$peak}. Reset it manually from the dashboard after reviewing.");
        }

        return true;
    }

    protected function pause(TradingAccount $account, Carbon $until, string $reason): void
    {
        $account->paused_until = $until;
        $account->pause_reason = $reason;

        SystemLog::write('risk', "[{$account->mode}] Entries paused until {$until->toDateTimeString()} UTC: {$reason}.", 'warning');
        $this->notifier?->notifyRiskEvent($account->mode, 'New entries paused', ucfirst($reason).". Resuming at {$until->toDateTimeString()} UTC. Open trades stay protected.");
    }
}
