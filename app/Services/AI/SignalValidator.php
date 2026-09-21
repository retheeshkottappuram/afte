<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;

class SignalValidator
{
    /**
     * Validate a candidate trading signal against market regime and false breakout risk.
     *
     * @param  array<string, mixed>  $signal
     * @param  array{opens: float[], highs: float[], lows: float[], closes: float[], volumes: float[], closeTimes: int[]}  $candles
     * @return array{
     *     approved: bool,
     *     confidence: int,
     *     regime: string,
     *     reason: string
     * }
     */
    public function validate(array $signal, array $candles): array
    {
        $driver = config('trading.ai.driver', 'heuristic');
        $geminiKey = config('trading.ai.gemini_api_key');

        if ($driver === 'gemini' && ! empty($geminiKey)) {
            return $this->validateWithGemini($signal, $candles, (string) $geminiKey);
        }

        return $this->validateHeuristically($signal, $candles);
    }

    /**
     * Institutional heuristic AI validation engine.
     *
     * @param  array<string, mixed>  $signal
     * @param  array{opens: float[], highs: float[], lows: float[], closes: float[], volumes: float[], closeTimes: int[]}  $candles
     * @return array{approved: bool, confidence: int, regime: string, reason: string}
     */
    public function validateHeuristically(array $signal, array $candles): array
    {
        $closes = $candles['closes'];
        $highs = $candles['highs'];
        $lows = $candles['lows'];
        $opens = $candles['opens'];
        $count = count($closes);

        $i = $count - 2; // Last confirmed candle
        $open = $opens[$i];
        $close = $closes[$i];
        $high = $highs[$i];
        $low = $lows[$i];
        $candleRange = max(0.0000001, $high - $low);

        $direction = $signal['direction'];
        $indicators = $signal['indicators'] ?? [];
        $rsi = (float) ($indicators['rsi'] ?? 50.0);
        $adx = (float) ($indicators['adx'] ?? 20.0);
        $volRatio = (float) ($indicators['volume_ratio'] ?? 1.0);

        $upperWick = $high - max($open, $close);
        $lowerWick = min($open, $close) - $low;
        $body = abs($close - $open);

        $upperWickPct = ($upperWick / $candleRange) * 100.0;
        $lowerWickPct = ($lowerWick / $candleRange) * 100.0;

        $penalties = 0;
        $reasons = [];

        // 1. Check False Breakout Wick Exhaustion
        if ($direction === 'LONG' && $upperWickPct > 42.0) {
            $penalties += 35;
            $reasons[] = "Severe upper wick rejection ({$upperWickPct}%) indicates profit-taking absorption.";
        }
        if ($direction === 'SHORT' && $lowerWickPct > 42.0) {
            $penalties += 35;
            $reasons[] = "Severe lower wick rejection ({$lowerWickPct}%) indicates buyer absorption.";
        }

        // 2. Volume Gating
        if ($volRatio < 0.90) {
            $penalties += 20;
            $reasons[] = "Breakout occurred on below-average volume ({$volRatio}x 20-SMA).";
        }

        // 3. ADX & Trend Quality
        $regime = 'TRENDING_EXPANSION';
        if ($adx >= 25.0 && $volRatio >= 1.25) {
            $regime = 'HIGH_MOMENTUM_EXPANSION';
        } elseif ($adx < 18.0) {
            $regime = 'WEAK_TREND_CHOP';
            $penalties += 15;
            $reasons[] = "ADX ({$adx}) indicates weak directional momentum.";
        }

        // 4. Overextension Check
        if ($direction === 'LONG' && $rsi > 75.0) {
            $penalties += 25;
            $reasons[] = "RSI ({$rsi}) indicates extreme overextension into resistance.";
        }
        if ($direction === 'SHORT' && $rsi < 25.0) {
            $penalties += 25;
            $reasons[] = "RSI ({$rsi}) indicates extreme oversold exhaustion.";
        }

        $baseConfidence = 90;
        $confidence = max(20, min(98, $baseConfidence - $penalties));
        $approved = $confidence >= (int) config('trading.ai.min_confidence_score', 70);

        if ($approved) {
            $reasons[] = "Market structure confirmed. Favorable volume expansion ({$volRatio}x) and aligned momentum.";
        }

        return [
            'approved' => $approved,
            'confidence' => $confidence,
            'regime' => $regime,
            'reason' => implode(' ', $reasons),
        ];
    }

    /**
     * Optional Gemini API integration for deeper natural language reasoning.
     *
     * @param  array<string, mixed>  $signal
     * @param  array<string, mixed>  $candles
     * @return array{approved: bool, confidence: int, regime: string, reason: string}
     */
    protected function validateWithGemini(array $signal, array $candles, string $apiKey): array
    {
        try {
            $prompt = 'You are a master institutional crypto quant. Evaluate this setup: '.json_encode($signal).'. Return JSON: {"approved": bool, "confidence": int (0-100), "regime": string, "reason": string}';

            $response = Http::timeout(8)->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$apiKey}", [
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
            ]);

            if ($response->successful()) {
                $text = $response->json()['candidates'][0]['content']['parts'][0]['text'] ?? '';
                if (preg_match('/\{.*\}/s', $text, $matches)) {
                    $json = json_decode($matches[0], true);
                    if (is_array($json) && isset($json['approved'])) {
                        return [
                            'approved' => (bool) $json['approved'],
                            'confidence' => (int) ($json['confidence'] ?? 75),
                            'regime' => (string) ($json['regime'] ?? 'TRENDING'),
                            'reason' => (string) ($json['reason'] ?? 'Gemini confirmed setup'),
                        ];
                    }
                }
            }
        } catch (\Exception) {
            // Fallback to heuristic
        }

        return $this->validateHeuristically($signal, $candles);
    }
}
