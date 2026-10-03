<?php

namespace App\Services\Trading;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Binance\BinanceFuturesClient;
use Carbon\Carbon;

class RiskManager
{
    public function __construct(
        protected BinanceFuturesClient $client
    ) {}

    /**
     * Get active compounding stage configuration for an account.
     *
     * @return array{
     *     stage: string,
     *     max_equity: float,
     *     max_positions: int,
     *     default_leverage: int,
     *     max_risk_pct: float,
     *     min_score: int
     * }
     */
    public function getCompoundingStage(TradingAccount $account): array
    {
        $stages = (array) config('trading.stages');
        $equity = $account->balance;

        if ($equity < 25.0) {
            $cfg = $stages['stage_1'] ?? [];

            return array_merge(['stage' => 'Stage 1 (Seed $5-$25)'], $cfg);
        }

        if ($equity < 100.0) {
            $cfg = $stages['stage_2'] ?? [];

            return array_merge(['stage' => 'Stage 2 (Acceleration $25-$100)'], $cfg);
        }

        $cfg = $stages['stage_3'] ?? [];

        return array_merge(['stage' => 'Stage 3 (Scale $100-$500)'], $cfg);
    }

    /**
     * Get real-time available margin balance in USD.
     */
    public function getAvailableBalance(TradingAccount $account): float
    {
        if ($account->mode === 'live' && $this->client->hasCredentials()) {
            try {
                $balances = $this->client->forMode($account->mode)->getBalance();
                foreach ($balances as $b) {
                    if (($b['asset'] ?? '') === 'USDT') {
                        $avail = (float) ($b['availableBalance'] ?? $b['crossWalletBalance'] ?? 0.0);
                        if ($avail > 0) {
                            return round($avail, 4);
                        }
                    }
                }
            } catch (\Throwable) {
                // Fallback to local calculation if Binance API transient error
            }
        }

        // Local calculation fallback: account balance minus margin used by currently open trades
        $openMargin = (float) Trade::where('mode', $account->mode)
            ->where('status', 'OPEN')
            ->sum('margin_used');

        return max(0.0, round($account->balance - $openMargin, 4));
    }

