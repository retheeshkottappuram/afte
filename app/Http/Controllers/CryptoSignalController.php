<?php

namespace App\Http\Controllers;

use App\Console\Commands\CheckCryptoSignals;
use App\Models\CryptoSignal;
use App\Models\User;
use App\Services\Crypto\BinanceClient;
use App\Services\Crypto\SignalEngine;
use App\Services\Crypto\SignalRecorder;
use App\Services\Crypto\TelegramNotifier;
use App\Services\Trading\RiskManager;
use App\Services\Trading\SignalAlgoTrader;
use App\Services\Trading\TradingTargetManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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
            'monitoredCoins' => app(TradingTargetManager::class)->getMonitoredCoins(),
            'tradingMode' => app(SignalAlgoTrader::class)->resolveTradingMode(),
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

            // Fetch BTC base candles for Relative Strength evaluation
            $btcCandles = ($symbol === 'BTCUSDT') ? $baseCandles : $binanceClient->klines('BTCUSDT', $interval, 320);

            $evaluation = $signalEngine->evaluateDetailed($baseCandles, $htf1Candles, $htf2Candles, $btcCandles);
            $history = $signalEngine->evaluateHistory($baseCandles, $htf1Candles, $htf2Candles, 140, $btcCandles);
            $closes = $baseCandles['closes'] ?? [];
            $lastClose = count($closes) >= 2 ? $closes[count($closes) - 2] : ($closes[count($closes) - 1] ?? 0.0);

            // Synchronize all chart markers into the database and dispatch Telegram alert immediately if a new signal is printed
            $syncResult = SignalRecorder::syncMarkers(
                $symbol,
                $interval,
                $history['markers'],
                $binanceClient->getMarketLabel(),
                dispatchTelegram: false
            );

            // Condition 1: Check Bitcoin Macro Trend
            $btcTrend = $binanceClient->getBtcMarketTrend();

            $signal = $evaluation['signal'];
            $diagnostics = $evaluation['diagnostics'];

            // Gate newly generated signal by Bitcoin Macro Trend & Minimum Institutional Score (82)
            if ($signal) {
                $side = strtoupper((string) ($signal['side'] ?? 'BUY'));
                if ($side === 'BUY' && ! $btcTrend['allow_long']) {
                    $diagnostics['rejection'] = "Blocked: BUY setup on {$symbol} contradicts Bitcoin 1h {$btcTrend['trend']} macro trend";
                    $signal = null;
                } elseif ($side === 'SELL' && ! $btcTrend['allow_short']) {
                    $diagnostics['rejection'] = "Blocked: SELL setup on {$symbol} contradicts Bitcoin 1h {$btcTrend['trend']} macro trend";
                    $signal = null;
                } elseif (($signal['score'] ?? 0) < 82) {
                    $diagnostics['rejection'] = "Setup score ({$signal['score']}) is below institutional minimum conviction (82)";
                    $signal = null;
                }
            }

            // Retain active trade setup from the latest marker ONLY if aligned with BTC macro, score >= 82, and SL not breached
            $lastMarker = ! empty($history['markers']) ? end($history['markers']) : null;
            if (! $signal && $lastMarker) {
                $markerScore = (int) ($lastMarker['score'] ?? 0);
                $side = strtoupper((string) ($lastMarker['side'] ?? 'BUY'));
                $isBtcAligned = ($side === 'BUY' && $btcTrend['allow_long']) || ($side === 'SELL' && $btcTrend['allow_short']);

                if ($markerScore >= 82 && $isBtcAligned) {
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
                        $sl = (float) ($lastMarker['sl'] ?? 0);
                        $entry = (float) ($lastMarker['entry'] ?? 0);
                        $invalidated = ($side === 'BUY' && $lastClose < $sl) || ($side === 'SELL' && $lastClose > $sl);

                        if (! $invalidated && $entry > 0) {
                            $atrPct = (float) ($lastMarker['atr_pct'] ?? 1.5);
                            $recLeverage = '5x - 10x';
                            $levMult = 10;
                            $slPct = round(abs($entry - $sl) / $entry * 100, 2);
                            $tp1Pct = round(abs(($lastMarker['tp1'] ?? $entry) - $entry) / $entry * 100, 2);
                            $tp2Pct = round(abs(($lastMarker['tp2'] ?? $entry) - $entry) / $entry * 100, 2);
                            $tp3Pct = round(abs(($lastMarker['tp3'] ?? $entry) - $entry) / $entry * 100, 2);
                            $rrRatio = $lastMarker['risk_reward'] ?? ($slPct > 0 ? '1 : '.round($tp2Pct / $slPct, 1) : '1 : 2.8');

                            $activePerpOptions = [
                                'recommended_leverage' => $recLeverage,
                                'margin_mode' => 'Isolated Margin',
                                'order_type' => 'Limit / Market Entry',
                                'risk_per_trade' => '1.0% - 2.0% Account Balance',
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
                                'setup_type' => $lastMarker['setup_type'] ?? 'PRE_BREAKOUT_COIL',
                                'setup_label' => $lastMarker['setup_label'] ?? ($lastMarker['setup_type'] ?? 'PRE-BREAKOUT COIL'),
                                'score' => $markerScore,
                                'grade' => (string) ($lastMarker['grade'] ?? 'A'),
                                'entry' => $entry,
                                'sl' => $sl,
                                'tp1' => (float) ($lastMarker['tp1'] ?? 0),
                                'tp2' => (float) ($lastMarker['tp2'] ?? 0),
                                'tp3' => (float) ($lastMarker['tp3'] ?? 0),
                                'risk_reward' => $rrRatio,
                                'rsi' => $lastMarker['rsi'] ?? null,
                                'adx' => $lastMarker['adx'] ?? null,
                                'atr_pct' => $atrPct,
                                'volume_ratio' => $lastMarker['volume_ratio'] ?? null,
                                'candle_close_time' => $markerTime * 1000,
                                'rs_ratio' => $lastMarker['rs_ratio'] ?? null,
                                'perpetual_options' => $activePerpOptions,
                            ];
                        }
                    }
                }
            }

            // Only provide perpetual options if a verified institutional signal is genuinely active.
            // Do NOT synthesize fake entry/SL/TP levels when the system is waiting on standby.
            $perpetualOptions = $signal['perpetual_options'] ?? null;

            // Automatic Order Execution Gate:
            // Whenever an opportunity arises on the chart, immediately place that order!
            // Live if live trading is opened/active, otherwise Paper trade.
            $autoOrderResult = null;
            if ($lastMarker && ((int) ($lastMarker['score'] ?? 0)) >= 80) {
                $markerTime = (int) ($lastMarker['time'] ?? 0);
                $candleAgeSeconds = now()->timestamp - $markerTime;
                $maxFreshnessSeconds = match ($interval) {
                    '1m' => 180,
                    '3m' => 450,
                    '5m' => 750,
                    '15m' => 2200,
                    '30m' => 4500,
                    '1h' => 9000,
                    default => 3600,
                };

                if ($candleAgeSeconds >= -60 && $candleAgeSeconds <= $maxFreshnessSeconds) {
                    $entryPrice = (float) ($lastMarker['entry'] ?? $lastClose);
                    $direction = strtoupper((string) ($lastMarker['side'] ?? 'BUY')) === 'BUY' ? 'LONG' : 'SHORT';
                    $sanitizedSl = app(RiskManager::class)->calculateAssetProtectionStopLoss(
                        $direction,
                        $entryPrice,
                        (float) ($lastMarker['sl'] ?? 0.0)
                    );

                    $chartSignal = [
                        'symbol' => $symbol,
                        'interval' => $interval,
                        'side' => strtoupper((string) ($lastMarker['side'] ?? 'BUY')),
                        'direction' => $direction,
                        'score' => (int) ($lastMarker['score'] ?? 80),
                        'grade' => (string) ($lastMarker['grade'] ?? 'A'),
                        'price' => $entryPrice,
                        'initial_sl' => $sanitizedSl,
                        'tp1' => (float) ($lastMarker['tp1'] ?? 0.0),
                        'tp2' => (float) ($lastMarker['tp2'] ?? 0.0),
                        'tp3' => (float) ($lastMarker['tp3'] ?? 0.0),
                        'risk_reward' => (string) ($lastMarker['risk_reward'] ?? '1 : 2.8'),
                        'marker_time' => $markerTime,
                        'setup_type' => (string) ($lastMarker['setup_type'] ?? 'BREAKOUT_CONFIRMED'),
                        'setup_label' => (string) ($lastMarker['setup_label'] ?? 'SIGNALALGO PRO SIGNAL'),
                        'indicators' => [
                            'rsi' => $lastMarker['rsi'] ?? 50,
                            'adx' => $lastMarker['adx'] ?? 20,
                            'atr_pct' => $lastMarker['atr_pct'] ?? 1.5,
                            'volume_ratio' => $lastMarker['volume_ratio'] ?? 1.0,
                            'rs_ratio' => $lastMarker['rs_ratio'] ?? 1.0,
                        ],
                        'raw_marker' => $lastMarker,
                    ];

                    try {
                        $autoOrderResult = app(SignalAlgoTrader::class)->processSignalForExecution($symbol, $chartSignal);
                    } catch (\Throwable $ex) {
                        Log::warning("Auto-order error on {$symbol}: {$ex->getMessage()}");
                    }
                }
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
                'auto_order' => $autoOrderResult,
                'monitored_coins' => TradingTargetManager::getMonitoredCoins(),
                'btc_macro' => $btcTrend,
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

            // Fetch BTC base candles for Relative Strength evaluation
            $btcCandles = ($symbol === 'BTCUSDT') ? $baseCandles : $binanceClient->klines('BTCUSDT', $interval, 320);

            $btcTrend = $binanceClient->getBtcMarketTrend();

            $evaluation = $signalEngine->evaluateDetailed($baseCandles, $htf1Candles, $htf2Candles, $btcCandles);
            $signal = $evaluation['signal'];

            // If no fresh signal on current closed candle, check if latest historical marker is an active valid setup
            if (! $signal) {
                $history = $signalEngine->evaluateHistory($baseCandles, $htf1Candles, $htf2Candles, 140, $btcCandles);
                $lastMarker = ! empty($history['markers']) ? end($history['markers']) : null;
                if ($lastMarker && ((int) ($lastMarker['score'] ?? 0)) >= 82) {
                    $side = strtoupper((string) ($lastMarker['side'] ?? 'BUY'));
                    $isBtcAligned = ($side === 'BUY' && $btcTrend['allow_long']) || ($side === 'SELL' && $btcTrend['allow_short']);
                    $closes = $baseCandles['closes'] ?? [];
                    $lastClose = count($closes) >= 2 ? $closes[count($closes) - 2] : ($closes[count($closes) - 1] ?? 0.0);
                    $sl = (float) ($lastMarker['sl'] ?? 0);
                    $entry = (float) ($lastMarker['entry'] ?? 0);
                    $invalidated = ($side === 'BUY' && $lastClose < $sl) || ($side === 'SELL' && $lastClose > $sl);
                    $candleAgeSeconds = now()->timestamp - ((int) ($lastMarker['time'] ?? 0));

                    if ($isBtcAligned && ! $invalidated && $entry > 0 && $candleAgeSeconds <= 14400) {
                        $atrPct = (float) ($lastMarker['atr_pct'] ?? 1.5);
                        $recLeverage = '5x - 10x';
                        $levMult = 10;
                        $slPct = round(abs($entry - $sl) / $entry * 100, 2);
                        $tp1Pct = round(abs(($lastMarker['tp1'] ?? $entry) - $entry) / $entry * 100, 2);
                        $tp2Pct = round(abs(($lastMarker['tp2'] ?? $entry) - $entry) / $entry * 100, 2);
                        $tp3Pct = round(abs(($lastMarker['tp3'] ?? $entry) - $entry) / $entry * 100, 2);
                        $rrRatio = $lastMarker['risk_reward'] ?? ($slPct > 0 ? '1 : '.round($tp2Pct / $slPct, 1) : '1 : 2.8');

                        $signal = [
                            'side' => $side,
                            'is_active_trade' => true,
                            'setup_type' => $lastMarker['setup_type'] ?? 'PRE_BREAKOUT_COIL',
                            'setup_label' => $lastMarker['setup_label'] ?? ($lastMarker['setup_type'] ?? 'PRE-BREAKOUT COIL'),
                            'score' => (int) ($lastMarker['score'] ?? 82),
                            'grade' => (string) ($lastMarker['grade'] ?? 'A'),
                            'entry' => $entry,
                            'sl' => $sl,
                            'tp1' => (float) ($lastMarker['tp1'] ?? 0),
                            'tp2' => (float) ($lastMarker['tp2'] ?? 0),
                            'tp3' => (float) ($lastMarker['tp3'] ?? 0),
                            'risk_reward' => $rrRatio,
                            'rsi' => $lastMarker['rsi'] ?? null,
                            'adx' => $lastMarker['adx'] ?? null,
                            'volume_ratio' => $lastMarker['volume_ratio'] ?? null,
                            'atr_pct' => $atrPct,
                            'candle_close_time' => ((int) ($lastMarker['time'] ?? 0)) * 1000,
                            'rs_ratio' => $lastMarker['rs_ratio'] ?? null,
                            'perpetual_options' => [
                                'recommended_leverage' => $recLeverage,
                                'margin_mode' => 'Isolated Margin',
                                'order_type' => 'Limit / Market Entry',
                                'risk_per_trade' => '1.0% - 2.0% Account Balance',
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
                            ],
                        ];
                    }
                }
            }

            if (! $signal) {
                return response()->json([
                    'success' => false,
                    'message' => "No verified institutional setup detected for {$symbol} ({$interval}). Standby for high-conviction breakout/pullback setup.",
                ], 422);
            }

            // Enforce Bitcoin Macro Trend Filter
            if ($signal['side'] === 'BUY' && ! $btcTrend['allow_long']) {
                return response()->json([
                    'success' => false,
                    'message' => "Alert blocked: BUY setup on {$symbol} contradicts Bitcoin 1h {$btcTrend['trend']} macro trend.",
                ], 422);
            }
            if ($signal['side'] === 'SELL' && ! $btcTrend['allow_short']) {
                return response()->json([
                    'success' => false,
                    'message' => "Alert blocked: SELL setup on {$symbol} contradicts Bitcoin 1h {$btcTrend['trend']} macro trend.",
                ], 422);
            }

            // Enforce Score >= 82
            if (($signal['score'] ?? 0) < 82) {
                return response()->json([
                    'success' => false,
                    'message' => "Alert blocked: Setup conviction score ({$signal['score']}) is below institutional threshold (82).",
                ], 422);
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
