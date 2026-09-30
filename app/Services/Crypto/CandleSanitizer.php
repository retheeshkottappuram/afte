<?php

namespace App\Services\Crypto;

class CandleSanitizer
{
    /**
     * Sanitize candle arrays to strictly contain ONLY CLOSED candles.
     * Removes the active forming candle to guarantee zero repainting and zero look-ahead bias.
     *
     * @param  array{
     *     opens?: array<int, float>,
     *     highs?: array<int, float>,
     *     lows?: array<int, float>,
     *     closes?: array<int, float>,
     *     volumes?: array<int, float>,
     *     closeTimes?: array<int, int>
     * }  $candles
     * @param  int|null  $referenceTimeMs  Reference epoch millisecond (defaults to current time)
     * @param  bool  $stripLastIfNoTimes  If closeTimes is empty, strip the last candle (presumed forming)
     * @return array{
     *     opens: array<int, float>,
     *     highs: array<int, float>,
     *     lows: array<int, float>,
     *     closes: array<int, float>,
     *     volumes: array<int, float>,
     *     closeTimes: array<int, int>
     * }
     */
    public static function onlyClosedCandles(
        array $candles,
        ?int $referenceTimeMs = null,
        bool $stripLastIfNoTimes = false
    ): array {
        $closes = $candles['closes'] ?? [];
        $count = count($closes);

        if ($count === 0) {
            return [
                'opens' => [],
                'highs' => [],
                'lows' => [],
                'closes' => [],
                'volumes' => [],
                'closeTimes' => [],
            ];
        }

        $closeTimes = $candles['closeTimes'] ?? [];
        $refTime = $referenceTimeMs ?? (int) (microtime(true) * 1000);

        $validCount = $count;

        if (! empty($closeTimes)) {
            // Find the last candle whose closeTime <= refTime
            while ($validCount > 0 && isset($closeTimes[$validCount - 1]) && $closeTimes[$validCount - 1] > $refTime) {
                $validCount--;
            }
        } elseif ($stripLastIfNoTimes && $validCount > 0) {
            $validCount--;
        }

        if ($validCount === $count) {
            return [
                'opens' => array_values($candles['opens'] ?? []),
                'highs' => array_values($candles['highs'] ?? []),
                'lows' => array_values($candles['lows'] ?? []),
                'closes' => array_values($candles['closes'] ?? []),
                'volumes' => array_values($candles['volumes'] ?? []),
                'closeTimes' => array_values($candles['closeTimes'] ?? []),
            ];
        }

        return [
            'opens' => array_slice(array_values($candles['opens'] ?? []), 0, $validCount),
            'highs' => array_slice(array_values($candles['highs'] ?? []), 0, $validCount),
            'lows' => array_slice(array_values($candles['lows'] ?? []), 0, $validCount),
            'closes' => array_slice(array_values($candles['closes'] ?? []), 0, $validCount),
            'volumes' => array_slice(array_values($candles['volumes'] ?? []), 0, $validCount),
            'closeTimes' => array_slice(array_values($candles['closeTimes'] ?? []), 0, $validCount),
        ];
    }

    /**
     * Check if a specific candle close time is closed relative to a reference time.
     */
    public static function isCandleClosed(int $closeTimeMs, ?int $referenceTimeMs = null): bool
    {
        $refTime = $referenceTimeMs ?? (int) (microtime(true) * 1000);

        return $closeTimeMs <= $refTime;
    }

    /**
     * Slice candle series arrays up to a specific index (inclusive).
     *
     * @param  array<string, mixed>  $candles
     * @return array{
     *     opens: array<int, float>,
     *     highs: array<int, float>,
     *     lows: array<int, float>,
     *     closes: array<int, float>,
     *     volumes: array<int, float>,
     *     closeTimes: array<int, int>
     * }
     */
    public static function sliceUpToIndex(array $candles, int $targetIndex): array
    {
        $length = max(0, $targetIndex + 1);

        return [
            'opens' => array_slice(array_values($candles['opens'] ?? []), 0, $length),
            'highs' => array_slice(array_values($candles['highs'] ?? []), 0, $length),
            'lows' => array_slice(array_values($candles['lows'] ?? []), 0, $length),
            'closes' => array_slice(array_values($candles['closes'] ?? []), 0, $length),
            'volumes' => array_slice(array_values($candles['volumes'] ?? []), 0, $length),
            'closeTimes' => array_slice(array_values($candles['closeTimes'] ?? []), 0, $length),
        ];
    }
}
