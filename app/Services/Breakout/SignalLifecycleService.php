<?php

namespace App\Services\Breakout;

/**
 * The EBD state machine. Pure: it takes a setup (array mirroring a breakout_setups row) plus the
 * market data available at that moment and returns the new setup and the lifecycle events. The live
 * scanner and the backtester call the same two methods, so a backtest cannot use information the
 * live run would not have had.
 *
 *   IDLE -> COMPRESSION_DETECTED -> EARLY_BREAKOUT_WATCH -> CONFIRMED_BREAKOUT -> RETEST_WAIT -> RETEST_CONFIRMED
 *                    |                      |                        |                 |
 *                    +-> EXPIRED            +-> FAILED_BREAKOUT       +-> FAILED / EXPIRED (tracking over, stop hit)
 *                    +-> EXTENDED_MOVE      +-> EXPIRED (not confirmed in time)
 *                                           +-> EXTENDED_MOVE -> RETEST_WAIT
 *
 * Events: EARLY, CONFIRMED, FAILED, EXPIRED, RETEST, EXTENDED; each carries `notify` (send Telegram or not).
 */
class SignalLifecycleService
{
    public const IDLE = 'IDLE';

    public const COMPRESSION = 'COMPRESSION_DETECTED';

    public const EARLY = 'EARLY_BREAKOUT_WATCH';

    public const CONFIRMED = 'CONFIRMED_BREAKOUT';

    public const RETEST_WAIT = 'RETEST_WAIT';

    public const RETEST_CONFIRMED = 'RETEST_CONFIRMED';

    public const FAILED = 'FAILED_BREAKOUT';

    public const EXPIRED = 'EXPIRED';

    public const EXTENDED = 'EXTENDED_MOVE';

    public const TERMINAL = [self::RETEST_CONFIRMED, self::FAILED, self::EXPIRED];

    /** States that still watch for the first price break (live early check applies). */
    public const ARMED = [self::COMPRESSION];

    public function __construct(
        protected EbdConfig $config,
        protected BreakoutTriggerDetector $triggers,
        protected BreakoutQualityScorer $scorer,
        protected SignalRiskCalculator $risk,
    ) {}

    public static function isTerminal(string $state): bool
    {
        return in_array($state, self::TERMINAL, true);
    }

    /**
     * A new setup from a ConsolidationDetector range recorded after candle `bar_close_ms` closed.
     *
     * @param  array<string, float|int>  $range
     * @return array<string, mixed>
     */
    public function open(string $symbol, array $range): array
    {
        return array_merge($range, [
            'symbol' => $symbol,
            'setup_key' => sprintf('%s:%s:%d', $symbol, $this->config->get('timeframes.signal'), $range['bar_close_ms']),
            'state' => self::COMPRESSION,
            'direction' => null,
            'compressed_ms' => (int) $range['bar_close_ms'],
            'state_since_ms' => (int) $range['bar_close_ms'],
            'inside_closes' => 0,
            'alerted' => false,
            'entry_valid' => false,
            'cancel_reason' => null,
        ]);
    }

