<?php

namespace App\Http\Controllers;

use App\Console\Commands\CheckCryptoSignals;
use App\Models\CryptoSignal;
use App\Models\User;
use App\Services\Crypto\BinanceClient;
use App\Services\Crypto\SignalEngine;
use App\Services\Crypto\SignalRecorder;
use App\Services\Crypto\TelegramNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class CryptoSignalController extends Controller
{
    /**
     * Show the CryptoLens signals and sentinel dashboard.
     */
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = Auth::user();

        $market = (string) config('crypto.market', 'futures');

        $cryptoConfig = [
            'symbols' => config('crypto.symbols', []),
            'market' => $market,
            'market_label' => $market === 'spot' ? 'Binance Spot' : 'Binance USDⓈ-M Futures',
            'interval' => config('crypto.interval', '15m'),
            'htf_interval' => config('crypto.htf_interval', '1h'),
            'cooldown_minutes' => config('crypto.cooldown_minutes', 75),
            'min_score' => config('crypto.indicators.minimum_score', 70),
            'telegram_ready' => ! empty(config('crypto.telegram.bot_token')) && ! empty(config('crypto.telegram.chat_id')),
        ];

        $allUsers = $user->isAdmin() ? User::orderBy('created_at', 'desc')->get() : collect([$user]);
        $recentAlerts = CryptoSignal::recent()->take(6)->get();

        return view('crypto.dashboard', [
            'user' => $user,
            'cryptoConfig' => $cryptoConfig,
            'allUsers' => $allUsers,
            'recentAlerts' => $recentAlerts,
        ]);
    }

    /**
     * Analyze a symbol in real-time with SignalAlgo PRO on Binance Futures.
     */
    public function analyze(
        Request $request,
        BinanceClient $binanceClient,
        SignalEngine $signalEngine
    ): JsonResponse {
        $rawSymbol = (string) $request->query('symbol', config('crypto.symbols.0', 'BTCUSDT'));
        $symbol = strtoupper(str_replace('.P', '', trim($rawSymbol)));
        if (! str_ends_with($symbol, 'USDT') && ! str_contains($symbol, ':')) {
            $symbol .= 'USDT';
        }

        $interval = strtolower((string) $request->query('interval', config('crypto.interval', '15m')));
        $htf1Interval = $this->resolveHtfInterval($interval);
        $htf2Interval = $this->resolveHtf2Interval($interval);
        $useHtf = (bool) config('crypto.indicators.use_htf_filter', true);

        try {
            $multiKlines = $binanceClient->fetchMultiTimeframeKlines(
                $symbol,
                $interval,
                $useHtf ? $htf1Interval : null,
                $useHtf ? $htf2Interval : null,
                320,
                260
            );
            $baseCandles = $multiKlines['base'];
            $htf1Candles = $multiKlines['htf1'];
            $htf2Candles = $multiKlines['htf2'];

            $evaluation = $signalEngine->evaluateDetailed($baseCandles, $htf1Candles, $htf2Candles);
            $history = $signalEngine->evaluateHistory($baseCandles, $htf1Candles, $htf2Candles, 140);
            $closes = $baseCandles['closes'] ?? [];
            $lastClose = count($closes) >= 2 ? $closes[count($closes) - 2] : ($closes[count($closes) - 1] ?? 0.0);

            // Synchronize all chart markers into the database and dispatch Telegram alert immediately if a new signal is printed
            $syncResult = SignalRecorder::syncMarkers(
                $symbol,
                $interval,
                $history['markers'],
                $binanceClient->getMarketLabel(),
                dispatchTelegram: true
            );

            $signal = $evaluation['signal'];
            $diagnostics = $evaluation['diagnostics'];

            // Retain active trade setup from the latest marker if the trade is still in progress (SL not breached)
            $lastMarker = ! empty($history['markers']) ? end($history['markers']) : null;
            if (! $signal && $lastMarker) {
                $markerTime = (int) ($lastMarker['time'] ?? 0);
                $candleAgeSeconds = now()->timestamp - $markerTime;
                $maxActiveSeconds = match ($interval) {
                    '1m' => 900,
                    '3m' => 1800,
                    '5m' => 3600,
                    '15m' => 14400, // 4 hours
                    '30m' => 28800, // 8 hours
                    '1h' => 43200,  // 12 hours
                    '4h' => 172800, // 48 hours
                    default => 14400,
                };

                if ($candleAgeSeconds <= $maxActiveSeconds) {
                    $side = strtoupper((string) ($lastMarker['side'] ?? 'BUY'));
                    $sl = (float) ($lastMarker['sl'] ?? 0);
                    $entry = (float) ($lastMarker['entry'] ?? 0);
                    $invalidated = ($side === 'BUY' && $lastClose < $sl) || ($side === 'SELL' && $lastClose > $sl);

                    if (! $invalidated && $entry > 0) {
                        $atrPct = (float) ($lastMarker['atr_pct'] ?? 1.5);
                        $recLeverage = $atrPct > 3.0 ? '3x - 5x' : ($atrPct < 1.0 ? '8x - 12x' : '5x - 10x');
                        $levMult = $atrPct > 3.0 ? 3 : ($atrPct < 1.0 ? 10 : 5);
                        $slPct = round(abs($entry - $sl) / $entry * 100, 2);
                        $tp1Pct = round(abs(($lastMarker['tp1'] ?? $entry) - $entry) / $entry * 100, 2);
                        $tp2Pct = round(abs(($lastMarker['tp2'] ?? $entry) - $entry) / $entry * 100, 2);
                        $tp3Pct = round(abs(($lastMarker['tp3'] ?? $entry) - $entry) / $entry * 100, 2);
                        $rrRatio = $slPct > 0 ? '1 : '.round($tp2Pct / $slPct, 1) : '1 : 2.0';

                        $activePerpOptions = [
                            'recommended_leverage' => $recLeverage,
                            'margin_mode' => 'Isolated Margin',
                            'order_type' => 'Limit / Market Entry',
                            'risk_per_trade' => '1% - 2% Account Balance',
                            'risk_reward' => $rrRatio,
                            'sl_pct' => $slPct,
                            'tp1_pct' => $tp1Pct,
                            'tp2_pct' => $tp2Pct,
                            'tp3_pct' => $tp3Pct,
                            'sl_leveraged_pct' => round($slPct * $levMult, 1),
                            'tp1_leveraged_pct' => round($tp1Pct * $levMult, 1),
                            'tp2_leveraged_pct' => round($tp2Pct * $levMult, 1),
                            'tp3_leveraged_pct' => round($tp3Pct * $levMult, 1),
                            'leverage_multiplier' => $levMult,
                            'liquidation_buffer' => '> 15% safety cushion',
                        ];

                        $signal = [
                            'side' => $side,
                            'is_active_trade' => true,
                            'setup_type' => $lastMarker['setup_type'] ?? 'TREND',
                            'score' => (int) ($lastMarker['score'] ?? 80),
                            'grade' => (string) ($lastMarker['grade'] ?? 'B'),
                            'entry' => $entry,
                            'sl' => $sl,
                            'tp1' => (float) ($lastMarker['tp1'] ?? 0),
                            'tp2' => (float) ($lastMarker['tp2'] ?? 0),
                            'tp3' => (float) ($lastMarker['tp3'] ?? 0),
                            'rsi' => $lastMarker['rsi'] ?? null,
                            'adx' => $lastMarker['adx'] ?? null,
                            'atr_pct' => $atrPct,
                            'volume_ratio' => $lastMarker['volume_ratio'] ?? null,
                            'candle_close_time' => $markerTime * 1000,
                            'perpetual_options' => $activePerpOptions,
                        ];
                    }
                }
            }

            $perpetualOptions = $signal['perpetual_options'] ?? null;
            if (! $perpetualOptions && $lastClose > 0) {
                $atrPct = (float) ($diagnostics['atr_pct'] ?? 1.5);
                $recLeverage = $atrPct > 3.0 ? '3x - 5x' : ($atrPct < 1.0 ? '8x - 12x' : '5x - 10x');
                $levMult = $atrPct > 3.0 ? 3 : ($atrPct < 1.0 ? 10 : 5);
                $slPct = round(max(1.0, $atrPct * 1.5), 2);
                $tp1Pct = round($slPct * 1.0, 2);
                $tp2Pct = round($slPct * 2.0, 2);
                $tp3Pct = round($slPct * 3.0, 2);

                $perpetualOptions = [
                    'recommended_leverage' => $recLeverage,
                    'margin_mode' => 'Isolated Margin',
                    'order_type' => 'Limit / Market Entry',
                    'risk_per_trade' => '1% - 2% Account Balance',
                    'risk_reward' => '1 : 2.0',
                    'sl_pct' => $slPct,
                    'tp1_pct' => $tp1Pct,
                    'tp2_pct' => $tp2Pct,
                    'tp3_pct' => $tp3Pct,
                    'sl_leveraged_pct' => round($slPct * $levMult, 1),
                    'tp1_leveraged_pct' => round($tp1Pct * $levMult, 1),
                    'tp2_leveraged_pct' => round($tp2Pct * $levMult, 1),
                    'tp3_leveraged_pct' => round($tp3Pct * $levMult, 1),
                    'leverage_multiplier' => $levMult,
                    'liquidation_buffer' => '> 15% safety cushion',
                ];
            }

            return response()->json([
                'success' => true,
                'symbol' => $symbol,
                'tv_symbol' => 'BINANCE:'.$symbol.'.P',
                'market' => $binanceClient->getMarketLabel(),
                'price' => $lastClose,
                'interval' => $interval,
                'htf_interval' => $htf1Interval,
                'htf2_interval' => $htf2Interval,
                'signal' => $signal,
                'diagnostics' => $diagnostics,
                'perpetual_options' => $perpetualOptions,
                'candles' => $history['candles'],
                'markers' => $history['markers'],
                'ema9' => $history['ema9'],
                'ema21' => $history['ema21'],
                'ema200' => $history['ema200'],
                'sync_result' => $syncResult,
                'binance_url' => "https://www.binance.com/en/futures/{$symbol}",
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => "Failed to analyze {$symbol}: ".$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Send an on-demand SignalAlgo PRO alert to Telegram for the given symbol.
     */
    public function sendAlert(
        Request $request,
        BinanceClient $binanceClient,
        SignalEngine $signalEngine,
        TelegramNotifier $telegramNotifier
    ): JsonResponse {
        $rawSymbol = (string) $request->input('symbol', config('crypto.symbols.0', 'BTCUSDT'));
        $symbol = strtoupper(str_replace('.P', '', trim($rawSymbol)));
        if (! str_ends_with($symbol, 'USDT') && ! str_contains($symbol, ':')) {
            $symbol .= 'USDT';
        }

        $interval = strtolower((string) $request->input('interval', config('crypto.interval', '15m')));
        $htf1Interval = $this->resolveHtfInterval($interval);
        $htf2Interval = $this->resolveHtf2Interval($interval);
        $useHtf = (bool) config('crypto.indicators.use_htf_filter', true);

        try {
            $baseCandles = $binanceClient->klines($symbol, $interval, 320);
            $htf1Candles = ($useHtf && $interval !== $htf1Interval) ? $binanceClient->klines($symbol, $htf1Interval, 260) : null;
            $htf2Candles = ($useHtf && $htf2Interval !== null && $interval !== $htf2Interval) ? $binanceClient->klines($symbol, $htf2Interval, 260) : null;

            $evaluation = $signalEngine->evaluateDetailed($baseCandles, $htf1Candles, $htf2Candles);
            $signal = $evaluation['signal'];

            $closes = $baseCandles['closes'] ?? [];
            $lastClose = count($closes) >= 2 ? $closes[count($closes) - 2] : ($closes[count($closes) - 1] ?? 0.0);
            $closeTime = count($baseCandles['closeTimes'] ?? []) >= 2 ? $baseCandles['closeTimes'][count($baseCandles['closeTimes']) - 2] : (now()->timestamp * 1000);

            if (! $signal) {
                $diagnostics = $evaluation['diagnostics'];
                $buyScore = (int) ($diagnostics['buy_score'] ?? 50);
                $sellScore = (int) ($diagnostics['sell_score'] ?? 50);
                $side = ($buyScore >= $sellScore) ? 'BUY' : 'SELL';
                $score = max($buyScore, $sellScore);
                $atrPct = (float) ($diagnostics['atr_pct'] ?? 1.5);
                $atrVal = ($lastClose * $atrPct) / 100;
                $slMult = 1.5;

                $sl = $side === 'BUY' ? round($lastClose - ($atrVal * $slMult), 4) : round($lastClose + ($atrVal * $slMult), 4);
                $risk = abs($lastClose - $sl);
                $tp1 = $side === 'BUY' ? round($lastClose + ($risk * 1.0), 4) : round($lastClose - ($risk * 1.0), 4);
                $tp2 = $side === 'BUY' ? round($lastClose + ($risk * 2.0), 4) : round($lastClose - ($risk * 2.0), 4);
                $tp3 = $side === 'BUY' ? round($lastClose + ($risk * 3.0), 4) : round($lastClose - ($risk * 3.0), 4);

                $recLeverage = $atrPct > 3.0 ? '3x - 5x' : ($atrPct < 1.0 ? '8x - 12x' : '5x - 10x');
                $levMult = $atrPct > 3.0 ? 3 : ($atrPct < 1.0 ? 10 : 5);
                $slPct = $lastClose > 0 ? round(abs($lastClose - $sl) / $lastClose * 100, 2) : 1.5;
                $tp1Pct = $lastClose > 0 ? round(abs($tp1 - $lastClose) / $lastClose * 100, 2) : 1.5;
                $tp2Pct = $lastClose > 0 ? round(abs($tp2 - $lastClose) / $lastClose * 100, 2) : 3.0;
                $tp3Pct = $lastClose > 0 ? round(abs($tp3 - $lastClose) / $lastClose * 100, 2) : 4.5;

                $signal = [
                    'side' => $side,
                    'score' => $score,
                    'entry' => round($lastClose, 4),
                    'sl' => $sl,
                    'tp1' => $tp1,
                    'tp2' => $tp2,
                    'tp3' => $tp3,
                    'rsi' => (float) ($diagnostics['rsi'] ?? 50.0),
                    'adx' => (float) ($diagnostics['adx'] ?? 25.0),
                    'volume_ratio' => (float) ($diagnostics['volume_ratio'] ?? 1.2),
                    'atr_pct' => $atrPct,
                    'candle_close_time' => $closeTime,
                    'perpetual_options' => [
                        'recommended_leverage' => $recLeverage,
                        'margin_mode' => 'Isolated Margin',
                        'order_type' => 'Limit / Market Entry',
                        'risk_per_trade' => '1% - 2% Account Balance',
                        'risk_reward' => '1 : 2.0',
                        'sl_pct' => $slPct,
                        'tp1_pct' => $tp1Pct,
                        'tp2_pct' => $tp2Pct,
                        'tp3_pct' => $tp3Pct,
                        'sl_leveraged_pct' => round($slPct * $levMult, 1),
                        'tp1_leveraged_pct' => round($tp1Pct * $levMult, 1),
                        'tp2_leveraged_pct' => round($tp2Pct * $levMult, 1),
                        'tp3_leveraged_pct' => round($tp3Pct * $levMult, 1),
                        'leverage_multiplier' => $levMult,
                        'liquidation_buffer' => '> 15% safety cushion',
                    ],
                ];
            }

            $message = CheckCryptoSignals::formatTelegramMessage(
                $symbol,
                $interval,
                $signal,
                $binanceClient->getMarketLabel()
            );

            $sent = $telegramNotifier->send($message);

            if ($sent) {
                CheckCryptoSignals::recordSignal($symbol, $interval, $signal, $binanceClient->getMarketLabel(), 'manual_alert');

                return response()->json([
                    'success' => true,
                    'message' => "SignalAlgo PRO alert for {$symbol} ({$signal['side']}) sent to Telegram successfully!",
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to deliver Telegram alert. Verify TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID in .env.',
            ], 500);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Map a base timeframe to its corresponding higher timeframe (HTF).
     */
    protected function resolveHtfInterval(string $interval): string
    {
        $htfMapping = [
            '1m' => '5m',
            '3m' => '15m',
            '5m' => '15m',
            '15m' => '1h',
            '30m' => '2h',
            '1h' => '4h',
            '2h' => '6h',
            '4h' => '1d',
            '1d' => '1d',
        ];

        return $htfMapping[strtolower($interval)] ?? (string) config('crypto.htf_interval', '1h');
    }

    /**
     * Map a base timeframe to its secondary higher timeframe (HTF2).
     */
    protected function resolveHtf2Interval(string $interval): ?string
    {
        $htfMapping = [
            '1m' => '15m',
            '3m' => '1h',
            '5m' => '1h',
            '15m' => '4h',
            '30m' => '4h',
            '1h' => '1d',
            '2h' => '1d',
            '4h' => '1w',
            '1d' => null,
        ];

        return $htfMapping[strtolower($interval)] ?? null;
    }
}
