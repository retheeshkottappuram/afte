<?php

namespace App\Services\Breakout;

use App\Models\BreakoutSetup;
use App\Models\BreakoutSetupEvent;
use App\Models\CryptoSignal;
use App\Models\Setting;
use App\Services\Crypto\BinanceClient;
use App\Services\Crypto\Indicators;
use App\Services\Strategy\Signal;
use App\Services\Strategy\SignalLedger;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One EBD run (called every minute by breakout:scan):
 *   - after each 5m close: closed-candle pass for the whole universe (new compressions, confirmations,
 *     failures, expiry, retests, extension), catching up any candles a missed cron run skipped;
 *   - every minute: live early check of the armed coins (price + 1m volume pace).
 * Each symbol is isolated: one failure is logged and the rest continue. State changes are written in a
 * transaction together with their event rows; the unique (setup, event) key stops duplicate alerts.
 */
class BreakoutStrategyService
{
    public const LAST_RUN_KEY = 'ebd_last_run';

    /** @var array<string, mixed> */
    protected array $metrics = [];

    public function __construct(
        protected EbdConfig $config,
        protected MarketDataService $data,
        protected IndicatorCalculator $indicators,
        protected ConsolidationDetector $detector,
        protected SignalLifecycleService $lifecycle,
        protected BreakoutAlerts $alerts,
        protected SignalLedger $ledger,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('breakout.enabled', true) && config('trading.strategy.breakout_engine', 'ebd') === 'ebd';
    }

    /**
     * @param  array<int, string>|null  $onlySymbols
     * @return array<string, mixed> run metrics
     */
    public function run(int $nowMs, bool $forceBarPass = false, ?array $onlySymbols = null, bool $dryRun = false): array
    {
        $started = microtime(true);
        $this->metrics = ['started_at' => Carbon::createFromTimestampMsUTC($nowMs)->toIso8601String(), 'bar_pass' => false, 'symbols' => 0,
            'evaluated' => 0, 'rejected' => [], 'errors' => [], 'events' => [], 'notified' => 0, 'duplicates_prevented' => 0, 'armed' => 0, 'dry_run' => $dryRun];

        $universe = $this->data->universe();
        if ($onlySymbols !== null) {
            $universe = array_intersect_key($universe, array_flip(array_map('strtoupper', $onlySymbols)));
        }
        $this->metrics['symbols'] = count($universe);
        if ($universe === []) {
            $this->metrics['errors'][] = BinanceClient::backoffSeconds() > 0 ? 'Binance back-off active' : 'empty universe (ticker feed unavailable?)';

            return $this->finish($started, $dryRun);
        }

        $barMs = Candles::intervalMs((string) $this->config->get('timeframes.signal'));
        $lastClose = intdiv($nowMs, $barMs) * $barMs - 1;
        $due = $nowMs - ($lastClose + 1) >= $this->config->int('scan.bar_pass_delay_seconds') * 1000;
        $spreads = $this->data->spreads();
        $funding = $this->data->fundingRates();

        if ($forceBarPass || ($due && ($dryRun || cache()->add("ebd:bar:{$lastClose}", true, 900)))) {
            $this->metrics['bar_pass'] = true;
            $this->closedPass($universe, $nowMs, $spreads, $funding, $dryRun);
        }
        $this->liveCheck($universe, $nowMs, $spreads, $funding, $dryRun);

        return $this->finish($started, $dryRun);
    }

