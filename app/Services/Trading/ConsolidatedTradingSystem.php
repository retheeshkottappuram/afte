<?php

namespace App\Services\Trading;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Binance\BinanceFuturesClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ============================================================================
 * AFTE MASTER CONSOLIDATED TRADING ENGINE
 * ============================================================================
 * Single-file consolidated architecture incorporating:
 * 1. PhpCliResolver          -> Cross-platform PHP 8.4 runtime resolution.
 * 2. BreakoutDetector        -> Pre-breakout coiling, Wyckoff spring, confirmed breakouts.
 * 3. SignalScoringEngine     -> 100-point multi-timeframe confluence model.
 * 4. MicroRiskManager        -> Binance $5.00 minNotional compliance, dynamic sizing.
 * 5. NativeOrderExecutor     -> Isolated margin, 10x leverage, native SL/TP placement.
 * 6. DynamicLifecycleManager -> 3-tier profit harvesting, fee buffer breakeven, ATR runner.
 * 7. ExchangeReconciler      -> Position synchronization preventing phantom closes.
 * ============================================================================
 */
class ConsolidatedTradingSystem
{
    public function __construct(
        protected BinanceFuturesClient $client,
        protected ?TradeReconciler $tradeReconciler = null
    ) {
        $this->tradeReconciler = $tradeReconciler ?? app(TradeReconciler::class);
    }

    // =========================================================================
    // 1. PHP CLI RUNTIME RESOLVER (Solves VPS 8.0.30 vs 8.4.x CLI mismatch)
    // =========================================================================

    public static function resolvePhpCli(): string
    {
        return PhpCliResolver::resolve();
    }

    // =========================================================================
    // 2. BREAKOUT & PRE-BREAKOUT DETECTION ENGINE
    // =========================================================================

