<?php

namespace App\Services\Crypto;

class BtcMacroAlignment
{
    protected bool $enabled;

    protected int $emaFast;

    protected int $emaSlow;

    protected int $slopeLookback;

    protected float $minSlopePct;

    protected bool $require4h;

    protected int $ema4h;

    /**
     * @param  array<string, mixed>|null  $config
     */
    public function __construct(?array $config = null)
    {
        $cfg = $config ?? (array) config('crypto.btc_macro', []);

        $this->enabled = (bool) ($cfg['enabled'] ?? true);
        $this->emaFast = (int) ($cfg['ema_fast'] ?? 50);
        $this->emaSlow = (int) ($cfg['ema_slow'] ?? 200);
        $this->slopeLookback = (int) ($cfg['slope_lookback'] ?? 3);
        $this->minSlopePct = (float) ($cfg['min_slope_pct'] ?? 0.01);
        $this->require4h = (bool) ($cfg['require_4h_confluence'] ?? true);
        $this->ema4h = (int) ($cfg['ema_4h'] ?? 50);
    }

    /**
     * Evaluate explicit BTC macro alignment on CLOSED candles.
     *
     * Rules:
     * - Longs require bullish alignment (1h close > EMA200 & EMA50, EMA50 slope rising, 4h trend bullish).
     * - Shorts require bearish alignment (1h close < EMA200 & EMA50, EMA50 slope falling, 4h trend bearish).
     * - Neutral / Choppy / Conflicting means NO SIGNAL (allow_long = false, allow_short = false).
     *
     * @param  array<string, mixed>  $btc1hCandles
     * @param  array<string, mixed>|null  $btc4hCandles
     * @return array{
     *     trend: string, // 'BULLISH', 'BEARISH', 'NEUTRAL'
     *     allow_long: bool,
     *     allow_short: bool,
     *     btc_price: float,
     *     ema_fast_1h: ?float,
     *     ema_slow_1h: ?float,
     *     slope_pct: float,
     *     confluence_4h: ?string,
     *     reason: string
     * }
     */
    public function evaluate(array $btc1hCandles, ?array $btc4hCandles = null, ?int $referenceTimeMs = null): array
    {
        $clean1h = CandleSanitizer::onlyClosedCandles($btc1hCandles, $referenceTimeMs);
        $c1h = $clean1h['closes'] ?? [];
        $count1h = count($c1h);

        if ($count1h < max(30, $this->emaFast + $this->slopeLookback)) {
            return [
                'trend' => 'NEUTRAL',
                'allow_long' => false,
                'allow_short' => false,
                'btc_price' => 0.0,
                'ema_fast_1h' => null,
                'ema_slow_1h' => null,
                'slope_pct' => 0.0,
                'confluence_4h' => null,
                'reason' => "Insufficient closed 1h BTC candles (need at least {$this->emaFast}, got {$count1h}) - signals blocked",
            ];
        }

        $i = $count1h - 1;
        $btcPrice = (float) $c1h[$i];

        if (! $this->enabled) {
            return [
                'trend' => 'DISABLED',
                'allow_long' => true,
                'allow_short' => true,
                'btc_price' => $btcPrice,
                'ema_fast_1h' => null,
                'ema_slow_1h' => null,
                'slope_pct' => 0.0,
                'confluence_4h' => null,
                'reason' => 'BTC Macro Alignment filter disabled in config',
            ];
        }

        // 1. Calculate 1h EMAs
        $emaFastSeries = Indicators::ema($c1h, $this->emaFast);
        $emaSlowSeries = Indicators::ema($c1h, $this->emaSlow);

        $curEmaFast = $emaFastSeries[$i] ?? $btcPrice;
        $curEmaSlow = $emaSlowSeries[$i] ?? null;

        // 2. Calculate EMA Fast slope over slope_lookback bars
        $pastIdx = max(0, $i - $this->slopeLookback);
        $pastEmaFast = $emaFastSeries[$pastIdx] ?? $curEmaFast;
        $slopePct = $pastEmaFast > 0 ? round((($curEmaFast - $pastEmaFast) / $pastEmaFast) * 100, 4) : 0.0;

        // 3. 1h Trend conditions
        $h1AboveFast = $btcPrice > $curEmaFast;
        $h1BelowFast = $btcPrice < $curEmaFast;
        $h1AboveSlow = ($curEmaSlow === null) || ($btcPrice > $curEmaSlow);
        $h1BelowSlow = ($curEmaSlow === null) || ($btcPrice < $curEmaSlow);
        $h1SlopeUp = $slopePct >= $this->minSlopePct;
        $h1SlopeDown = $slopePct <= -$this->minSlopePct;

        $h1Bullish = $h1AboveFast && $h1AboveSlow && $h1SlopeUp;
        $h1Bearish = $h1BelowFast && $h1BelowSlow && $h1SlopeDown;

        // 4. 4h Trend Confluence (if enabled and 4h candles provided)
        $confluence4h = null;
        $h4Bullish = true;
        $h4Bearish = true;

        if ($this->require4h && $btc4hCandles !== null) {
            $clean4h = CandleSanitizer::onlyClosedCandles($btc4hCandles, $referenceTimeMs);
            $c4h = $clean4h['closes'] ?? [];
            $count4h = count($c4h);

            if ($count4h >= max(20, $this->ema4h)) {
                $j = $count4h - 1;
                $btc4hPrice = (float) $c4h[$j];
                $ema4hSeries = Indicators::ema($c4h, $this->ema4h);
                $curEma4h = $ema4hSeries[$j] ?? $btc4hPrice;

                $h4Bullish = $btc4hPrice > $curEma4h;
                $h4Bearish = $btc4hPrice < $curEma4h;
                $confluence4h = $h4Bullish ? 'BULLISH' : ($h4Bearish ? 'BEARISH' : 'NEUTRAL');
            } else {
                // If 4h is required but insufficient history exists, treat as neutral
                $h4Bullish = false;
                $h4Bearish = false;
                $confluence4h = 'INSUFFICIENT_DATA';
            }
        }

        // 5. Final Directional Determination
        if ($h1Bullish && $h4Bullish) {
            return [
                'trend' => 'BULLISH',
                'allow_long' => true,
                'allow_short' => false,
                'btc_price' => $btcPrice,
                'ema_fast_1h' => round($curEmaFast, 2),
                'ema_slow_1h' => $curEmaSlow !== null ? round($curEmaSlow, 2) : null,
                'slope_pct' => $slopePct,
                'confluence_4h' => $confluence4h,
                'reason' => 'BTC 1h > EMA200 & rising EMA50'.($confluence4h ? ' + 4h confluence' : ''),
            ];
        }

        if ($h1Bearish && $h4Bearish) {
            return [
                'trend' => 'BEARISH',
                'allow_long' => false,
                'allow_short' => true,
                'btc_price' => $btcPrice,
                'ema_fast_1h' => round($curEmaFast, 2),
                'ema_slow_1h' => $curEmaSlow !== null ? round($curEmaSlow, 2) : null,
                'slope_pct' => $slopePct,
                'confluence_4h' => $confluence4h,
                'reason' => 'BTC 1h < EMA200 & falling EMA50'.($confluence4h ? ' + 4h confluence' : ''),
            ];
        }

        // Neutral / Choppy / Conflicting: All signals blocked
        $neutralReasons = [];
        if (! $h1Bullish && ! $h1Bearish) {
            $neutralReasons[] = '1h price/EMAs choppy or slope flat ('.$slopePct.'%)';
        }
        if ($this->require4h && (($h1Bullish && ! $h4Bullish) || ($h1Bearish && ! $h4Bearish))) {
            $neutralReasons[] = '4h timeframe conflicting ('.($confluence4h ?? 'NEUTRAL').')';
        }

        return [
            'trend' => 'NEUTRAL',
            'allow_long' => false,
            'allow_short' => false,
            'btc_price' => $btcPrice,
            'ema_fast_1h' => round($curEmaFast, 2),
            'ema_slow_1h' => $curEmaSlow !== null ? round($curEmaSlow, 2) : null,
            'slope_pct' => $slopePct,
            'confluence_4h' => $confluence4h,
            'reason' => 'Neutral/Choppy BTC: '.implode('; ', $neutralReasons).' - all signals blocked',
        ];
    }
}
