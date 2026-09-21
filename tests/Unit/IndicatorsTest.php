<?php

namespace Tests\Unit;

use App\Services\Trading\Indicators;
use PHPUnit\Framework\TestCase;

class IndicatorsTest extends TestCase
{
    public function test_sma_calculation(): void
    {
        $data = [10.0, 20.0, 30.0, 40.0, 50.0];
        $sma = Indicators::sma($data, 3);

        $this->assertNull($sma[0]);
        $this->assertNull($sma[1]);
        $this->assertEquals(20.0, $sma[2]);
        $this->assertEquals(30.0, $sma[3]);
        $this->assertEquals(40.0, $sma[4]);
    }

    public function test_ema_calculation(): void
    {
        $data = [10.0, 11.0, 12.0, 13.0, 14.0, 15.0];
        $ema = Indicators::ema($data, 3);

        $this->assertNull($ema[0]);
        $this->assertNull($ema[1]);
        $this->assertEquals(11.0, $ema[2]); // Seed SMA (10+11+12)/3 = 11.0
        $this->assertGreaterThan(11.0, $ema[3]);
        $this->assertLessThan(15.0, $ema[5]);
    }

    public function test_atr_calculation(): void
    {
        $highs = [105.0, 107.0, 108.0, 106.0, 109.0, 110.0, 112.0, 111.0, 113.0, 115.0, 114.0, 116.0, 118.0, 117.0, 120.0, 122.0];
        $lows = [95.0, 97.0, 99.0, 98.0, 101.0, 102.0, 104.0, 103.0, 105.0, 107.0, 106.0, 108.0, 110.0, 109.0, 112.0, 114.0];
        $closes = [100.0, 105.0, 102.0, 104.0, 107.0, 108.0, 109.0, 107.0, 110.0, 112.0, 111.0, 114.0, 115.0, 113.0, 118.0, 120.0];

        $atr = Indicators::atr($highs, $lows, $closes, 14);

        $this->assertCount(count($highs), $atr);
        $this->assertNotNull($atr[13]);
        $this->assertGreaterThan(0.0, $atr[15]);
    }

    public function test_rsi_bounds(): void
    {
        $closes = [
            100.0, 102.0, 104.0, 103.0, 105.0, 107.0, 106.0, 108.0, 110.0, 112.0,
            111.0, 115.0, 117.0, 116.0, 120.0, 122.0, 125.0, 128.0, 130.0, 132.0,
        ];

        $rsi = Indicators::rsi($closes, 14);

        $lastRsi = end($rsi);
        $this->assertNotNull($lastRsi);
        $this->assertGreaterThanOrEqual(0.0, $lastRsi);
        $this->assertLessThanOrEqual(100.0, $lastRsi);
        // Consistent uptrend should yield RSI > 60
        $this->assertGreaterThan(60.0, $lastRsi);
    }

    public function test_bollinger_bands(): void
    {
        $closes = array_fill(0, 30, 100.0);
        $closes[25] = 105.0;
        $closes[26] = 110.0;
        $closes[27] = 112.0;

        $bb = Indicators::bollingerBands($closes, 20, 2.0);

        $lastIdx = count($closes) - 1;
        $this->assertGreaterThan($bb['middle'][$lastIdx], $bb['upper'][$lastIdx]);
        $this->assertLessThan($bb['middle'][$lastIdx], $bb['lower'][$lastIdx]);
    }
}
