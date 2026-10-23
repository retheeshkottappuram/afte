# EBD v1: Early Breakout Detection

**Status (2026-10-23):** EBD v1 has replaced the old breakout path. It is the only source of breakout signals and Telegram breakout alerts. It is **alerts only**: it never places orders, and the bot opens no breakout trades.

**Read this before trading any alert.** See §9 for the measured backtest results, costs included. Treat the alerts as information, not as trade instructions.

## 1. Repository audit (before the change)

| Area | Found |
|---|---|
| Stack | Laravel 13, PHP 8.4. MySQL in production, SQLite in tests. Cache store `database`. |
| Cron | The hosting panel runs `php artisan schedule:run` every minute. Shared hosting: no daemons, no supervisor. |
| Schedule (`routes/console.php`) | `trade:engine` (every minute: trade management every 5 s, scans after each close), `crypto:scan --manual`, `crypto:resolve-signals` (every 5 min), nightly AI training, daily summary and reconcile, `queue:work`. |
| Market data | `App\Services\Crypto\BinanceClient`: public REST, pooled requests, short caches. It had **no** 418/429 back-off and no weight tracking, and it dropped the quote-volume, trade-count and taker-volume fields. |
| Indicators | `App\Services\Crypto\Indicators`: Wilder ATR/RSI, EMA, Bollinger, and others. |
| Old breakout strategy | Three parts: (1) `StrategyEngine::rawTriggers` SQUEEZE_BREAKOUT on **1h** closes; (2) `watchCandidates` → `BreakoutWatcher::store` → the "Breakout setups" Telegram message; (3) `BreakoutWatcher::check()` every minute → EARLY_BREAKOUT, auto-traded through `SignalAlgoTrader` (`TradingDaemonCommand` watcher block). |
| Signals table | `crypto_signals`: prices decimal(18,8), so PEPE/SHIB-size prices lose digits. Dedupe is in code (`SignalLedger`). Outcomes come from `crypto:resolve-signals` (5m replay). |
| Alerts | `SignalAlerts` → `TelegramGateway` (hourly limit, digest, retries). |
| Backtests | `trade:backtest` (`BacktestingEngine`, 1h). History is cached in the DB cache only. |

**Why the old path missed early breakouts:**
- It used a 1h box, rebuilt only once an hour, so a confirmation could arrive up to an hour late.
- Its volume pace was a straight-line extrapolation (`vol_sma × elapsed share`).
- It kept no persisted lifecycle (no failed, retest or extended states, and no linked alerts).
- No minute-level volume history was used.

**Missing data, still missing after this change:**
- Historical intrabar (1m) volume for the backtest. Downloading it for 50 coins × 12 months is about 26M candles, which the shared host cannot hold.
- A historical order book, so historical spread is unknown.

## 2. What was built

| File | Role |
|---|---|
| `config/breakout.php` | Every threshold, with documented defaults. `EbdConfig::validate()` rejects out-of-range values and names the key. |
| `app/Services/Breakout/Candles.php` | Normalised candle series (UTC ms, quote volume in USDT when available) and data-quality checks: gaps, duplicates, ordering, high/low consistency, NaN, negative volume. |
| `IndicatorCalculator.php` | ATR (Wilder), NATR, EMA 9/21, RSI 14, Bollinger bandwidth, bandwidth percentile over the previous 100, RVOL against the previous 20, CLV, body/ATR, and 1m volume pace. Causal; warm-up stays `null`. |
| `ConsolidationDetector.php` | Stage A. |
| `BreakoutTriggerDetector.php` | Stage B (early) and Stage C (confirmed). |
| `BreakoutQualityScorer.php` | Deterministic 0–100 score. |
| `SignalRiskCalculator.php` | Entry, SL, TP1/TP2, cost-adjusted R:R, optional size. |
| `SignalLifecycleService.php` | The state machine. Pure, shared by live and backtest. |
| `MarketDataService.php` | Wraps `BinanceClient` (universe, 5m/15m/1h, 1m for armed coins, prices, spreads, funding, freshness). |
| `BreakoutStrategyService.php` | One run: the closed-candle pass and the live early check, transactions, metrics. |
| `BreakoutAlerts.php` | Telegram messages through the existing `TelegramGateway`. |
| `HistoryStore.php`, `BreakoutBacktester.php` | Backtest data (sequential, throttled, disk cache) and the replay. |
| `app/Models/BreakoutSetup.php`, `BreakoutSetupEvent.php` | Persistence. |
| `app/Console/Commands/BreakoutScanCommand.php` | `breakout:scan`, scheduled every minute. |
| `app/Console/Commands/BreakoutBacktestCommand.php` | `breakout:backtest`. |