    /**
     * Verify if account is permitted to take a new trade.
     *
     * @return array{allowed: bool, reason: string}
     */
    public function canOpenTrade(
        TradingAccount $account,
        string $symbol,
        int $signalScore,
        bool $isManual = false,
        bool $isReversal = false
    ): array {
        if ($account->mode === 'live' && ! config('trading.allow_live_trading', false)) {
            return ['allowed' => false, 'reason' => 'LIVE trading is disabled in this environment to prevent dual-instance collisions with production.'];
        }

        if ($account->kill_switch) {
            return ['allowed' => false, 'reason' => 'Emergency Kill Switch is ACTIVE. Trading halted.'];
        }

        if (! $isReversal && $account->paused_until !== null && $account->paused_until->isFuture()) {
            $remaining = $account->paused_until->diffForHumans();

            return ['allowed' => false, 'reason' => "Circuit breaker active after consecutive losses. Paused until {$remaining}."];
        }

        if (! $isManual && ! $account->is_running) {
            return ['allowed' => false, 'reason' => 'Auto-trading is paused. Enable Auto-Trading to execute new signals.'];
        }

        $stage = $this->getCompoundingStage($account);

        // Check Signal Score against stage requirements
        if ($signalScore < (int) ($stage['min_score'] ?? 80)) {
            return ['allowed' => false, 'reason' => "Signal score {$signalScore} is below required threshold {$stage['min_score']} for {$stage['stage']}."];
        }

        // Restrict high-priced/heavyweight coins (BTC/ETH) in micro-account stage to preserve lot sizing
        $excludedSymbols = (array) ($stage['exclude_symbols'] ?? []);
        if (in_array(strtoupper($symbol), $excludedSymbols, true)) {
            return ['allowed' => false, 'reason' => "Symbol {$symbol} is excluded in {$stage['stage']} to preserve micro-capital lot sizing."];
        }

        // Strict single-coin strategy enforcement: Only permitted monitored coins
        if (config('trading.single_coin_strict', false) && ! TradingTargetManager::isCoinAllowed($symbol)) {
            $activeCoin = TradingTargetManager::getActiveCoin();

            return [
                'allowed' => false,
                'reason' => "Trading is strictly restricted to permitted monitored coins. Trades on {$symbol} are not allowed.",
            ];
        }

        // Check if there is already an open trade for this exact symbol (unless flipping in a reversal)
        if (! $isReversal) {
            $existingTrade = Trade::where('mode', $account->mode)
                ->where('symbol', $symbol)
                ->where('status', 'OPEN')
                ->exists();

            if ($existingTrade) {
                return ['allowed' => false, 'reason' => "An active trade for {$symbol} is already open."];
            }
        }

        // Fetch all currently open trades
        $openTrades = Trade::where('mode', $account->mode)
            ->where('status', 'OPEN')
            ->get();

        $maxPositions = (int) ($stage['max_positions'] ?? 3);
        $maxUnprotected = (int) ($stage['max_unprotected'] ?? 2);

        // Classify trades into Unprotected (at-risk) vs Protected (risk-free runners)
        $unprotectedTrades = $openTrades->filter(fn (Trade $t): bool => ! $t->isProtected());

        // 1. Check total positions limit
        if ($openTrades->count() >= $maxPositions) {
            // If all active positions are protected/risk-free, allow up to 1 extra runner slot if available balance allows
            $hasOnlyProtected = $unprotectedTrades->isEmpty();
            $maxAllowedWithRunners = $maxPositions + 1;

            if (! $hasOnlyProtected || $openTrades->count() >= $maxAllowedWithRunners) {
                return ['allowed' => false, 'reason' => "Max open positions limit ({$maxPositions}) reached for {$stage['stage']}."];
            }
        }

        // 2. Check unprotected (at-risk) positions limit (prevents simultaneous risk exposure)
        if ($unprotectedTrades->count() >= $maxUnprotected) {
            return ['allowed' => false, 'reason' => "Active risk capacity reached ({$unprotectedTrades->count()}/{$maxUnprotected} unprotected positions). Waiting for current trade to lock breakeven or TP1."];
        }

        // 3. Check real Available Margin Balance
        $availMargin = $this->getAvailableBalance($account);
        $minRequiredMargin = (float) config('trading.fund_management.min_available_margin', 0.50);

        if ($availMargin < $minRequiredMargin) {
            return ['allowed' => false, 'reason' => "Insufficient available margin (\${$availMargin}). Minimum \${$minRequiredMargin} free balance required to open an additional trade."];
        }

        return ['allowed' => true, 'reason' => 'Risk parameters and available capital approved.'];
    }

    /**
     * Compute a strictly bounded Stop Loss price guaranteeing mathematical asset protection.
     * Prevents catastrophic capital loss on high-allocation single coin positions.
     */
    public function calculateAssetProtectionStopLoss(
        string $direction,
        float $entryPrice,
        ?float $proposedSl = null
    ): float {
        if ($entryPrice <= 0) {
            return 0.0;
        }

        $isLong = in_array(strtoupper($direction), ['LONG', 'BUY'], true);
        $minSlPct = (float) config('trading.risk.min_sl_distance_pct', 0.80);
        $maxSlPct = (float) config('trading.risk.max_sl_distance_pct', 1.60);
        $defaultSlPct = (float) config('trading.risk.default_sl_distance_pct', 1.25);

        $defaultSl = $isLong
            ? round($entryPrice * (1.0 - ($defaultSlPct / 100.0)), 6)
            : round($entryPrice * (1.0 + ($defaultSlPct / 100.0)), 6);

        if ($proposedSl === null || $proposedSl <= 0) {
            return $defaultSl;
        }

        // Direction sanity check: Long SL must be strictly below entry, Short SL must be strictly above entry
        if ($isLong && $proposedSl >= $entryPrice) {
            return $defaultSl;
        }
        if (! $isLong && $proposedSl <= $entryPrice) {
            return $defaultSl;
        }

        $distancePct = (abs($entryPrice - $proposedSl) / $entryPrice) * 100.0;

        // Clamp within asset protection bounds
        if ($distancePct < $minSlPct) {
            return $isLong
                ? round($entryPrice * (1.0 - ($minSlPct / 100.0)), 6)
                : round($entryPrice * (1.0 + ($minSlPct / 100.0)), 6);
        }

        if ($distancePct > $maxSlPct) {
            return $isLong
                ? round($entryPrice * (1.0 - ($maxSlPct / 100.0)), 6)
                : round($entryPrice * (1.0 + ($maxSlPct / 100.0)), 6);
        }

        return round($proposedSl, 6);
    }

