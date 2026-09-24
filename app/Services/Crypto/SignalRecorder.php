<?php

namespace App\Services\Crypto;

use App\Console\Commands\CheckCryptoSignals;
use App\Models\CryptoSignal;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SignalRecorder
{
    /**
     * Synchronize chart markers into the database and dispatch Telegram alerts for unnotified signals.
     *
     * @param  array<int, array<string, mixed>>  $markers
     * @return array{persisted_count: int, telegram_sent: bool, latest_signal: ?array<string, mixed>}
     */
    public static function syncMarkers(
        string $symbol,
        string $interval,
        array $markers,
        string $marketLabel = 'Binance USDⓈ-M Futures',
        bool $dispatchTelegram = true
    ): array {
        $cleanSymbol = strtoupper(str_replace('.P', '', trim($symbol)));
        $interval = strtolower(trim($interval));
        $persistedCount = 0;
        $telegramSent = false;
        $latestSignalPayload = null;

        if (empty($markers)) {
            return [
                'persisted_count' => 0,
                'telegram_sent' => false,
                'latest_signal' => null,
            ];
        }

        $botToken = (string) config('crypto.telegram.bot_token', '');
        $chatId = (string) config('crypto.telegram.chat_id', '');
        $telegramNotifier = new TelegramNotifier($botToken, $chatId);
        $cooldownMinutes = (int) config('crypto.cooldown_minutes', 75);

        // Sort markers by time ascending to process chronologically
        usort($markers, static function (array $a, array $b): int {
            return ((int) ($a['time'] ?? 0)) <=> ((int) ($b['time'] ?? 0));
        });

        $totalMarkers = count($markers);

        foreach ($markers as $index => $marker) {
            try {
                $timeSec = (int) ($marker['time'] ?? 0);
                if ($timeSec <= 0) {
                    continue;
                }

                $candleCloseTime = Carbon::createFromTimestampUTC($timeSec);
                $candleCloseTimeMs = $timeSec * 1000;
                $side = strtoupper((string) ($marker['side'] ?? 'BUY'));
                $setupType = strtoupper((string) ($marker['setup_type'] ?? 'TREND'));
                $score = (int) ($marker['score'] ?? 0);
                $grade = (string) ($marker['grade'] ?? 'C');
                $entry = (float) ($marker['entry'] ?? 0.0);
                $sl = (float) ($marker['sl'] ?? 0.0);
                $tp1 = (float) ($marker['tp1'] ?? 0.0);
                $tp2 = (float) ($marker['tp2'] ?? 0.0);
                $tp3 = (float) ($marker['tp3'] ?? 0.0);
                $rsi = isset($marker['rsi']) ? (float) $marker['rsi'] : null;
                $adx = isset($marker['adx']) ? (float) $marker['adx'] : null;
                $atrPct = isset($marker['atr_pct']) ? (float) $marker['atr_pct'] : 1.5;
                $volRatio = isset($marker['volume_ratio']) ? (float) $marker['volume_ratio'] : 1.0;

                // Derive perpetual risk-reward and leverage options
                $slPct = $entry > 0 ? round(abs($entry - $sl) / $entry * 100, 2) : 1.5;
                $tp1Pct = $entry > 0 ? round(abs($tp1 - $entry) / $entry * 100, 2) : 1.5;
                $tp2Pct = $entry > 0 ? round(abs($tp2 - $entry) / $entry * 100, 2) : 3.0;
                $tp3Pct = $entry > 0 ? round(abs($tp3 - $entry) / $entry * 100, 2) : 4.5;
                $rrRatio = $slPct > 0 ? '1 : '.round($tp2Pct / $slPct, 1) : '1 : 2.0';

                $recLeverage = $atrPct > 3.0 ? '3x - 5x' : ($atrPct < 1.0 ? '8x - 12x' : '5x - 10x');
                $levMult = $atrPct > 3.0 ? 3 : ($atrPct < 1.0 ? 10 : 5);

                $perpetualOptions = [
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

                $signalPayload = [
                    'side' => $side,
                    'setup_type' => $setupType,
                    'score' => $score,
                    'grade' => $grade,
                    'entry' => $entry,
                    'sl' => $sl,
                    'tp1' => $tp1,
                    'tp2' => $tp2,
                    'tp3' => $tp3,
                    'rsi' => $rsi,
                    'adx' => $adx,
                    'volume_ratio' => $volRatio,
                    'atr_pct' => $atrPct,
                    'candle_close_time' => $candleCloseTimeMs,
                    'perpetual_options' => $perpetualOptions,
                    'is_chart_printed' => true,
                ];

                $isLatestMarker = ($index === ($totalMarkers - 1));
                if ($isLatestMarker) {
                    $latestSignalPayload = $signalPayload;
                }

                // Determine whether this candle closed recently enough to qualify as a fresh, live alert
                $candleAgeSeconds = now()->timestamp - $timeSec;
                $maxFreshnessSeconds = match (strtolower($interval)) {
                    '1m' => 180,         // 3 minutes max
                    '3m' => 300,         // 5 minutes max
                    '5m' => 600,         // 10 minutes max
                    '15m' => 1500,       // 25 minutes max (within current / next candle)
                    '30m' => 2700,       // 45 minutes max
                    '1h' => 4500,        // 75 minutes max
                    '2h' => 9000,        // 2.5 hours
                    '4h' => 18000,       // 5 hours
                    '1d' => 86400,       // 24 hours
                    default => 1500,
                };
                $isFreshCandle = ($candleAgeSeconds >= -60 && $candleAgeSeconds <= $maxFreshnessSeconds);

                // Check or create signal in database
                /** @var CryptoSignal $signalRecord */
                $signalRecord = CryptoSignal::firstOrCreate(
                    [
                        'symbol' => $cleanSymbol,
                        'interval' => $interval,
                        'candle_close_time' => $candleCloseTime,
                        'side' => $side,
                    ],
                    [
                        'market' => $marketLabel,
                        'setup_type' => $setupType,
                        'score' => $score,
                        'grade' => $grade,
                        'entry_price' => $entry,
                        'stop_loss' => $sl,
                        'take_profit_1' => $tp1,
                        'take_profit_2' => $tp2,
                        'take_profit_3' => $tp3,
                        'risk_reward' => $rrRatio,
                        'rsi' => $rsi,
                        'adx' => $adx,
                        'volume_ratio' => $volRatio,
                        'atr_pct' => $atrPct,
                        'leverage' => $recLeverage,
                        'margin_mode' => 'Isolated Margin',
                        'source' => ($isLatestMarker && $isFreshCandle) ? 'chart_live' : 'chart_history',
                        'telegram_sent' => false,
                        'sent_at' => $candleCloseTime,
                    ]
                );

                if ($signalRecord->wasRecentlyCreated) {
                    $persistedCount++;
                }

                // If this is the latest marker on the chart AND is a fresh candle close, evaluate for Telegram dispatch
                // Professional Institutional Filter: Only dispatch setups meeting minimum score threshold
                $minScore = (int) (config('crypto.indicators.minimum_score') ?? config('crypto.min_score', 70));
                if ($isLatestMarker && $isFreshCandle && $score >= $minScore && $dispatchTelegram) {
                    $dedupCacheKey = "crypto-signal:telegram-sent:{$cleanSymbol}:{$interval}:{$timeSec}:{$side}";
                    $generalCooldownKey = "crypto-signal:{$cleanSymbol}:{$interval}:{$side}";

                    $alreadyAlerted = $signalRecord->telegram_sent || Cache::has($dedupCacheKey);

                    if (! $alreadyAlerted && $telegramNotifier->isConfigured()) {
                        // Real-Time Live Price & Timing Validation (TimingGuard)
                        $timingGuard = new TimingGuard;
                        $timing = $timingGuard->validateEntry(
                            symbol: $cleanSymbol,
                            side: $side,
                            entryPrice: $entry,
                            tp1Price: $tp1,
                            candleCloseTimeMs: $candleCloseTimeMs,
                            detectionTimeMs: microtime(true)
                        );

                        // Attach live price metrics to payload
                        $signalPayload['live_price'] = $timing['live_price'];
                        $signalPayload['slippage_pct'] = $timing['slippage_pct'];
                        $signalPayload['timing_status'] = $timing['status'];
                        $signalPayload['timing_reason'] = $timing['reason'];
                        $signalPayload['ist_timestamp'] = $timing['ist_timestamp'];
                        $signalPayload['latency_ms'] = $timing['latency_ms'];

                        // If setup completely invalidated in opposite direction, do not send
                        if ($timing['status'] === 'INVALIDATED') {
                            Log::warning("SignalRecorder: Suppressed invalidated setup for {$cleanSymbol} {$interval} ({$timing['reason']})");
                            Cache::put($dedupCacheKey, true, now()->addDays(7));
                        } else {
                            $message = CheckCryptoSignals::formatTelegramMessage($cleanSymbol, $interval, $signalPayload, $marketLabel);
                            $sendResult = $telegramNotifier->sendWithDetails($message);

                            if ($sendResult['success']) {
                                $signalRecord->update([
                                    'telegram_sent' => true,
                                    'source' => 'chart_live',
                                    'sent_at' => now(),
                                ]);

                                Cache::put($dedupCacheKey, true, now()->addDays(7));
                                Cache::put($generalCooldownKey, true, now()->addMinutes($cooldownMinutes));

                                $telegramSent = true;
                                Log::info("Dispatched Telegram alert for live marker on {$cleanSymbol} {$interval} ({$side}) in {$sendResult['latency_ms']}ms");
                            } else {
                                Log::error("Failed to send Telegram alert for chart marker on {$cleanSymbol} {$interval}: {$sendResult['error']}");
                            }
                        }
                    }
                } elseif ($isLatestMarker && (! $isFreshCandle || $score < $minScore)) {
                    // Pre-mark cache so this stale historical or low-conviction marker is never accidentally alerted later
                    $dedupCacheKey = "crypto-signal:telegram-sent:{$cleanSymbol}:{$interval}:{$timeSec}:{$side}";
                    Cache::put($dedupCacheKey, true, now()->addDays(7));
                }
            } catch (Throwable $e) {
                Log::error("Failed to sync marker for {$cleanSymbol}: {$e->getMessage()}", [
                    'marker' => $marker,
                    'exception' => $e,
                ]);
            }
        }

        return [
            'persisted_count' => $persistedCount,
            'telegram_sent' => $telegramSent,
            'latest_signal' => $latestSignalPayload,
        ];
    }
}