**Changed files:**
- `BinanceClient`: keeps the extra kline fields; adds rate-limit back-off and weight tracking for all callers.
- `StrategyEngine`, `BreakoutWatcher`: the legacy breakout path is off unless `STRATEGY_BREAKOUT_ENGINE=legacy`.
- `TradingDaemonCommand`: the "watching" count now comes from EBD.
- `MarketScanController::watch` and the scanner page: show EBD setups and states.
- `TradeHealthCommand`: shows the last EBD run.
- Dashboard: the "Breakout" switch is relabelled "Pullback", because it now only gates Trend Pullback.
- `TrainSignalModelCommand`: excludes EBD rows from training.
- `routes/console.php`: one schedule line.
- `.env.example`.

### Database (reversible; nothing dropped)

- **`breakout_setups`** holds one row per compression range.
  - Key: unique `setup_key = SYMBOL:5m:compression_close_ms`.
  - Range and levels: R, S, ATR and triggers.
  - Detection: detection price, mode and latency.
  - Trade levels: entry, SL, TP1, TP2, net R:R.
  - Measures: RVOL, pace, extension, early and confirmed score, `entry_valid`, cancel reason.
  - Timestamps (UTC): compressed, detected, confirmed, retest, failed, expired.
  - `state_data` holds the engine's working state as JSON.
  - Links: the crypto signal and the Telegram message id.
  - Decimal(24,12) prices. Indexes on (symbol, state), (state, updated_at) and (direction, confirmed_at).
- **`breakout_setup_events`** holds one row per lifecycle event, with a **unique (setup, event)** key. That key is what makes alerts idempotent across repeated or concurrent cron runs.
- **`crypto_signals`** price columns are widened to decimal(24,12). The down migration restores (18,8).
- **Mirrored signals:**
  - Each early, confirmed or retest signal with valid levels is also written to `crypto_signals`: setup `EBD_EARLY` / `EBD_CONFIRMED` / `EBD_RETEST`, interval 5m, source `ebd`, `is_shadow = true` (never auto-traded).
  - The alert log and `crypto:resolve-signals` therefore track their outcomes with no change.

## 3. Formulas

All baselines use completed candles before the candle being judged.

**Indicators:**
- TR_t = max(H−L, |H−C₍t−1₎|, |L−C₍t−1₎|); ATR_t = (13·ATR₍t−1₎ + TR_t)/14.
- NATR = ATR/C.
- EMA: α = 2/(n+1).
- RSI 14, Wilder.

**Compression range** (after candle k closes; the breakout candidate is k+1):
- R = max H over k−19..k; S = min L over k−19..k; W = R − S; RangeRatio = W/ATR(k).
- BBW = (Upper − Lower)/MA20, using 2σ bands (population σ).
- Compressed when BBW(k) ≤ 30th percentile of BBW(k−100..k−1) **and** RangeRatio ≤ 4.

**Volume:**
- RVOL_t = V_t / mean(V₍t−20..t−1₎), using quote volume (USDT).
- Live pace: V(closed 1m candles of the forming 5m candle) / median of the same first k minutes of the previous 20 5m candles.
- Unavailable when k = 0, there are fewer than 10 samples, or the baseline is zero. Unavailable counts as **not met**.

**Candle strength:**
- Body/ATR = |C−O|/ATR.
- CLV_long = (C−L)/(H−L); CLV_short = (H−C)/(H−L). Null on a zero-range candle.

**Extension:** Ext = (P − R)/ATR for longs, (S − P)/ATR for shorts. It always uses the R/S/ATR captured when the setup was recorded.

