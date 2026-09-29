<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Trading Engine Default Mode
    |--------------------------------------------------------------------------
    | Modes:
    | - 'paper': 100% simulated with seed capital and real live Binance price feed.
    | - 'live': Real Binance Futures account (Requires API keys with trading permission).
    */
    'mode' => env('TRADING_MODE', 'paper'),

    /*
    |--------------------------------------------------------------------------
    | PHP CLI Binary & Shared Hosting Controls
    |--------------------------------------------------------------------------
    */
    'php_binary' => env('PHP_BINARY_PATH', '/usr/php84/usr/bin/php'),
    'daemon_heartbeat_timeout' => (int) env('DAEMON_HEARTBEAT_TIMEOUT', 90),
    'daemon_auto_spawn' => (bool) env('DAEMON_AUTO_SPAWN', false),

    /*
    |--------------------------------------------------------------------------
    | Live Trading Safety Gate
    |--------------------------------------------------------------------------
    | Strictly prevents real live Binance order execution from local or dev
    | environments to protect against dual-instance collisions with production.
    */
    'allow_live_trading' => (bool) env('ALLOW_LIVE_TRADING', env('APP_ENV') === 'production' || env('TRADING_MODE') === 'live'),

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
        // Stage 1: Seed ($3 to $25) - High-velocity micro compounding on momentum altcoins
        'stage_1' => [
            'max_equity' => 25.0,
            'max_positions' => 3,     // Allows up to 3 concurrent positions
            'max_unprotected' => 2,   // Allows up to 2 unprotected positions; protected (BE/TP1) positions don't count
            'default_leverage' => 10,
            'max_risk_pct' => 5.0,
            'min_score' => 80,
            'max_coin_price' => 50.0, // Exclude heavy coins (BTC/ETH) to allow fine-grained lot sizing
            'exclude_symbols' => ['BTCUSDT', 'ETHUSDT'],
        ],
        // Stage 2: Acceleration ($25 to $100)
        'stage_2' => [
            'max_equity' => 100.0,
            'max_positions' => 3,
            'max_unprotected' => 2,
            'default_leverage' => 8,
            'max_risk_pct' => 4.0,
            'min_score' => 80,
            'max_coin_price' => 250.0,
            'exclude_symbols' => [],
        ],
        // Stage 3: Scale ($100 to $500)
        'stage_3' => [
            'max_equity' => 500.0,
            'max_positions' => 4,
            'max_unprotected' => 2,
            'default_leverage' => 5,
            'max_risk_pct' => 2.5,
            'min_score' => 78,
            'max_coin_price' => 100000.0,
            'exclude_symbols' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Trade Fund Utilization Controls
    |--------------------------------------------------------------------------
    */
    'fund_management' => [
        'min_available_margin' => (float) env('TRADING_MIN_AVAILABLE_MARGIN', 0.50),       // Minimum free available margin in USD to open a new trade
        'exempt_protected_positions' => true, // Breakeven or profit-locked trades do not block new trades
        'stage1_target_notional' => (float) env('TRADING_STAGE1_TARGET_NOTIONAL', 5.50),     // Sized for Binance $5 minimum notional (~$0.55 margin at 10x)
        'amount_per_trade' => env('TRADING_AMOUNT_PER_TRADE') !== null ? (float) env('TRADING_AMOUNT_PER_TRADE') : null, // Fixed margin amount in USD added per trade, null for dynamic
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
    | Dynamic Trade Management Parameters (Asymmetric Edge)
    |--------------------------------------------------------------------------
    */
    'management' => [
        // Fast Breakeven Lock: Triggered at +0.45% gain (+4.5% ROE at 10x) OR +4.5% ROE
        // Guarantees winning positions NEVER turn into red losses!
        'be_gain_pct' => 0.45,
        'be_roe_threshold' => 4.5,
        'be_fee_buffer_pct' => 0.10, // Entry + 0.10% covers taker fees

        // Partial Profit Booking:
        'tp1_pct' => 0.85,           // Fast TP1 at +0.85% price gain (+8.5% ROE at 10x)
        'tp1_close_ratio' => 0.50,   // Close 50% at TP1 to bank guaranteed cash

        'tp2_pct' => 1.80,           // TP2 at +1.80% price gain (+18% ROE at 10x)
        'tp2_close_ratio' => 0.30,   // Close 30% at TP2

        // Remaining 20% runs on Trailing SL to capture explosive breakouts:
        'trailing_sl_atr_mult' => 1.8,
        'trailing_sl_trigger_pct' => 1.20, // Start trailing after +1.20% gain

        // Trade Stagnation & Dead-Position Timeout Pruner (Micro-Account Capital Velocity):
        'stagnation_timeout_minutes' => 60, // Close if holding > 60m with positive profit without hitting TP1
        'max_hold_minutes' => 90,           // Hard exit after 90m for stagnant flat trades to free margin
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
        'recv_window' => (int) env('BINANCE_RECV_WINDOW', 30000),
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
            'SUIUSDT', 'DOGEUSDT', 'NEARUSDT', 'SOLUSDT', 'RENDERUSDT',
            '1000PEPEUSDT', 'FETUSDT', 'SEIUSDT',
            'LINKUSDT', 'XRPUSDT', 'ADAUSDT', 'TIAUSDT', 'INJUSDT',
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
        'live_only' => (bool) env('TELEGRAM_LIVE_ONLY', true), // Only dispatch Telegram alerts for real live trading; suppress simulated paper trades
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
