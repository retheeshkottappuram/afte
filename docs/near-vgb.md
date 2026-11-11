# NEAR 1H Volatility-Gated Breakout

The only strategy in AFTE since 2026-11-11. It is a port of the user's Pine v6 script. Code:

- Entry and EMA exit: [NearBreakoutTrader](../app/Services/Trading/NearBreakoutTrader.php)
- Trail and safety stop: `DynamicTradeManager::manageNear`
- Settings: `near_vgb` in [config/trading.php](../config/trading.php)

## Rules

Everything runs on closed 1h NEARUSDT candles. The bot checks each candle 30 seconds after it closes.

| | Script | AFTE |
|---|---|---|
| Entry (long only, flat only) | crossover of close over the highest close of the previous 12 bars, close > EMA50, green candle, ATR14 >= 0.30% of close, size > 0 | same |
| Volatility gate | `ta.percentrank(atrPct, 1440)[1]`: 80 or more means no trade, 20 or less means 1.0, anything else 0.5 | same: the previous 1440 values, lagged one bar |
| Size | equity / close x size, 1x margin | margin = balance x size x 0.98 (fee buffer), 1x leverage, Binance lot rounding |
| Exit 1 | `close < ema` at the bar close | same, at market |
| Exit 2 | `trail_points` = `trail_offset` = 0.10 x ATR | the trail arms at entry + d, then exits at the high − d; d updates to the latest ATR every candle; checked on the mark price about every 5s while the cron engine runs |
| Safety stop | none | a reduce-only Binance stop at entry − 3 x ATR (at most 8%), so a crash between engine runs is capped |

## Telegram

All of these messages are sent with priority, so the hourly message limit never holds them back.

**Strategy alerts.** They follow the script whether or not the bot trades:

- **📈 BUY signal** on the entry candle. It shows the price, EMA50, ATR%, rank and size, the trail arm level, and the safety stop.
- **↗️ trail armed** and **📉 SELL / exit signal** (trail hit), on the live price.
- **📉 SELL / exit signal** on a 1h close below EMA50.
- While the bot holds the trade, its own trade messages report the exit instead, so exits are not sent twice.

**Trade messages, live and paper.** Paper ones are muted by `TELEGRAM_LIVE_ONLY=true`:

- opened;
- trail armed;
- stop edited, and breakeven;
- "partly closed on Binance, not by the bot";
- closed, with the reason, net PnL and balance;
- "⏭ not traded by the bot: why".

## Backtest

- **Data:** cached 12 months of NEAR, tested 2025-11-30 to 2026-10-22, after a 1440-bar warm-up.
- **Costs:** 0.05% fee per side plus 0.02% slippage.
- **Agreement:** the PHP `evaluate()` and an independent Python replay produce the same 356 raw entry signals on this data.

| How the trail is replayed inside each candle | Trades | Win % | PF | Return | Max drawdown |
|---|---|---|---|---|---|
| 1h OHLC path, as TradingView guesses it | 343 | 80% | 3.68 | +148% | 5% |
| Real 5-minute price path | 343 | 56% | 0.92 | −3% | 9% |
| Real 5-minute path, trail 0.5 ATR | 291 | 62% | 0.71 | −26% | 32% |
| Real 5-minute path, trail 1.0 ATR | 230 | 54% | 0.82 | −22% | 29% |

The 0.10 ATR trail is only a few ticks wide. On a 1h candle TradingView does not know whether the high or the low came first, so it assumes the order that fills the trail at the best price. With the real order of prices the edge disappears. The live bot, which checks prices every few seconds, should behave like the 5-minute rows, not the first row.

The user chose to trade it live anyway (2026-11-11).

## Removed on 2026-11-11

Removed:

- Swing SAR, Lab (SOL/NEAR), Day dip, and Trend Pullback / breakout auto-trading;
- the market scanner and its signals page;
- EBD breakout alerts, the signal ledger and outcome tracking, and the AI model;
- the sentinel and the Telegram alert log.

Database tables are kept, with no drops. An old open position is still managed, but only by its stop.
