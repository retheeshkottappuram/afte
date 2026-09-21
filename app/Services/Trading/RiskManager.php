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
     * Verify if account is permitted to take a new trade.
     *
     * @return array{allowed: bool, reason: string}
     */
    public function canOpenTrade(TradingAccount $account, string $symbol, int $signalScore): array
    {
        if ($account->kill_switch) {
            return ['allowed' => false, 'reason' => 'Emergency Kill Switch is ACTIVE. Trading halted.'];
        }

        if ($account->paused_until !== null && $account->paused_until->isFuture()) {
            $remaining = $account->paused_until->diffForHumans();

            return ['allowed' => false, 'reason' => "Circuit breaker active after consecutive losses. Paused until {$remaining}."];
        }

        if (! $account->is_running) {
            return ['allowed' => false, 'reason' => 'Auto-trading is paused. Enable Auto-Trading to execute new signals.'];
        }

        $stage = $this->getCompoundingStage($account);

        // Check Signal Score against stage requirements
        if ($signalScore < (int) ($stage['min_score'] ?? 80)) {
            return ['allowed' => false, 'reason' => "Signal score {$signalScore} is below required threshold {$stage['min_score']} for {$stage['stage']}."];
        }

        // Check Open Positions Limit
        $openTradesCount = Trade::where('mode', $account->mode)
            ->where('status', 'OPEN')
            ->count();

        $maxPositions = (int) ($stage['max_positions'] ?? 1);
        if ($openTradesCount >= $maxPositions) {
            return ['allowed' => false, 'reason' => "Max open positions limit ({$maxPositions}) reached for {$stage['stage']}."];
        }

        // Check if there is already an open trade for this symbol
        $existingTrade = Trade::where('mode', $account->mode)
            ->where('symbol', $symbol)
            ->where('status', 'OPEN')
            ->exists();

        if ($existingTrade) {
            return ['allowed' => false, 'reason' => "An active trade for {$symbol} is already open."];
        }

        // Check available balance
        if ($account->balance < 2.0) {
            return ['allowed' => false, 'reason' => "Insufficient account balance (\${$account->balance}). Minimum \$2.00 required."];
        }

        return ['allowed' => true, 'reason' => 'Risk parameters approved.'];
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
        $maxRiskPct = (float) ($stage['max_risk_pct'] ?? 10.0);

        $slDistance = abs($entryPrice - $slPrice);
        if ($slDistance <= 0 || $entryPrice <= 0) {
            return ['allowed' => false, 'quantity' => 0, 'margin' => 0, 'leverage' => $leverage, 'risk_usd' => 0, 'notional' => 0, 'reason' => 'Invalid SL or entry price.'];
        }

        $slPct = $slDistance / $entryPrice;

        // Dollar amount risked on this trade
        $riskUsd = $account->balance * ($maxRiskPct / 100.0);

        // Desired position notional based on risk amount
        $targetNotional = $riskUsd / $slPct;

        // Binance Minimum Notional enforcement ($5.00 minimum)
        $minNotional = max(5.2, $this->client->getMinNotional($symbol));

        // If target notional is smaller than exchange minimum, size up to minimum notional
        $notional = max($minNotional, $targetNotional);

        // Calculate margin required
        $marginRequired = $notional / $leverage;

        // In Stage 1 ($5-$25), ensure margin does not exceed available balance
        if ($marginRequired > $account->balance * 0.90) {
            // Adjust leverage or margin if possible
            $marginRequired = $account->balance * 0.85;
            $notional = $marginRequired * $leverage;

            if ($notional < $minNotional) {
                // Notional would fall below Binance minimum
                return [
                    'allowed' => false,
                    'quantity' => 0,
                    'margin' => 0,
                    'leverage' => $leverage,
                    'risk_usd' => 0,
                    'notional' => 0,
                    'reason' => "Account balance (\${$account->balance}) insufficient to meet Binance minimum notional (\${$minNotional}) at {$leverage}x leverage.",
                ];
            }
        }

        $rawQuantity = $notional / $entryPrice;
        $formattedQuantity = $this->client->formatQuantity($symbol, $rawQuantity);

        if ($formattedQuantity <= 0) {
            return ['allowed' => false, 'quantity' => 0, 'margin' => 0, 'leverage' => $leverage, 'risk_usd' => 0, 'notional' => 0, 'reason' => 'Calculated lot size resulted in 0 after precision rounding.'];
        }

        $finalNotional = $formattedQuantity * $entryPrice;
        $finalMargin = round($finalNotional / $leverage, 4);

        return [
            'allowed' => true,
            'quantity' => $formattedQuantity,
            'margin' => $finalMargin,
            'leverage' => $leverage,
            'risk_usd' => round($slDistance * $formattedQuantity, 4),
            'notional' => round($finalNotional, 2),
            'reason' => 'Calculated successfully within compounding risk limits.',
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
            $account->consecutive_losses += 1;
            $account->consecutive_wins = 0;

            // Circuit breaker: Check consecutive loss limit
            $maxConsecutive = (int) config('trading.circuit_breakers.max_consecutive_losses', 2);
            if ($account->consecutive_losses >= $maxConsecutive) {
                $cooldownMinutes = (int) config('trading.circuit_breakers.loss_cooldown_minutes', 120);
                $account->paused_until = Carbon::now()->addMinutes($cooldownMinutes);
            }
        }

        $account->save();
    }
}