    /**
     * @param  array<string, array<string, mixed>>  $universe
     * @param  array<string, float>  $spreads
     * @param  array<string, float>  $funding
     */
    protected function closedPass(array $universe, int $nowMs, array $spreads, array $funding, bool $dryRun): void
    {
        $symbols = array_keys($universe);
        $c5 = $this->data->candles($symbols, '5m', $nowMs);
        $c15 = $this->data->candles($symbols, '15m', $nowMs);
        $c1h = $this->data->candles($symbols, '1h', $nowMs);
        $active = BreakoutSetup::query()->active()->whereIn('symbol', $symbols)->get()->keyBy('symbol');
        $minBars = $this->config->int('compression.bbw_lookback') + $this->config->int('indicators.bb_period') + 2;

        foreach ($symbols as $symbol) {
            try {
                $candles = $c5[$symbol] ?? null;
                if ($candles === null) {
                    $this->metrics['rejected'][$symbol] = 'no 5m candles (request failed or rate-limited)';

                    continue;
                }
                $problems = $this->data->problems($candles, $nowMs, $minBars);
                if ($problems !== []) {
                    $this->metrics['rejected'][$symbol] = implode('; ', $problems);

                    continue;
                }

                $this->metrics['evaluated']++;
                $s = $this->indicators->series($candles);
                $context = $this->context($symbol, $universe[$symbol], $spreads, $funding, $c15[$symbol] ?? null, $c1h[$symbol] ?? null);
                $row = $active[$symbol] ?? null;

                if ($row !== null) {
                    // Every closed candle since the last transition, in order (catches up after a missed cron run).
                    $setup = $row->toEngine();
                    $events = [];
                    foreach ($candles->closeTimes as $i => $closeMs) {
                        if ($closeMs <= $setup['state_since_ms'] || SignalLifecycleService::isTerminal($setup['state'])) {
                            continue;
                        }
                        [$setup, $new] = $this->lifecycle->onClosedBar($setup, $candles, $s, $i, $context);
                        $events = [...$events, ...$new];
                    }
                    $this->save($row, $setup, $events, $nowMs, $dryRun);
                }

                // A new range only when the symbol has no live setup (one that just ended waits a candle).
                if ($row === null) {
                    $found = $this->detector->detect($candles, $s, $candles->lastIndex(), $spreads[$symbol] ?? null);
                    if ($found['ok']) {
                        $setup = $this->lifecycle->open($symbol, $found['range']);
                        $this->save(null, $setup, [], $nowMs, $dryRun);
                    }
                }
            } catch (Throwable $e) {
                $this->metrics['errors'][] = "{$symbol}: ".$e->getMessage();
                Log::warning("EBD {$symbol}: ".$e->getMessage());
            }
        }
    }