    /**
     * Compute safe position size, margin, and leverage for $5 -> $500 challenge.
     * Enforces >= 50% available fund utilization in single-coin mode.
     *
     * @return array{
     *     allowed: bool,
     *     quantity: float,
     *     margin: float,
     *     leverage: int,
     *     risk_usd: float,
     *     notional: float,
     *     reason: string
     * }
     */
    public function calculatePositionSize(
        TradingAccount $account,
        string $symbol,
        float $entryPrice,
        float $slPrice
    ): array {
        $stage = $this->getCompoundingStage($account);
        $leverage = (int) ($stage['default_leverage'] ?? 10);
        $maxRiskPct = (float) ($stage['max_risk_pct'] ?? 5.0);

        $slDistance = abs($entryPrice - $slPrice);
        if ($slDistance <= 0 || $entryPrice <= 0) {
            if ($entryPrice > 0) {
                $slPrice = $this->calculateAssetProtectionStopLoss('LONG', $entryPrice, null);
                $slDistance = abs($entryPrice - $slPrice);
            } else {
                return ['allowed' => false, 'quantity' => 0, 'margin' => 0, 'leverage' => $leverage, 'risk_usd' => 0, 'notional' => 0, 'reason' => 'Invalid SL or entry price.'];
            }
        }

        $slPct = $slDistance / $entryPrice;

        // Determine Available Free Margin
        $availMargin = $this->getAvailableBalance($account);

        // Binance Minimum Notional enforcement ($5.00 minimum)
        $minNotional = max(5.20, $this->client->getMinNotional($symbol));

        // Check if an explicit amount added per trade (in USD) is configured
        $configuredAmount = config('trading.fund_management.amount_per_trade');
        $isSingleCoin = (bool) config('trading.single_coin_strict', false);
        $singleCoinFundPct = (float) config('trading.fund_management.single_coin_fund_percent', 50.0);
        $maxAllocPct = (float) config('trading.fund_management.max_fund_allocation_pct', 75.0);

        if ($configuredAmount !== null && (float) $configuredAmount > 0) {
            // User-configured exact margin amount added per trade
            $targetMargin = max((float) $configuredAmount, $minNotional / $leverage);
            $targetNotional = $targetMargin * $leverage;
        } elseif ($isSingleCoin) {
            // Dedicated Single-Coin Strategy: At least 50% of available funds utilized for the trade
            $targetMargin = max($minNotional / $leverage, $availMargin * ($singleCoinFundPct / 100.0));
            // Cap at maximum allocation safety ceiling (e.g. 75% margin, ensuring >= 25% liquidation shield)
            $targetMargin = min($targetMargin, $availMargin * ($maxAllocPct / 100.0));
            $targetNotional = max($minNotional, $targetMargin * $leverage);
        } elseif ($account->balance < 25.0) {
            // In Stage 1 ($3 - $25) multi-coin mode, size position close to minimum notional so multiple trades can run safely
            $targetNotional = (float) config('trading.fund_management.stage1_target_notional', 5.25);
            $targetNotional = max($minNotional, $targetNotional);
        } else {
            // For larger accounts, scale notional based on risk % and SL distance
            $riskUsd = $account->balance * ($maxRiskPct / 100.0);
            $targetNotional = max($minNotional, $riskUsd / $slPct);
        }

        $marginRequired = $targetNotional / $leverage;

        // Ensure margin does not exceed available free margin (keep at least 25% safety buffer in single-coin mode, 15% in multi-coin)
        $maxAffordableMargin = $availMargin * ($maxAllocPct / 100.0);

        if ($marginRequired > $maxAffordableMargin) {
            $targetNotional = $maxAffordableMargin * $leverage;
            $marginRequired = $targetNotional / $leverage;

            if ($targetNotional < $minNotional) {
                return [
                    'allowed' => false,
                    'quantity' => 0,
                    'margin' => 0,
                    'amount_added' => 0,
                    'leverage' => $leverage,
                    'risk_usd' => 0,
                    'notional' => 0,
                    'reason' => "Available margin (\${$availMargin}) insufficient to fund Binance minimum notional (\${$minNotional}) at {$leverage}x leverage.",
                ];
            }
        }

        $rawQuantity = $targetNotional / $entryPrice;
        $formattedQuantity = $this->client->formatQuantity($symbol, $rawQuantity);

        $info = $this->client->getExchangeInfo()[$symbol] ?? null;
        $step = (float) ($info['stepSize'] ?? 0.001);
        $precision = (int) ($info['quantityPrecision'] ?? 3);

        // Fallback: If target notional was smaller than 1 exchange lot step, check if 1 step is affordable
        if ($formattedQuantity <= 0 && $step > 0) {
            $stepNotional = $step * $entryPrice;
            $stepMargin = $stepNotional / $leverage;
            if ($stepMargin <= $maxAffordableMargin && $stepMargin <= ($availMargin * 0.45)) {
                $formattedQuantity = round($step, $precision);
            }
        }

        // Ensure lot size meets exchange minNotional ($5.00)
        if ($formattedQuantity > 0 && ($formattedQuantity * $entryPrice) < $minNotional && $step > 0) {
            while (($formattedQuantity * $entryPrice) < $minNotional) {
                $candidateQty = round($formattedQuantity + $step, $precision);
                if (($candidateQty * $entryPrice / $leverage) > $maxAffordableMargin) {
                    break;
                }
                $formattedQuantity = $candidateQty;
            }
        }

        if ($formattedQuantity <= 0) {
            return ['allowed' => false, 'quantity' => 0, 'margin' => 0, 'amount_added' => 0, 'leverage' => $leverage, 'risk_usd' => 0, 'notional' => 0, 'reason' => 'Calculated lot size resulted in 0 after precision rounding.'];
        }

        $finalNotional = $formattedQuantity * $entryPrice;
        if ($finalNotional < $minNotional) {
            return ['allowed' => false, 'quantity' => 0, 'margin' => 0, 'amount_added' => 0, 'leverage' => $leverage, 'risk_usd' => 0, 'notional' => 0, 'reason' => "Position notional (\${$finalNotional}) is below Binance minimum notional (\${$minNotional})."];
        }

        $finalMargin = round($finalNotional / $leverage, 4);

        return [
            'allowed' => true,
            'quantity' => $formattedQuantity,
            'margin' => $finalMargin,
            'amount_added' => $finalMargin,
            'leverage' => $leverage,
            'risk_usd' => round($slDistance * $formattedQuantity, 4),
            'notional' => round($finalNotional, 2),
            'reason' => 'Calculated successfully within compounding risk and available fund limits.',
        ];
    }