    /**
     * Evaluates candle arrays to detect high-probability pre-breakout setups
     * before the price explodes, identifying compression, springs, and coils.
     *
     * @param  array  $baseCandles  15m candles ['opens', 'highs', 'lows', 'closes', 'volumes', 'closeTimes']
     * @param  array|null  $htf1Candles  1h candles
     * @param  array|null  $htf2Candles  4h candles
     * @param  array|null  $btcCandles  BTC 15m candles
     */
    public function detectBreakoutSetup(
        array $baseCandles,
        ?array $htf1Candles = null,
        ?array $htf2Candles = null,
        ?array $btcCandles = null
    ): ?array {
        $closes = $baseCandles['closes'] ?? [];
        $highs = $baseCandles['highs'] ?? [];
        $lows = $baseCandles['lows'] ?? [];
        $opens = $baseCandles['opens'] ?? [];
        $volumes = $baseCandles['volumes'] ?? [];
        $closeTimes = $baseCandles['closeTimes'] ?? [];
        $count = count($closes);

        if ($count < 55) {
            return null;
        }

        $i = $count - 2; // Last closed candle
        $currentClose = (float) $closes[$i];
        $currentHigh = (float) $highs[$i];
        $currentLow = (float) $lows[$i];
        $currentOpen = (float) $opens[$i];
        $currentVol = (float) $volumes[$i];
        $closeTimeMs = (int) ($closeTimes[$i] ?? (now()->timestamp * 1000));

        // Technical indicators on 15m
        $ema9 = Indicators::ema($closes, 9);
        $ema21 = Indicators::ema($closes, 21);
        $ema200 = Indicators::ema($closes, 200);
        $rsi = Indicators::rsi($closes, 14);
        $atr = Indicators::atr($highs, $lows, $closes, 14);
        $volSma = Indicators::sma($volumes, 20);
        [$adx, $plusDI, $minusDI] = Indicators::adx($highs, $lows, $closes, 14);
        [$bbUpper, $bbMiddle, $bbLower, $bbWidth] = Indicators::bollingerBands($closes, 20, 2.0);
        $ttm = Indicators::ttmSqueeze($highs, $lows, $closes, 20);

        $curEma9 = (float) ($ema9[$i] ?? $currentClose);
        $curEma21 = (float) ($ema21[$i] ?? $currentClose);
        $curEma200 = isset($ema200[$i]) ? (float) $ema200[$i] : null;
        $curRsi = (float) ($rsi[$i] ?? 50.0);
        $curAtr = (float) ($atr[$i] ?? ($currentClose * 0.015));
        $atrPct = $currentClose > 0 ? round(($curAtr / $currentClose) * 100, 2) : 1.5;
        $curVolSma = (float) ($volSma[$i] ?? 1.0);
        $volRatio = $curVolSma > 0 ? round($currentVol / $curVolSma, 2) : 1.0;
        $curAdx = (float) ($adx[$i] ?? 20.0);

        // Higher-Timeframe Trend Alignment (1h)
        $htf1Bull = true;
        $htf1Bear = true;
        if ($htf1Candles && ! empty($htf1Candles['closes'])) {
            $htfCloses = $htf1Candles['closes'];
            $htfIdx = count($htfCloses) - 2;
            if ($htfIdx >= 0) {
                $htfEma21 = Indicators::ema($htfCloses, 21);
                $htfEma200 = Indicators::ema($htfCloses, 200);
                $htfClose = (float) $htfCloses[$htfIdx];
                $h200 = isset($htfEma200[$htfIdx]) ? (float) $htfEma200[$htfIdx] : null;
                $h21 = isset($htfEma21[$htfIdx]) ? (float) $htfEma21[$htfIdx] : null;

                $htf1Bull = ($h200 === null || $htfClose > $h200) && ($h21 === null || $htfClose >= $h21 * 0.995);
                $htf1Bear = ($h200 === null || $htfClose < $h200) && ($h21 === null || $htfClose <= $h21 * 1.005);
            }
        }

        // Relative Strength vs Bitcoin
        $rsRatio = 1.0;
        if ($btcCandles && ! empty($btcCandles['closes'])) {
            $rsRatio = Indicators::relativeStrength($closes, $btcCandles['closes'], 24);
        }

        // Structural Support & Resistance Lookback (30 candles)
        $structureLen = 30;
        $lookbackHighs = array_slice($highs, max(0, $i - $structureLen), $structureLen);
        $lookbackLows = array_slice($lows, max(0, $i - $structureLen), $structureLen);
        $resistance = max($lookbackHighs);
        $support = min($lookbackLows);

        $distToResPct = $resistance > 0 ? round((($resistance - $currentClose) / $resistance) * 100, 2) : 999.0;
        $distToSuppPct = $support > 0 ? round((($currentClose - $support) / $support) * 100, 2) : 999.0;

        $candleRange = max(0.0000001, $currentHigh - $currentLow);
        $body = abs($currentClose - $currentOpen);
        $bodyRatio = $candleRange > 0 ? $body / $candleRange : 0.0;
        $upperWick = $currentHigh - max($currentClose, $currentOpen);
        $lowerWick = min($currentClose, $currentOpen) - $currentLow;
        $upperWickRatio = $upperWick / $candleRange;
        $lowerWickRatio = $lowerWick / $candleRange;

        $isBullCandle = ($currentClose > $currentOpen) && ($bodyRatio >= 0.40) && ($upperWickRatio <= 0.30);
        $isBearCandle = ($currentClose < $currentOpen) && ($bodyRatio >= 0.40) && ($lowerWickRatio <= 0.30);

        // Volatility Squeeze Check (BB inside KC or tight BB width)
        $bbCompression = false;
        if (isset($bbWidth[$i]) && $bbWidth[$i] !== null) {
            $recentBbWidths = array_slice(array_filter($bbWidth), -25);
            if (! empty($recentBbWidths)) {
                $minWidth = min($recentBbWidths);
                $bbCompression = ($bbWidth[$i] <= $minWidth * 1.25);
            }
        }
        $isCompressed = ($ttm['squeeze_on'][$i] ?? false) || $bbCompression;

        $low1 = (float) $lows[$i];
        $low2 = (float) $lows[$i - 1];
        $low3 = (float) $lows[$i - 2];
        $hasHigherLows = ($low1 >= $low2 * 0.999 && $low2 >= $low3 * 0.999);

        $high1 = (float) $highs[$i];
        $high2 = (float) $highs[$i - 1];
        $high3 = (float) $highs[$i - 2];
        $hasLowerHighs = ($high1 <= $high2 * 1.001 && $high2 <= $high3 * 1.001);

        // ---------------------------------------------------------------------
        // PATTERN A: PRE-BREAKOUT ASCENDING COIL SQUEEZE (Enter BEFORE Breakout)
        // ---------------------------------------------------------------------
        if ($distToResPct >= 0.12 && $distToResPct <= 1.30 && ($hasHigherLows || $currentClose > $curEma9) && $isBullCandle && $upperWickRatio <= 0.28 && $rsRatio >= 0.995 && $htf1Bull) {
            $entry = $currentClose;
            $rawSl = min($low1, $low2) * 0.998;
            $score = 92 + ($isCompressed ? 4 : 0) + ($volRatio >= 1.2 ? 2 : 0);

            return $this->formatTradePackage('PRE_BREAKOUT_COIL', 'BUY', min(98, $score), 'A', $resistance, $distToResPct, $entry, $rawSl, $volRatio, $curRsi, $curAdx, $atrPct, 'PRE-BREAKOUT ASCENDING COIL', $closeTimeMs, $rsRatio);
        }

        // ---------------------------------------------------------------------
        // PATTERN B: PRE-BREAKDOWN DESCENDING COIL SQUEEZE (Enter BEFORE Breakdown)
        // ---------------------------------------------------------------------
        if ($distToSuppPct >= 0.12 && $distToSuppPct <= 1.30 && ($hasLowerHighs || $currentClose < $curEma9) && $isBearCandle && $lowerWickRatio <= 0.28 && $rsRatio <= 1.005 && $htf1Bear) {
            $entry = $currentClose;
            $rawSl = max($high1, $high2) * 1.002;
            $score = 92 + ($isCompressed ? 4 : 0) + ($volRatio >= 1.2 ? 2 : 0);

            return $this->formatTradePackage('PRE_BREAKDOWN_DESCENDING_COIL', 'SELL', min(98, $score), 'A', $support, $distToSuppPct, $entry, $rawSl, $volRatio, $curRsi, $curAdx, $atrPct, 'PRE-BREAKDOWN DESCENDING COIL', $closeTimeMs, $rsRatio);
        }

        // ---------------------------------------------------------------------
        // PATTERN C: WYCKOFF SPRING LIQUIDITY REVERSAL
        // ---------------------------------------------------------------------
        if (($currentLow < $support || $lows[$i - 1] < $support) && $currentClose > $support && $currentClose > $currentOpen && $lowerWickRatio >= 0.35 && $rsRatio >= 0.995 && ! $htf1Bear) {
            $entry = $currentClose;
            $rawSl = min($currentLow, (float) $lows[$i - 1]) * 0.998;

            return $this->formatTradePackage('WYCKOFF_SPRING', 'BUY', 95, 'A+', $support, 0.0, $entry, $rawSl, $volRatio, $curRsi, $curAdx, $atrPct, 'WYCKOFF SPRING REVERSAL', $closeTimeMs, $rsRatio);
        }

        // ---------------------------------------------------------------------
        // PATTERN D: WYCKOFF UPTHRUST LIQUIDITY REVERSAL
        // ---------------------------------------------------------------------
        if (($currentHigh > $resistance || $highs[$i - 1] > $resistance) && $currentClose < $resistance && $currentClose < $currentOpen && $upperWickRatio >= 0.35 && $rsRatio <= 1.005 && ! $htf1Bull) {
            $entry = $currentClose;
            $rawSl = max($currentHigh, (float) $highs[$i - 1]) * 1.002;

            return $this->formatTradePackage('WYCKOFF_UPTHRUST', 'SELL', 95, 'A+', $resistance, 0.0, $entry, $rawSl, $volRatio, $curRsi, $curAdx, $atrPct, 'WYCKOFF UPTHRUST REVERSAL', $closeTimeMs, $rsRatio);
        }

        // ---------------------------------------------------------------------
        // PATTERN E: CONFIRMED MOMENTUM BREAKOUT
        // ---------------------------------------------------------------------
        if ($currentClose > $resistance && $isBullCandle && $volRatio >= 1.25 && $curRsi >= 50.0 && $curRsi <= 74.0 && $rsRatio >= 0.995 && $htf1Bull && ($curEma200 === null || $currentClose > $curEma200)) {
            $entry = $currentClose;
            $rawSl = max($resistance * 0.995, $currentLow * 0.998);

            return $this->formatTradePackage('BREAKOUT_CONFIRMED', 'BUY', 88, 'A', $resistance, 0.0, $entry, $rawSl, $volRatio, $curRsi, $curAdx, $atrPct, 'RESISTANCE BREAKOUT CONFIRMED', $closeTimeMs, $rsRatio);
        }

        // ---------------------------------------------------------------------
        // PATTERN F: CONFIRMED MOMENTUM BREAKDOWN
        // ---------------------------------------------------------------------
        if ($currentClose < $support && $isBearCandle && $volRatio >= 1.25 && $curRsi <= 50.0 && $curRsi >= 26.0 && $rsRatio <= 1.005 && $htf1Bear && ($curEma200 === null || $currentClose < $curEma200)) {
            $entry = $currentClose;
            $rawSl = min($support * 1.005, $currentHigh * 1.002);

            return $this->formatTradePackage('BREAKDOWN_CONFIRMED', 'SELL', 88, 'A', $support, 0.0, $entry, $rawSl, $volRatio, $curRsi, $curAdx, $atrPct, 'SUPPORT BREAKDOWN CONFIRMED', $closeTimeMs, $rsRatio);
        }

        return null;
    }

