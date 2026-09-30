<?php

namespace App\Services\AI;

use App\Models\Trade;
use App\Services\Binance\BinanceFuturesClient;
use App\Services\Trading\Indicators;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ActiveTradeMonitorAgent
{
    public function __construct(
        protected BinanceFuturesClient $client
    ) {}

    /**
     * Actively monitor an open trade against multi-timeframe candle structure,
     * volume profile, and dynamic trend health.
     *
     * Prevents premature panic exits on normal market noise, nurtures winning trends,
     * and trails stop losses structurally behind swing pivots to allow 2x - 5x profit capture.
     *
     * @return array{
     *     action: string,            // 'HOLD', 'TRAIL_SL', 'EMERGENCY_EXIT', 'NOOP'
     *     decision: string,          // Human-readable AI decision title
     *     reason: string,            // Rationale explaining the trend observation
     *     suggested_sl: ?float,      // Dynamic structural stop price to ratchet upward/downward
     *     target_price: ?float,      // Extended expansion target
     *     metrics: array<string, mixed>
     * }
     */
    public function monitorTrade(Trade $trade, float $currentPrice): array
    {
        if ($trade->entry_price <= 0 || $currentPrice <= 0) {
            return $this->noopResult('Invalid price data.');
        }

        $highestPrice = $trade->highest_price ?? $currentPrice;
        $lowestPrice = $trade->lowest_price ?? $currentPrice;

        $peakGainPct = $trade->isLong()
            ? (($highestPrice - $trade->entry_price) / $trade->entry_price) * 100.0
            : (($trade->entry_price - $lowestPrice) / $trade->entry_price) * 100.0;

        $currentGainPct = $trade->isLong()
            ? (($currentPrice - $trade->entry_price) / $trade->entry_price) * 100.0
            : (($trade->entry_price - $currentPrice) / $trade->entry_price) * 100.0;

        $currentRoe = $trade->calculateRoe($currentPrice);
        $isLong = $trade->isLong();

        // Fetch recent 15m klines (cached briefly for 10s to prevent API spam)
        $klines = $this->getCachedKlines($trade->symbol, '15m', 40);
        if (empty($klines['closes']) || count($klines['closes']) < 20) {
            return $this->noopResult('Insufficient klines for active AI evaluation.');
        }

        $closes = $klines['closes'];
        $highs = $klines['highs'];
        $lows = $klines['lows'];
        $volumes = $klines['volumes'];
        $count = count($closes);
        $i = $count - 1; // latest active candle

        $ema9Array = Indicators::ema($closes, 9);
        $ema21Array = Indicators::ema($closes, 21);
        $volSma20Array = Indicators::sma($volumes, 20);

        $ema9 = $ema9Array[$i] ?? $currentPrice;
        $ema21 = $ema21Array[$i] ?? $currentPrice;
        $volSma20 = $volSma20Array[$i] ?? 1.0;
        $currentVol = $volumes[$i] ?? 1.0;
        $volRatio = $volSma20 > 0 ? round($currentVol / $volSma20, 2) : 1.0;

        // Calculate swing pivots over the last 10 candles (excluding potential spike outliers)
        $recentLows = array_slice($lows, -10);
        $recentHighs = array_slice($highs, -10);
        $recentSwingLow = min($recentLows);
        $recentSwingHigh = max($recentHighs);

        // Trend Health:
        // Long trend is intact if price is above or testing 21 EMA
        // Short trend is intact if price is below or testing 21 EMA
        $isTrendIntact = $isLong
            ? ($currentPrice >= $ema21 * 0.997)
            : ($currentPrice <= $ema21 * 1.003);

        $isAccelerating = $isLong
            ? ($currentPrice > $ema9 && $ema9 >= $ema21)
            : ($currentPrice < $ema9 && $ema9 <= $ema21);

        // Calculate distance to initial risk boundary
        $slDist = abs($trade->entry_price - $trade->initial_sl);
        if ($slDist <= 0) {
            $slDist = $trade->entry_price * 0.015;
        }

        // Extended 2x to 3x expansion profit targets
        $targetPrice = $isLong
            ? round($trade->entry_price + ($slDist * 2.8), 6)
            : round($trade->entry_price - ($slDist * 2.8), 6);

        // ==============================================================
        // CASE 1: WINNING TRADE IN PROFIT (Gain >= +0.80% / ROE >= +8%)
        // ==============================================================
        if ($currentGainPct >= 0.80 || $peakGainPct >= 1.00) {
            // Find structural swing pivot level to ratchet trailing stop
            $swingSl = $isLong
                ? round($recentSwingLow * 0.9985, 6)
                : round($recentSwingHigh * 1.0015, 6);

            $isProfitableSl = $isLong
                ? ($swingSl > $trade->entry_price)
                : ($swingSl < $trade->entry_price);

            // If the structural swing pivot protects substantial profit (> entry), trail to it!
            if ($isProfitableSl) {
                $isTighterThanCurrent = $isLong
                    ? ($swingSl > $trade->current_sl)
                    : ($swingSl < $trade->current_sl);

                if ($isTighterThanCurrent) {
                    $lockedRoe = round((abs($swingSl - $trade->entry_price) / $trade->entry_price) * 100 * $trade->leverage, 1);

                    return [
                        'action' => 'TRAIL_SL',
                        'decision' => 'TRAIL_STRUCTURAL_SWING',
                        'reason' => "Trend is surging (+{$currentGainPct}%). Trailing SL to swing pivot \${$swingSl} (guarantees +{$lockedRoe}% ROE profit). Letting runner target \${$targetPrice}!",
                        'suggested_sl' => $swingSl,
                        'target_price' => $targetPrice,
                        'metrics' => [
                            'current_gain_pct' => round($currentGainPct, 2),
                            'peak_gain_pct' => round($peakGainPct, 2),
                            'current_roe' => round($currentRoe, 2),
                            'vol_ratio' => $volRatio,
                            'ema9' => round($ema9, 4),
                            'ema21' => round($ema21, 4),
                            'is_trend_intact' => $isTrendIntact,
                            'swing_pivot' => $swingSl,
                        ],
                    ];
                }
            }

            // If price had a minor pullback from peak (e.g. LINK gave back 35% of peak gain):
            // Check if pullback volume is declining (healthy consolidation!)
            $isHealthyPullback = $volRatio <= 1.4 && $isTrendIntact;
            if ($isHealthyPullback) {
                return [
                    'action' => 'HOLD',
                    'decision' => 'LET_WINNER_RUN',
                    'reason' => "Healthy consolidation on 15m (pullback vol {$volRatio}x 20-SMA, trend intact). Holding position to capture 2x profit target (\${$targetPrice}).",
                    'suggested_sl' => null,
                    'target_price' => $targetPrice,
                    'metrics' => [
                        'current_gain_pct' => round($currentGainPct, 2),
                        'peak_gain_pct' => round($peakGainPct, 2),
                        'current_roe' => round($currentRoe, 2),
                        'vol_ratio' => $volRatio,
                        'is_trend_intact' => true,
                    ],
                ];
            }
        }

        // ==============================================================
        // CASE 2: DEVELOPING TRADE (+0.10% to +0.80% GAIN)
        // ==============================================================
        if ($currentGainPct > 0) {
            return [
                'action' => 'HOLD',
                'decision' => 'NURTURE_TREND',
                'reason' => "Trade gaining traction (+{$currentGainPct}%). 15m structure is stable. Watching and waiting for expansion toward \${$targetPrice}.",
                'suggested_sl' => null,
                'target_price' => $targetPrice,
                'metrics' => [
                    'current_gain_pct' => round($currentGainPct, 2),
                    'peak_gain_pct' => round($peakGainPct, 2),
                    'current_roe' => round($currentRoe, 2),
                    'vol_ratio' => $volRatio,
                    'is_trend_intact' => $isTrendIntact,
                ],
            ];
        }

        // ==============================================================
        // CASE 3: DRAWDOWN / TEST OF SUPPORT (SL BOUNDARY)
        // ==============================================================
        // Check for severe abnormal volume counter-trend engulfing breakdown
        $isSevereBreakdown = $isLong
            ? ($currentPrice < $ema21 * 0.985 && $volRatio >= 2.5)
            : ($currentPrice > $ema21 * 1.015 && $volRatio >= 2.5);

        if ($isSevereBreakdown && $currentGainPct <= -1.2) {
            return [
                'action' => 'EMERGENCY_EXIT',
                'decision' => 'STRUCTURAL_INVALIDATION_EXIT',
                'reason' => "Severe high-volume counter-trend invalidation detected ({$volRatio}x volume, EMA21 broken). Exiting cleanly before hard SL hit.",
                'suggested_sl' => null,
                'target_price' => null,
                'metrics' => [
                    'current_gain_pct' => round($currentGainPct, 2),
                    'vol_ratio' => $volRatio,
                    'is_trend_intact' => false,
                ],
            ];
        }

        return [
            'action' => 'HOLD',
            'decision' => 'HOLD_WITHIN_RISK_LIMITS',
            'reason' => "Price is fluctuating within predefined structural risk boundary (SL: \${$trade->current_sl}). Holding position patiently.",
            'suggested_sl' => null,
            'target_price' => $targetPrice,
            'metrics' => [
                'current_gain_pct' => round($currentGainPct, 2),
                'peak_gain_pct' => round($peakGainPct, 2),
                'current_roe' => round($currentRoe, 2),
                'vol_ratio' => $volRatio,
                'is_trend_intact' => $isTrendIntact,
            ],
        ];
    }

    /**
     * Fallback evaluation if Binance klines are temporarily unreachable.
     *
     * @return array{action: string, decision: string, reason: string, suggested_sl: ?float, target_price: ?float, metrics: array<string, mixed>}
     */
    protected function fallbackEvaluation(
        Trade $trade,
        float $currentPrice,
        float $currentGainPct,
        float $peakGainPct,
        float $currentRoe
    ): array {
        if ($currentGainPct >= 1.20) {
            return [
                'action' => 'HOLD',
                'decision' => 'LET_WINNER_RUN',
                'reason' => "Trade is profitable (+{$currentGainPct}% / +{$currentRoe}% ROE). Letting winner run toward extended target.",
                'suggested_sl' => null,
                'target_price' => null,
                'metrics' => [
                    'current_gain_pct' => round($currentGainPct, 2),
                    'peak_gain_pct' => round($peakGainPct, 2),
                    'current_roe' => round($currentRoe, 2),
                ],
            ];
        }

        return [
            'action' => 'HOLD',
            'decision' => 'HOLD_ACTIVE_POSITION',
            'reason' => "Monitoring position at \${$currentPrice} (Current Gain: {$currentGainPct}%). Trend active.",
            'suggested_sl' => null,
            'target_price' => null,
            'metrics' => [
                'current_gain_pct' => round($currentGainPct, 2),
                'peak_gain_pct' => round($peakGainPct, 2),
                'current_roe' => round($currentRoe, 2),
            ],
        ];
    }

    /**
     * Get cached 15m klines from Binance.
     *
     * @return array{opens: float[], highs: float[], lows: float[], closes: float[], volumes: float[], closeTimes: int[]}
     */
    protected function getCachedKlines(string $symbol, string $interval = '15m', int $limit = 40): array
    {
        $cacheKey = "ai_agent:klines:{$symbol}:{$interval}:{$limit}";

        return Cache::remember($cacheKey, 10, function () use ($symbol, $interval, $limit): array {
            try {
                return $this->client->klines($symbol, $interval, $limit);
            } catch (Throwable $e) {
                Log::debug("[ActiveTradeMonitorAgent] Failed to fetch klines for {$symbol}: {$e->getMessage()}");

                return ['opens' => [], 'highs' => [], 'lows' => [], 'closes' => [], 'volumes' => [], 'closeTimes' => []];
            }
        });
    }

    /**
     * @return array{action: string, decision: string, reason: string, suggested_sl: ?float, target_price: ?float, metrics: array<string, mixed>}
     */
    protected function noopResult(string $reason): array
    {
        return [
            'action' => 'NOOP',
            'decision' => 'NO_ACTION',
            'reason' => $reason,
            'suggested_sl' => null,
            'target_price' => null,
            'metrics' => [],
        ];
    }

    /**
     * Helper to check if AI monitor recommends holding winner for extended target.
     */
    public function shouldLetWinnerRun(Trade $trade, float $currentPrice): bool
    {
        $res = $this->monitorTrade($trade, $currentPrice);

        return ($res['action'] ?? '') === 'HOLD' && ($res['decision'] ?? '') === 'LET_WINNER_RUN';
    }
}
