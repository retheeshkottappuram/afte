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
        'max_positions' => [ // equity ceiling => max simultaneous AT-RISK positions (stop still below breakeven)
            25 => 1,
            100 => 2,
            PHP_INT_MAX => 3,
        ],
        // User's decision (2026-10-20): more positions may open while the others are risk-free (stop at breakeven or
        // better), up to this many in total, so open risk never exceeds the at-risk limit above.
        'max_positions_protected' => (int) env('TRADING_MAX_POSITIONS_PROTECTED', 4),
        'max_same_side' => 2, // at-risk positions in one direction
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
        // Breakout signal engine. 'ebd' (default, 2026-10-23 user decision): EBD v1 (config/breakout.php, breakout:scan)
        // is the only breakout signal and alert source, alerts only; Squeeze Breakout, the hourly watch list and the
        // minute watcher below are off and the bot opens no breakout trades. 'legacy' restores the old path (rollback).
        'breakout_engine' => env('STRATEGY_BREAKOUT_ENGINE', 'ebd'),
        // Minute breakout watcher (legacy engine): Telegram alerts ("coiled" after each scan, "breaking out" in real time).
        'breakout_alerts' => (bool) env('STRATEGY_BREAKOUT_ALERTS', true),
        // "Breakout setups" message (before the break). 12-month audit, top 50 (2026-10-18, bt_setup_alerts.php): all
        // ~26/day as sent then: only ~33% went 2 ATR the right way first, PF 0.81 (months 1-10) / 1.18 (last 2).
        // Entry > 1.5% away (PF 0.60-0.74) and stop > 4% (PF 0.74-0.88) were the worst. Top-10 coin + 4h trend agreeing
        // + stop <= 4% + entry <= 1.5% away: ~1/day, PF 1.03 / 2.91, the only subset not losing in either period.
        // User's decision (2026-10-20): send every leaning setup within 2.5% (tagged proven / unproven) and let the
        // bot trade them all, knowing the full list lost in that audit. true / 1.5 = the measured subset only.
        // 2026-10-22: user reported wrong-way / late breakouts; back to the measured subset (env restores the old).
        'setup_alerts' => [
            'max_distance_pct' => (float) env('STRATEGY_SETUP_ALERT_MAX_DISTANCE_PCT', 1.5),
            'proven_only' => (bool) env('STRATEGY_SETUP_ALERT_PROVEN_ONLY', true), // top-10 + 4h trend + box lean
        ],
        // Swing BUY/SELL Telegram alerts (SuperTrend 10,3 flip on 1.5x volume), never auto-traded (user's decision
        // 2026-10-13). 12-month test: 43.9% won, about breakeven (PF 0.98). The message states this.
        'topbottom_alerts' => (bool) env('STRATEGY_TOPBOTTOM_ALERTS', true),
        // Auto-trade early breakouts (before the candle closes). Off (user's decision 2026-10-13): a 5m replay of the
        // live watcher (12 months, 23 coins) lost with realistic fills, even on the proven subset (last 4 months
        // PF 0.83). User's decision (2026-10-20): on again, the bot trades every predicted breakout when it breaks,
        // sized from the breakout half of the fund (swing_sar.fund_share). false = alerts only.
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
            // User's decision (2026-10-12): trade only the profitable subset; the others are alerted, not traded.
            // User's decision (2026-10-20): trade them all again.
            // 2026-10-22: user reported most breakouts going the wrong way; back to the profitable subset only.
            // (A 1-minute "ignition" detector was tested the same day as a replacement: 30 coins, 90 days, no
            // setting made money even before fees -- best unseen -0.13R, PF 0.64. Not shipped.)
            'trade_all_breakouts' => (bool) env('STRATEGY_EARLY_TRADE_ALL', false),
            // Minute watcher confirmation: volume so far on pace for this x the 20-bar average, judged on at
            // least this share of the candle. Skip the entry when price is already this many R past the trigger.
            'volume_pace' => (float) env('STRATEGY_EARLY_VOLUME_PACE', 1.5),
            'min_elapsed_share' => (float) env('STRATEGY_EARLY_MIN_ELAPSED_SHARE', 0.25),
            'max_chase_r' => env('STRATEGY_EARLY_MAX_CHASE_R') !== null ? (float) env('STRATEGY_EARLY_MAX_CHASE_R') : null,
            // Exit when an hourly candle closes back inside the box. Live week to 2026-10-12: it closed 21 of 22
            // trades, none reached TP1. 12-month test, proven subset: without it win 43.6% / +0.25R / $24 -> $29.02,
            // with it win 25.8% / +0.20R / $24 -> $28.44. Off: the normal stop and trail manage the trade.
            'failed_exit' => (bool) env('STRATEGY_EARLY_FAILED_EXIT', false),
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
    | Swing SAR (stop-and-reverse on the chart's swing BUY/SELL flips)
    |--------------------------------------------------------------------------
    | User's request (2026-10-18): buy on a swing BUY, hold until the opposite swing SELL, then reverse; the stop
    | trails the SuperTrend(10,3) line every closed 1h candle. Only confirmed flips open trades ("BUY?"/"SELL?" and
    | filtered signals never do; they only close). Fixed $10 margin at 5x, separate from the main bot's slots.
    | Off by default: the user turns it on after seeing the 12-month test (SwingSarTrader docblock).
    */
    'swing_sar' => [
        'enabled' => (bool) env('SWING_SAR_ENABLED', false),
        // WATCHLIST = the monitored coins (Watchlist::symbols(), edited on the Signals page); or a comma list.
        // User's decision (2026-10-20): all monitored coins, 4 slots.
        'symbols' => array_values(array_filter(array_map(fn (string $s): string => strtoupper(trim($s)), explode(',', (string) env('SWING_SAR_SYMBOLS', 'WATCHLIST'))))),
        // User's decision (2026-10-20): 15m candles, confirmed flips only. 12-month test on NEAR + SUI
        // (bt_sar_15m.php, $30 trades): every stop variant LOST on months 1-10 (PF 0.72-0.85, ~15 trades/week);
        // the shipped one (2% cap, breakeven at +1R, then 1.5 ATR trail) PF 0.85 / 0.83, max drawdown $37.
        // 1h on the same coins: ~1.5 trades/week, PF 0.83-1.42.
        // User's decision (2026-10-25): back to 1h candles, the chart timeframe. 12-month test (swing1h_bot.py, 30 coins
        // from the EBD cache, bot exits below, fees + slippage, confirmed flips + 4h filter): PF 0.94 (train / validation /
        // last 3 months 0.83 / 0.86 / 1.20), 553 trades, win 53%. Without the 4h filter PF 0.95. Not a proven edge.
        // Entry stays at the flip candle's close: a limit at that candle's low / high (where the chart arrow sits) filled
        // only 137 of 553 flips with no better PF (0.98 vs 1.00 with simple exits).
        'interval' => (string) env('SWING_SAR_INTERVAL', '1h'),
        // Margin per trade = balance x fund_share / max_open (the other half is for the breakout bot); the fixed
        // margin_usd is used only when fund_share is 0.
        'fund_share' => (float) env('SWING_SAR_FUND_SHARE', 0.5),
        'margin_usd' => (float) env('SWING_SAR_MARGIN_USD', 10.0),
        // User's decision (2026-10-25, "medium fund"): size each trade to lose risk_pct of equity at its stop (margin from
        // the swing fund share, leverage 5x-10x as needed). 12-month 1h replay (sizing.py, runner exit, $24): 3% risk ->
        // $28.6 after 12 months, max drawdown 77%, 24% chance of the balance halving at some point; 1.25% -> 45% / 0%.
        // 0 = the old fixed margin (balance x fund_share / max_open).
        'risk_pct' => (float) env('SWING_SAR_RISK_PCT', 3.0),
        'leverage' => (int) env('SWING_SAR_LEVERAGE', 5),
        'max_open' => (int) env('SWING_SAR_MAX_OPEN', 4),
        // The placed stop (line + ATR buffer) is capped at this % from entry (flips often print at a top / bottom).
        'max_sl_pct' => (float) env('SWING_SAR_MAX_SL_PCT', 2.0),
        'max_order_sl_pct' => (float) env('SWING_SAR_MAX_ORDER_SL_PCT', 6.0),
        // true = confirmed flips only; "BUY?" / "SELL?" are never traded (an opposite "?" still closes an open trade).
        'confirmed_only' => (bool) env('SWING_SAR_CONFIRMED_ONLY', true),
        // User's decision (2026-10-21): also trade "BUY?" / "SELL?" flips, with a fixed tight stop (unconfirmed_sl_pct
        // from entry) instead of the SuperTrend stop; the dynamic rules then work on that small R. Kept on although the
        // 12-month test (bt_unconf.php, 8 coins, 15m, bot exits) was worse than confirmed flips: "?" only PF 0.63
        // (win 32%), confirmed only PF 0.85, both PF 0.80 with ~70% more trades. Off: SWING_SAR_UNCONFIRMED_ENTRIES=false.
        // 2026-10-25, 1h: "?" flips with the 0.6% stop lost heavily (PF 0.52, 2146 trades, every period), so off by default.
        'unconfirmed_entries' => (bool) env('SWING_SAR_UNCONFIRMED_ENTRIES', false),
        // Which signals the chart prints and the bot trades: 'supertrend' = SuperTrend(10,3) flips (default; the user
        // reverted to it on 2026-10-21 after the V3 test below), 'v3' = SignalAlgo PRO V3 100-point confluence score
        // (ConfluenceEngine), 'sr' = S/R reversal engine (ReversalEngine; chart-only until it passes its walk-forward test).
        // 12-month test (bt_v3.php, 8 coins, 15m, bot exits): V3 >= 80 3.8 trades/day, win 39%, median PF 0.73;
        // with the 70-79 "?" (0.6% stop) 8.2/day, PF 0.70; >= 85 only PF 0.79. SuperTrend confirmed: 10.3/day, PF 0.85.
        // V3 signals come ~1.0% after the turn (SuperTrend ~1.4%). None of these made money in the test.
        'signal_source' => env('SWING_SAR_SIGNAL_SOURCE', 'supertrend'),
        // S/R reversal engine (ReversalEngine): sweep of a 2+ touch zone + reversal candle, trend from the higher timeframe.
        'sr' => [
            'zone_atr' => 0.5,           // pivots within this many ATR form one zone
            'min_touches' => 2,          // higher-timeframe pivots count 2
            'sweep_atr' => 1.0,          // the wick may poke through the zone by at most this
            'volume_ratio' => 1.3,
            'min_confirmations' => (int) env('SWING_SAR_SR_MIN_CONFIRMATIONS', 1), // RSI divergence / volume / follow-through
            'min_rr' => (float) env('SWING_SAR_SR_MIN_RR', 2.0),                    // to the next opposite zone
            'counter_trend' => (bool) env('SWING_SAR_SR_COUNTER_TREND', true),      // against the 1h trend only with RSI divergence
            'lookback' => 300,
        ],
        'v3' => [
            'min_score' => (float) env('SWING_SAR_V3_MIN_SCORE', 80),          // BUY / SELL
            'unconfirmed_score' => (float) env('SWING_SAR_V3_Q_SCORE', 70),    // BUY? / SELL?
            'conflict_score' => 60,       // the other side scoring this much = no signal
            'breakout_lookback' => 20,    // candles of the range that must be broken
            'max_rsi' => 75,              // no BUY above RSI 75 (no SELL below 25)
            'max_extension_atr' => 2.0,   // no signal when the close is more than 2 ATR past EMA21
            'min_rr' => 2.0,              // the next swing level must be at least 2R away
        ],
        'unconfirmed_sl_pct' => (float) env('SWING_SAR_UNCONFIRMED_SL_PCT', 0.6),
        // Only enter when the 4h SuperTrend points the same way.
        'require_4h_trend' => (bool) env('SWING_SAR_REQUIRE_4H', true),
        // Checked every 5s, best price in R (1R = the entry-to-stop distance). User's decision (2026-10-25, "book maximum
        // profit"): the "runner" exit, no partials. 12-month 1h test (swing_conf.py, 30 coins, 553 trades): runner PF 1.08
        // (train / validation / last 3 months 1.11 / 0.78 / 1.38) vs the old partials 1/3 at +0.75R and 1/3 at +1.5R with
        // ladder and ROE lock PF 0.94 (0.83 / 0.86 / 1.20); a full exit at 1.5R / 2R / 3R PF 1.00-1.02.
        //  - partials [at R, share of the original size] (none);
        //  - ladder [at R, stop at R] (0 = breakeven + fees): breakeven at +1R;
        //  - from +trail_from_r R the stop also trails the best price by trail_atr ATR (0 = off).
        // Old exits: partials [[0.75, 0.33], [1.5, 0.33]], ladder [[0.75, -0.25], [1.5, 0.5]], trail 1.5 / 1.5, profit_lock below.
        'partials' => [],
        'ladder' => [[1.0, 0.0]],
        'trail_from_r' => (float) env('SWING_SAR_TRAIL_FROM_R', 2.0),
        'trail_atr' => (float) env('SWING_SAR_TRAIL_ATR', 2.0),
        // Profit lock on peak ROE (price move % x leverage): [peak ROE %, share of the peak gain the stop keeps]. Off in the
        // runner exit (it cut winners short); the old setting was [[10, 0.50], [20, 0.65], [30, 0.75]].
        'profit_lock' => [],
        // With a profit lock: from this peak ROE the stop also trails just past the last 2 closed candles' low / high.
        'swing_trail_from_roe' => (float) env('SWING_SAR_SWING_TRAIL_FROM_ROE', 10),
        // Book half at TP1 (exits.tp1_r) too. Off: in the 15m test it did not help (PF 0.84 / 0.77 vs 0.85 / 0.83).
        'take_profit' => (bool) env('SWING_SAR_TAKE_PROFIT', false),
        // Stop = SuperTrend line pushed out by this many ATR; trail = move it with the line every closed candle.
        'stop_buffer_atr' => (float) env('SWING_SAR_STOP_BUFFER_ATR', 1.0),
        'trail' => (bool) env('SWING_SAR_TRAIL', true),
        // Telegram "stop moved" reply only when the stop moved at least this % of price since the last one.
        'trail_notify_pct' => 0.5,
    ],

    /*
    | Strategy-lab live trial (user's decision 2026-10-21): one week of the two lab candidates on 4h candles, each
    | sized to lose about risk_pct of equity at its (wide, 8-12%) stop. No new entries after `until` (UTC); open
    | trades keep their stops. Swing SAR leaves these coins alone while the lab owns them. Off: LAB_ENABLED=false.
    */
    /*
    | Day dip BUY (user's decision 2026-10-25, DayRangeTrader): on Watchlist coins in a daily uptrend (close above a
    | rising EMA20), buy k x ADR(14) below the UTC day open, target tp x ADR above, stop sl x ADR below, close 23:55 UTC.
    | Levels go to Telegram after the open; the engine buys at market when the price touches the level ("virtual limit").
    | 12-month test (dayrange.py, 30 coins, 1h): best of 216 variants, PF 1.10 / 1.36 / 1.07, ~+0.02R a trade. Unproven.
    */
    'day_range' => [
        'enabled' => (bool) env('DAY_RANGE_ENABLED', true),
        'symbols' => array_values(array_filter(array_map(fn (string $s): string => strtoupper(trim($s)), explode(',', (string) env('DAY_RANGE_SYMBOLS', 'WATCHLIST'))))),
        'k' => (float) env('DAY_RANGE_K', 0.8),
        'tp' => (float) env('DAY_RANGE_TP', 0.6),
        'sl' => (float) env('DAY_RANGE_SL', 0.8),
        'adr_days' => 14,
        'ema' => 20,
        'risk_pct' => (float) env('DAY_RANGE_RISK_PCT', 1.0),
        'max_sl_pct' => 10.0,          // the stop is 0.8 x the daily range (often 4-6%); size comes from risk_pct
        'max_open' => (int) env('DAY_RANGE_MAX_OPEN', 2),
        'plan_after_minutes' => 5,     // levels are worked out and sent at 00:05 UTC
        'last_entry_hour' => 23,       // no new entry from 23:00 UTC (the trade closes at 23:55)
    ],

    'lab' => [
        'enabled' => (bool) env('LAB_ENABLED', true),
        'until' => env('LAB_UNTIL', '2026-10-28 12:00:00'),
        'risk_pct' => (float) env('LAB_RISK_PCT', 3.0),
        'leverage' => (int) env('LAB_LEVERAGE', 5),
        'max_sl_pct' => 15.0,
        'strategies' => [
            // Lab: train / validation / last-2-months PF 1.13 / 1.22 / 1.87 (11 trades)
            'SOLUSDT' => ['type' => 'tsmom', 'fast' => 42, 'slow' => 180, 'trail' => 4.5],
            // Lab: 1.65 / 2.11 / 1.68 (5 trades)
            'NEARUSDT' => ['type' => 'squeeze', 'len' => 20, 'sqz' => 0.35, 'stop' => 2.0, 'trail' => 2.5],
        ],
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
