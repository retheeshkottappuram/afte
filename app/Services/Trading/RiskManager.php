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
    public function canOpenTrade(TradingAccount $account, string $symbol, int $signalScore, bool $isManual = false): array
    {
        if ($account->mode === 'live' && ! config('trading.allow_live_trading', false)) {
            return ['allowed' => false, 'reason' => 'LIVE trading is disabled in this environment to prevent dual-instance collisions with production.'];
        }

        if ($account->kill_switch) {
            return ['allowed' => false, 'reason' => 'Emergency Kill Switch is ACTIVE. Trading halted.'];
        }

        if ($account->paused_until !== null && $account->paused_until->isFuture()) {
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

        // Strict single-coin strategy enforcement: Only the selected coin is permitted to trade
        if (config('trading.single_coin_strict', true) && ! TradingTargetManager::isCoinAllowed($symbol)) {
            $activeCoin = TradingTargetManager::getActiveCoin();

            return [
                'allowed' => false,
                'reason' => "Trading is strictly restricted to selected coin ({$activeCoin}). Trades on {$symbol} are not allowed.",
            ];
        }

        // Check if there is already an open trade for this exact symbol
        $existingTrade = Trade::where('mode', $account->mode)
            ->where('symbol', $symbol)
            ->where('status', 'OPEN')
            ->exists();

        if ($existingTrade) {
            return ['allowed' => false, 'reason' => "An active trade for {$symbol} is already open."];
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
     * Compute safe position size, margin, and leverage for $5 -> $500 challenge.
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
            return ['allowed' => false, 'quantity' => 0, 'margin' => 0, 'leverage' => $leverage, 'risk_usd' => 0, 'notional' => 0, 'reason' => 'Invalid SL or entry price.'];
        }

        $slPct = $slDistance / $entryPrice;

        // Determine Available Free Margin
        $availMargin = $this->getAvailableBalance($account);

        // Binance Minimum Notional enforcement ($5.00 minimum)
        $minNotional = max(5.20, $this->client->getMinNotional($symbol));

        // Check if an explicit amount added per trade (in USD) is configured
        $configuredAmount = config('trading.fund_management.amount_per_trade');

        if ($configuredAmount !== null && (float) $configuredAmount > 0) {
            // User-configured exact margin amount added per trade
            $targetNotional = max($minNotional, ((float) $configuredAmount) * $leverage);
        } elseif ($account->balance < 25.0) {
            // In Stage 1 ($3 - $25), size position close to minimum notional so multiple trades can run safely
            $targetNotional = (float) config('trading.fund_management.stage1_target_notional', 5.25);
            $targetNotional = max($minNotional, $targetNotional);
        } else {
            // For larger accounts, scale notional based on risk % and SL distance
            $riskUsd = $account->balance * ($maxRiskPct / 100.0);
            $targetNotional = max($minNotional, $riskUsd / $slPct);
        }

        $marginRequired = $targetNotional / $leverage;

        // Ensure margin does not exceed available free margin (keep at least 15% buffer)
        $maxAffordableMargin = $availMargin * 0.85;

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
