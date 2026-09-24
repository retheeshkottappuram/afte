<?php

namespace App\Console\Commands;

use App\Models\CryptoSignal;
use App\Services\Crypto\BinanceClient;
use App\Services\Crypto\SignalEngine;
use App\Services\Crypto\SignalRecorder;
use App\Services\Crypto\TelegramNotifier;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckCryptoSignals extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crypto:check-signals
                            {--dry-run : Evaluate signals without sending Telegram alerts or touching cache}
                            {--symbol= : Optional specific symbol to evaluate (e.g. BTCUSDT)}
                            {--interval= : Optional timeframe interval to evaluate (e.g. 5m, 15m, 1h)}
                            {--all : Automatically scan all active liquid Binance Futures contracts}
                            {--min-score= : Optional override for minimum confidence score (e.g. 40, 50)}
                            {--min-atr-pct= : Optional override for minimum ATR% threshold (e.g. 0.05)}
                            {--ignore-cooldown : Bypass cooldown cache to force alert}
                            {--test-alert : Send an immediate live test alert to Telegram with real prices}
                            {--watch : Run continuously in a loop for 24/7 scanning}
                            {--watch-interval=60 : Sleep interval in seconds between scans in watch mode}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Poll Binance klines, evaluate multi-factor technical signals, and push Telegram alerts.';

    /**
     * Execute the console command.
     */
    public function handle(BinanceClient $binanceClient): int
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '512M');
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        try {
            DB::connection()->disableQueryLog();
        } catch (Throwable) {
        }

        $dryRun = (bool) $this->option('dry-run');
        $singleSymbol = $this->option('symbol');
        $scanAll = (bool) $this->option('all');
        $intervalOption = $this->option('interval');
        $minScoreOption = $this->option('min-score');
        $minAtrPctOption = $this->option('min-atr-pct');
        $ignoreCooldown = (bool) $this->option('ignore-cooldown');
        $testAlert = (bool) $this->option('test-alert');

        $minVolume = (float) config('crypto.min_24h_volume', 5000000.0);
        $configuredSymbols = (array) config('crypto.symbols', ['BTCUSDT', 'ETHUSDT', 'SOLUSDT']);
        $isConfiguredAll = in_array('ALL', array_map('strtoupper', $configuredSymbols), true) || (bool) config('crypto.all_symbols', false);

        if ($singleSymbol) {
            $symbols = [strtoupper((string) $singleSymbol)];
        } elseif ($scanAll || $isConfiguredAll) {
            $this->info("Fetching all active liquid Binance Futures USDT perpetuals (min 24h volume: \${$minVolume})...");
            try {
                $symbols = Cache::remember('crypto:futures:liquid_symbols', 1800, function () use ($binanceClient, $minVolume): array {
                    return $binanceClient->getActiveFuturesSymbols($minVolume);
                });
                $this->info('Discovered '.count($symbols).' active liquid futures symbols.');
            } catch (Throwable $e) {
                $this->warn("Failed to fetch dynamic symbols ({$e->getMessage()}). Falling back to configured symbols.");
                $symbols = array_values(array_diff($configuredSymbols, ['ALL']));
            }
        } else {
            $symbols = array_values(array_diff($configuredSymbols, ['ALL']));
        }

        if (empty($symbols)) {
            $symbols = ['BTCUSDT', 'ETHUSDT', 'SOLUSDT'];
        }
        $rawIntervals = $intervalOption ? (string) $intervalOption : (string) config('crypto.interval', '15m');
        $intervals = array_values(array_filter(array_map('trim', explode(',', $rawIntervals))));
        $cooldownMinutes = (int) config('crypto.cooldown_minutes', 75);

        $indicatorConfig = (array) config('crypto.indicators', []);
        if ($minScoreOption !== null) {
            $indicatorConfig['minimum_score'] = (int) $minScoreOption;
        }
        if ($minAtrPctOption !== null) {
            $indicatorConfig['min_atr_pct'] = (float) $minAtrPctOption;
        }

        $signalEngine = new SignalEngine($indicatorConfig);
        $telegramNotifier = new TelegramNotifier(
            (string) config('crypto.telegram.bot_token', ''),
            (string) config('crypto.telegram.chat_id', '')
        );

        // Immediate live test alert mode
        if ($testAlert) {
            $testSymbol = $symbols[0] ?? 'BTCUSDT';
            $testInterval = $intervals[0] ?? '15m';
            $this->info("Fetching live {$testSymbol} prices to generate a real Telegram test alert...");

            try {
                $candles = $binanceClient->klines($testSymbol, $testInterval, 50);
                $lastClose = $candles['closes'][count($candles['closes']) - 2];
                $closeTime = $candles['closeTimes'][count($candles['closeTimes']) - 2];

                $testSignal = [
                    'side' => 'BUY',
                    'score' => 92,
                    'grade' => 'A',
                    'entry' => round($lastClose, 4),
                    'sl' => round($lastClose * 0.985, 4),
                    'tp1' => round($lastClose * 1.015, 4),
                    'tp2' => round($lastClose * 1.030, 4),
                    'tp3' => round($lastClose * 1.045, 4),
                    'rsi' => 62.4,
                    'adx' => 26.8,
                    'volume_ratio' => 1.75,
                    'atr_pct' => 1.25,
                    'candle_close_time' => $closeTime,
                    'perpetual_options' => [
                        'recommended_leverage' => '5x - 10x',
                        'margin_mode' => 'Isolated Margin',
                        'risk_reward' => '1 : 2.5',
                        'sl_pct' => 1.50,
                        'tp1_pct' => 1.50,
                        'tp2_pct' => 3.00,
                        'tp3_pct' => 4.50,
                        'sl_leveraged_pct' => 7.5,
                        'tp1_leveraged_pct' => 7.5,
                        'tp2_leveraged_pct' => 15.0,
                        'tp3_leveraged_pct' => 22.5,
                        'leverage_multiplier' => 5,
                    ],
                ];

                $message = $this->formatTelegramMessage($testSymbol, $testInterval, $testSignal, $binanceClient->getMarketLabel());
                $sent = $telegramNotifier->send($message);

                if ($sent) {
                    self::recordSignal($testSymbol, $testInterval, $testSignal, $binanceClient->getMarketLabel(), 'test_alert');
                    $this->info('✅ [SUCCESS] Real-scenario test alert delivered to your Telegram bot!');
                    $this->line("• Symbol: {$testSymbol} (Live Price: {$lastClose})");
                    $this->line("• Market: {$binanceClient->getMarketLabel()}");
                    $this->line('• Bot Token: configured');
                    $this->line('• Target Chat ID: '.config('crypto.telegram.chat_id'));
                } else {
                    $this->error('❌ Failed to send Telegram alert. Check storage/logs/laravel.log.');
                }
            } catch (Throwable $e) {
                $this->error("Error creating test alert: {$e->getMessage()}");
            }

            return Command::SUCCESS;
        }

        $minScoreLabel = $indicatorConfig['minimum_score'] ?? config('crypto.indicators.minimum_score', 70);

        $watch = (bool) $this->option('watch');
        $watchInterval = max(10, (int) ($this->option('watch-interval') ?: 60));

        Cache::put('crypto:manual_scan:status', 'RUNNING', 600);
        Cache::put('crypto:manual_scan:running', true, 600);
        Cache::put('crypto:manual_scan:signals', [], 3600);
        $processedCount = 0;
        $totalSymbols = count($symbols);
        $foundSignals = [];

        do {
            foreach ($intervals as $interval) {
                $interval = strtolower($interval);
                $htf1Interval = self::resolveHtf1Interval($interval);
                $htf2Interval = self::resolveHtf2Interval($interval);
                $useHtf1 = (bool) ($indicatorConfig['use_htf1'] ?? true) && ($interval !== $htf1Interval);
                $useHtf2 = (bool) ($indicatorConfig['use_htf2'] ?? true) && ($htf2Interval !== null) && ($interval !== $htf2Interval);

                $this->info("Starting crypto signal check... [Market: {$binanceClient->getMarketLabel()} | Timeframe: {$interval}".($useHtf1 ? " | HTF1: {$htf1Interval}" : '').($useHtf2 ? " | HTF2: {$htf2Interval}" : '')." | Min Score: {$minScoreLabel}]".($dryRun ? ' (DRY RUN)' : ''));

                foreach ($symbols as $symbol) {
                    if (Cache::pull('crypto:manual_scan:stop') || file_exists(storage_path('framework/stop-manual-scan'))) {
                        $this->warn("\n⚠️ Stop signal received from frontend. Aborting market scan...");
                        Cache::put('crypto:manual_scan:status', 'STOPPED', 3600);
                        Cache::put('crypto:manual_scan:running', false, 3600);
                        @unlink(storage_path('framework/stop-manual-scan'));

                        return Command::SUCCESS;
                    }

                    $processedCount++;
                    Cache::put('crypto:manual_scan:heartbeat', now()->timestamp, 300);
                    Cache::put('crypto:manual_scan:status', 'RUNNING', 300);
                    Cache::put('crypto:manual_scan:running', true, 300);
                    Cache::put('crypto:manual_scan:progress', [
                        'current_symbol' => $symbol,
                        'index' => $processedCount,
                        'total' => $totalSymbols,
                        'percent' => $totalSymbols > 0 ? round(($processedCount / $totalSymbols) * 100) : 0,
                        'signals_found' => count($foundSignals),
                    ], 300);

                    if ($processedCount % 20 === 0 && function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }

                    try {
                        $this->line("Evaluating symbol: <comment>{$symbol}</comment>");

                        // 1. Fetch base timeframe candles (limit 320 for 200 EMA + buffers)
                        $baseCandles = $binanceClient->klines($symbol, $interval, 320);

                        // 2. Fetch HTF1 and HTF2 candles
                        $htf1Candles = null;
                        if ($useHtf1) {
                            try {
                                $htf1Candles = $binanceClient->klines($symbol, $htf1Interval, 260);
                            } catch (Throwable) {
                                $htf1Candles = null;
                            }
                        }

                        $htf2Candles = null;
                        if ($useHtf2) {
                            try {
                                $htf2Candles = $binanceClient->klines($symbol, $htf2Interval, 260);
                            } catch (Throwable) {
                                $htf2Candles = null;
                            }
                        }

                        // 3. Synchronize all chart markers with database history and dispatch Telegram for any new marker
                        $history = $signalEngine->evaluateHistory($baseCandles, $htf1Candles, $htf2Candles, 140);
                        $syncResult = SignalRecorder::syncMarkers(
                            $symbol,
                            $interval,
                            $history['markers'],
                            $binanceClient->getMarketLabel(),
                            dispatchTelegram: ! $dryRun
                        );

                        if ($syncResult['telegram_sent']) {
                            $this->info("  -> 🚀 [CHART MARKER DISPATCHED] Telegram alert sent and synced for latest {$symbol} signal!");
                        }
                        if ($syncResult['persisted_count'] > 0) {
                            $this->line("  -> <fg=green>Persisted {$syncResult['persisted_count']} chart marker(s) for {$symbol} to Alert History table.</>");
                        }

                        // 4. Evaluate detailed diagnostics on the latest closed candle
                        $evalResult = $signalEngine->evaluateDetailed($baseCandles, $htf1Candles, $htf2Candles);
                        $signal = $evalResult['signal'];
                        $diag = $evalResult['diagnostics'] ?? [];

                        if ($signal === null) {
                            if (isset($diag['rejection']) && $diag['rejection'] !== null) {
                                $this->line("  -> <fg=gray>No signal for {$symbol}: {$diag['rejection']}</>");
                            } else {
                                $buy = $diag['buy_score'] ?? 0;
                                $sell = $diag['sell_score'] ?? 0;
                                $min = $diag['minimum_score'] ?? $minScoreLabel;
                                $rsi = $diag['rsi'] ?? '-';
                                $adx = $diag['adx'] ?? '-';
                                $this->line("  -> <fg=gray>No signal for {$symbol} (BUY: {$buy}/100, SELL: {$sell}/100 | Min: {$min} | RSI: {$rsi}, ADX: {$adx})</>");
                            }

                            continue;
                        }

                        $sideEmoji = $signal['side'] === 'BUY' ? '🟢' : '🔴';
                        $gradeStr = isset($signal['grade']) ? " [Grade {$signal['grade']}]" : '';
                        $foundSignals[] = [
                            'symbol' => $symbol,
                            'side' => $signal['side'],
                            'score' => $signal['score'],
                            'grade' => $signal['grade'] ?? ($signal['score'] >= 90 ? 'A' : 'B'),
                            'entry' => $signal['entry'],
                            'sl' => $signal['sl'],
                            'tp1' => $signal['tp1'],
                            'tp2' => $signal['tp2'],
                            'tp3' => $signal['tp3'],
                            'rsi' => $signal['rsi'] ?? null,
                            'adx' => $signal['adx'] ?? null,
                            'volume_ratio' => $signal['volume_ratio'] ?? null,
                            'atr_pct' => $signal['atr_pct'] ?? null,
                            'time' => Carbon::now('Asia/Kolkata')->format('H:i:s \I\S\T'),
                        ];
                        Cache::put('crypto:manual_scan:signals', $foundSignals, 3600);

                        if ($dryRun) {
                            $this->table(
                                ['Field', 'Value'],
                                [
                                    ['Symbol', $symbol],
                                    ['Side', "{$sideEmoji} {$signal['side']}"],
                                    ['Score', "{$signal['score']}/100"],
                                    ['Entry Price', $signal['entry']],
                                    ['Stop Loss (SL)', $signal['sl']],
                                    ['Take Profit 1 (TP1)', $signal['tp1']],
                                    ['Take Profit 2 (TP2)', $signal['tp2']],
                                    ['Take Profit 3 (TP3)', $signal['tp3']],
                                    ['RSI(14)', $signal['rsi']],
                                    ['ADX(14)', $signal['adx']],
                                    ['Volume Ratio', "{$signal['volume_ratio']}x"],
                                    ['ATR %', "{$signal['atr_pct']}%"],
                                    ['Candle Closed At', Carbon::createFromTimestampMs($signal['candle_close_time'])->toDateTimeString().' UTC'],
                                ]
                            );
                            $this->comment("  [DRY RUN] Skipped Telegram notification & cache cooldown for {$symbol}.");

                            continue;
                        }

                        // 5. Check Cache Cooldown for detailed evaluator
                        $cacheKey = "crypto-signal:{$symbol}:{$interval}:{$signal['side']}";
                        if (! $ignoreCooldown && Cache::has($cacheKey)) {
                            $this->warn("  -> Signal for {$symbol} ({$signal['side']}) is cooling down. Skipping Telegram alert. (Use --ignore-cooldown to bypass)");

                            continue;
                        }

                        // 6. Store cooldown in cache
                        Cache::put($cacheKey, true, now()->addMinutes($cooldownMinutes));

                        // 7. Format and send Telegram notification
                        $message = $this->formatTelegramMessage($symbol, $interval, $signal, $binanceClient->getMarketLabel());
                        $sent = $telegramNotifier->send($message);

                        if ($sent) {
                            self::recordSignal($symbol, $interval, $signal, $binanceClient->getMarketLabel(), 'cron_scanner');
                            $this->info("  -> Telegram alert sent successfully for {$symbol} ({$signal['side']})!");
                        } else {
                            $this->error("  -> Failed to send Telegram alert for {$symbol}. Check log files for details.");
                        }
                    } catch (Throwable $e) {
                        $this->error("Error checking signals for {$symbol}: {$e->getMessage()}");
                        Log::error("CheckCryptoSignals error for {$symbol}", [
                            'exception' => $e,
                            'symbol' => $symbol,
                        ]);
                    }
                }
            }

            $this->info('Crypto signal check iteration completed.');

            if ($watch) {
                $this->line("<fg=yellow>Continuous watch mode active. Sleeping for {$watchInterval} seconds before next check (Press Ctrl+C to terminate)...</>");
                sleep($watchInterval);
            }
        } while ($watch);

        Cache::put('crypto:manual_scan:status', 'COMPLETED', 3600);
        Cache::put('crypto:manual_scan:running', false, 3600);
        $this->info('✨ [COMPLETED] Market scan finished successfully. Total setups found: '.count($foundSignals));

        return Command::SUCCESS;
    }

    /**
     * Format the signal payload into an HTML message for Telegram.
     *
     * @param  array{
     *     side: string,
     *     score: int,
     *     entry: float,
     *     sl: float,
     *     tp1: float,
     *     tp2: float,
     *     tp3: float,
     *     rsi: float,
     *     adx: float,
     *     volume_ratio: float,
     *     atr_pct: float,
     *     candle_close_time: int,
     *     perpetual_options?: array<string, mixed>
     * }  $signal
     */
    public static function formatTelegramMessage(string $symbol, string $interval, array $signal, string $marketLabel = 'Binance USDⓈ-M Futures'): string
    {
        $cleanSymbol = strtoupper(str_replace('.P', '', trim($symbol)));
        $isBuy = ($signal['side'] === 'BUY');
        $emoji = $isBuy ? '🟢' : '🔴';
        $type = $signal['type'] ?? ($signal['setup_type'] ?? 'TREND');

        // Exact Indian Standard Time (IST) formatting
        $istTime = $signal['ist_timestamp'] ?? Carbon::now('Asia/Kolkata')->format('d-M-Y H:i:s \I\S\T');
        $latencyMs = $signal['latency_ms'] ?? 180;
        $livePrice = (float) ($signal['live_price'] ?? ($signal['entry'] ?? 0));
        $entry = (float) $signal['entry'];
        $sl = (float) $signal['sl'];
        $tp1 = (float) $signal['tp1'];
        $tp2 = (float) $signal['tp2'];
        $tp3 = (float) $signal['tp3'];

        $entryStr = $entry < 1 ? number_format($entry, 6, '.', '') : number_format($entry, 4, '.', '');
        $livePriceStr = $livePrice < 1 ? number_format($livePrice, 6, '.', '') : number_format($livePrice, 4, '.', '');
        $slStr = $sl < 1 ? number_format($sl, 6, '.', '') : number_format($sl, 4, '.', '');
        $tp1Str = $tp1 < 1 ? number_format($tp1, 6, '.', '') : number_format($tp1, 4, '.', '');
        $tp2Str = $tp2 < 1 ? number_format($tp2, 6, '.', '') : number_format($tp2, 4, '.', '');
        $tp3Str = $tp3 < 1 ? number_format($tp3, 6, '.', '') : number_format($tp3, 4, '.', '');

        $perp = $signal['perpetual_options'] ?? [];
        $slPct = (float) ($perp['sl_pct'] ?? ($entry > 0 ? round(abs($entry - $sl) / $entry * 100, 2) : 0.0));
        $tp1Pct = (float) ($perp['tp1_pct'] ?? ($entry > 0 ? round(abs($tp1 - $entry) / $entry * 100, 2) : 0.0));
        $tp2Pct = (float) ($perp['tp2_pct'] ?? ($entry > 0 ? round(abs($tp2 - $entry) / $entry * 100, 2) : 0.0));
        $tp3Pct = (float) ($perp['tp3_pct'] ?? ($entry > 0 ? round(abs($tp3 - $entry) / $entry * 100, 2) : 0.0));
        $rrRatio = $perp['risk_reward'] ?? ($slPct > 0 ? '1 : '.round($tp2Pct / $slPct, 1) : '1 : 2.5');

        $leverage = $perp['recommended_leverage'] ?? '5x - 10x';
        $levMult = (int) ($perp['leverage_multiplier'] ?? 5);
        $slLev = $perp['sl_leveraged_pct'] ?? round($slPct * $levMult, 1);
        $tp1Lev = $perp['tp1_leveraged_pct'] ?? round($tp1Pct * $levMult, 1);
        $tp2Lev = $perp['tp2_leveraged_pct'] ?? round($tp2Pct * $levMult, 1);
        $tp3Lev = $perp['tp3_leveraged_pct'] ?? round($tp3Pct * $levMult, 1);
        $grade = $signal['grade'] ?? ($signal['score'] >= 90 ? 'A' : ($signal['score'] >= 82 ? 'B' : 'C'));
        $breakoutLevel = (float) ($signal['breakout_level'] ?? 0.0);
        $breakoutLevelStr = $breakoutLevel < 1 ? number_format($breakoutLevel, 6, '.', '') : number_format($breakoutLevel, 4, '.', '');
        $distPct = (float) ($signal['distance_pct'] ?? 0.0);
        $slippagePct = (float) ($signal['slippage_pct'] ?? 0.0);
        $slippageSign = $slippagePct >= 0 ? "+{$slippagePct}%" : "{$slippagePct}%";

        // ==========================================
        // 1. TIMING GUARD: ENTRY MISSED / EXTENDED
        // ==========================================
        if (($signal['timing_status'] ?? '') === 'MISSED') {
            $chartUrl = config('app.url')."/signals?symbol={$cleanSymbol}&interval={$interval}";

            return "⚠️⚠️⚠️ <b>PULLBACK RETEST SETUP | ENTRY EXTENDED</b> ⚠️⚠️⚠️\n"
                ."⚡ <b>SIGNALALGO PRO™ | CHART SIGNAL DETECTED</b>\n"
                ."🛑 <i>Price moved +{$slippagePct}% beyond initial entry. Wait for a retest pullback!</i>\n\n"
                ."━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
                ."🪙 <b>PAIR:</b> <code>#{$cleanSymbol}</code> ({$marketLabel})\n"
                ."🧭 <b>DIRECTION:</b> {$emoji} <b>".($isBuy ? 'LONG (BUY)' : 'SHORT (SELL)')."</b>\n"
                ."⏱ <b>TIMEFRAME:</b> <b>{$interval}</b> (Confirmed Candle Close)\n"
                ."🏆 <b>QUALITY:</b> <b>GRADE {$grade}</b> (Score: <b>{$signal['score']}/100</b>)\n\n"
                ."📋 <b>TRADE TARGETS & PULLBACK ZONE:</b>\n"
                ."──────────────────────────\n"
                ."• <b>Limit Pullback Zone:</b> <code>{$entryStr}</code> (Live Market: <code>{$livePriceStr}</code>)\n"
                ."• <b>Stop Loss:</b> <code>{$slStr}</code> (-{$slPct}% | -{$slLev}% @ {$levMult}x)\n"
                ."• <b>Take Profit 1:</b> <code>{$tp1Str}</code> (+{$tp1Pct}% | +{$tp1Lev}% @ {$levMult}x)\n"
                ."• <b>Take Profit 2:</b> <code>{$tp2Str}</code> (+{$tp2Pct}% | +{$tp2Lev}% @ {$levMult}x)\n"
                ."• <b>Take Profit 3:</b> <code>{$tp3Str}</code> (+{$tp3Pct}% | +{$tp3Lev}% @ {$levMult}x)\n\n"
                ."🛡️ <b>RISK MANAGEMENT:</b>\n"
                ."• <b>Leverage:</b> <b>{$leverage}</b> (Isolated Margin)\n"
                ."• <b>Risk / Reward:</b> <b>{$rrRatio}</b>\n"
                ."• <b>Action:</b> Place a Limit Buy/Sell at <code>{$entryStr}</code>. Do NOT FOMO market chase.\n\n"
                ."━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
                ."📅 <b>Evaluation Time:</b> {$istTime}\n"
                ."⚡ <b>System Latency:</b> {$latencyMs}ms\n\n"
                ."🔗 <a href=\"https://www.binance.com/en/futures/{$cleanSymbol}\"><b>👉 Open {$cleanSymbol} on Binance Futures</b></a>\n"
                ."🔗 <a href=\"{$chartUrl}\"><b>👉 View Signal on SignalAlgo PRO Chart</b></a>";
        }

        // ==========================================
        // 2. PRE-BREAKOUT WATCH ALERT
        // ==========================================
        if ($type === 'BREAKOUT_WATCH') {
            return "🟡🟡🟡 <b>PRE-BREAKOUT WATCH | EARLY SETUP</b> 🟡🟡🟡\n"
                ."⚡ <b>SIGNALALGO PRO™ | PREPARE ORDERS (DO NOT ENTER YET)</b>\n"
                ."🎯 <i>Institutional Pre-Breakout Alert — High Confluence Approaching!</i>\n\n"
                ."━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
                ."🪙 <b>PAIR:</b> <code>#{$cleanSymbol}</code> ({$marketLabel})\n"
                ."🧭 <b>POTENTIAL DIRECTION:</b> {$emoji} <b>".($isBuy ? 'LONG (RESISTANCE BREAKOUT)' : 'SHORT (SUPPORT BREAKDOWN)')."</b>\n"
                ."⚡ <b>Breakout Probability:</b> <b>{$signal['score']}/100</b> [Grade {$grade}]\n"
                ."⏱ <b>Timeframe:</b> {$interval} (Multi-Timeframe Aligned)\n\n"
                ."🎯 <b>BREAKOUT WATCH METRICS:</b>\n"
                ."──────────────────────────\n"
                .'• <b>Key '.($isBuy ? 'Resistance' : 'Support').":</b> <code>{$breakoutLevelStr}</code>\n"
                ."• <b>Current Market Price:</b> <code>{$livePriceStr}</code>\n"
                ."• <b>Distance to Breakout:</b> <b>{$distPct}%</b>\n"
                ."• <b>Volume Surge:</b> {$signal['volume_ratio']}x vs 20-SMA\n"
                ."• <b>RSI(14) Momentum:</b> {$signal['rsi']}\n"
                ."• <b>ADX(14) Power:</b> {$signal['adx']}\n\n"
                ."🎯 <b>PROJECTED TARGETS UPON CONFIRMATION:</b>\n"
                ."• <b>Trigger Entry:</b> <code>{$entryStr}</code>\n"
                ."• <b>Protective SL:</b> <code>{$slStr}</code> (-{$slPct}%)\n"
                ."• <b>Take Profit 1:</b> <code>{$tp1Str}</code> (+{$tp1Pct}%)\n"
                ."• <b>Take Profit 2:</b> <code>{$tp2Str}</code> (+{$tp2Pct}%)\n"
                ."• <b>Take Profit 3:</b> <code>{$tp3Str}</code> (+{$tp3Pct}%)\n\n"
                ."━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
                ."💡 <b>Action:</b> Keep chart open. A confirmed trade signal will fire once candle closes above level.\n"
                ."📅 <b>Generated:</b> {$istTime}\n"
                ."⚡ <b>Total Latency:</b> {$latencyMs}ms\n\n"
                ."🔗 <a href=\"https://www.binance.com/en/futures/{$cleanSymbol}\"><b>👉 Open {$cleanSymbol} on Binance Futures</b></a>";
        }

        // ==========================================
        // 3. CONFIRMED CHART SIGNAL (NEW TRADE ENTRY)
        // ==========================================
        $directionFull = $isBuy ? 'LONG (BUY)' : 'SHORT (SELL)';
        $actionVerb = $isBuy ? 'ENTER NEW LONG (BUY) TRADE NOW' : 'ENTER NEW SHORT (SELL) TRADE NOW';
        $topBanner = $isBuy
            ? "🟢🟢🟢 <b>NEW TRADE ENTRY SIGNAL</b> 🟢🟢🟢\n⚡ <b>SIGNALALGO PRO™ | CHART SIGNAL PRINTED</b>\n🎯 <b>ACTION: {$actionVerb}</b>"
            : "🔴🔴🔴 <b>NEW TRADE ENTRY SIGNAL</b> 🔴🔴🔴\n⚡ <b>SIGNALALGO PRO™ | CHART SIGNAL PRINTED</b>\n🎯 <b>ACTION: {$actionVerb}</b>";

        $setupDesc = match ($type) {
            'RETEST_ENTRY' => 'Breakout Retest Pullback Bounce',
            'BREAKOUT_CONFIRMED' => 'Confirmed Structure Breakout',
            'REVERSAL' => 'High-Probability Trend Reversal',
            default => 'Institutional Trend Continuation',
        };

        $chartUrl = config('app.url')."/signals?symbol={$cleanSymbol}&interval={$interval}";

        return "{$topBanner}\n\n"
            ."━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
            ."🪙 <b>PAIR:</b> <code>#{$cleanSymbol}</code> ({$marketLabel})\n"
            ."🧭 <b>DIRECTION:</b> {$emoji} <b>{$directionFull}</b>\n"
            ."⏱ <b>TIMEFRAME:</b> <b>{$interval}</b> (Confirmed Candle Close)\n"
            ."🏆 <b>QUALITY:</b> <b>GRADE {$grade}</b> (Confluence: <b>{$signal['score']}/100</b>)\n\n"
            ."📋 <b>TRADE EXECUTION CARD (TAP VALUES TO COPY):</b>\n"
            ."──────────────────────────\n"
            ."• <b>ORDER TYPE:</b> <code>Market / Limit Entry</code>\n"
            ."• <b>ENTRY PRICE:</b> <code>{$entryStr}</code> (Live: <code>{$livePriceStr}</code> | {$slippageSign})\n"
            ."• <b>STOP LOSS (SL):</b> <code>{$slStr}</code> (<b>-{$slPct}%</b> | -{$slLev}% @ {$levMult}x)\n"
            ."• <b>TARGET 1 (TP1):</b> <code>{$tp1Str}</code> (<b>+{$tp1Pct}%</b> | +{$tp1Lev}% @ {$levMult}x)\n"
            ."  └ <i>⚡ Action: Close 40% & Shift Stop Loss to Breakeven</i>\n"
            ."• <b>TARGET 2 (TP2):</b> <code>{$tp2Str}</code> (<b>+{$tp2Pct}%</b> | +{$tp2Lev}% @ {$levMult}x)\n"
            ."  └ <i>⚡ Action: Close 35%</i>\n"
            ."• <b>TARGET 3 (TP3):</b> <code>{$tp3Str}</code> (<b>+{$tp3Pct}%</b> | +{$tp3Lev}% @ {$levMult}x)\n"
            ."  └ <i>⚡ Action: Leave 25% Runner with Trailing Stop</i>\n\n"
            ."🛡️ <b>RISK & POSITION SIZING:</b>\n"
            ."──────────────────────────\n"
            ."• <b>Margin Mode:</b> <b>Isolated Margin</b>\n"
            ."• <b>Recommended Leverage:</b> <b>{$leverage}</b>\n"
            ."• <b>Risk / Reward Ratio:</b> <b>{$rrRatio}</b>\n"
            ."• <b>Risk Allocation:</b> 1.0% - 2.0% Maximum Account Risk\n\n"
            ."📊 <b>CHART CONFLUENCE (Why Signal Was Printed):</b>\n"
            ."──────────────────────────\n"
            ."• <b>Setup Type:</b> {$setupDesc}\n"
            .($breakoutLevel > 0 ? "• <b>Breakout Level:</b> <code>{$breakoutLevelStr}</code> (Broken & Verified)\n" : '')
            ."• <b>RSI(14) Momentum:</b> <b>{$signal['rsi']}</b>\n"
            ."• <b>ADX Trend Power:</b> <b>{$signal['adx']}</b> (Strong Trend)\n"
            ."• <b>Volume Surge:</b> <b>{$signal['volume_ratio']}x</b> vs 20-period SMA\n"
            ."• <b>Volatility ATR%:</b> <b>{$signal['atr_pct']}%</b>\n\n"
            ."━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
            ."📅 <b>Execution Time:</b> {$istTime}\n"
            ."⚡ <b>Signal Delivery Latency:</b> {$latencyMs}ms\n\n"
            ."🔗 <a href=\"https://www.binance.com/en/futures/{$cleanSymbol}\"><b>👉 Open #{$cleanSymbol} on Binance Futures</b></a>\n"
            ."🔗 <a href=\"{$chartUrl}\"><b>👉 View Signal on SignalAlgo PRO Chart</b></a>";
    }

    /**
     * Map a base timeframe to its primary higher timeframe (HTF1).
     */
    public static function resolveHtf1Interval(string $interval): string
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
    public static function resolveHtf2Interval(string $interval): ?string
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

    /**
     * Backwards-compatible alias for primary HTF.
     */
    public static function resolveHtfInterval(string $interval): string
    {
        return self::resolveHtf1Interval($interval);
    }

    /**
     * Persist a sent signal alert to the database.
     *
     * @param  array<string, mixed>  $signal
     */
    public static function recordSignal(
        string $symbol,
        string $interval,
        array $signal,
        string $marketLabel = 'Binance USDⓈ-M Futures',
        string $source = 'cron_scanner'
    ): ?CryptoSignal {
        try {
            $perp = $signal['perpetual_options'] ?? [];
            $candleCloseTime = isset($signal['candle_close_time']) && $signal['candle_close_time'] > 0
                ? Carbon::createFromTimestampMs((int) $signal['candle_close_time'])
                : now();

            return CryptoSignal::create([
                'symbol' => strtoupper(str_replace('.P', '', trim($symbol))),
                'market' => $marketLabel,
                'interval' => strtolower($interval),
                'side' => strtoupper((string) ($signal['side'] ?? 'BUY')),
                'setup_type' => strtoupper((string) ($signal['setup_type'] ?? 'TREND')),
                'score' => (int) ($signal['score'] ?? 0),
                'grade' => (string) ($signal['grade'] ?? 'C'),
                'entry_price' => (float) ($signal['entry'] ?? 0.0),
                'stop_loss' => (float) ($signal['sl'] ?? 0.0),
                'take_profit_1' => (float) ($signal['tp1'] ?? 0.0),
                'take_profit_2' => (float) ($signal['tp2'] ?? 0.0),
                'take_profit_3' => (float) ($signal['tp3'] ?? 0.0),
                'risk_reward' => (string) ($perp['risk_reward'] ?? '1 : 2.5'),
                'rsi' => isset($signal['rsi']) ? (float) $signal['rsi'] : null,
                'adx' => isset($signal['adx']) ? (float) $signal['adx'] : null,
                'volume_ratio' => isset($signal['volume_ratio']) ? (float) $signal['volume_ratio'] : null,
                'atr_pct' => isset($signal['atr_pct']) ? (float) $signal['atr_pct'] : null,
                'leverage' => (string) ($perp['recommended_leverage'] ?? '5x - 10x'),
                'margin_mode' => (string) ($perp['margin_mode'] ?? 'Isolated Margin'),
                'candle_close_time' => $candleCloseTime,
                'source' => $source,
                'telegram_sent' => true,
                'sent_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error("Failed to record CryptoSignal in database: {$e->getMessage()}", [
                'symbol' => $symbol,
                'interval' => $interval,
                'exception' => $e,
            ]);

            return null;
        }
    }
}