**Levels:**
- SL_long = min(lowest low of the last 3 candles, R − 0.25·ATR); SL_short = max(highest high, S + 0.25·ATR).
- TP1 = E ± 1.5·risk; TP2 = E ± 2·risk.

**Costs and net R:R:**
- Costs per unit = E·(2·taker 0.05% + 2·slippage 0.03% + spread) + adverse funding × (2h/8h).
- Net R:R(TP1) = (reward − costs)/(risk + costs).

**Size (display only, never ordered):** Q·r/|E − SL|, and only when equity is known.

## 4. Exact conditions

| Stage | Condition |
|---|---|
| A. Compression | ≥ 122 closed 5m candles; data fresh (the last closed candle is the one that just closed) with no gaps or duplicates; RangeRatio ≤ 4; BBW percentile ≤ 30; spread ≤ 15 bps. One live setup per coin. Expires after 12 candles without a break (silent). |
| B. Early (live, every minute) | State COMPRESSION_DETECTED; price > R + 0.05·ATR (or < S − 0.05·ATR); 1m volume pace ≥ 1.5; spread ≤ 15 bps; price age ≤ 90 s; Ext ≤ 1.5; early score ≥ 60. → **EARLY BREAKOUT WATCH**, provisional. If Ext > 1.5 at first sight → EXTENDED_MOVE (no entry, no BUY NOW). |
| B'. Seen only at the close | Close beyond the early buffer with RVOL ≥ 1.5 but not confirmed → early alert marked "seen at the 5m close" (latency 300 s). |
| C. Confirmed (5m close) | Close > R + 0.10·ATR (or < S − 0.10·ATR); RVOL ≥ 1.8; CLV ≥ 0.75; body in the breakout direction; RSI > 55 and rising (or < 45 and falling); Ext ≤ 1.5. Body ≥ 0.5·ATR is a score preference (`BREAKOUT_EBD_BODY_REQUIRED=true` makes it a hard rule). Entry valid only with score ≥ 75 and valid levels (SL 0.2–3%, net R:R ≥ 1). Score 60–74 = "watchlist only"; < 60 = "no entry". |
| D. Failed | 2 consecutive closes back inside the range, a close beyond the opposite edge, or the stop level trading after a valid confirmation. |
| E. Retest | After a confirmed or extended break: a candle trades within 0.25·ATR of the broken edge and closes beyond it. A rejection candle (CLV ≥ 0.6, body in the direction) → **RETEST_CONFIRMED**, a separate signal with its own levels. Otherwise RETEST_WAIT for up to 6 candles. |
| F. Late | Ext > 1.5 → EXTENDED_MOVE, announced only for setups that already alerted, as "BREAKOUT IN PROGRESS, do not chase". |
| Early not confirmed | 3 candles → EXPIRED (announced). |
| Tracking | Confirmed setups are followed for 48 candles (4h), then closed; TP2 also closes tracking. |

**Score** (0–100):

| Component | Points |
|---|---|
| Compression | 15 |
| Breakout clearance | 20 |
| Volume | 25 |
| Candle | 15 |
| Momentum | 10 |
| Liquidity | 10 |
| Higher timeframe (1h EMA21 side and slope, 15m compression) | 5 |

The formulas are in `BreakoutQualityScorer`. Buckets: 85 high, 75 valid, 60 watchlist, below 60 reject. The score never replaces the price break.

## 5. Scheduler

- **No new cron entry.** The existing `schedule:run` cron now also runs `breakout:scan` every minute.
- **Overlap control:** `breakout:scan` runs in the background under its own cache lock (`ebd-scan`, 70 s). It does not use `withoutOverlapping`, whose mutex sticks on this host.
- **Each minute:** the live early check on up to 15 coins within 0.5 ATR of a trigger (1m klines for those only).
- **≥ 20 s after each 5m close:** one closed-candle pass for the top-50 universe, guarded by `ebd:bar:{close}`. It catches up on candles a missed run skipped.
- **Failure handling:**
  - Each coin is isolated: a failure is logged and the scan continues.
  - A Binance 418/429 stops all public calls until Retry-After.
  - The run skips batches once the used weight passes 1800 a minute.