    /**
     * Live Stage B for COMPRESSION_DETECTED setups whose price is near or beyond a trigger.
     *
     * @param  array<string, array<string, mixed>>  $universe
     * @param  array<string, float>  $spreads
     * @param  array<string, float>  $funding
     */
    protected function liveCheck(array $universe, int $nowMs, array $spreads, array $funding, bool $dryRun): void
    {
        $prices = $this->data->prices();
        $maxAge = $this->config->int('liquidity.max_price_age_seconds') * 1000;
        $armed = [];
        foreach (BreakoutSetup::query()->where('state', SignalLifecycleService::COMPRESSION)->get() as $row) {
            $quote = $prices[$row->symbol] ?? null;
            if ($quote === null || ($quote['time_ms'] > 0 && $nowMs - $quote['time_ms'] > $maxAge)) {
                continue;
            }
            $setup = $row->toEngine();
            $distance = min($setup['long_trigger'] - $quote['price'], $quote['price'] - $setup['short_trigger']) / $setup['atr'];
            if ($distance <= $this->config->float('early.arm_distance_atr')) {
                $armed[$row->symbol] = ['row' => $row, 'price' => $quote['price'], 'distance' => $distance];
            }
        }
        uasort($armed, fn (array $a, array $b): int => $a['distance'] <=> $b['distance']);
        $armed = array_slice($armed, 0, $this->config->int('early.max_armed_symbols'), true);
        $this->metrics['armed'] = count($armed);
        if ($armed === []) {
            return;
        }

        $symbols = array_keys($armed);
        $m1 = $this->data->minuteCandles($symbols);
        $c5 = $this->data->candles($symbols, '5m', $nowMs);
        $c15 = $this->data->candles($symbols, '15m', $nowMs);
        $c1h = $this->data->candles($symbols, '1h', $nowMs);
        $barMs = Candles::intervalMs((string) $this->config->get('timeframes.signal'));
        $barOpen = intdiv($nowMs, $barMs) * $barMs;

        foreach ($armed as $symbol => $a) {
            try {
                $candles = $c5[$symbol] ?? null;
                if ($candles === null || $candles->count() < 3) {
                    continue;
                }
                $s = $this->indicators->series($candles);
                $k = $candles->lastIndex();
                $minute = $m1[$symbol] ?? null;
                $pace = $minute === null ? null : IndicatorCalculator::volumePace($minute, $barOpen, $barMs, $nowMs, 20, $this->config->int('early.min_pace_samples'));
                if ($pace !== null && $this->config->int('early.min_elapsed_seconds') > $pace['minutes'] * 60) {
                    $pace = null;
                }

                $tick = [
                    'price' => $a['price'],
                    'now_ms' => $nowMs,
                    'bar_open_ms' => $barOpen,
                    'bar_ms' => $barMs,
                    'pace' => $pace['pace'] ?? null,
                    'pace_minutes' => $pace['minutes'] ?? null,
                    'spread_bps' => $spreads[$symbol] ?? null,
                    'funding_rate' => $funding[$symbol] ?? null,
                    'forming' => $minute === null ? null : $this->formingBar($minute, $barOpen),
                    'structure' => ['lows' => array_slice($candles->lows, -2), 'highs' => array_slice($candles->highs, -2)],
                    'rsi' => $s['rsi'][$k],
                    'rsi_prev' => $s['rsi'][$k - 1] ?? null,
                    'ema_fast' => $s['ema_fast'][$k],
                    'ema_slow' => $s['ema_slow'][$k],
                    'context' => $this->context($symbol, $universe[$symbol] ?? [], $spreads, $funding, $c15[$symbol] ?? null, $c1h[$symbol] ?? null),
                ];
                [$setup, $events] = $this->lifecycle->onTick($a['row']->toEngine(), $tick);
                if ($events !== [] || ($setup['last_check'] ?? null) !== ($a['row']->state_data['last_check'] ?? null)) {
                    $this->save($a['row'], $setup, $events, $nowMs, $dryRun);
                }
            } catch (Throwable $e) {
                $this->metrics['errors'][] = "{$symbol} (live): ".$e->getMessage();
                Log::warning("EBD live {$symbol}: ".$e->getMessage());
            }
        }
    }