    /**
     * Format a detected setup into standard risk-controlled payload with 3-tier targets.
     */
    protected function formatTradePackage(
        string $type,
        string $side,
        int $score,
        string $grade,
        float $breakoutLevel,
        float $distancePct,
        float $entry,
        float $rawSl,
        float $volRatio,
        float $rsi,
        float $adx,
        float $atrPct,
        string $setupLabel,
        int $closeTimeMs,
        float $rsRatio = 1.0
    ): array {
        // Enforce structural risk boundaries (min 0.75%, max 1.50%)
        $rawSlDist = abs($entry - $rawSl);
        $minSlDist = $entry * 0.0075;
        $maxSlDist = $entry * 0.0150;
        $risk = max($minSlDist, min($maxSlDist, $rawSlDist));

        $sl = $side === 'BUY' ? round($entry - $risk, 6) : round($entry + $risk, 6);
        $tp1 = $side === 'BUY' ? round($entry + ($risk * 1.80), 6) : round($entry - ($risk * 1.80), 6); // 1:1.80 R:R (+15% ROE)
        $tp2 = $side === 'BUY' ? round($entry + ($risk * 3.20), 6) : round($entry - ($risk * 3.20), 6); // 1:3.20 R:R (+27% ROE)
        $tp3 = $side === 'BUY' ? round($entry + ($risk * 5.00), 6) : round($entry - ($risk * 5.00), 6); // 1:5.00 R:R (+45% ROE runner)

        $slPct = $entry > 0 ? round(($risk / $entry) * 100, 2) : 1.0;
        $tp1Pct = $entry > 0 ? round(abs($tp1 - $entry) / $entry * 100, 2) : 1.8;
        $tp2Pct = $entry > 0 ? round(abs($tp2 - $entry) / $entry * 100, 2) : 3.2;
        $rrRatio = $slPct > 0 ? '1 : '.round($tp2Pct / $slPct, 1) : '1 : 2.8';

        return [
            'type' => $type,
            'side' => $side,
            'direction' => $side === 'BUY' ? 'LONG' : 'SHORT',
            'score' => $score,
            'grade' => $grade,
            'breakout_level' => round($breakoutLevel, 4),
            'distance_pct' => $distancePct,
            'entry' => round($entry, 4),
            'sl' => round($sl, 4),
            'tp1' => round($tp1, 4),
            'tp2' => round($tp2, 4),
            'tp3' => round($tp3, 4),
            'sl_pct' => $slPct,
            'tp1_pct' => $tp1Pct,
            'tp2_pct' => $tp2Pct,
            'risk_reward' => $rrRatio,
            'target_profit_pct' => $tp2Pct,
            'target_profit_leveraged_pct' => round($tp2Pct * 10, 1),
            'volume_ratio' => $volRatio,
            'rsi' => round($rsi, 1),
            'adx' => round($adx, 1),
            'atr_pct' => $atrPct,
            'setup_label' => $setupLabel,
            'candle_close_time' => $closeTimeMs,
            'rs_ratio' => round($rsRatio, 4),
            'perpetual_options' => [
                'recommended_leverage' => '8x - 10x',
                'margin_mode' => 'Isolated Margin',
                'risk_reward' => $rrRatio,
                'tp1_close_ratio' => 0.35,
                'tp2_close_ratio' => 0.35,
                'runner_ratio' => 0.30,
            ],
        ];
    }