- **Run metrics:** stored in the `ebd_last_run` setting and shown in `trade:health` and on the scanner page.

## 6. Alerts

- Formats follow §13 of the brief: EARLY BREAKOUT WATCH, CONFIRMED BREAKOUT, RETEST CONFIRMED, FAILED BREAKOUT, BREAKOUT EXPIRED and BREAKOUT IN PROGRESS, in LONG and SHORT versions.
- Times are shown in UTC plus `BREAKOUT_EBD_TIMEZONE` (default Asia/Kolkata).
- Later messages reply to the first alert of the setup.
- **Not sent:**
  - compression;
  - silent expiry;
  - events older than 10 minutes (missed cron runs);
  - anything in shadow mode or with the dashboard alert switch off.
- A per-coin, per-direction cooldown (30 min) applies to new early and confirmed alerts.

## 7. Backtest

See §9 for the results.

**How it runs:** `php artisan breakout:backtest --months=12 --top=50`.
- Downloads are sequential (never parallel) and throttled to 1000 weight a minute, cached in `storage/app/ebd-history`.
- The replay goes candle by candle through the same `SignalLifecycleService`.

**Fills and exits:**
- **Early:** inside the crossing candle at the trigger (or the open on a gap).
- **Confirmed and retest:** at the next candle's open.
- **Exits:** TP1 half, then the stop moves to breakeven; TP2 for the rest; a 4h time stop. A same-candle stop and target count as the stop first.
- **Costs:** fees, slippage and the default spread on every fill, plus historical funding.
- **Periods:** chronological train / validation / out-of-sample = first 50% / next 25% / last 25%. No parameter was tuned on any period.

**Limits:**
- **Early fills are price-only.** The 1m volume pace at the crossing cannot be rebuilt from 5m candles. The pace filter is assumed met, and the early score floor is lowered by the 20 pace points it cannot see. The exact live early-alert performance therefore cannot be reconstructed from 5m OHLC.
- **The crossing time inside a candle is unknown.** On the entry candle only the stop is checked: a touch is a loss, and no target is credited.
- **Universe survivorship.** The coins are today's top 50, so coins that died are missing.

## 8. Deploy and rollback

**Deploy** (the user commits and pulls):

```
git pull
/usr/php84/usr/bin/php artisan migrate --force
/usr/php84/usr/bin/php artisan optimize:clear
```

Then press Ctrl+F5 on the dashboard and check `php artisan trade:health` for "Early Breakout Detection".

**Optional .env settings** (defaults are fine): `BREAKOUT_EBD_MODE=shadow` to store without Telegram; `BREAKOUT_EBD_MAX_SYMBOLS`, `BREAKOUT_EBD_COOLDOWN_MINUTES`, `BREAKOUT_EBD_TIMEZONE`. Run `optimize:clear` after any change.

**Roll back** to the old breakout path:

```
# .env
STRATEGY_BREAKOUT_ENGINE=legacy
/usr/php84/usr/bin/php artisan optimize:clear
```

- **What changes:** this stops `breakout:scan` and restores Squeeze Breakout, the hourly watch list and the minute watcher. With `STRATEGY_INTRABAR_BREAKOUTS=true`, it also restores auto-trading of early breakouts.
- **Tables:** they can stay. `php artisan migrate:rollback --step=2` removes them and restores decimal(18,8) on `crypto_signals`.
- **Before rolling back the migrations,** check that no price in `crypto_signals` needs more than 8 decimals.

## 9. Backtest results (run 2026-10-23)

| Setting | Value |
|---|---|
| Data | Binance USDT-M futures public klines (5m, 15m, 1h) plus historical funding, 2025-10-28 to 2026-10-22 UTC |
| Coins | The top 30 by 24h volume on 2026-10-23: BTC, ETH, ZEC, SOL, IRYS, XRP, 1000PEPE, NEAR, HYPE, WLD, BTW, MAGMA, DOGE, BNB, AKE, ENA, BTR, AGT, SUI, PUMP, UNI, BIRB, ADA, PAXG, FIL, AVAX, BMT, GTC, XMR, LINK |
| Parameters | The defaults in `config/breakout.php`; nothing was tuned |
| Costs | All included: fees, slippage, spread and funding |
| Report | `storage/app/ebd-backtest/top30.json` |