    /**
     * Writes the setup and its events atomically, then sends the new announced events.
     *
     * @param  array<string, mixed>  $setup
     * @param  array<int, array<string, mixed>>  $events
     */
    protected function save(?BreakoutSetup $row, array $setup, array $events, int $nowMs, bool $dryRun): void
    {
        foreach ($events as $event) {
            $this->metrics['events'][] = "{$setup['symbol']} {$event['type']} ({$event['from']} -> {$event['to']})";
        }
        if ($dryRun) {
            return;
        }

        $expectedState = $row?->state;
        $created = [];
        $saved = DB::transaction(function () use ($row, $setup, $events, $expectedState, &$created): ?BreakoutSetup {
            if ($row === null) {
                $row = BreakoutSetup::query()->firstOrCreate(['setup_key' => $setup['setup_key']], BreakoutSetup::columnsFor($setup));
                if (! $row->wasRecentlyCreated) {
                    $this->metrics['duplicates_prevented']++;

                    return null;
                }
            } else {
                // Another run moved this setup on: drop this result instead of overwriting newer state.
                $fresh = BreakoutSetup::query()->lockForUpdate()->find($row->id);
                if ($fresh === null || $fresh->state !== $expectedState || $fresh->state_since_ms !== $row->state_since_ms) {
                    $this->metrics['duplicates_prevented']++;

                    return null;
                }
                $fresh->update(BreakoutSetup::columnsFor($setup));
                $row = $fresh;
            }

            foreach ($events as $event) {
                $inserted = BreakoutSetupEvent::query()->insertOrIgnore([
                    'breakout_setup_id' => $row->id,
                    'event' => $event['type'],
                    'from_state' => $event['from'],
                    'to_state' => $event['to'],
                    'payload' => json_encode($event, JSON_PARTIAL_OUTPUT_ON_ERROR),
                    'notify' => (bool) $event['notify'],
                    'occurred_at' => Carbon::createFromTimestampMsUTC($event['at_ms']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($inserted === 0) {
                    $this->metrics['duplicates_prevented']++;

                    continue;
                }
                $created[] = $event['type'];
            }

            return $row;
        });

        if ($saved === null) {
            return;
        }
        foreach ($created as $type) {
            $this->announce($saved, $type, $nowMs);
        }
    }

    protected function announce(BreakoutSetup $row, string $type, int $nowMs): void
    {
        $event = BreakoutSetupEvent::query()->where('breakout_setup_id', $row->id)->where('event', $type)->first();
        if ($event === null) {
            return;
        }
        $payload = $event->payload;
        $signal = null;
        if (in_array($type, ['EARLY', 'CONFIRMED', 'RETEST'], true) && ($payload['levels']['ok'] ?? false)) {
            $signal = $this->recordSignal($row, $payload);
        }

        try {
            $messageId = $this->alerts->dispatch($row, $event, $nowMs);
        } catch (Throwable $e) {
            $this->metrics['errors'][] = "{$row->symbol} telegram: ".$e->getMessage();
            $messageId = null;
        }

        if ($messageId !== null) {
            $this->metrics['notified']++;
            $event->update(['notified_at' => now(), 'telegram_message_id' => (string) $messageId]);
            if ($row->telegram_message_id === null) {
                $row->update(['telegram_message_id' => (string) $messageId]);
            }
            $signal?->update(['telegram_sent' => true, 'telegram_message_id' => (string) $messageId]);
        } elseif ($type === 'EARLY') {
            // Not sent (cooldown, alerts off, shadow mode): later FAILED/EXPIRED messages must not refer to it.
            $data = $row->state_data;
            $data['alerted'] = false;
            $row->update(['state_data' => $data]);
        }
    }

    /**
     * Mirrors early/confirmed/retest signals into crypto_signals (shadow, never auto-traded) so the
     * alert log and crypto:resolve-signals track their outcomes like every other signal.
     */
    protected function recordSignal(BreakoutSetup $row, array $e): ?CryptoSignal
    {
        $levels = $e['levels'];
        $setup = match ($e['type']) {
            'EARLY' => 'EBD_EARLY',
            'RETEST' => 'EBD_RETEST',
            default => 'EBD_CONFIRMED',
        };
        $signal = new Signal(
            symbol: $row->symbol,
            interval: (string) $this->config->get('timeframes.signal'),
            side: $e['direction'],
            setup: $setup,
            setupLabel: 'EBD v1 '.strtolower(str_replace('EBD_', '', $setup)),
            time: intdiv((int) $e['at_ms'], 1000),
            entry: $levels['entry'],
            stopLoss: $levels['sl'],
            tp1: $levels['tp1'],
            tp2: $levels['tp2'],
            tp3: $levels['tp2'],
            atr: (float) $row->atr,
            isShadow: true,
            filters: [],
            confluences: [],
            features: ['ebd_setup' => $row->setup_key, 'score' => $e['score']['score'] ?? 0, 'extension_atr' => round((float) ($e['extension_atr'] ?? 0), 3)],
            indicators: ['rsi' => $e['metrics']['rsi'] ?? null, 'volume_ratio' => $e['metrics']['rvol'] ?? $e['pace'] ?? null],
            grade: match ($e['score']['bucket'] ?? null) {
                'HIGH' => 'A',
                'VALID' => 'B',
                default => 'C',
            },
        );

        try {
            $record = $this->ledger->record($signal, 'ebd');
            $record->update(['auto_trade_status' => 'Alert only: EBD signals are never auto-traded', 'score' => $e['score']['score'] ?? 0]);
            if ($row->crypto_signal_id === null) {
                $row->update(['crypto_signal_id' => $record->id]);
            }

            return $record;
        } catch (Throwable $ex) {
            $this->metrics['errors'][] = "{$row->symbol} ledger: ".$ex->getMessage();

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $meta  universe row
     * @return array<string, mixed>
     */
    protected function context(string $symbol, array $meta, array $spreads, array $funding, ?Candles $m15, ?Candles $h1): array
    {
        $context = [
            'spread_bps' => $spreads[$symbol] ?? null,
            'funding_rate' => $funding[$symbol] ?? ($meta['funding_rate'] ?? null),
            'quote_volume_24h' => $meta['quote_volume'] ?? null,
            'h1' => null,
            'm15_compressed' => null,
        ];

        if ($h1 !== null && $h1->count() > $this->config->int('context.h1_ema') + $this->config->int('context.h1_slope_bars')) {
            $ema = Indicators::ema($h1->closes, $this->config->int('context.h1_ema'));
            $k = $h1->lastIndex();
            $back = $k - $this->config->int('context.h1_slope_bars');
            if ($ema[$k] !== null && $ema[$back] !== null) {
                $context['h1'] = ['close' => $h1->closes[$k], 'ema' => $ema[$k], 'ema_slope' => $ema[$k] - $ema[$back]];
            }
        }
        if ($m15 !== null && $m15->count() > 0) {
            $pct = IndicatorCalculator::priorPercentile(
                $this->indicators->bandwidth($m15->closes, $this->config->int('indicators.bb_period'), $this->config->float('indicators.bb_mult')),
                min($this->config->int('compression.bbw_lookback'), max(1, $m15->count() - $this->config->int('indicators.bb_period') - 1)),
            );
            $last = $pct[$m15->lastIndex()] ?? null;
            $context['m15_compressed'] = $last === null ? null : $last <= $this->config->float('context.m15_bbw_percentile');
        }

        return $context;
    }

    /** @return array{open: float, high: float, low: float}|null */
    protected function formingBar(Candles $m1, int $barOpen): ?array
    {
        $open = null;
        $high = -INF;
        $low = INF;
        foreach ($m1->openTimes as $i => $t) {
            if ($t < $barOpen) {
                continue;
            }
            $open ??= $m1->opens[$i];
            $high = max($high, $m1->highs[$i]);
            $low = min($low, $m1->lows[$i]);
        }

        return $open === null ? null : ['open' => $open, 'high' => $high, 'low' => $low];
    }

    /** @return array<string, mixed> */
    protected function finish(float $started, bool $dryRun): array
    {
        $this->metrics['duration_s'] = round(microtime(true) - $started, 2);
        $this->metrics['used_weight'] = BinanceClient::usedWeight();
        $this->metrics['rejected_count'] = count($this->metrics['rejected']);
        $this->metrics['active_setups'] = BreakoutSetup::query()->active()->count();
        if (! $dryRun) {
            $stored = $this->metrics;
            $stored['rejected'] = array_slice($stored['rejected'], 0, 10, true);
            $stored['errors'] = array_slice($stored['errors'], 0, 10);
            Setting::putValue(self::LAST_RUN_KEY, $stored);
        }
        if ($this->metrics['errors'] !== []) {
            Log::warning('EBD run errors', ['errors' => array_slice($this->metrics['errors'], 0, 10)]);
        }

        return $this->metrics;
    }
}
