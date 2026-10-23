<?php

namespace App\Services\Breakout;

/**
 * Stage A: a volatility-compressed range on completed 5m candles.
 *
 * After candle k has closed, the range is candles k-n+1..k (n = 20): R = max high, S = min low,
 * W = R - S. It qualifies when W / ATR14(k) <= 4.0 AND the Bollinger bandwidth at k sits at or
 * below the 30th percentile of the previous 100 bandwidths. The breakout candidate is candle k+1,
 * so the boundaries never include the breakout candle or any later candle.
 */
class ConsolidationDetector
{
    public function __construct(protected EbdConfig $config) {}

    /**
     * @param  array<string, array<int, ?float>>  $s  IndicatorCalculator::series()
     * @return array{ok: bool, reason: ?string, range?: array<string, float|int>}
     */
    public function detect(Candles $c, array $s, int $k, ?float $spreadBps = null): array
    {
        $n = $this->config->int('compression.lookback');
        if ($k < $n - 1 || $k > $c->lastIndex()) {
            return ['ok' => false, 'reason' => 'not enough candles'];
        }

        $atr = $s['atr'][$k] ?? null;
        $bbwPct = $s['bbw_pct'][$k] ?? null;
        $vavg = self::nextBaseline($c, $k, $this->config->int('indicators.volume_baseline'));
        if ($atr === null || $atr <= 0 || $bbwPct === null) {
            return ['ok' => false, 'reason' => 'indicator warm-up incomplete'];
        }
        if ($vavg === null || $vavg <= 0) {
            return ['ok' => false, 'reason' => 'no volume baseline'];
        }

        $highs = array_slice($c->highs, $k - $n + 1, $n);
        $lows = array_slice($c->lows, $k - $n + 1, $n);
        $resistance = max($highs);
        $support = min($lows);
        $rangeRatio = ($resistance - $support) / $atr;

        if ($rangeRatio > $this->config->float('compression.max_range_atr')) {
            return ['ok' => false, 'reason' => sprintf('range %.2f ATR > %.2f', $rangeRatio, $this->config->float('compression.max_range_atr'))];
        }
        if ($bbwPct > $this->config->float('compression.bbw_percentile')) {
            return ['ok' => false, 'reason' => sprintf('bandwidth percentile %.0f > %.0f', $bbwPct, $this->config->float('compression.bbw_percentile'))];
        }
        if ($spreadBps !== null && $spreadBps > $this->config->float('liquidity.max_spread_bps')) {
            return ['ok' => false, 'reason' => sprintf('spread %.1f bps too wide', $spreadBps)];
        }

        return ['ok' => true, 'reason' => null, 'range' => [
            'resistance' => $resistance,
            'support' => $support,
            'atr' => $atr,
            'natr' => $atr / $c->closes[$k],
            'range_ratio' => $rangeRatio,
            'bbw' => (float) $s['bbw'][$k],
            'bbw_pct' => $bbwPct,
            'vol_baseline' => $vavg,
            'bar_index' => $k,
            'bar_close_ms' => $c->closeTimes[$k],
            'long_trigger' => $resistance + $this->config->float('early.buffer_atr') * $atr,
            'short_trigger' => $support - $this->config->float('early.buffer_atr') * $atr,
            'long_confirm' => $resistance + $this->config->float('confirm.buffer_atr') * $atr,
            'short_confirm' => $support - $this->config->float('confirm.buffer_atr') * $atr,
        ]];
    }

    /** Volume baseline for the candle after k (mean of candles k-n+1..k) when k is the last closed candle. */
    public static function nextBaseline(Candles $c, int $k, int $n): ?float
    {
        if ($k < $n - 1) {
            return null;
        }

        return array_sum(array_slice($c->volumes, $k - $n + 1, $n)) / $n;
    }
}