    // =========================================================================
    // 3. MICRO-CAPITAL POSITION SIZER (Binance $5 minNotional Compliance)
    // =========================================================================

    /**
     * Calculate safe position sizing strictly adhering to Binance minNotional
     * and preventing overleveraging on accounts under $10 USD.
     */
    public function calculatePositionSize(TradingAccount $account, string $symbol, float $entryPrice, float $slPrice): array
    {
        $equity = max(1.0, (float) $account->balance);
        $minNotional = 5.20; // Binance requires >= $5.00 notional; $5.20 provides safety margin against tick drops
        $leverage = 10;

        // Banned toxic assets that destroyed margin in historical audit
        $bannedSymbols = ['GRAMUSDT', '牛来USDT', 'AKEUSDT', 'GUSDT', 'USUSDT'];
        if (in_array(strtoupper($symbol), $bannedSymbols, true)) {
            return ['allowed' => false, 'reason' => "Symbol {$symbol} is on the toxic illiquid blacklist."];
        }

        // Max concurrent positions guard: strict cap of 2 for accounts <= $25
        $openCount = Trade::where('mode', $account->mode)->where('status', 'OPEN')->count();
        if ($openCount >= 2) {
            return ['allowed' => false, 'reason' => "Maximum 2 open positions reached ({$openCount} active). Protecting margin cushion."];
        }

        $margin = round($minNotional / $leverage, 4); // ~$0.52 USD margin per position
        if ($margin > ($equity * 0.30)) {
            // If account has shrunk to $2.00, allocate up to 45% margin to satisfy Binance $5 minNotional
            if ($margin > ($equity * 0.50)) {
                return ['allowed' => false, 'reason' => "Account equity (\${$equity}) insufficient to cover Binance \$5.00 minimum order."];
            }
        }

        $quantity = $entryPrice > 0 ? ($minNotional / $entryPrice) : 0.0;

        return [
            'allowed' => true,
            'quantity' => $quantity,
            'margin' => $margin,
            'leverage' => $leverage,
            'notional' => $minNotional,
            'risk_usd' => round(abs($entryPrice - $slPrice) * $quantity, 4),
        ];
    }

