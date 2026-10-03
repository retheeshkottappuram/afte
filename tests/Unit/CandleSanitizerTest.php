<?php

namespace Tests\Unit;

use App\Services\Crypto\CandleSanitizer;
use PHPUnit\Framework\TestCase;

class CandleSanitizerTest extends TestCase
{
    /**
     * Helper to generate a realistic synthetic series of N closed candles.
     *
     * @return array{opens: array<int, float>, highs: array<int, float>, lows: array<int, float>, closes: array<int, float>, volumes: array<int, float>, closeTimes: array<int, int>}
     */
    protected function generateSyntheticCandles(int $count, int $startCloseTimeMs = 1700000000000, int $intervalMs = 900000): array
    {
        $opens = [];
        $highs = [];
        $lows = [];
        $closes = [];
        $volumes = [];
        $closeTimes = [];

        $price = 100.0;
        for ($k = 0; $k < $count; $k++) {
            $open = $price;
            $delta = sin($k * 0.15) * 1.5 + 0.1;
            $close = $open + $delta;
            $high = max($open, $close) + 0.5;
            $low = min($open, $close) - 0.5;
            $vol = 1500.0 + ($k % 5) * 200.0;
            $cTime = $startCloseTimeMs + ($k * $intervalMs);

            $opens[] = round($open, 4);
            $highs[] = round($high, 4);
            $lows[] = round($low, 4);
            $closes[] = round($close, 4);
            $volumes[] = round($vol, 2);
            $closeTimes[] = $cTime;

            $price = $close;
        }

        return [
            'opens' => $opens,
            'highs' => $highs,
            'lows' => $lows,
            'closes' => $closes,
            'volumes' => $volumes,
            'closeTimes' => $closeTimes,
        ];
    }

    public function test_forming_candle_is_stripped_when_close_time_is_in_future(): void
    {
        $baseCandles = $this->generateSyntheticCandles(60);

        // Candle 59 closes at T = 1700053100000.
        // Add candle 60 (forming) whose closeTime is in the future relative to refTime:
        $tClose = $baseCandles['closeTimes'][59];
        $formingCloseTime = $tClose + 900000;

        $rawCandles = $baseCandles;
        $rawCandles['opens'][] = 110.0;
        $rawCandles['highs'][] = 115.0;
        $rawCandles['lows'][] = 109.0;
        $rawCandles['closes'][] = 114.0;
        $rawCandles['volumes'][] = 500.0;
        $rawCandles['closeTimes'][] = $formingCloseTime;

        $this->assertCount(61, $rawCandles['closes']);

        // Reference time is exactly at candle 59 close
        $sanitized = CandleSanitizer::onlyClosedCandles($rawCandles, $tClose);

        $this->assertCount(60, $sanitized['closes']);
        $this->assertSame($baseCandles['closes'], $sanitized['closes']);
        $this->assertSame($baseCandles['closeTimes'], $sanitized['closeTimes']);
        $this->assertSame($tClose, end($sanitized['closeTimes']));
    }

    public function test_is_candle_closed_boundary_checks(): void
    {
        $closeTime = 1700000000000;

        // 1 millisecond before close: NOT closed
        $this->assertFalse(CandleSanitizer::isCandleClosed($closeTime, $closeTime - 1));

        // Exact millisecond of close: CLOSED
        $this->assertTrue(CandleSanitizer::isCandleClosed($closeTime, $closeTime));

        // After close: CLOSED
        $this->assertTrue(CandleSanitizer::isCandleClosed($closeTime, $closeTime + 5000));
    }
}