### EBD v1

Every variant lost money in every period, including the out-of-sample one:

| Variant | Trades | Win % | Avg R | PF | Train PF | Validation PF | Out-of-sample PF |
|---|---|---|---|---|---|---|---|
| Early (price-only, pace assumed) | 19,102 | 38.1 | −0.22 | 0.67 | 0.66 | 0.68 | 0.70 |
| Confirmed (next open) | 2,508 | 37.3 | −0.22 | 0.67 | 0.66 | 0.68 | 0.67 |
| Confirmed, score ≥ 85 only | 216 | 40.3 | −0.14 | 0.78 | | | |
| Retest | 3,313 | 37.5 | −0.22 | 0.68 | 0.71 | 0.62 | 0.68 |

- **Every slice lost.** That covers long and short, with and without the 1h trend, and every score bucket. None reached break-even.
- **Average cost per trade:** about 0.45–0.5R. Before costs the setups are roughly break-even: 5m stops are about the same size as fees, spread and slippage.
- **The cost gate filtered most candidates:**
  - early signals rejected for "net R:R after costs < 1": 50,049;
  - early stops tighter than 0.2%: 8,025;
  - confirmed signals rejected for net R:R: 7,069.
- **Lifecycle:**
  - 108,888 compression setups, of which 84,779 broke out;
  - 83,333 early alerts (including those seen at the close), of which 82.8% were never confirmed;
  - 15,189 confirmed, of which 40.5% later failed.
- **Earliness:**
  - average extension at first detection: 0.075 ATR;
  - 99.4% of the moves of 3 ATR or more were detected before a third of the move.
- **Bottom line:** EBD *does* catch moves early, but the moves it catches do not pay after costs.

### Baseline: the old breakout engine on the same 30 coins and period

Command: `STRATEGY_BREAKOUT_ENGINE=legacy php artisan trade:backtest <same 30> --months=12 --setups=SQUEEZE_BREAKOUT,EARLY_BREAKOUT --intrabar` (1h).

| Setup | Trades | Win % | Avg R | PF |
|---|---|---|---|---|
| Squeeze Breakout (1h close) | 264 | 40.9 | +0.14 | 1.31 |
| Early Breakout (old minute watcher) | 6,093 | 30.6 | −0.21 | 0.65 |
| Portfolio of both, $24, live risk rules | 447 | 25.7 | −0.29 | 0.57 ($24 → $0.57) |

- **The only positive breakout rule in either engine is the old 1h Squeeze Breakout confirmed at the candle close** (PF 1.31). Its rules were chosen on earlier data in this same period, so treat it as partly in-sample.
- **EBD v1 replaces it, as the user decided.** The early variants of both engines lose by about the same amount (PF 0.65 for the old, 0.67 for EBD).
- **Combined mode was not run:** the user chose a full replacement.

### What this means

- **EBD alerts are unproven, and the backtest says they lose after costs.** Every entry alert says so in a 📉 line.
- **EBD never trades.**
- **To stop the Telegram messages but keep measuring live** (results are tracked through `crypto:resolve-signals`, source `ebd`), set `BREAKOUT_EBD_MODE=shadow`.
- **To go back to the old 1h Squeeze Breakout path,** set `STRATEGY_BREAKOUT_ENGINE=legacy`.

## 10. Known limits and open issues

- **Live early alerts cannot be measured exactly from history.** The 1m volume pace is not reconstructable from 5m candles, so the live early alert (which needs pace ≥ 1.5) is a filtered subset of the backtested "early" variant. Its true edge is unknown until live data accumulates.
- **No historical spread:** the default 2 bps was used. Wide-spread coins are worse live.
- **Survivorship and look-ahead:** the coins are today's top 30.
- **Ambiguous candles:** in candles where both triggers were crossed, no early signal was taken.
- **Unbuilt items from the brief:**
  - open interest and order-book imbalance (no reliable historical source);
  - a BTC market-regime filter;
  - position sizing in alerts (`SignalRiskCalculator::quantity` exists, but alerts do not show a size).

These are recommendations, not completed work.
