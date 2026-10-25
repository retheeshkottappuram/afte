<?php

namespace App\Services\Breakout;

use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * Typed, validated access to config/breakout.php. Every strategy threshold is read from here,
 * so nothing is hard-coded in the detectors.
 */
final class EbdConfig
{
    /** @param  array<string, mixed>  $values */
    public function __construct(protected array $values) {}

    /** @param  array<string, mixed>  $overrides  dot-keys, e.g. ['confirm.min_rvol' => 2.0] */
    public static function fromConfig(array $overrides = []): self
    {
        $values = (array) config('breakout', []);
        foreach ($overrides as $key => $value) {
            Arr::set($values, $key, $value);
        }

        return new self($values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->values, $key, $default);
    }

    public function float(string $key): float
    {
        return (float) $this->get($key);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Rejects out-of-range parameters with the exact key and allowed range.
     *
     * @throws InvalidArgumentException
     */
    public function validate(): self
    {
        $errors = [];
        $ranges = [
            'compression.lookback' => [5, 200],
            'compression.max_range_atr' => [0.5, 20],
            'compression.bbw_percentile' => [1, 99],
            'compression.bbw_lookback' => [20, 500],
            'compression.expiry_bars' => [1, 288],
            'indicators.atr_period' => [2, 100],
            'indicators.ema_fast' => [2, 100],
            'indicators.ema_slow' => [3, 200],
            'indicators.rsi_period' => [2, 100],
            'indicators.bb_period' => [5, 200],
            'indicators.volume_baseline' => [5, 200],
            'early.buffer_atr' => [0, 2],
            'early.min_volume_pace' => [0.1, 20],
            'early.watch_bars' => [1, 48],
            'confirm.buffer_atr' => [0, 3],
            'confirm.min_rvol' => [0.1, 20],
            'confirm.min_close_location' => [0, 1],
            'confirm.min_body_atr' => [0, 5],
            'confirm.rsi_long_min' => [50, 100],
            'confirm.rsi_short_max' => [0, 50],
            'failure.inside_closes' => [1, 10],
            'retest.zone_atr' => [0, 3],
            'retest.wait_bars' => [1, 48],
            'max_extension_atr' => [0.1, 10],
            'risk.tp1_r' => [0.5, 10],
            'risk.tp2_r' => [0.5, 20],
            'risk.min_sl_pct' => [0, 5],
            'risk.max_sl_pct' => [0.1, 20],
            'costs.taker_fee' => [0, 0.01],
            'costs.slippage' => [0, 0.01],
            'liquidity.max_spread_bps' => [0.1, 500],
            'score.high' => [1, 100],
            'score.valid' => [1, 100],
            'score.watchlist' => [0, 100],
            'universe.max_symbols' => [1, 300],
            'notifications.cooldown_minutes' => [0, 1440],
            'notifications.record_days' => [1, 365],
            'notifications.record_min_signals' => [1, 1000],
            'notifications.record_min_pf' => [0, 100],
        ];

        foreach ($ranges as $key => [$min, $max]) {
            $value = $this->get($key);
            if (! is_numeric($value) || $value < $min || $value > $max) {
                $errors[] = sprintf('breakout.%s = %s (allowed %s..%s)', $key, var_export($value, true), $min, $max);
            }
        }

        $rules = [
            'confirm.buffer_atr must be >= early.buffer_atr' => $this->float('confirm.buffer_atr') >= $this->float('early.buffer_atr'),
            'indicators.ema_slow must be > indicators.ema_fast' => $this->int('indicators.ema_slow') > $this->int('indicators.ema_fast'),
            'risk.tp2_r must be > risk.tp1_r' => $this->float('risk.tp2_r') > $this->float('risk.tp1_r'),
            'risk.max_sl_pct must be > risk.min_sl_pct' => $this->float('risk.max_sl_pct') > $this->float('risk.min_sl_pct'),
            'score thresholds must be watchlist < valid < high' => $this->float('score.watchlist') < $this->float('score.valid') && $this->float('score.valid') < $this->float('score.high'),
            'mode must be alerts or shadow' => in_array($this->get('mode'), ['alerts', 'shadow'], true),
            'timeframes.signal must be 5m' => $this->get('timeframes.signal') === '5m',
            'notifications.min_bucket must be HIGH, VALID or WATCHLIST' => in_array($this->get('notifications.min_bucket'), ['HIGH', 'VALID', 'WATCHLIST'], true),
        ];
        foreach ($rules as $message => $ok) {
            if (! $ok) {
                $errors[] = $message;
            }
        }

        if ($errors !== []) {
            throw new InvalidArgumentException('Invalid EBD configuration: '.implode('; ', $errors));
        }

        return $this;
    }
}