    /**
     * Stage B, live: the latest observed price of a forming 5m candle.
     *
     * @param  array{price: float, now_ms: int, bar_open_ms: int, bar_ms: int, pace: ?float, pace_minutes?: int, spread_bps: ?float,
     *     funding_rate?: ?float, forming?: array{open: float, high: float, low: float}, structure: array{lows: array<int, float>, highs: array<int, float>},
     *     rsi?: ?float, rsi_prev?: ?float, ema_fast?: ?float, ema_slow?: ?float, context?: array<string, mixed>}  $tick
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}
     */
    public function onTick(array $setup, array $tick): array
    {
        if ($setup['state'] !== self::COMPRESSION || $tick['bar_open_ms'] <= $setup['compressed_ms']) {
            return [$setup, []];
        }

        $price = $tick['price'];
        $early = $this->triggers->early($setup, $price, $tick['pace'], $tick['spread_bps']);
        if ($early['direction'] === null) {
            return [$setup, []];
        }
        $direction = $early['direction'];

        if ($early['extended']) {
            return $this->transition($setup, self::EXTENDED, $tick['now_ms'], [
                'type' => 'EXTENDED', 'notify' => false, 'direction' => $direction, 'price' => $price, 'extension_atr' => $early['extension_atr'],
                'reason' => 'first observed beyond the trigger already extended: BREAKOUT_IN_PROGRESS, not an entry',
            ], ['direction' => $direction, 'extension_atr' => $early['extension_atr'], 'observed_trigger_price' => $price,
                'detected_ms' => $tick['now_ms'], 'detection_mode' => 'live', 'cancel_reason' => 'extended before detection']);
        }
        if (! $early['ok']) {
            $setup['last_check'] = implode('; ', $early['reasons']);

            return [$setup, []];
        }

        $forming = $tick['forming'] ?? null;
        $score = $this->scorer->score([
            'direction' => $direction,
            'bbw_pct' => $setup['bbw_pct'],
            'range_ratio' => $setup['range_ratio'],
            'extension_atr' => $early['extension_atr'],
            'volume' => $tick['pace'],
            'volume_accelerating' => null,
            'body_atr' => $forming ? IndicatorCalculator::bodyAtr($forming['open'], $price, $setup['atr']) : null,
            'close_location' => $forming ? IndicatorCalculator::closeLocation(max($forming['high'], $price), min($forming['low'], $price), $price, $direction) : null,
            'rsi' => $tick['rsi'] ?? null,
            'rsi_prev' => $tick['rsi_prev'] ?? null,
            'ema_fast' => $tick['ema_fast'] ?? null,
            'ema_slow' => $tick['ema_slow'] ?? null,
            'spread_bps' => $tick['spread_bps'],
        ] + $this->contextFeatures($tick['context'] ?? [], $direction));

        // score_floor: only the backtester lowers it (it cannot observe the 1m volume pace that live scores use).
        $floor = (float) ($tick['score_floor'] ?? $this->config->float('score.watchlist'));
        if ($score['score'] < $floor) {
            $setup['last_check'] = "early score {$score['score']} below {$floor}";

            return [$setup, []];
        }

        $structure = $direction === 'LONG' ? [...$tick['structure']['lows'], $forming['low'] ?? $price] : [...$tick['structure']['highs'], $forming['high'] ?? $price];
        $levels = $this->risk->levels($direction, $price, $setup, $structure, $tick['spread_bps'], $tick['funding_rate'] ?? null);

        return $this->transition($setup, self::EARLY, $tick['now_ms'], [
            'type' => 'EARLY', 'notify' => true, 'direction' => $direction, 'price' => $price,
            'score' => $score, 'levels' => $levels, 'pace' => $tick['pace'], 'pace_minutes' => $tick['pace_minutes'] ?? null,
            'extension_atr' => $early['extension_atr'], 'detection_mode' => 'live',
        ], [
            'direction' => $direction,
            'observed_trigger_price' => $price,
            'detected_ms' => $tick['now_ms'],
            'detection_mode' => 'live',
            'detection_latency_s' => intdiv($tick['now_ms'] - $tick['bar_open_ms'], 1000),
            'rvol_pace' => $tick['pace'],
            'early_score' => $score['score'],
            'early_components' => $score['components'],
            'extension_atr' => $early['extension_atr'],
            'alerted' => true,
        ] + $this->levelColumns($levels));
    }

    /**
     * Stages B (observed at the close), C, D, E, F on the closed candle i.
     *
     * @param  array<string, array<int, ?float>>  $s
     * @param  array<string, mixed>  $context  spread_bps, funding_rate, quote_volume_24h, h1, m15_compressed
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}
     */
    public function onClosedBar(array $setup, Candles $c, array $s, int $i, array $context = []): array
    {
        $closeMs = $c->closeTimes[$i];
        if (self::isTerminal($setup['state']) || $closeMs <= $setup['state_since_ms']) {
            return [$setup, []];
        }

        return match ($setup['state']) {
            self::COMPRESSION => $this->fromCompression($setup, $c, $s, $i, $context),
            self::EARLY => $this->fromEarly($setup, $c, $s, $i, $context),
            self::CONFIRMED, self::RETEST_WAIT, self::EXTENDED => $this->afterBreak($setup, $c, $s, $i, $context),
            default => [$setup, []],
        };
    }

