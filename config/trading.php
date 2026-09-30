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
    'mode' => env('TRADING_MODE', 'live'),

    /*
    |--------------------------------------------------------------------------
    | Targeted Single-Coin Trading Asset
    |--------------------------------------------------------------------------
    | The dedicated asset monitored continuously on 15m & 1h SignalAlgo PRO charts.
    */
    'symbol' => env('TRADING_SYMBOL', 'NEARUSDT'),
    'active_coin' => env('TRADING_ACTIVE_COIN', 'NEARUSDT'),
    'single_coin_strict' => (bool) env('TRADING_SINGLE_COIN_STRICT', true),

    /*
    |--------------------------------------------------------------------------
    | PHP CLI Binary & Shared Hosting Controls
    |--------------------------------------------------------------------------
    */
    'php_binary' => env('PHP_BINARY_PATH', 'php'),
    'daemon_heartbeat_timeout' => (int) env('DAEMON_HEARTBEAT_TIMEOUT', 90),
    'daemon_auto_spawn' => (bool) env('DAEMON_AUTO_SPAWN', true),

    /*
    |--------------------------------------------------------------------------
    | Live Trading Safety Gate
    |--------------------------------------------------------------------------
    | Permits live trading when configured in .env.
    */
    'allow_live_trading' => (bool) env('ALLOW_LIVE_TRADING', true),

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
        'single_coin_fund_percent' => (float) env('TRADING_SINGLE_COIN_FUND_PERCENT', 50.0), // Dedicated single-coin margin allocation: at least 50% of available funds
        'max_fund_allocation_pct' => (float) env('TRADING_MAX_FUND_ALLOCATION_PCT', 75.0),  // Safety cap: leaves at least 25% free margin as collateral cushion
        'amount_per_trade' => env('TRADING_AMOUNT_PER_TRADE') !== null ? (float) env('TRADING_AMOUNT_PER_TRADE') : null, // Fixed margin amount in USD added per trade, null for dynamic
    ],

    /*
    |--------------------------------------------------------------------------
    | Asset Protection & Proper Stop Loss Controls
    |--------------------------------------------------------------------------
    | Ensures mathematical asset protection against catastrophic drawdowns.
    */
    'risk' => [
        'min_sl_distance_pct' => (float) env('TRADING_MIN_SL_PCT', 0.80),         // Minimum 0.80% SL distance (prevents noise stopouts)
        'max_sl_distance_pct' => (float) env('TRADING_MAX_SL_PCT', 1.60),         // Maximum 1.60% SL distance (Asset Protection Cap, ~16% ROE max risk)
        'default_sl_distance_pct' => (float) env('TRADING_DEFAULT_SL_PCT', 1.25), // 1.25% safe default if signal marker SL is missing or inverted
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breakers & Risk Controls
    |--------------------------------------------------------------------------
    */
    'circuit_breakers' => [
        'max_consecutive_losses' => (int) env('TRADING_MAX_CONSECUTIVE_LOSSES', 4),
        'loss_cooldown_minutes' => (int) env('TRADING_LOSS_COOLDOWN_MINUTES', 20), // 20 minutes cooldown (reduced from 120m)
        'max_daily_loss_pct' => (float) env('TRADING_MAX_DAILY_LOSS_PCT', 15.0),    // 15% daily drawdown cap
        'emergency_kill_switch' => (bool) env('TRADING_KILL_SWITCH', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dynamic Trade Management Parameters (Asymmetric Edge & Profit Lock)
    |--------------------------------------------------------------------------
    */
    'management' => [
        // Breakeven Lock: Triggered at +1.20% gain (+12.0% ROE at 10x)
        'be_gain_pct' => (float) env('TRADING_BE_GAIN_PCT', 1.20),
        'be_roe_threshold' => (float) env('TRADING_BE_ROE_THRESHOLD', 12.0),
        'be_fee_buffer_pct' => (float) env('TRADING_BE_FEE_BUFFER_PCT', 0.25), // 0.25% buffer overcomes 0.10% Binance round-trip fee

        // Tier 1 Stepped Ratchet: At +1.20% gain, lock SL at +0.25% net profit
        'lock1_gain_pct' => 1.20,
        'lock1_sl_pct' => 0.25,

        // Partial Profit Booking:
        'tp1_pct' => (float) env('TRADING_TP1_PCT', 1.80),           // Harvest 35% at +1.80% price gain (+18% ROE at 10x)
        'tp1_close_ratio' => (float) env('TRADING_TP1_CLOSE_RATIO', 0.35),

        // Tier 2 Stepped Ratchet: At +1.80% gain (TP1 hit), lock SL at +0.60% profit (+6.0% ROE)
        'lock2_gain_pct' => 1.80,
        'lock2_sl_pct' => 0.60,

        'tp2_pct' => (float) env('TRADING_TP2_PCT', 3.20),           // Harvest 35% at +3.20% price gain (+32% ROE at 10x)
        'tp2_close_ratio' => (float) env('TRADING_TP2_CLOSE_RATIO', 0.35),

        // Remaining 30% runs on dynamic ATR trailing SL to capture multi-dollar breakouts
        'trailing_sl_atr_mult' => (float) env('TRADING_TRAILING_SL_ATR_MULT', 1.5),
        'trailing_sl_trigger_pct' => (float) env('TRADING_TRAILING_SL_TRIGGER_PCT', 2.50),

        // Anti-Giveback Circuit: Disabled at low profits so normal 1m pullbacks do NOT choke winners (e.g. LINK)
        'peak_profit_min_gain_pct' => (float) env('TRADING_PEAK_PROFIT_MIN_GAIN_PCT', 4.00),
        'peak_profit_giveback_pct' => (float) env('TRADING_PEAK_PROFIT_GIVEBACK_PCT', 40.0),

        // Stagnation & Dead-Position Timeout Pruner (0 disables time-based forced closures)
        'stagnation_timeout_minutes' => (int) env('TRADING_STAGNATION_TIMEOUT_MINUTES', 0),
        'max_hold_minutes' => (int) env('TRADING_MAX_HOLD_MINUTES', 0),
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
        'live_only' => (bool) env('TELEGRAM_LIVE_ONLY', false), // Set to false so user receives Telegram alerts during both paper and live trading
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
