<?php

namespace App\Services\Breakout;

/**
 * Stage B (early, live price) and Stage C (confirmed, closed candle) breakout checks.
 * Both always measure against the boundaries and ATR captured when the range was recorded,
 * never against a range recomputed after the break.
 */
class BreakoutTriggerDetector
{
    public function __construct(protected EbdConfig $config) {}

    /** ExtensionATR = (P - R) / ATR for longs, (S - P) / ATR for shorts. */
    public static function extensionAtr(array $range, string $direction, float $price): float
    {
        return $direction === 'LONG'
            ? ($price - $range['resistance']) / $range['atr']
            : ($range['support'] - $price) / $range['atr'];
    }

    /** Which buffered trigger the price is beyond, if any. */
    public static function crossedDirection(array $range, float $price): ?string
    {
        return match (true) {
            $price > $range['long_trigger'] => 'LONG',
            $price < $range['short_trigger'] => 'SHORT',
            default => null,
        };
    }

    /**
     * Stage B: live price beyond R + 0.05 ATR (or S - 0.05 ATR) with volume pace >= 1.5.
     * A missing pace (no 1m data, thin baseline) fails the check: it is unavailable, not satisfied.
     *
     * @return array{ok: bool, direction: ?string, extended: bool, reasons: array<int, string>, extension_atr: ?float}
     */
    public function early(array $range, float $price, ?float $pace, ?float $spreadBps): array
    {
        $direction = self::crossedDirection($range, $price);
        if ($direction === null) {
            return ['ok' => false, 'direction' => null, 'extended' => false, 'reasons' => ['price inside the buffered range'], 'extension_atr' => null];
        }

        $extension = self::extensionAtr($range, $direction, $price);
        $reasons = [];
        $extended = $extension > $this->config->float('max_extension_atr');
        if ($extended) {
            $reasons[] = sprintf('extended %.2f ATR past the boundary (max %.2f)', $extension, $this->config->float('max_extension_atr'));
        }
        if ($pace === null) {
            $reasons[] = 'volume pace unavailable';
        } elseif ($pace < $this->config->float('early.min_volume_pace')) {
            $reasons[] = sprintf('volume pace %.2fx < %.2fx', $pace, $this->config->float('early.min_volume_pace'));
        }
        if ($spreadBps !== null && $spreadBps > $this->config->float('liquidity.max_spread_bps')) {
            $reasons[] = sprintf('spread %.1f bps too wide', $spreadBps);
        }

        return ['ok' => $reasons === [], 'direction' => $direction, 'extended' => $extended, 'reasons' => $reasons, 'extension_atr' => $extension];
    }

    /**
     * Stage C on the closed candle i: close beyond the confirm buffer, RVOL >= 1.8, directional close
     * location >= 0.75, body >= 0.5 ATR (hard only when body_required), RSI > 55 and rising
     * (< 45 and falling for shorts), extension <= 1.5 ATR.
     *
     * @param  array<string, array<int, ?float>>  $s
     * @return array{ok: bool, closed_beyond: bool, reasons: array<int, string>, metrics: array<string, ?float>}
     */
    public function confirm(Candles $c, array $s, int $i, array $range, string $direction): array
    {
        $long = $direction === 'LONG';
        $close = $c->closes[$i];
        $atr = (float) $range['atr'];
        $level = $long ? $range['long_confirm'] : $range['short_confirm'];
        $closedBeyond = $long ? $close > $level : $close < $level;

        $rvol = $s['rvol'][$i] ?? null;
        $clv = IndicatorCalculator::closeLocation($c->highs[$i], $c->lows[$i], $close, $direction);
        $bodyAtr = IndicatorCalculator::bodyAtr($c->opens[$i], $close, $atr);
        $rsi = $s['rsi'][$i] ?? null;
        $rsiPrev = $s['rsi'][$i - 1] ?? null;
        $extension = self::extensionAtr($range, $direction, $close);
        $directional = $long ? $close > $c->opens[$i] : $close < $c->opens[$i];

        $reasons = [];
        if (! $closedBeyond) {
            $reasons[] = 'close not beyond the confirm buffer';
        }
        if ($rvol === null) {
            $reasons[] = 'relative volume unavailable';
        } elseif ($rvol < $this->config->float('confirm.min_rvol')) {
            $reasons[] = sprintf('RVOL %.2fx < %.2fx', $rvol, $this->config->float('confirm.min_rvol'));
        }
        if ($clv === null || $clv < $this->config->float('confirm.min_close_location')) {
            $reasons[] = sprintf('close location %s < %.2f', $clv === null ? 'n/a' : number_format($clv, 2), $this->config->float('confirm.min_close_location'));
        }
        if (! $directional) {
            $reasons[] = 'candle body against the breakout';
        }
        if ($this->config->bool('confirm.body_required') && ($bodyAtr ?? 0) < $this->config->float('confirm.min_body_atr')) {
            $reasons[] = sprintf('body %.2f ATR < %.2f', $bodyAtr ?? 0, $this->config->float('confirm.min_body_atr'));
        }
        if ($rsi === null || $rsiPrev === null) {
            $reasons[] = 'RSI unavailable';
        } elseif ($long && ! ($rsi > $this->config->float('confirm.rsi_long_min') && $rsi > $rsiPrev)) {
            $reasons[] = sprintf('RSI %.1f not > %.0f and rising', $rsi, $this->config->float('confirm.rsi_long_min'));
        } elseif (! $long && ! ($rsi < $this->config->float('confirm.rsi_short_max') && $rsi < $rsiPrev)) {
            $reasons[] = sprintf('RSI %.1f not < %.0f and falling', $rsi, $this->config->float('confirm.rsi_short_max'));
        }
        if ($extension > $this->config->float('max_extension_atr')) {
            $reasons[] = sprintf('extended %.2f ATR (max %.2f)', $extension, $this->config->float('max_extension_atr'));
        }

        return ['ok' => $reasons === [], 'closed_beyond' => $closedBeyond, 'reasons' => $reasons, 'metrics' => [
            'close' => $close,
            'rvol' => $rvol,
            'close_location' => $clv,
            'body_atr' => $bodyAtr,
            'rsi' => $rsi,
            'rsi_prev' => $rsiPrev,
            'extension_atr' => $extension,
            'ema_fast' => $s['ema_fast'][$i] ?? null,
            'ema_slow' => $s['ema_slow'][$i] ?? null,
        ]];
    }
}