    /**
     * Handle trade closed event to update account balance, stats, and circuit breakers.
     */
    public function handleTradeClosed(TradingAccount $account, Trade $trade): void
    {
        $account->refresh();
        $pnl = (float) $trade->realized_pnl;

        $account->balance = round($account->balance + $pnl, 4);
        $account->equity = $account->balance;
        $account->total_trades += 1;

        if ($account->balance > $account->peak_equity) {
            $account->peak_equity = $account->balance;
        }

        if ($pnl > 0) {
            $account->winning_trades += 1;
            $account->consecutive_wins += 1;
            $account->consecutive_losses = 0;
        } else {
            $account->losing_trades += 1;
            $account->consecutive_wins = 0;

            // Only increment consecutive losses if it was a genuine adverse loss (not tiny fee friction <= $0.015)
            if ($pnl < -0.015) {
                $account->consecutive_losses += 1;
            }

            // Circuit breaker: Check consecutive loss limit
            $maxConsecutive = (int) config('trading.circuit_breakers.max_consecutive_losses', 4);
            if ($account->consecutive_losses >= $maxConsecutive) {
                $cooldownMinutes = (int) config('trading.circuit_breakers.loss_cooldown_minutes', 20);
                $account->paused_until = Carbon::now()->addMinutes($cooldownMinutes);
            }
        }

        $account->save();
    }
}