    protected function fromCompression(array $setup, Candles $c, array $s, int $i, array $context): array
    {
        $close = $c->closes[$i];
        $closeMs = $c->closeTimes[$i];
        $direction = BreakoutTriggerDetector::crossedDirection($setup, $close);

        if ($direction !== null) {
            $confirm = $this->triggers->confirm($c, $s, $i, $setup, $direction);
            $setup['direction'] = $direction;
            $setup['detected_ms'] = $closeMs;
            $setup['detection_mode'] = 'candle_close';
            $setup['detection_latency_s'] = intdiv($c->intervalMs, 1000);
            $setup['observed_trigger_price'] = $close;

            if ($confirm['ok']) {
                return $this->confirm($setup, $c, $s, $i, $context, $confirm);
            }
            if ($confirm['metrics']['extension_atr'] > $this->config->float('max_extension_atr')) {
                return $this->transition($setup, self::EXTENDED, $closeMs, [
                    'type' => 'EXTENDED', 'notify' => false, 'direction' => $direction, 'price' => $close,
                    'extension_atr' => $confirm['metrics']['extension_atr'], 'reason' => 'closed too far past the boundary: BREAKOUT_IN_PROGRESS, not an entry',
                ], ['extension_atr' => $confirm['metrics']['extension_atr'], 'cancel_reason' => 'extended before detection']);
            }
            if (($s['rvol'][$i] ?? 0) >= $this->config->float('early.min_volume_pace')) {
                return $this->earlyOnClose($setup, $c, $s, $i, $context, $direction, $confirm);
            }
            // A low-volume poke outside the box: no signal, the box stays armed until it expires.
            $setup['direction'] = null;
            $setup['last_check'] = 'closed outside on low volume: '.implode('; ', $confirm['reasons']);
        }

        if ($this->barsSince($setup['compressed_ms'], $closeMs, $c) >= $this->config->int('compression.expiry_bars')) {
            return $this->transition($setup, self::EXPIRED, $closeMs, ['type' => 'EXPIRED', 'notify' => false, 'reason' => 'no breakout within '.$this->config->int('compression.expiry_bars').' candles'],
                ['cancel_reason' => 'compression expired', 'expired_ms' => $closeMs]);
        }

        return [$setup, []];
    }

    protected function earlyOnClose(array $setup, Candles $c, array $s, int $i, array $context, string $direction, array $confirm): array
    {
        $closeMs = $c->closeTimes[$i];
        $score = $this->scoreClosed($setup, $c, $s, $i, $context, $direction, $confirm['metrics']);
        if ($score['score'] < $this->config->float('score.watchlist')) {
            $setup['direction'] = null;
            $setup['last_check'] = "closed outside, score {$score['score']} below ".$this->config->float('score.watchlist');

            return [$setup, []];
        }
        $levels = $this->levelsAt($setup, $c, $i, $direction, $c->closes[$i], $context);

        return $this->transition($setup, self::EARLY, $closeMs, [
            'type' => 'EARLY', 'notify' => true, 'direction' => $direction, 'price' => $c->closes[$i], 'score' => $score,
            'levels' => $levels, 'pace' => $s['rvol'][$i], 'extension_atr' => $confirm['metrics']['extension_atr'],
            'detection_mode' => 'candle_close', 'pending' => $confirm['reasons'],
        ], [
            'early_score' => $score['score'],
            'early_components' => $score['components'],
            'rvol_pace' => $s['rvol'][$i],
            'extension_atr' => $confirm['metrics']['extension_atr'],
            'alerted' => true,
        ] + $this->levelColumns($levels));
    }

