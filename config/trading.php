<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Trading Engine Default Mode
    |--------------------------------------------------------------------------
    | Modes:
    | - 'paper': 100% simulated with seed capital and real live Binance price feed.
    | - 'testnet': Uses Binance Futures Testnet API.
    | - 'shadow': Real Binance live feed, logs trades without execution.
    | - 'live': Real Binance Futures account (Requires API keys with trading permission).
    */
    'mode' => env('TRADING_MODE', 'paper'),

    /*
    |--------------------------------------------------------------------------
    | Compounding Challenge ($5 to $500)
    |--------------------------------------------------------------------------
    */
    'seed_capital' => (float) env('TRADING_SEED_CAPITAL', 5.0),
    'target_capital' => (float) env('TRADING_TARGET_CAPITAL', 500.0),

    /*
    |--------------------------------------------------------------------------
    | Compounding Stages & Dynamic Risk Scaling
    |--------------------------------------------------------------------------
    */
    'stages' => [
        // Stage 1: Seed ($5 to $25)
        'stage_1' => [
            'max_equity' => 25.0,
            'max_positions' => 1,
            'default_leverage' => 10,
            'max_risk_pct' => 10.0, // Risk ~$0.50-$0.75 on $5-$7 balance
            'min_score' => 84,     // Only top Grade-A setups
        ],
        // Stage 2: Acceleration ($25 to $100)
        'stage_2' => [
            'max_equity' => 100.0,
            'max_positions' => 2,
            'default_leverage' => 7,
            'max_risk_pct' => 4.0,  // Risk ~$1.00-$4.00
            'min_score' => 82,
        ],
        // Stage 3: Scale ($100 to $500)
        'stage_3' => [
            'max_equity' => 500.0,
            'max_positions' => 3,
            'default_leverage' => 5,
            'max_risk_pct' => 2.0,  // Risk $2.00-$10.00
            'min_score' => 80,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breakers & Risk Controls
    |--------------------------------------------------------------------------
    */
    'circuit_breakers' => [
        'max_consecutive_losses' => (int) env('TRADING_MAX_CONSECUTIVE_LOSSES', 2),
        'loss_cooldown_minutes' => (int) env('TRADING_LOSS_COOLDOWN_MINUTES', 120), // 2 hours
        'max_daily_loss_pct' => (float) env('TRADING_MAX_DAILY_LOSS_PCT', 15.0),    // 15% daily drawdown cap
        'emergency_kill_switch' => (bool) env('TRADING_KILL_SWITCH', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dynamic Trade Management Parameters
    |--------------------------------------------------------------------------
    */
    'management' => [
        // Breakeven lock: Triggered when price moves favorably by +1.0%
        'be_gain_pct' => 1.0,
        'be_fee_buffer_pct' => 0.12, // Entry + 0.12% to cover maker/taker round-trip fees

        // Partial Profit Booking:
        'tp1_pct' => 2.0,            // TP1 at +2.0% price gain
        'tp1_close_ratio' => 0.33,   // Close 33% at TP1

        'tp2_pct' => 4.0,            // TP2 at +4.0% price gain
        'tp2_close_ratio' => 0.33,   // Close 33% at TP2

        // Remaining 34% runs on Trailing SL:
        'trailing_sl_atr_mult' => 2.0,
        'trailing_sl_trigger_pct' => 2.5, // Start trailing after +2.5% gain
    ],

    /*
    |--------------------------------------------------------------------------
    | Binance Futures API Configuration
    |--------------------------------------------------------------------------
    */
    'binance' => [
        'api_key' => env('BINANCE_API_KEY', ''),
        'api_secret' => env('BINANCE_API_SECRET', ''),
        'testnet_key' => env('BINANCE_TESTNET_KEY', ''),
        'testnet_secret' => env('BINANCE_TESTNET_SECRET', ''),
        'recv_window' => (int) env('BINANCE_RECV_WINDOW', 5000),
        'endpoints' => [
            'live_rest' => 'https://fapi.binance.com',
            'testnet_rest' => 'https://testnet.binancefuture.com',
            'live_ws' => 'wss://fstream.binance.com',
            'testnet_ws' => 'wss://stream.binancefuture.com',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Market Scanner Settings
    |--------------------------------------------------------------------------
    */
    'scanner' => [
        'base_interval' => '15m',
        'htf1_interval' => '1h',
        'htf2_interval' => '4h',
        'min_quote_volume_24h' => 10000000.0, // $10M min 24h volume
        'top_symbols_limit' => 25,
        'priority_symbols' => [
            'BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT', 'SUIUSDT',
            'DOGEUSDT', 'NEARUSDT', 'AVAXUSDT', 'ADAUSDT', 'XRPUSDT',
            'LINKUSDT', 'APTUSDT', 'DOTUSDT', 'RENDERUSDT', 'PEPEUSDT',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Telegram Notifications
    |--------------------------------------------------------------------------
    */
    'telegram' => [
        'enabled' => (bool) env('TELEGRAM_NOTIFICATIONS_ENABLED', false),
        'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
        'chat_id' => env('TELEGRAM_CHAT_ID', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Validator (Gemini / Claude / Analytical Heuristics)
    |--------------------------------------------------------------------------
    */
    'ai' => [
        'driver' => env('AI_VALIDATOR_DRIVER', 'heuristic'), // 'heuristic' or 'gemini'
        'gemini_api_key' => env('GEMINI_API_KEY', ''),
        'min_confidence_score' => 70,
    ],
];