    // =========================================================================
    // 4. ORDER EXECUTION WITH NATIVE BINANCE ALGO PROTECTION
    // =========================================================================

    /**
     * Submit live order to Binance with Isolated Margin, 10x leverage, and
     * synchronized native exchange Algo Stop Loss.
     */
    public function executeTrade(array $setup, string $mode = 'live'): array
    {
        $symbol = $setup['symbol'];
        $side = $setup['side'];
        $direction = $side === 'BUY' ? 'LONG' : 'SHORT';
        $entryPrice = (float) $setup['entry'];
        $slPrice = (float) $setup['sl'];
        $tp1Price = (float) $setup['tp1'];
        $tp2Price = (float) $setup['tp2'];

        $account = TradingAccount::getForMode($mode);
        $sizing = $this->calculatePositionSize($account, $symbol, $entryPrice, $slPrice);

        if (! $sizing['allowed']) {
            return ['status' => 'rejected', 'message' => $sizing['reason'], 'trade' => null];
        }

        $quantity = (float) $sizing['quantity'];
        $margin = (float) $sizing['margin'];
        $leverage = (int) $sizing['leverage'];
        $binanceOrderId = null;

        if ($mode === 'live') {
            if (! config('trading.allow_live_trading', true)) {
                return ['status' => 'rejected', 'message' => 'Live trading disabled in configuration.', 'trade' => null];
            }

            try {
                $fapi = $this->client->forMode('live');
                try {
                    $fapi->setMarginType($symbol, 'ISOLATED');
                } catch (Throwable) {
                }
                try {
                    $fapi->setLeverage($symbol, $leverage);
                } catch (Throwable) {
                }

                $binanceSide = $side === 'BUY' ? 'BUY' : 'SELL';
                $formattedQty = $fapi->formatQuantity($symbol, $quantity);

                $orderResult = $fapi->placeOrder([
                    'symbol' => $symbol,
                    'side' => $binanceSide,
                    'type' => 'MARKET',
                    'quantity' => $formattedQty,
                ]);

                $binanceOrderId = (string) ($orderResult['orderId'] ?? null);
                if (isset($orderResult['avgPrice']) && (float) $orderResult['avgPrice'] > 0) {
                    $entryPrice = (float) $orderResult['avgPrice'];
                }

                // Place native exchange Stop Loss protection
                $closeSide = $side === 'BUY' ? 'SELL' : 'BUY';
                try {
                    $fapi->placeStopLoss($symbol, $closeSide, $slPrice, (float) $formattedQty, true);
                } catch (Throwable $slErr) {
                    Log::warning("[ConsolidatedEngine] Native SL placement error on {$symbol}: {$slErr->getMessage()}");
                }

                $fapi->clearAccountCache();
            } catch (Throwable $e) {
                Log::error("[ConsolidatedEngine] Live Binance order failed on {$symbol}: {$e->getMessage()}");

                return ['status' => 'error', 'message' => "Binance execution failed: {$e->getMessage()}", 'trade' => null];
            }
        }

        // Create Database Trade Record
        $trade = Trade::create([
            'symbol' => $symbol,
            'side' => $direction,
            'mode' => $mode,
            'status' => 'OPEN',
            'stage' => 'ENTRY',
            'entry_price' => $entryPrice,
            'quantity' => $quantity,
            'remaining_quantity' => $quantity,
            'margin_used' => $margin,
            'leverage' => $leverage,
            'initial_sl' => $slPrice,
            'current_sl' => $slPrice,
            'tp1_price' => $tp1Price,
            'tp2_price' => $tp2Price,
            'be_locked' => false,
            'tp1_hit' => false,
            'tp2_hit' => false,
            'binance_order_id' => $binanceOrderId,
            'meta' => [
                'setup_type' => $setup['setup_type'] ?? 'CONSOLIDATED_ENTRY',
                'setup_label' => $setup['setup_label'] ?? 'ACTIVE SETUP',
                'score' => $setup['score'] ?? 85,
                'risk_reward' => $setup['risk_reward'] ?? '1:2.8',
            ],
            'opened_at' => Carbon::now(),
        ]);

        return [
            'status' => 'opened',
            'message' => "Successfully opened {$symbol} {$direction}! Active on {$mode} mode.",
            'trade' => $trade,
        ];
    }