    protected function fromEarly(array $setup, Candles $c, array $s, int $i, array $context): array
    {
        $closeMs = $c->closeTimes[$i];
        $direction = $setup['direction'];
        $confirm = $this->triggers->confirm($c, $s, $i, $setup, $direction);
        if ($confirm['ok']) {
            return $this->confirm($setup, $c, $s, $i, $context, $confirm);
        }

        [$setup, $failed] = $this->failureCheck($setup, $c, $i);
        if ($failed !== null) {
            return $failed;
        }
        if ($confirm['metrics']['extension_atr'] > $this->config->float('max_extension_atr')) {
            return $this->transition($setup, self::EXTENDED, $closeMs, [
                'type' => 'EXTENDED', 'notify' => $setup['alerted'] && $this->config->bool('notifications.notify_extended'), 'direction' => $direction,
                'price' => $c->closes[$i], 'extension_atr' => $confirm['metrics']['extension_atr'],
                'reason' => 'ran past '.$this->config->float('max_extension_atr').' ATR without a confirming candle: BREAKOUT_IN_PROGRESS, do not chase',
            ], ['extension_atr' => $confirm['metrics']['extension_atr']]);
        }
        if ($this->barsSince($setup['state_since_ms'], $closeMs, $c) >= $this->config->int('early.watch_bars')) {
            return $this->transition($setup, self::EXPIRED, $closeMs, [
                'type' => 'EXPIRED', 'notify' => $setup['alerted'], 'direction' => $direction, 'price' => $c->closes[$i],
                'reason' => 'not confirmed within '.$this->config->int('early.watch_bars').' candles: '.implode('; ', $confirm['reasons']),
            ], ['cancel_reason' => 'early alert not confirmed', 'expired_ms' => $closeMs]);
        }

        return [$setup, []];
    }

    protected function confirm(array $setup, Candles $c, array $s, int $i, array $context, array $confirm): array
    {
        $closeMs = $c->closeTimes[$i];
        $direction = $setup['direction'];
        $score = $this->scoreClosed($setup, $c, $s, $i, $context, $direction, $confirm['metrics']);
        $levels = $this->levelsAt($setup, $c, $i, $direction, $c->closes[$i], $context);
        $bucket = $score['bucket'];
        $entryValid = $levels['ok'] && in_array($bucket, ['HIGH', 'VALID'], true);
        $why = match (true) {
            ! $levels['ok'] => 'no entry: '.$levels['reason'],
            $bucket === 'WATCHLIST' => 'watchlist only: score '.$score['score'],
            $bucket === 'REJECT' => 'no entry: score '.$score['score'].' below '.$this->config->float('score.watchlist'),
            default => null,
        };

        return $this->transition($setup, self::CONFIRMED, $closeMs, [
            'type' => 'CONFIRMED', 'notify' => $bucket !== 'REJECT' || $setup['alerted'], 'direction' => $direction, 'price' => $c->closes[$i],
            'score' => $score, 'levels' => $levels, 'metrics' => $confirm['metrics'], 'entry_valid' => $entryValid, 'why' => $why,
            'early_score' => $setup['early_score'] ?? null,
        ], [
            'confirmed_ms' => $closeMs,
            'confirmed_score' => $score['score'],
            'confirmed_components' => $score['components'],
            'rvol' => $confirm['metrics']['rvol'],
            'extension_atr' => $confirm['metrics']['extension_atr'],
            'entry_valid' => $entryValid,
            'cancel_reason' => $why,
            'inside_closes' => 0,
            'alerted' => $setup['alerted'] || $bucket !== 'REJECT',
            'confirm_metrics' => $confirm['metrics'],
        ] + $this->levelColumns($levels));
    }

