<?php

namespace App\Services\Breakout;

/**
 * Proposed (analytical) entry, stop and targets. Not execution prices; nothing here places orders.
 *
 *   Long:  SL = min(structural low, R - 0.25 ATR)    risk = E - SL    TP1 = E + 1.5 risk    TP2 = E + 2 risk
 *   Short: SL = max(structural high, S + 0.25 ATR)   risk = SL - E    TP1 = E - 1.5 risk    TP2 = E - 2 risk
 *
 * Costs per unit = entry * (2 * (taker fee + slippage) + spread) + adverse funding over the expected hold.
 * Net R:R(TP1) = (reward - costs) / (risk + costs). A candidate is rejected (never silently adjusted) when
 * the levels are invalid, the stop is too tight or too wide, or the net R:R is below the minimum.
 */
class SignalRiskCalculator
{
    public function __construct(protected EbdConfig $config) {}

    /**
     * @param  array<int, float>  $structureLows  lows (long) or highs (short) of the recent candles incl. the breakout candle
     * @return array{ok: bool, reason: ?string, entry: float, sl: ?float, tp1: ?float, tp2: ?float, risk: ?float, sl_pct: ?float,
     *     cost_per_unit: ?float, net_rr_tp1: ?float, net_rr_tp2: ?float, costs: array<string, float>}
     */
    public function levels(string $direction, float $entry, array $range, array $structureLows, ?float $spreadBps = null, ?float $fundingRate = null): array
    {
        $long = $direction === 'LONG';
        $buffer = $this->config->float('risk.stop_buffer_atr') * $range['atr'];
        $structure = $structureLows === [] ? null : ($long ? min($structureLows) : max($structureLows));
        $boundaryStop = $long ? $range['resistance'] - $buffer : $range['support'] + $buffer;
        $sl = $structure === null ? $boundaryStop : ($long ? min($structure, $boundaryStop) : max($structure, $boundaryStop));

        $costs = $this->costs($entry, $direction, $spreadBps, $fundingRate);
        $base = ['entry' => $entry, 'sl' => null, 'tp1' => null, 'tp2' => null, 'risk' => null, 'sl_pct' => null,
            'cost_per_unit' => $costs['total'], 'net_rr_tp1' => null, 'net_rr_tp2' => null, 'costs' => $costs];

        $risk = $long ? $entry - $sl : $sl - $entry;
        if ($entry <= 0 || $sl <= 0 || $risk <= 0) {
            return ['ok' => false, 'reason' => 'stop is not on the losing side of the entry'] + $base;
        }

        $slPct = $risk / $entry * 100;
        $tp1 = $long ? $entry + $this->config->float('risk.tp1_r') * $risk : $entry - $this->config->float('risk.tp1_r') * $risk;
        $tp2 = $long ? $entry + $this->config->float('risk.tp2_r') * $risk : $entry - $this->config->float('risk.tp2_r') * $risk;
        $net = fn (float $tp): float => (abs($tp - $entry) - $costs['total']) / ($risk + $costs['total']);
        $result = ['entry' => $entry, 'sl' => $sl, 'tp1' => $tp1, 'tp2' => $tp2, 'risk' => $risk, 'sl_pct' => $slPct,
            'cost_per_unit' => $costs['total'], 'net_rr_tp1' => $net($tp1), 'net_rr_tp2' => $net($tp2), 'costs' => $costs];

        $reason = match (true) {
            $tp1 <= 0 || $tp2 <= 0 => 'target below zero',
            $slPct < $this->config->float('risk.min_sl_pct') => sprintf('stop %.2f%% tighter than %.2f%%', $slPct, $this->config->float('risk.min_sl_pct')),
            $slPct > $this->config->float('risk.max_sl_pct') => sprintf('stop %.2f%% wider than %.2f%%', $slPct, $this->config->float('risk.max_sl_pct')),
            $result['net_rr_tp1'] < $this->config->float('risk.min_net_rr') => sprintf('net R:R %.2f after costs < %.2f', $result['net_rr_tp1'], $this->config->float('risk.min_net_rr')),
            default => null,
        };

        return ['ok' => $reason === null, 'reason' => $reason] + $result;
    }

    /**
     * Estimated round-trip cost per unit of the asset, in quote currency.
     *
     * @return array{fees: float, slippage: float, spread: float, funding: float, total: float, spread_bps: float}
     */
    public function costs(float $entry, string $direction, ?float $spreadBps, ?float $fundingRate): array
    {
        $spreadBps ??= $this->config->float('costs.default_spread_bps');
        $fees = 2 * $this->config->float('costs.taker_fee') * $entry;
        $slippage = 2 * $this->config->float('costs.slippage') * $entry;
        $spread = $spreadBps / 10_000 * $entry;
        // Longs pay positive funding, shorts pay negative funding (8h periods).
        $adverse = $fundingRate === null ? 0.0 : max(0.0, ($direction === 'LONG' ? 1 : -1) * $fundingRate);
        $funding = $adverse * $entry * ($this->config->float('risk.expected_hold_hours') / 8);

        return ['fees' => $fees, 'slippage' => $slippage, 'spread' => $spread, 'funding' => $funding,
            'total' => $fees + $slippage + $spread + $funding, 'spread_bps' => $spreadBps];
    }

    /**
     * Quantity for a risk budget (Q * r / |E - SL|), linear USDT-M contracts. Null without equity:
     * no position size is invented.
     */
    public static function quantity(?float $equity, float $riskFraction, float $entry, float $sl): ?float
    {
        $perUnit = abs($entry - $sl);
        if ($equity === null || $equity <= 0 || $perUnit <= 0 || $riskFraction <= 0) {
            return null;
        }

        return $equity * $riskFraction / $perUnit;
    }
}
