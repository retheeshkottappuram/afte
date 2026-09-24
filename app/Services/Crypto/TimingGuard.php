<?php

namespace App\Services\Crypto;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TimingGuard
{
    public const MAX_ALLOWED_SLIPPAGE_PCT = 0.70; // Max 0.70% price movement beyond entry before flagging as missed

    public const MAX_TP1_PROGRESS_PCT = 45.0; // If already > 45% towards TP1, entry is missed

    public function __construct(
        protected ?BinanceClient $binanceClient = null
    ) {
        $this->binanceClient = $binanceClient ?? new BinanceClient;
    }

    /**
     * Perform real-time live price and timing validation before sending a signal.
     *
     * @param  string  $symbol  Pair (e.g. BTCUSDT)
     * @param  string  $side  'BUY' or 'SELL'
     * @param  float  $entryPrice  Calculated entry price from candle close/breakout
     * @param  float|null  $tp1Price  Target Take Profit 1 price
     * @param  int  $candleCloseTimeMs  Candle close timestamp in milliseconds
     * @param  float|null  $detectionTimeMs  Microtime timestamp when condition was detected
     * @return array{
     *     is_actionable: bool,
     *     status: string,
     *     live_price: float,
     *     slippage_pct: float,
     *     tp_progress_pct: float,
     *     ist_timestamp: string,
     *     latency_ms: int,
     *     reason: ?string
     * }
     */
    public function validateEntry(
        string $symbol,
        string $side,
        float $entryPrice,
        ?float $tp1Price = null,
        int $candleCloseTimeMs = 0,
        ?float $detectionTimeMs = null
    ): array {
        $cleanSymbol = strtoupper(str_replace('.P', '', trim($symbol)));
        $nowMs = (int) round(microtime(true) * 1000);
        $detectionMs = $detectionTimeMs ? (int) round($detectionTimeMs * 1000) : $nowMs;

        // Fetch live ticker price directly from Binance Futures
        $livePrice = $this->fetchLivePrice($cleanSymbol);
        if ($livePrice <= 0) {
            $livePrice = $entryPrice;
        }

        $side = strtoupper($side);
        $isBuy = ($side === 'BUY');

        // Calculate slippage percentage from intended entry
        if ($entryPrice > 0) {
            $slippagePct = $isBuy
                ? round((($livePrice - $entryPrice) / $entryPrice) * 100, 3)
                : round((($entryPrice - $livePrice) / $entryPrice) * 100, 3);
        } else {
            $slippagePct = 0.0;
        }

        // Calculate TP1 progress
        $tpProgressPct = 0.0;
        if ($tp1Price !== null && $tp1Price > 0 && $entryPrice > 0) {
            $tpDistance = abs($tp1Price - $entryPrice);
            if ($tpDistance > 0) {
                $actualMove = $isBuy ? ($livePrice - $entryPrice) : ($entryPrice - $livePrice);
                $tpProgressPct = round(max(0, ($actualMove / $tpDistance) * 100), 1);
            }
        }

        // Calculate latency from candle close or detection
        $latencyMs = 0;
        if ($candleCloseTimeMs > 0 && $nowMs >= $candleCloseTimeMs) {
            $latencyMs = $nowMs - $candleCloseTimeMs;
        } else {
            $latencyMs = max(5, $nowMs - $detectionMs);
        }

        $istTimestamp = Carbon::now('Asia/Kolkata')->format('d-M-Y H:i:s \I\S\T');

        // Evaluate whether price moved too far before execution
        $isActionable = true;
        $status = 'VALID';
        $reason = null;

        if ($slippagePct > self::MAX_ALLOWED_SLIPPAGE_PCT || $tpProgressPct > self::MAX_TP1_PROGRESS_PCT) {
            $isActionable = false;
            $status = 'MISSED';
            $reason = sprintf(
                'Price moved +%.2f%% (%.1f%% of TP1) beyond entry before execution. Do not chase.',
                $slippagePct,
                $tpProgressPct
            );
            Log::warning("TimingGuard: Entry missed for {$cleanSymbol} {$side} (Entry: {$entryPrice}, Live: {$livePrice}, Slippage: +{$slippagePct}%)");
        } elseif ($slippagePct < -1.8) {
            // Price collapsed significantly below buy entry or spiked above sell entry
            $isActionable = false;
            $status = 'INVALIDATED';
            $reason = sprintf(
                'Price diverged %.2f%% in opposite direction. Setup invalidated.',
                abs($slippagePct)
            );
            Log::warning("TimingGuard: Setup invalidated for {$cleanSymbol} {$side} (Entry: {$entryPrice}, Live: {$livePrice})");
        }

        return [
            'is_actionable' => $isActionable,
            'status' => $status,
            'live_price' => $livePrice,
            'slippage_pct' => $slippagePct,
            'tp_progress_pct' => $tpProgressPct,
            'ist_timestamp' => $istTimestamp,
            'latency_ms' => $latencyMs,
            'reason' => $reason,
        ];
    }

    /**
     * Fetch the live real-time price for a symbol from Binance Futures.
     */
    public function fetchLivePrice(string $symbol): float
    {
        $symbol = strtoupper(str_replace('.P', '', trim($symbol)));
        $market = $this->binanceClient?->getMarket() ?? 'futures';
        $endpoint = ($market === 'spot')
            ? 'https://api.binance.com/api/v3/ticker/price'
            : 'https://fapi.binance.com/fapi/v1/ticker/price';

        try {
            $response = Http::timeout(3)
                ->acceptJson()
                ->get($endpoint, ['symbol' => $symbol]);

            if ($response->successful()) {
                $price = (float) ($response->json('price') ?? 0.0);
                if ($price > 0) {
                    return $price;
                }
            }
        } catch (Throwable $e) {
            Log::debug("TimingGuard: Failed to fetch live price for {$symbol}: {$e->getMessage()}");
        }

        return 0.0;
    }
}