    /** Stages D-F after a confirmed or extended break. */
    protected function afterBreak(array $setup, Candles $c, array $s, int $i, array $context): array
    {
        $closeMs = $c->closeTimes[$i];
        $long = $setup['direction'] === 'LONG';
        $high = $c->highs[$i];
        $low = $c->lows[$i];
        $close = $c->closes[$i];
        $notify = (bool) $setup['alerted'];

        if (($setup['sl'] ?? null) !== null && $setup['entry_valid'] && ($long ? $low <= $setup['sl'] : $high >= $setup['sl'])) {
            return $this->transition($setup, self::FAILED, $closeMs, ['type' => 'FAILED', 'notify' => $notify, 'direction' => $setup['direction'], 'price' => $close, 'reason' => 'stop-loss level traded'],
                ['cancel_reason' => 'stop hit', 'failed_ms' => $closeMs]);
        }
        [$setup, $failed] = $this->failureCheck($setup, $c, $i);
        if ($failed !== null) {
            return $failed;
        }
        if (($setup['tp2'] ?? null) !== null && $setup['entry_valid'] && ($long ? $high >= $setup['tp2'] : $low <= $setup['tp2'])) {
            return $this->transition($setup, self::EXPIRED, $closeMs, ['type' => 'EXPIRED', 'notify' => false, 'reason' => 'TP2 reached, tracking complete'],
                ['cancel_reason' => 'tp2 reached', 'expired_ms' => $closeMs]);
        }

        $boundary = $long ? $setup['resistance'] : $setup['support'];
        $zone = $this->config->float('retest.zone_atr') * $setup['atr'];
        $touched = $long ? $low <= $boundary + $zone : $high >= $boundary - $zone;
        $heldBeyond = $long ? $close > $boundary : $close < $boundary;
        $clv = IndicatorCalculator::closeLocation($high, $low, $close, $setup['direction']);
        $rejection = $heldBeyond && $clv !== null && $clv >= $this->config->float('retest.min_close_location')
            && ($long ? $close > $c->opens[$i] : $close < $c->opens[$i]);

        if ($touched && $heldBeyond && $setup['state'] !== self::RETEST_WAIT) {
            if ($rejection) {
                return $this->retestConfirmed($setup, $c, $s, $i, $context);
            }

            return $this->transition($setup, self::RETEST_WAIT, $closeMs, ['type' => 'RETEST_WAIT', 'notify' => false, 'price' => $close], ['retest_ms' => $closeMs]);
        }
        if ($setup['state'] === self::RETEST_WAIT) {
            if ($rejection) {
                return $this->retestConfirmed($setup, $c, $s, $i, $context);
            }
            if ($this->barsSince($setup['state_since_ms'], $closeMs, $c) >= $this->config->int('retest.wait_bars')) {
                return $this->transition($setup, self::EXPIRED, $closeMs, ['type' => 'EXPIRED', 'notify' => false, 'reason' => 'retest not confirmed'],
                    ['cancel_reason' => 'retest not confirmed', 'expired_ms' => $closeMs]);
            }

            return [$setup, []];
        }

        $since = $setup['confirmed_ms'] ?? $setup['state_since_ms'];
        if ($this->barsSince($since, $closeMs, $c) >= $this->config->int('tracking_bars')) {
            return $this->transition($setup, self::EXPIRED, $closeMs, ['type' => 'EXPIRED', 'notify' => false, 'reason' => 'tracking window over'],
                ['cancel_reason' => 'tracking finished', 'expired_ms' => $closeMs]);
        }

        return [$setup, []];
    }

    protected function retestConfirmed(array $setup, Candles $c, array $s, int $i, array $context): array
    {
        $closeMs = $c->closeTimes[$i];
        $direction = $setup['direction'];
        $levels = $this->levelsAt($setup, $c, $i, $direction, $c->closes[$i], $context);

        return $this->transition($setup, self::RETEST_CONFIRMED, $closeMs, [
            'type' => 'RETEST', 'notify' => $setup['alerted'] || $levels['ok'], 'direction' => $direction, 'price' => $c->closes[$i],
            'levels' => $levels, 'entry_valid' => $levels['ok'], 'why' => $levels['ok'] ? null : 'no entry: '.$levels['reason'],
        ], ['retest_ms' => $closeMs, 'retest_entry' => $levels['ok'] ? $levels['entry'] : null, 'retest_sl' => $levels['sl'],
            'retest_tp1' => $levels['tp1'], 'retest_tp2' => $levels['tp2']]);
    }

