<?php

namespace Tests\Support;

use App\Services\Breakout\Candles;

/**
 * Deterministic SYNTHETIC 5m candle series for the EBD tests. The shapes imitate the reference
 * patterns (PEPE: long base then a resistance break on volume; IRYS: narrow range then a sharp
 * expansion; ARK: sideways then a break with a big volume spike). They are not real market data
 * and no real detection times are implied.
 */
final class EbdFixtures
{
    public const BAR_MS = 300_000;

    /** A fixed 5m boundary: 2026-10-05 12:00:00 UTC. */
    public const T0 = 1_791_201_600_000;

    /** @var array<int, array{0: float, 1: float, 2: float, 3: float, 4: float}> */
    protected array $bars = [];

    protected float $last;

    public function __construct(float $start = 100.0)
    {
        $this->last = $start;
    }

    public static function start(float $price = 100.0): self
    {
        return new self($price);
    }

    /** Oscillating candles around $center with amplitude $amp (normal volatility). */
    public function noisy(int $n, float $center, float $amp, float $volume = 1000.0): self
    {
        for ($i = 0; $i < $n; $i++) {
            $close = $center + $amp * sin($i * 1.3) + 0.5 * $amp * sin($i * 0.7);
            $this->bar($this->last, max($this->last, $close) + 0.4 * $amp, min($this->last, $close) - 0.4 * $amp, $close, $volume * (1 + 0.2 * sin($i)));
        }

        return $this;
    }

    public function bar(float $open, float $high, float $low, float $close, float $volume): self
    {
        $this->bars[] = [$open, $high, $low, $close, $volume];
        $this->last = $close;

        return $this;
    }

    /** Candle from the last close to $close with small wicks. */
    public function to(float $close, float $volume, float $wick = 0.02): self
    {
        return $this->bar($this->last, max($this->last, $close) + $wick, min($this->last, $close) - $wick, $close, $volume);
    }

    public function count(): int
    {
        return count($this->bars);
    }

    public function lastClose(): float
    {
        return $this->last;
    }

    /** Close time of the last candle so that it ends at $endCloseMs (default T0 - 1). */
    public function candles(?int $endCloseMs = null): Candles
    {
        return Candles::fromKlines($this->klines($endCloseMs), '5m');
    }

    /** BinanceClient::parseRawKlines() shape. */
    public function klines(?int $endCloseMs = null, int $barMs = self::BAR_MS): array
    {
        $end = $endCloseMs ?? self::T0 - 1;
        $n = count($this->bars);
        $k = ['opens' => [], 'highs' => [], 'lows' => [], 'closes' => [], 'volumes' => [], 'closeTimes' => [], 'openTimes' => [], 'quoteVolumes' => [], 'trades' => [], 'takerBuyQuote' => []];
        foreach ($this->bars as $i => [$o, $h, $l, $c, $v]) {
            $close = $end - ($n - 1 - $i) * $barMs;
            $k['opens'][] = $o;
            $k['highs'][] = $h;
            $k['lows'][] = $l;
            $k['closes'][] = $c;
            $k['volumes'][] = $v / $c;
            $k['quoteVolumes'][] = $v;
            $k['closeTimes'][] = $close;
            $k['openTimes'][] = $close - $barMs + 1;
            $k['trades'][] = 100;
            $k['takerBuyQuote'][] = $v / 2;
        }

        return $k;
    }

    /** Raw Binance kline rows (for Http::fake). */
    public function raw(?int $endCloseMs = null, int $barMs = self::BAR_MS): array
    {
        $k = $this->klines($endCloseMs, $barMs);
        $rows = [];
        foreach ($k['closes'] as $i => $c) {
            $rows[] = [$k['openTimes'][$i], (string) $k['opens'][$i], (string) $k['highs'][$i], (string) $k['lows'][$i], (string) $c,
                (string) $k['volumes'][$i], $k['closeTimes'][$i], (string) $k['quoteVolumes'][$i], 100, '0', (string) $k['takerBuyQuote'][$i], '0'];
        }

        return $rows;
    }

    /** 150 normal candles then 30 tight ones at 100: a compressed 5m range (R ~100.1, S ~99.9). */
    public static function coiled(float $volume = 1000.0): self
    {
        return self::start(100)->noisy(150, 100, 1.0, $volume)->noisy(30, 100, 0.06, $volume);
    }

    /** PEPE-like: a long quiet base, then a decisive close through resistance on ~3x volume. */
    public static function pepeLike(): self
    {
        $f = self::start(100)->noisy(150, 100, 1.0)->noisy(40, 100, 0.06);

        return $f->bar(100.02, 100.225, 100.0, 100.21, 3200);
    }

    /** A break that closes far past the boundary in one candle (about 2.7 ATR): too late to enter. */
    public static function extendedLong(): self
    {
        $f = self::start(100)->noisy(150, 100, 1.0)->noisy(40, 100, 0.06);

        return $f->bar(100.02, 100.62, 100.0, 100.58, 3200);
    }

    /** IRYS-like: narrow range, then a sharp expansion candle with accelerating volume. */
    public static function irysLike(): self
    {
        $f = self::start(100)->noisy(150, 100, 0.8)->noisy(24, 100, 0.05, 900);

        return $f->bar(100.0, 100.40, 99.98, 100.39, 3600);
    }

    /** ARK-like short mirror: sideways, then a break below support with a big volume spike. */
    public static function arkLikeShort(): self
    {
        $f = self::start(100)->noisy(150, 100, 1.0)->noisy(30, 100, 0.06);

        return $f->bar(99.98, 100.0, 99.64, 99.65, 4000);
    }
}
