<?php

namespace App\Services\Breakout;

/**
 * Deterministic 0-100 quality score. Each component is a linear ramp between documented anchors,
 * clamped to its maximum points, and is independently testable:
 *
 *   compression   15  = 9 * (1 - bbw_pct / pct_max)  + 6 * (max_rr - range_ratio) / (max_rr - 1.5)
 *   breakout      20  = ramps 0 -> 20 as clearance goes 0 -> 0.10 ATR, holds 20 to 0.75 ATR, falls to 0 at the max extension
 *   volume        25  = 20 * (rvol - 1) / 2  (1x = 0, 3x = 20)  + 5 if volume is accelerating
 *   candle        15  = 8 * body_atr / 1.0  + 7 * (close_location - 0.5) / 0.5
 *   momentum      10  = 5 * |RSI - 50| / 20 in the breakout direction + 2 if RSI moves that way + 3 if EMA9/EMA21 agree
 *   liquidity     10  = 7 * (max_spread - spread) / (max_spread - 1 bps) + 3 * (24h volume - $20M) / ($500M - $20M)
 *   higher tf      5  = 3 if the 1h close is on the trade side of a 1h EMA21 sloping that way + 2 if the 15m chart is also compressed
 *
 * A missing input scores 0 for its part (spread missing = half of the spread part) and is listed in `missing`.
 * The score never creates a breakout: callers only score setups whose core price break already passed.
 */
class BreakoutQualityScorer
{
    public const MAX = ['compression' => 15, 'breakout' => 20, 'volume' => 25, 'candle' => 15, 'momentum' => 10, 'liquidity' => 10, 'higher_tf' => 5];

    public function __construct(protected EbdConfig $config) {}

    public static function ramp(?float $x, float $from, float $to): float
    {
        if ($x === null || $to == $from) {
            return 0.0;
        }

        return max(0.0, min(1.0, ($x - $from) / ($to - $from)));
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array{score: int, bucket: string, components: array<string, float>, missing: array<int, string>}
     */
    public function score(array $f): array
    {
        $missing = [];
        $components = [
            'compression' => $this->compression($f['bbw_pct'] ?? null, $f['range_ratio'] ?? null),
            'breakout' => $this->breakout($f['extension_atr'] ?? null),
            'volume' => $this->volume($f['volume'] ?? null, $f['volume_accelerating'] ?? null),
            'candle' => $this->candle($f['body_atr'] ?? null, $f['close_location'] ?? null),
            'momentum' => $this->momentum($f['direction'], $f['rsi'] ?? null, $f['rsi_prev'] ?? null, $f['ema_fast'] ?? null, $f['ema_slow'] ?? null),
            'liquidity' => $this->liquidity($f['spread_bps'] ?? null, $f['quote_volume_24h'] ?? null),
            'higher_tf' => $this->higherTimeframe($f['h1_aligned'] ?? null, $f['m15_compressed'] ?? null),
        ];
        foreach (['volume', 'body_atr', 'close_location', 'rsi', 'spread_bps', 'quote_volume_24h', 'h1_aligned', 'm15_compressed'] as $key) {
            if (! isset($f[$key])) {
                $missing[] = $key;
            }
        }

        $score = (int) round(array_sum($components));

        return ['score' => $score, 'bucket' => $this->bucket($score), 'components' => array_map(fn (float $v): float => round($v, 2), $components), 'missing' => $missing];
    }

    public function bucket(int $score): string
    {
        return match (true) {
            $score >= $this->config->float('score.high') => 'HIGH',
            $score >= $this->config->float('score.valid') => 'VALID',
            $score >= $this->config->float('score.watchlist') => 'WATCHLIST',
            default => 'REJECT',
        };
    }

    public function compression(?float $bbwPct, ?float $rangeRatio): float
    {
        $pctMax = $this->config->float('compression.bbw_percentile');
        $rrMax = $this->config->float('compression.max_range_atr');

        return 9 * ($bbwPct === null ? 0 : 1 - self::ramp($bbwPct, 0, $pctMax))
            + 6 * ($rangeRatio === null ? 0 : 1 - self::ramp($rangeRatio, 1.5, $rrMax));
    }

    public function breakout(?float $extensionAtr): float
    {
        if ($extensionAtr === null || $extensionAtr <= 0) {
            return 0.0;
        }
        $full = $this->config->float('confirm.buffer_atr');
        $max = $this->config->float('max_extension_atr');

        return match (true) {
            $extensionAtr < $full => 20 * self::ramp($extensionAtr, 0, $full),
            $extensionAtr <= 0.75 => 20.0,
            default => 20 * (1 - self::ramp($extensionAtr, 0.75, $max)),
        };
    }

    public function volume(?float $relative, ?bool $accelerating): float
    {
        return 20 * self::ramp($relative, 1.0, 3.0) + ($accelerating === true ? 5 : 0);
    }

    public function candle(?float $bodyAtr, ?float $closeLocation): float
    {
        return 8 * self::ramp($bodyAtr, 0, 1.0) + 7 * self::ramp($closeLocation, 0.5, 1.0);
    }

    public function momentum(string $direction, ?float $rsi, ?float $rsiPrev, ?float $emaFast, ?float $emaSlow): float
    {
        $sign = $direction === 'LONG' ? 1 : -1;
        $points = $rsi === null ? 0 : 5 * self::ramp(($rsi - 50) * $sign, 0, 20);
        if ($rsi !== null && $rsiPrev !== null && ($rsi - $rsiPrev) * $sign > 0) {
            $points += 2;
        }
        if ($emaFast !== null && $emaSlow !== null && ($emaFast - $emaSlow) * $sign > 0) {
            $points += 3;
        }

        return $points;
    }

    public function liquidity(?float $spreadBps, ?float $quoteVolume24h): float
    {
        $spread = $spreadBps === null ? 3.5 : 7 * (1 - self::ramp($spreadBps, 1.0, $this->config->float('liquidity.max_spread_bps')));

        return $spread + 3 * self::ramp($quoteVolume24h, 20_000_000, 500_000_000);
    }

    public function higherTimeframe(?bool $h1Aligned, ?bool $m15Compressed): float
    {
        return ($h1Aligned === true ? 3 : 0) + ($m15Compressed === true ? 2 : 0);
    }
}