    /**
     * Stage D: a close back inside the old range counts; N consecutive (default 2) = failed.
     * A close beyond the opposite boundary fails at once.
     *
     * @return array{0: array<string, mixed>, 1: ?array}
     */
    protected function failureCheck(array $setup, Candles $c, int $i): array
    {
        $long = $setup['direction'] === 'LONG';
        $close = $c->closes[$i];
        $closeMs = $c->closeTimes[$i];
        $inside = $long ? $close <= $setup['resistance'] : $close >= $setup['support'];
        $opposite = $long ? $close < $setup['support'] : $close > $setup['resistance'];

        $setup['inside_closes'] = $inside ? ($setup['inside_closes'] ?? 0) + 1 : 0;
        if ($opposite || $setup['inside_closes'] >= $this->config->int('failure.inside_closes')) {
            $reason = $opposite ? 'closed beyond the opposite side of the range' : 'closed back inside the range for '.$setup['inside_closes'].' candles';

            return [$setup, $this->transition($setup, self::FAILED, $closeMs,
                ['type' => 'FAILED', 'notify' => (bool) $setup['alerted'], 'direction' => $setup['direction'], 'price' => $close, 'reason' => $reason],
                ['cancel_reason' => $reason, 'failed_ms' => $closeMs])];
        }

        return [$setup, null];
    }

    protected function scoreClosed(array $setup, Candles $c, array $s, int $i, array $context, string $direction, array $metrics): array
    {
        $vol = $c->volumes[$i];
        $prior = array_slice($c->volumes, max(0, $i - 3), min(3, $i));

        return $this->scorer->score([
            'direction' => $direction,
            'bbw_pct' => $setup['bbw_pct'],
            'range_ratio' => $setup['range_ratio'],
            'extension_atr' => $metrics['extension_atr'],
            'volume' => $metrics['rvol'],
            'volume_accelerating' => $prior === [] ? null : $vol > max($prior),
            'body_atr' => $metrics['body_atr'],
            'close_location' => $metrics['close_location'],
            'rsi' => $metrics['rsi'],
            'rsi_prev' => $metrics['rsi_prev'],
            'ema_fast' => $metrics['ema_fast'],
            'ema_slow' => $metrics['ema_slow'],
            'spread_bps' => $context['spread_bps'] ?? null,
        ] + $this->contextFeatures($context, $direction));
    }

    /** @return array<string, mixed> */
    protected function contextFeatures(array $context, string $direction): array
    {
        $h1 = $context['h1'] ?? null;

        return [
            'quote_volume_24h' => $context['quote_volume_24h'] ?? null,
            'h1_aligned' => $h1 === null ? null : ($direction === 'LONG'
                ? $h1['close'] > $h1['ema'] && $h1['ema_slope'] > 0
                : $h1['close'] < $h1['ema'] && $h1['ema_slope'] < 0),
            'm15_compressed' => $context['m15_compressed'] ?? null,
        ];
    }

    protected function levelsAt(array $setup, Candles $c, int $i, string $direction, float $entry, array $context): array
    {
        $n = $this->config->int('risk.structure_bars');
        $from = max(0, $i - $n + 1);
        $structure = $direction === 'LONG' ? array_slice($c->lows, $from, $i - $from + 1) : array_slice($c->highs, $from, $i - $from + 1);

        return $this->risk->levels($direction, $entry, $setup, $structure, $context['spread_bps'] ?? null, $context['funding_rate'] ?? null);
    }

    /** @return array<string, ?float> */
    protected function levelColumns(array $levels): array
    {
        return ['entry' => $levels['entry'], 'sl' => $levels['sl'], 'tp1' => $levels['tp1'], 'tp2' => $levels['tp2'],
            'net_rr' => $levels['net_rr_tp1'], 'costs' => $levels['costs'], 'risk_ok' => $levels['ok'], 'risk_reason' => $levels['reason']];
    }

    /** Candle closes after $fromMs up to $toMs (a live tick mid-candle: its own candle counts as the first). */
    protected function barsSince(int $fromMs, int $toMs, Candles $c): int
    {
        return (int) ceil(($toMs - $fromMs) / $c->intervalMs - 1e-9);
    }

    /** @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>} */
    protected function transition(array $setup, string $state, int $atMs, array $event, array $changes = []): array
    {
        $from = $setup['state'];
        $setup = array_merge($setup, $changes, ['state' => $state, 'state_since_ms' => $atMs]);
        $event += ['from' => $from, 'to' => $state, 'at_ms' => $atMs, 'symbol' => $setup['symbol'], 'setup_key' => $setup['setup_key']];

        return [$setup, [$event]];
    }
}
