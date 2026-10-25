<?php

/*
|--------------------------------------------------------------------------
| Early Breakout Detection (EBD v1)
|--------------------------------------------------------------------------
| 5m compression -> live early break -> closed-candle confirmation, with 15m/1h context.
| Alerts only: this engine never places orders.
|
| Every number below is a starting parameter for experimentation, NOT a proven profitable
| setting. See docs/ebd-v1.md for the backtest and its limitations.
| EbdConfig::validate() checks the ranges when breakout:scan starts.
*/

return [
    'enabled' => (bool) env('BREAKOUT_EBD_ENABLED', true),

    // 'alerts' = persist + Telegram, 'shadow' = persist only (validation mode, no Telegram).
    'mode' => env('BREAKOUT_EBD_MODE', 'alerts'),

    'strategy' => 'EBD',
    'version' => 'v1',
    'exchange' => 'Binance',
    'market_type' => 'USDT-M Perpetual',

    'timeframes' => [
        'signal' => '5m',     // compression, breakout structure, confirmation
        'context' => '15m',   // consolidation on the slower chart (score only)
        'trend' => '1h',      // higher-timeframe trend (score only)
        'live' => '1m',       // volume pace for early alerts
    ],

    // Universe: SymbolAnalyzer::universe() (top N by 24h quote volume, listed >= 30 days, PERPETUAL, TRADING)
    'universe' => [
        'max_symbols' => (int) env('BREAKOUT_EBD_MAX_SYMBOLS', 50),
        'min_quote_volume_24h' => (float) env('BREAKOUT_EBD_MIN_VOLUME_24H', 20_000_000),
    ],

    // Candles fetched per timeframe (warm-up for ATR/RSI/EMA + 100-bar bandwidth lookback + range).
    'history_bars' => [
        '5m' => 300,
        '15m' => 150,
        '1h' => 100,
    ],

    'indicators' => [
        'atr_period' => 14,
        'ema_fast' => 9,
        'ema_slow' => 21,
        'rsi_period' => 14,
        'bb_period' => 20,
        'bb_mult' => 2.0,
        'volume_baseline' => 20,          // completed candles in the RVOL mean
    ],

    'compression' => [
        'lookback' => 20,                 // completed 5m candles in the range
        'max_range_atr' => (float) env('BREAKOUT_EBD_MAX_RANGE_ATR', 4.0),
        'bbw_percentile' => (float) env('BREAKOUT_EBD_BBW_PERCENTILE', 30),
        'bbw_lookback' => 100,            // completed candles in the bandwidth percentile
        'expiry_bars' => 12,              // completed 5m candles before an unbroken setup expires
    ],

    'early' => [
        'buffer_atr' => 0.05,             // B_long = R + 0.05 ATR, B_short = S - 0.05 ATR
        'min_volume_pace' => (float) env('BREAKOUT_EBD_MIN_VOLUME_PACE', 1.5),
        'min_pace_samples' => 10,         // comparable past 5m candles needed for a pace baseline
        'min_elapsed_seconds' => 60,      // at least one closed 1m candle inside the forming 5m candle
        'watch_bars' => 3,                // completed candles an early alert may wait for confirmation
        'max_armed_symbols' => 15,        // coins checked on 1m data per minute (closest to a trigger)
        'arm_distance_atr' => 0.5,        // only coins within this distance of a trigger are armed
    ],

    'confirm' => [
        'buffer_atr' => 0.10,             // close > R + 0.10 ATR (long), close < S - 0.10 ATR (short)
        'min_rvol' => (float) env('BREAKOUT_EBD_MIN_RVOL', 1.8),
        'min_close_location' => 0.75,
        'min_body_atr' => 0.5,
        'body_required' => (bool) env('BREAKOUT_EBD_BODY_REQUIRED', false), // false = a preference (score) only
        'rsi_long_min' => 55,
        'rsi_short_max' => 45,
    ],

    'failure' => [
        'inside_closes' => 2,             // closes back inside the range (consecutive) = failed
    ],

    'retest' => [
        'zone_atr' => 0.25,               // revisit within 0.25 ATR of the broken boundary
        'min_close_location' => 0.6,
        'wait_bars' => 6,
    ],

    // Higher-timeframe context (score only, never a gate for early alerts).
    'context' => [
        'h1_ema' => 21,
        'h1_slope_bars' => 3,
        'm15_bbw_percentile' => 50,       // 15m bandwidth at or below this percentile = compressed on the slower chart too
    ],

    'tracking_bars' => 48,                // confirmed setups are followed for 4h, then closed

    'max_extension_atr' => (float) env('BREAKOUT_EBD_MAX_EXTENSION_ATR', 1.5),

    'risk' => [
        'stop_buffer_atr' => 0.25,        // SL = min(structural low, R - 0.25 ATR)
        'structure_bars' => 3,
        'tp1_r' => 1.5,
        'tp2_r' => 2.0,
        'min_sl_pct' => 0.2,
        'max_sl_pct' => (float) env('BREAKOUT_EBD_MAX_SL_PCT', 3.0),
        'min_net_rr' => 1.0,              // TP1 reward / risk after costs
        'expected_hold_hours' => 2,       // funding estimate horizon
    ],

    'costs' => [
        'taker_fee' => 0.0005,            // per fill
        'slippage' => 0.0003,             // per fill
        'default_spread_bps' => 2.0,      // used only when the live spread is unavailable
    ],

    'liquidity' => [
        'max_spread_bps' => (float) env('BREAKOUT_EBD_MAX_SPREAD_BPS', 15),
        'max_price_age_seconds' => 90,
    ],

    'score' => [
        'high' => 85,
        'valid' => 75,
        'watchlist' => 60,                // below = reject
    ],

    'notifications' => [
        'cooldown_minutes' => (int) env('BREAKOUT_EBD_COOLDOWN_MINUTES', 30), // per symbol + direction for new early/confirmed alerts
        'display_timezone' => env('BREAKOUT_EBD_TIMEZONE', 'Asia/Kolkata'),
        'max_event_age_minutes' => 10,    // an event older than this (missed cron runs) is stored but not sent
        // Shown under every alert. Backtest 2025-10-28..2026-10-22, top 30, costs included (docs/ebd-v1.md):
        // every variant lost (PF 0.67-0.68, -0.22R per trade), also on the unseen last 3 months.
        'backtest_note' => env('BREAKOUT_EBD_BACKTEST_NOTE', 'Backtest (12 months, 30 coins, after costs): EBD lost, PF 0.67. Unproven.'),
        'notify_extended' => true,        // only for setups that already sent an alert
        // User's decision (2026-10-25): Telegram gets only confirmed entries from coins with a good live record. Live chat
        // 22-25 Oct: 213 early alerts, 45% failed; 12-month backtest: early PF 0.67. Early watches stay on the dashboard.
        'early' => (bool) env('BREAKOUT_EBD_NOTIFY_EARLY', false),
        'min_bucket' => env('BREAKOUT_EBD_MIN_BUCKET', 'VALID'),   // CONFIRMED / RETEST entries need this score bucket
        // Coin record gate: entry alerts only for a coin + direction with >= record_min_signals resolved EBD confirmed /
        // retest signals in the last record_days and a profit factor >= record_min_pf (R after fees, from crypto_signals).
        // Every signal is still recorded silently, so a coin earns (and loses) its place. Warning (2026-10-25 test,
        // percoin.php): coins picked this way on months 1-6 did NOT stay profitable afterwards (validation PF 0.53-0.63).
        'record_gate' => (bool) env('BREAKOUT_EBD_RECORD_GATE', true),
        'record_days' => (int) env('BREAKOUT_EBD_RECORD_DAYS', 30),
        'record_min_signals' => (int) env('BREAKOUT_EBD_RECORD_MIN_SIGNALS', 5),
        'record_min_pf' => (float) env('BREAKOUT_EBD_RECORD_MIN_PF', 1.3),
        'record_by_direction' => true,    // LONG and SHORT records apart (live: shorts +0.45R, longs -0.26R)
    ],

    'scan' => [
        'lock_seconds' => 70,
        'batch_size' => 25,               // symbols per pooled HTTP batch
        'bar_pass_delay_seconds' => 20,   // run the closed-candle pass >= 20s after the 5m close
        'max_used_weight' => 1800,        // Binance 1m weight budget before a run backs off
    ],
];
