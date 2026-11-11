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
    'seed_capital' => (float) env('TRADING_SEED_CAPITAL', 24.0),
    // Dashboard milestones between the starting balance and target_capital.
    'milestones' => [50.0, 100.0, 250.0],
    'target_capital' => (float) env('TRADING_TARGET_CAPITAL', 500.0),

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
        'max_positions' => [ // equity ceiling => max simultaneous AT-RISK positions (stop still below breakeven)
            25 => 1,
            100 => 2,
            PHP_INT_MAX => 3,
        ],
        // User's decision (2026-10-20): more positions may open while the others are risk-free (stop at breakeven or
        // better), up to this many in total, so open risk never exceeds the at-risk limit above.
        'max_positions_protected' => (int) env('TRADING_MAX_POSITIONS_PROTECTED', 4),
        'max_same_side' => 2, // at-risk positions in one direction
        'min_free_margin' => (float) env('TRADING_MIN_AVAILABLE_MARGIN', 0.50), // USD of free margin needed to open a trade
    ],

    /*
    |--------------------------------------------------------------------------
    | Fees (paper fills and PnL estimates)
    |--------------------------------------------------------------------------
    */
    'exits' => [
        'fee_rate' => 0.0005,          // Taker fee per fill
        'paper_slippage' => 0.0003,
    ],

    /*
    |--------------------------------------------------------------------------
    | NEAR 1H Volatility-Gated Breakout (the only strategy, user's script 2026-11-11)
    |--------------------------------------------------------------------------
    | Long NEARUSDT when a closed 1h candle crosses above the highest close of the previous 12, is green, closes
    | above EMA50, ATR14 is >= 0.30% of price and the ATR% percentile (previous 1440 bars, lagged one bar) is below
    | 80. Size: 100% of equity at 1x when the percentile is <= 20, else 50%. Exits: a close below EMA50, or a
    | trail of 0.10 x ATR that starts once price is 0.10 x ATR above entry. safety_atr is not in the script: an
    | exchange stop for crashes between engine runs.
    | Test 2025-11-30..2026-10-22 (fees + slippage): 343 trades, PF 3.68 when the trail is replayed on 1h candles
    | (TradingView's assumption), PF 0.92 / -3% on the real 5m price path. See docs/near-vgb.md.
    */
    'near_vgb' => [
        'enabled' => (bool) env('NEAR_VGB_ENABLED', true),
        'symbol' => env('NEAR_VGB_SYMBOL', 'NEARUSDT'),
        'interval' => '1h',
        'break_len' => 12,
        'ema_len' => 50,
        'atr_len' => 14,
        'trail_mult' => (float) env('NEAR_VGB_TRAIL_MULT', 0.10),
        'min_atr_pct' => 0.30,
        'gate_len' => 1440,
        'high_gate' => 80.0,
        'low_gate' => 20.0,
        'safety_atr' => (float) env('NEAR_VGB_SAFETY_ATR', 3.0),
        'max_sl_pct' => 8.0,
        'equity_buffer' => 0.98, // margin = balance x size x this, leaves room for fees
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

];
