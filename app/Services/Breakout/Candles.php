<?php

namespace App\Services\Breakout;

/**
 * One symbol/timeframe candle series in exchange time (UTC epoch ms), oldest first.
 * Quote volume (USDT) is used when Binance supplies it; otherwise base volume is used and
 * the series is flagged so callers know RVOL is not comparable across assets.
 */
final class Candles
{
    /**
     * @param  array<int, int>  $openTimes
     * @param  array<int, int>  $closeTimes
     * @param  array<int, float>  $opens
     * @param  array<int, float>  $highs
     * @param  array<int, float>  $lows
     * @param  array<int, float>  $closes
     * @param  array<int, float>  $volumes  quote volume when $quoteVolume, else base volume
     * @param  array<int, int|null>  $trades
     */
    public function __construct(
        public readonly int $intervalMs,
        public readonly array $openTimes,
        public readonly array $closeTimes,
        public readonly array $opens,
        public readonly array $highs,
        public readonly array $lows,
        public readonly array $closes,
        public readonly array $volumes,
        public readonly array $trades,
        public readonly bool $quoteVolume,
    ) {}

    public static function intervalMs(string $interval): int
    {
        $unit = substr($interval, -1);
        $n = (int) substr($interval, 0, -1);

        return $n * match ($unit) {
            'm' => 60_000,
            'h' => 3_600_000,
            'd' => 86_400_000,
            default => throw new \InvalidArgumentException("Unsupported interval {$interval}"),
        };
    }

    /**
     * From BinanceClient::parseRawKlines() output.
     *
     * @param  array<string, array<int, mixed>>  $k
     */
    public static function fromKlines(array $k, string $interval): self
    {
        $ms = self::intervalMs($interval);
        $closeTimes = array_map('intval', array_values($k['closeTimes'] ?? []));
        $openTimes = isset($k['openTimes'])
            ? array_map('intval', array_values($k['openTimes']))
            : array_map(fn (int $t): int => $t - $ms + 1, $closeTimes);
        $hasQuote = isset($k['quoteVolumes']) && count($k['quoteVolumes']) === count($closeTimes);
        $volumes = $hasQuote ? $k['quoteVolumes'] : ($k['volumes'] ?? []);

        return new self(
            $ms,
            $openTimes,
            $closeTimes,
            array_map('floatval', array_values($k['opens'] ?? [])),
            array_map('floatval', array_values($k['highs'] ?? [])),
            array_map('floatval', array_values($k['lows'] ?? [])),
            array_map('floatval', array_values($k['closes'] ?? [])),
            array_map('floatval', array_values($volumes)),
            array_values($k['trades'] ?? array_fill(0, count($closeTimes), null)),
            $hasQuote,
        );
    }

    public function count(): int
    {
        return count($this->closes);
    }

    public function lastIndex(): int
    {
        return $this->count() - 1;
    }

    /** Only candles closed at $nowMs: the forming candle is dropped (never used for baselines). */
    public function closedAt(int $nowMs): self
    {
        $n = $this->count();
        while ($n > 0 && $this->closeTimes[$n - 1] > $nowMs) {
            $n--;
        }

        return $this->slice(0, $n);
    }

    public function slice(int $offset, ?int $length = null): self
    {
        $s = fn (array $a): array => array_slice($a, $offset, $length);

        return new self($this->intervalMs, $s($this->openTimes), $s($this->closeTimes), $s($this->opens), $s($this->highs),
            $s($this->lows), $s($this->closes), $s($this->volumes), $s($this->trades), $this->quoteVolume);
    }

    /** The candle containing $timeMs, or null. */
    public function indexAt(int $timeMs): ?int
    {
        for ($i = $this->lastIndex(); $i >= 0; $i--) {
            if ($this->openTimes[$i] <= $timeMs && $timeMs <= $this->closeTimes[$i]) {
                return $i;
            }
            if ($this->closeTimes[$i] < $timeMs) {
                return null;
            }
        }

        return null;
    }

    /**
     * Data-quality problems that make the series unusable (empty list = OK): gaps, duplicates,
     * out-of-order times, non-positive prices, high < low, NaN, negative volume.
     *
     * @return array<int, string>
     */
    public function problems(): array
    {
        $issues = [];
        $n = $this->count();
        if ($n === 0) {
            return ['no candles'];
        }
        foreach ([$this->opens, $this->highs, $this->lows, $this->volumes, $this->closeTimes] as $series) {
            if (count($series) !== $n) {
                return ['series length mismatch'];
            }
        }

        for ($i = 0; $i < $n; $i++) {
            $o = $this->opens[$i];
            $h = $this->highs[$i];
            $l = $this->lows[$i];
            $c = $this->closes[$i];
            if (is_nan($o + $h + $l + $c + $this->volumes[$i]) || min($o, $h, $l, $c) <= 0) {
                $issues[] = "invalid price at #{$i}";
            } elseif ($h < $l || $h < max($o, $c) - 1e-12 || $l > min($o, $c) + 1e-12) {
                $issues[] = "high/low inconsistent at #{$i}";
            }
            if ($this->volumes[$i] < 0) {
                $issues[] = "negative volume at #{$i}";
            }
            if ($i > 0) {
                $step = $this->openTimes[$i] - $this->openTimes[$i - 1];
                if ($step === 0) {
                    $issues[] = "duplicate candle at #{$i}";
                } elseif ($step < 0) {
                    $issues[] = "out-of-order candle at #{$i}";
                } elseif ($step !== $this->intervalMs) {
                    $issues[] = "gap before #{$i}";
                }
            }
            if (count($issues) >= 5) {
                break;
            }
        }

        return $issues;
    }
}