    // =========================================================================
    // 5. 3-TIER ACTIVE TRADE LIFECYCLE MANAGER (Preserves Winning Runners)
    // =========================================================================

    /**
     * Actively manage open trade against live tick mark price.
     * Replaces premature 35% giveback with 3-tier partial harvesting (35% TP1, 35% TP2, 30% Runner).
     */
    public function manageActiveTrade(Trade $trade, float $currentPrice): array
    {
        if (! $trade->isOpen() || $trade->entry_price <= 0) {
            return ['status' => 'ignored', 'message' => 'Trade is not open.'];
        }

        $isLong = $trade->isLong();

        // 1. Check Hard Stop Loss Breach
        $slHit = $isLong ? ($currentPrice <= $trade->current_sl) : ($currentPrice >= $trade->current_sl);
        if ($slHit) {
            $reason = $trade->stage === 'TRAILING' ? 'TRAILING_STOP' : ($trade->be_locked ? 'BREAKEVEN_STOP' : 'STOP_LOSS');

            return $this->closeTrade($trade, $currentPrice, $reason);
        }

        $gainPct = $isLong
            ? (($currentPrice - $trade->entry_price) / $trade->entry_price) * 100.0
            : (($trade->entry_price - $currentPrice) / $trade->entry_price) * 100.0;

        // 2. TIER 1: Breakeven Lock with Fee Buffer (+0.25%)
        // Activated at +1.20% price gain (+12% ROE at 10x). Guarantees profit after round-trip fees.
        if (! $trade->be_locked && $gainPct >= 1.20) {
            $feeBufferPct = 0.25; // 0.25% buffer overcomes Binance 0.10% round-trip fee
            $newSl = $isLong
                ? $trade->entry_price * (1.0 + ($feeBufferPct / 100.0))
                : $trade->entry_price * (1.0 - ($feeBufferPct / 100.0));

            $trade->current_sl = round($newSl, 6);
            $trade->be_locked = true;
            $trade->stage = 'BE_LOCKED';
            $this->updateExchangeStopLoss($trade, $trade->current_sl);
            Log::info("[ConsolidatedEngine] Trade #{$trade->id} ({$trade->symbol}) Breakeven locked at \${$trade->current_sl} (+{$feeBufferPct}% net profit guaranteed)");
        }

        // 3. TIER 2: Take Profit 1 (TP1) - Harvest 35% of initial position
        $tp1Hit = $isLong ? ($currentPrice >= $trade->tp1_price) : ($currentPrice <= $trade->tp1_price);
        if (! $trade->tp1_hit && $tp1Hit) {
            $ratio = 0.35;
            $closeQty = $this->client->formatQuantity($trade->symbol, $trade->quantity * $ratio);

            if ($closeQty > 0 && $closeQty < $trade->remaining_quantity) {
                $pnl = $isLong
                    ? ($currentPrice - $trade->entry_price) * $closeQty
                    : ($trade->entry_price - $currentPrice) * $closeQty;

                $trade->realized_pnl = round($trade->realized_pnl + $pnl, 4);
                $trade->remaining_quantity = round($trade->remaining_quantity - $closeQty, 6);
                $trade->tp1_hit = true;
                $trade->stage = 'TP1_HIT';

                // Ratchet SL to +0.60% profit (+6% ROE locked)
                $lockSl = $isLong ? $trade->entry_price * 1.006 : $trade->entry_price * 0.994;
                $trade->current_sl = round($lockSl, 6);
                $this->updateExchangeStopLoss($trade, $trade->current_sl);
                Log::info("[ConsolidatedEngine] Trade #{$trade->id} TP1 booked! Banked \${$pnl}. SL ratcheted to +0.60%.");
            }
        }

        // 4. TIER 3: Take Profit 2 (TP2) - Harvest second 35% (70% total banked)
        $tp2Hit = $isLong ? ($currentPrice >= $trade->tp2_price) : ($currentPrice <= $trade->tp2_price);
        if ($trade->tp1_hit && ! $trade->tp2_hit && $tp2Hit) {
            $ratio = 0.35;
            $closeQty = $this->client->formatQuantity($trade->symbol, $trade->quantity * $ratio);

            if ($closeQty > 0 && $closeQty < $trade->remaining_quantity) {
                $pnl = $isLong
                    ? ($currentPrice - $trade->entry_price) * $closeQty
                    : ($trade->entry_price - $currentPrice) * $closeQty;

                $trade->realized_pnl = round($trade->realized_pnl + $pnl, 4);
                $trade->remaining_quantity = round($trade->remaining_quantity - $closeQty, 6);
                $trade->tp2_hit = true;
                $trade->stage = 'TP2_HIT';

                // Ratchet SL to +2.00% profit (+20% ROE locked)
                $lockSl = $isLong ? $trade->entry_price * 1.020 : $trade->entry_price * 0.980;
                $trade->current_sl = round($lockSl, 6);
                $this->updateExchangeStopLoss($trade, $trade->current_sl);
                Log::info("[ConsolidatedEngine] Trade #{$trade->id} TP2 booked! Total 70% banked. Runner remaining.");
            }
        }

        // 5. TIER 4: RUNNER (Remaining 30%) - Dynamic ATR Chandelier Trailing
        // Solves the exact issue where LINK was choked at +$0.04 instead of catching +$0.23+
        if ($trade->tp2_hit || ($trade->tp1_hit && $gainPct >= 2.50)) {
            $trade->stage = 'TRAILING';
            $trailDist = $trade->entry_price * 0.015; // 1.5% trailing distance behind runner

            if ($isLong) {
                $candidateSl = round($currentPrice - $trailDist, 6);
                if ($candidateSl > $trade->current_sl) {
                    $trade->current_sl = $candidateSl;
                    $this->updateExchangeStopLoss($trade, $trade->current_sl);
                }
            } else {
                $candidateSl = round($currentPrice + $trailDist, 6);
                if ($candidateSl < $trade->current_sl) {
                    $trade->current_sl = $candidateSl;
                    $this->updateExchangeStopLoss($trade, $trade->current_sl);
                }
            }
        }

        $trade->save();

        return ['status' => 'managed', 'message' => "Trade #{$trade->id} active at \${$currentPrice}. Stage: {$trade->stage}."];
    }

