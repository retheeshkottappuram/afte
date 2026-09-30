<?php

namespace Tests\Unit;

use App\Services\Crypto\BreakoutDetector;
use App\Services\Crypto\CandleSanitizer;
use App\Services\Crypto\SignalEngine;
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

    public function test_signal_computed_at_candle_close_is_identical_when_recomputed_later(): void
    {
        // 1. Generate 80 closed bars
        $candlesAtClose = $this->generateSyntheticCandles(80);
        $tCandleClose = end($candlesAtClose['closeTimes']);

        // Evaluate signal immediately at candle close T
        $detector = new BreakoutDetector;
        $engine = new SignalEngine;

        $sanitizedInitial = CandleSanitizer::onlyClosedCandles($candlesAtClose, $tCandleClose);
        $breakoutAtClose = $detector->evaluate($sanitizedInitial, referenceTimeMs: $tCandleClose);
        $trendAtClose = $engine->evaluateDetailed($sanitizedInitial, referenceTimeMs: $tCandleClose);

        // 2. Simulate subsequent time passage:
        // Intra-bar ticks arrive for the NEW forming candle (high volatility, wild price swings)
        $rawCandlesLaterWithWildForming = $candlesAtClose;
        $rawCandlesLaterWithWildForming['opens'][] = end($candlesAtClose['closes']);
        $rawCandlesLaterWithWildForming['highs'][] = end($candlesAtClose['closes']) * 1.25; // +25% intraday wick!
        $rawCandlesLaterWithWildForming['lows'][] = end($candlesAtClose['closes']) * 0.75;  // -25% intraday dump!
        $rawCandlesLaterWithWildForming['closes'][] = end($candlesAtClose['closes']) * 1.10;
        $rawCandlesLaterWithWildForming['volumes'][] = 999999.0; // massive volume tick!
        $rawCandlesLaterWithWildForming['closeTimes'][] = $tCandleClose + 900000;

        // Recompute signal targeting the closed candle at T
        $sanitizedLater = CandleSanitizer::onlyClosedCandles($rawCandlesLaterWithWildForming, $tCandleClose);
        $breakoutRecomputed = $detector->evaluate($sanitizedLater, referenceTimeMs: $tCandleClose);
        $trendRecomputed = $engine->evaluateDetailed($sanitizedLater, referenceTimeMs: $tCandleClose);

        // 3. Assert zero repainting: Breakout detector output is identical
        $this->assertEquals($breakoutAtClose, $breakoutRecomputed, 'Breakout signal must not repaint when intra-bar forming ticks occur');

        // Assert zero repainting: SignalEngine output is identical
        $this->assertEquals($trendAtClose['signal'], $trendRecomputed['signal'], 'SignalEngine signal must not repaint when intra-bar forming ticks occur');
        $this->assertEquals($trendAtClose['diagnostics'], $trendRecomputed['diagnostics'], 'Diagnostics must be 100% identical');

        // 4. Simulate even later: 3 more candles have closed in the future
        $futureCandles = $this->generateSyntheticCandles(84);
        $sanitizedHistoricalSlice = CandleSanitizer::onlyClosedCandles($futureCandles, $tCandleClose);

        $breakoutFromFutureSlice = $detector->evaluate($sanitizedHistoricalSlice, referenceTimeMs: $tCandleClose);
        $trendFromFutureSlice = $engine->evaluateDetailed($sanitizedHistoricalSlice, referenceTimeMs: $tCandleClose);

        $this->assertEquals($breakoutAtClose, $breakoutFromFutureSlice, 'Signal evaluated on historical slice must equal original real-time signal');
        $this->assertEquals($trendAtClose['signal'], $trendFromFutureSlice['signal'], 'Engine signal on historical slice must equal original real-time signal');
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
