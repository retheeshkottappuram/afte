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
    | Monitored Trading Assets (At least 5 coins)
    |--------------------------------------------------------------------------
    | The dedicated assets monitored continuously on 15m & 1h SignalAlgo PRO charts.
    */
    'symbol' => env('TRADING_SYMBOL', 'BTCUSDT'),
    'active_coin' => env('TRADING_ACTIVE_COIN', 'BTCUSDT'),
    'single_coin_strict' => (bool) env('TRADING_SINGLE_COIN_STRICT', false),
    'monitored_coins' => array_values(array_filter(array_map('trim', explode(',', env('TRADING_MONITORED_COINS', 'BTCUSDT,ETHUSDT,SOLUSDT,SUIUSDT,NEARUSDT,1000PEPEUSDT,DOGEUSDT,AVAXUSDT,BNBUSDT,XRPUSDT,LINKUSDT,FETUSDT'))))),

    /*
    |--------------------------------------------------------------------------
    | PHP CLI Binary & Shared Hosting Controls
    |--------------------------------------------------------------------------
    */
    'php_binary' => env('PHP_BINARY_PATH', 'php'),
    'daemon_heartbeat_timeout' => (int) env('DAEMON_HEARTBEAT_TIMEOUT', 90),
    'daemon_auto_spawn' => false, // Shared hosting: the cron scheduler is the only process launcher

    /*
    |--------------------------------------------------------------------------
    | Live Trading Safety Gate
    |--------------------------------------------------------------------------
    | Permits live trading when configured in .env.
    */
    'allow_live_trading' => (bool) env('ALLOW_LIVE_TRADING', false),

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
            'max_positions' => 3,
            'max_unprotected' => 2,
            'default_leverage' => 10,
            'max_risk_pct' => 5.0,
            'min_score' => 80,
            'max_coin_price' => 100000.0,
            'exclude_symbols' => [],
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
        'max_consecutive_losses' => (int) env('TRADING_MAX_CONSECUTIVE_LOSSES', 3),
        'loss_cooldown_minutes' => (int) env('TRADING_LOSS_COOLDOWN_MINUTES', 360), // 6h pause after a losing streak (no revenge trading)
        'max_daily_loss_pct' => (float) env('TRADING_MAX_DAILY_LOSS_PCT', 6.0),     // No new entries after -6% on the UTC day
        'max_drawdown_pct' => (float) env('TRADING_MAX_DRAWDOWN_PCT', 30.0),        // Kill switch at -30% from peak equity
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
    | Cron Engine (shared hosting: `php artisan schedule:run` every minute)
    |--------------------------------------------------------------------------
    */
    'engine' => [
        'cycle_seconds' => (int) env('TRADING_ENGINE_CYCLE_SECONDS', 50),   // Work window per cron tick
        'manage_every_seconds' => (int) env('TRADING_ENGINE_MANAGE_SECONDS', 5),
        'lock_seconds' => 70,
        'stall_after_seconds' => 180,
        'http_timeout_seconds' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Position Sizing (risk is defined by the stop-loss, not by leverage)
    |--------------------------------------------------------------------------
    */
    'sizing' => [
        'risk_per_trade_pct' => (float) env('TRADING_RISK_PER_TRADE_PCT', 2.0),
        'small_account_max_risk_pct' => (float) env('TRADING_SMALL_ACCOUNT_MAX_RISK_PCT', 5.0), // Min-notional trades allowed up to this risk
        'small_account_equity' => 25.0,
        'max_leverage' => (int) env('TRADING_MAX_LEVERAGE', 10),
        'max_margin_pct' => 90.0,
        'max_positions' => [ // equity ceiling => max simultaneous positions
            25 => 1,
            100 => 2,
            PHP_INT_MAX => 3,
        ],
        'max_same_side' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Exit Plan (shared by live management, paper simulation and backtests)
    |--------------------------------------------------------------------------
    */
    'exits' => [
        'tp1_r' => 1.5,
        'tp2_r' => 3.0,
        'tp1_close_ratio' => 0.5,
        'breakeven_at_r' => 1.0,
        'after_tp1_lock_r' => 0.5,
        'trail_atr_mult' => 1.5,
        'time_stop_hours' => 12,       // Close if not reached +0.5R by then
        'time_stop_min_r' => 0.5,
        'max_hold_hours' => 48,
        'fee_rate' => 0.0005,          // Taker fee per fill
        'paper_slippage' => 0.0003,
    ],

    /*
    |--------------------------------------------------------------------------
    | Strategy Engine (one engine for auto-trader, scanner, chart and Telegram)
    |--------------------------------------------------------------------------
    */
    'strategy' => [
        'base_interval' => '1h',
        'regime_interval' => '4h',
        // Timeframes the engine scans right after each of their candle closes (the base one drives the dashboard scanner).
        // 15m failed its 12-month backtest (every setup negative: Squeeze PF 0.80, Pullback 0.84), so only 1h by default.
        'scan_intervals' => array_values(array_filter(array_map('trim', explode(',', (string) env('STRATEGY_SCAN_INTERVALS', '1h'))))),
        // Timeframes the auto-trader may enter on; the others are scanned for alerts and manual trading only
        'trade_intervals' => array_values(array_filter(array_map('trim', explode(',', (string) env('STRATEGY_TRADE_INTERVALS', '1h'))))),
        'min_quote_volume_24h' => (float) env('STRATEGY_MIN_VOLUME_24H', 20000000.0),
        'min_listing_days' => 30,
        'min_atr_pct' => 0.35,
        'min_atr_pct_by_setup' => [
            // Trend Pullback on quiet coins lost (tight stops, fees eat the R); ATR >= 1.06% won in- and out-of-sample.
            'TREND_PULLBACK' => (float) env('STRATEGY_PULLBACK_MIN_ATR_PCT', 1.06),
        ],
        'max_atr_pct' => 4.0,
        'max_adverse_funding' => 0.0005,
        'regime_min_adx' => 18.0,
        'min_sl_pct' => 0.6,
        'max_sl_pct' => 1.8,
        'max_sl_pct_by_setup' => [ // breakouts often need a wider stop; risk stays sized by the stop distance
            'SQUEEZE_BREAKOUT' => (float) env('STRATEGY_BREAKOUT_MAX_SL_PCT', 4.0),
            'EARLY_BREAKOUT' => (float) env('STRATEGY_BREAKOUT_MAX_SL_PCT', 4.0),
        ],
        // Minute breakout watcher: Telegram alerts ("coiled" after each scan, "breaking out" in real time).
        'breakout_alerts' => (bool) env('STRATEGY_BREAKOUT_ALERTS', true),
        // Auto-trade early breakouts (before the candle closes). The user's decision: the 12-month backtest of
        // early entries lost, so they trade as their own setup with half risk, a daily cap and an auto-pause.
        'intrabar_breakouts' => (bool) env('STRATEGY_INTRABAR_BREAKOUTS', true),
        'early_breakout' => [
            'stop_mode' => env('STRATEGY_EARLY_STOP_MODE', 'mid'), // atr | inside | mid: box midpoint tested best (PF 0.78 pessimistic to 1.17 optimistic)
            'anticipate_pct' => (float) env('STRATEGY_EARLY_ANTICIPATE_PCT', 0.15), // enter this % before the breakout level
            // Watch / alert / early-enter only when the box itself leans the trend's way (edge + structure + volume).
            // 12-month test: alert direction right 74.5% (was 48.9%); early trades PF 0.87-1.25 -> 1.11-1.18.
            // (one-sided mode only)
            'require_box_bias' => (bool) env('STRATEGY_EARLY_REQUIRE_BOX_BIAS', true),
            // User's decision (2026-10-07): watch and alert both box edges on every squeezed coin (no 4h-trend
            // requirement); the volume-confirmed break picks the side. false = trend side only (needs box bias).
            'two_sided' => (bool) env('STRATEGY_EARLY_TWO_SIDED', true),
            // User's decision (2026-10-07): auto-trade early breakouts even when their measured edge is negative.
            'ignore_min_edge' => (bool) env('STRATEGY_EARLY_IGNORE_MIN_EDGE', true),
            // 12-month test, top 50 (2026-10-07): trading every two-sided breakout = ~17/week, PF 0.70-1.07,
            // $5 -> $0.98-$6.14, max DD 36-81%. Only the trend + box-bias + top-10 subset: ~3/week, PF 1.39-1.58,
            // $5 -> $6.27-$6.87, max DD 5-7%. false = alert every breakout, trade only that subset.
            'trade_all_breakouts' => (bool) env('STRATEGY_EARLY_TRADE_ALL', true),
            'risk_pct' => (float) env('STRATEGY_EARLY_RISK_PCT', 1.0),
            'max_per_day' => (int) env('STRATEGY_EARLY_MAX_PER_DAY', 8),
            'max_open' => (int) env('STRATEGY_EARLY_MAX_OPEN', 1),
            // 0 = no separate pause; the global 3-loss cooldown (circuit_breakers) applies instead.
            'pause_after_losses' => (int) env('STRATEGY_EARLY_PAUSE_AFTER_LOSSES', 0),
        ],
        'core_setups' => ['TREND_PULLBACK', 'SQUEEZE_BREAKOUT', 'EARLY_BREAKOUT'],
        'min_setup_expectancy_r' => (float) env('STRATEGY_MIN_SETUP_EXPECTANCY_R', 0.05), // pause setups below this measured edge
        'shadow_setups' => ['SWING_REVERSAL', 'EMA_CROSS'],
        'min_ai_lift' => (float) env('STRATEGY_MIN_AI_LIFT', 0.85), // skip signals the AI rates clearly below an average signal
        'live_requires_proven_setup' => (bool) env('STRATEGY_LIVE_REQUIRES_PROVEN', true),
        'auto_trade_grades' => ['A', 'B'],
        'telegram_grades' => ['A', 'B'],
        // 12-month test by volume rank (2026-10-06): Squeeze Breakout won only on the top 10 coins (+0.15R, PF 1.34)
        // and lost on every lower group; Trend Pullback with ATR >= 1.06% won on ranks 1-50 (+0.15R per group, held
        // on coins it was not tuned on). So: scan the top 50, breakouts only on the top 10.
        'max_universe' => (int) env('STRATEGY_MAX_UNIVERSE', 50),
        'max_volume_rank_by_setup' => [
            'SQUEEZE_BREAKOUT' => (int) env('STRATEGY_BREAKOUT_MAX_RANK', 10),
            // Early breakouts: every coin scanned (user's decision 2026-10-07).
            'EARLY_BREAKOUT' => (int) env('STRATEGY_EARLY_MAX_RANK', 50),
        ],
        'manual_scan_min_volume_24h' => (float) env('STRATEGY_MANUAL_SCAN_MIN_VOLUME', 5000000.0), // on-demand scanner covers smaller coins too (shown, flagged if below the trading floor)
        'manual_scan_max_symbols' => 200,
        'manual_scan_lookback_bars' => 6,
        'banned_symbols' => ['USDCUSDT', 'FDUSDUSDT', 'TUSDUSDT', 'BTCDOMUSDT'],
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
        'live_only' => (bool) env('TELEGRAM_LIVE_ONLY', false), // true = suppress paper trade events (signals, risk and health alerts still sent)
        'max_messages_per_hour' => 20,
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