    /**
     * Close out trade remaining quantity cleanly and record final metrics.
     */
    public function closeTrade(Trade $trade, float $exitPrice, string $reason): array
    {
        $closeQty = $trade->remaining_quantity > 0 ? $trade->remaining_quantity : $trade->quantity;
        $exitOrderId = null;

        if ($trade->mode === 'live') {
            try {
                $fapi = $this->client->forMode('live');
                $closeSide = $trade->isLong() ? 'SELL' : 'BUY';
                $formattedQty = $fapi->formatQuantity($trade->symbol, $closeQty);

                $res = $fapi->placeOrder([
                    'symbol' => $trade->symbol,
                    'side' => $closeSide,
                    'type' => 'MARKET',
                    'quantity' => $formattedQty,
                    'reduceOnly' => 'true',
                ]);
                $exitOrderId = $res['orderId'] ?? null;
            } catch (Throwable $e) {
                Log::warning("[ConsolidatedEngine] Failed live close order on Binance for #{$trade->id}: {$e->getMessage()}");
            }
        }

        $trade->exit_price = $exitPrice;
        $trade->exit_reason = $reason;

        // Reconcile truthfully via TradeReconciler
        $this->tradeReconciler->reconcileClosedTrade($trade, $exitOrderId, $reason);

        Log::info("[ConsolidatedEngine] Trade #{$trade->id} ({$trade->symbol}) CLOSED. Reason: {$reason}, Net PnL: \${$trade->net_pnl}, Fee: \${$trade->commission}");

        return ['status' => 'closed', 'message' => "Trade #{$trade->id} closed via {$reason} at \${$trade->exit_price}."];
    }

    /**
     * Update native Binance Stop Loss algo order when trailing stops advance.
     */
    protected function updateExchangeStopLoss(Trade $trade, float $newSl): void
    {
        if ($trade->mode !== 'live') {
            return;
        }

        try {
            $fapi = $this->client->forMode('live');
            $closeSide = $trade->isLong() ? 'SELL' : 'BUY';
            $qty = $trade->remaining_quantity > 0 ? $trade->remaining_quantity : $trade->quantity;
            $formattedQty = $fapi->formatQuantity($trade->symbol, $qty);

            $fapi->placeStopLoss($trade->symbol, $closeSide, $newSl, (float) $formattedQty, true);
        } catch (Throwable $e) {
            Log::debug("[ConsolidatedEngine] SL update notice on {$trade->symbol}: {$e->getMessage()}");
        }
    }
}
