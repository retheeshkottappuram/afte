<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Monitored Crypto Symbols
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of symbols to poll from Binance (e.g. BTCUSDT,ETHUSDT).
    | Set CRYPTO_ALL_SYMBOLS=true or CRYPTO_SYMBOLS=ALL to dynamically monitor
    | all active liquid Binance Futures USDT perpetual pairs.
    |
    */
    'symbols' => array_values(array_filter(array_map('trim', explode(',', env('CRYPTO_SYMBOLS', 'BTCUSDT,ETHUSDT,SOLUSDT,SUIUSDT,NEARUSDT,1000PEPEUSDT,DOGEUSDT,AVAXUSDT,BNBUSDT,XRPUSDT,LINKUSDT,FETUSDT'))))),
    'all_symbols' => (bool) env('CRYPTO_ALL_SYMBOLS', true),
    'min_24h_volume' => (float) env('CRYPTO_MIN_24H_VOLUME', 2500000.0),

    /*
    |--------------------------------------------------------------------------
    | Institutional Universe Filter Settings
    |--------------------------------------------------------------------------
    |
    | Stringent liquidity, spread, and security gates for candidate scanning.
    |
    */
    'universe' => [
        'min_24h_volume' => (float) env('CRYPTO_MIN_24H_VOLUME', 2500000.0), // $2.5M USD baseline for active futures
        'max_spread_pct' => (float) env('CRYPTO_MAX_SPREAD_PCT', 0.03),        // 0.03%
        'min_listing_days' => (int) env('CRYPTO_MIN_LISTING_DAYS', 30),        // 30 days
        'exclude_non_ascii' => (bool) env('CRYPTO_EXCLUDE_NON_ASCII', true),
        'blacklist' => array_values(array_filter(array_map('trim', explode(',', env('CRYPTO_BLACKLIST', 'GRAMUSDT,AKEUSDT,GUSDT,USUSDT,USDCUSDT,FDUSDUSDT,TUSDUSDT,EURUSDT,BUSDUSDT,DAIUSDT'))))),
    ],

    /*
    |--------------------------------------------------------------------------
    | BTC Macro Alignment Settings
    |--------------------------------------------------------------------------
    |
    | Explicit BTC macro directional gate. Longs require bullish alignment,
    | shorts require bearish, and neutral/choppy BTC blocks all signals.
    |
    */
    'btc_macro' => [
        'enabled' => (bool) env('CRYPTO_BTC_MACRO_ENABLED', true),
        'ema_fast' => (int) env('CRYPTO_BTC_EMA_FAST', 50),
        'ema_slow' => (int) env('CRYPTO_BTC_EMA_SLOW', 200),
        'slope_lookback' => (int) env('CRYPTO_BTC_SLOPE_LOOKBACK', 3),
        'min_slope_pct' => (float) env('CRYPTO_BTC_MIN_SLOPE_PCT', 0.01),
        'require_4h_confluence' => (bool) env('CRYPTO_BTC_REQUIRE_4H', true),
        'ema_4h' => (int) env('CRYPTO_BTC_4H_EMA', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Binance Market Type
    |--------------------------------------------------------------------------
    |
    | 'futures' (Binance USDⓈ-M Perpetual Futures via fapi.binance.com)
    | or 'spot' (Binance Spot via api.binance.com).
    |
    */
    'market' => env('CRYPTO_MARKET', 'futures'),

    /*
    |--------------------------------------------------------------------------
    | Polling Timeframes
    |--------------------------------------------------------------------------
    |
    | Base evaluation timeframe and higher-timeframe filter.
    |
    */
    'interval' => env('CRYPTO_INTERVAL', '15m'),
    'htf_interval' => env('CRYPTO_HTF_INTERVAL', '1h'),

    /*
    |--------------------------------------------------------------------------
    | Cooldown Period
    |--------------------------------------------------------------------------
    |
    | Minutes to wait before firing another signal for the same symbol/interval/side.
    | Default is 75 minutes (~5 candles on a 15m chart).
    |
    */
    'cooldown_minutes' => (int) env('CRYPTO_COOLDOWN_MINUTES', 35),

    /*
    |--------------------------------------------------------------------------
    | Telegram Notifications
    |--------------------------------------------------------------------------
    |
    | Bot token and target chat ID for Telegram alerts.
    |
    */
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
        'chat_id' => env('TELEGRAM_CHAT_ID', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Signal Engine Indicator Tunables (SignalEngine v2)
    |--------------------------------------------------------------------------
    |
    | Thresholds and parameters for multi-confirmation technical indicators.
    |
    */
    'indicators' => [
        'fast_len' => (int) env('CRYPTO_FAST_EMA', 9),
        'slow_len' => (int) env('CRYPTO_SLOW_EMA', 21),
        'trend_len' => (int) env('CRYPTO_TREND_EMA', 200),
        'use_htf1' => (bool) env('CRYPTO_USE_HTF', true),
        'use_htf2' => (bool) env('CRYPTO_USE_HTF2', true),
        'adx_len' => (int) env('CRYPTO_ADX_LEN', 14),
        'adx_min' => (float) env('CRYPTO_ADX_MIN', 20.0),
        'rsi_len' => (int) env('CRYPTO_RSI_LEN', 14),
        'rsi_long_min' => (float) env('CRYPTO_RSI_LONG_MIN', 52.0),
        'rsi_short_max' => (float) env('CRYPTO_RSI_SHORT_MAX', 48.0),
        'vol_len' => (int) env('CRYPTO_VOL_LEN', 20),
        'vol_mult' => (float) env('CRYPTO_VOL_MULT', 1.30),
        'obv_lookback' => (int) env('CRYPTO_OBV_LOOKBACK', 5),
        'structure_len' => (int) env('CRYPTO_STRUCTURE_LEN', 20),
        'breakout_buffer_pct' => (float) env('CRYPTO_BREAKOUT_BUFFER_PCT', 0.15),
        'atr_len' => (int) env('CRYPTO_ATR_LEN', 14),
        'min_atr_pct' => (float) env('CRYPTO_MIN_ATR_PCT', 0.05),
        'max_atr_pct' => (float) env('CRYPTO_MAX_ATR_PCT', 6.0),
        'bb_len' => (int) env('CRYPTO_BB_LEN', 20),
        'bb_mult' => (float) env('CRYPTO_BB_MULT', 2.0),
        'bb_expansion_lookback' => (int) env('CRYPTO_BB_EXPANSION_LOOKBACK', 3),
        'persistence_bars' => (int) env('CRYPTO_PERSISTENCE_BARS', 3),
        'divergence_lookback' => (int) env('CRYPTO_DIVERGENCE_LOOKBACK', 24),
        'sl_mult' => (float) env('CRYPTO_SL_MULT', 1.5),
        'tp1_mult' => (float) env('CRYPTO_TP1_MULT', 1.5),
        'tp2_mult' => (float) env('CRYPTO_TP2_MULT', 3.0),
        'tp3_mult' => (float) env('CRYPTO_TP3_MULT', 4.5),
        'use_structure_sl' => (bool) env('CRYPTO_USE_STRUCTURE_SL', true),
        'min_rr' => (float) env('CRYPTO_MIN_RR', 2.0),
        'minimum_score' => (int) env('CRYPTO_MIN_SCORE', 82),
        'grade_a' => (int) env('CRYPTO_GRADE_A', 90),
        'grade_b' => (int) env('CRYPTO_GRADE_B', 82),
        'signal_cooldown_bars' => (int) env('CRYPTO_SIGNAL_COOLDOWN_BARS', 4),
        'opposite_cooldown_bars' => (int) env('CRYPTO_OPPOSITE_COOLDOWN_BARS', 3),
        'enable_reversals' => (bool) env('CRYPTO_ENABLE_REVERSALS', false),
        'reversal_min_score' => (int) env('CRYPTO_REV_MIN_SCORE', 75),
        'reversal_rsi_overbought' => (float) env('CRYPTO_REV_RSI_OVERBOUGHT', 64.0),
        'reversal_rsi_oversold' => (float) env('CRYPTO_REV_RSI_OVERSOLD', 36.0),
    ],
];
