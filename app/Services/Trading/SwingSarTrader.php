<?php

namespace App\Services\Trading;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Crypto\BinanceClient;
use App\Services\Crypto\CandleSanitizer;
use App\Services\Notifications\TelegramGateway;
use App\Services\Strategy\MarketScanService;
use App\Services\Strategy\StrategyEngine;
use App\Services\Strategy\Watchlist;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Swing SAR: stop-and-reverse on the chart's swing BUY/SELL flips (SuperTrend(10,3), StrategyEngine::swingSignalAt).
 * After every closed candle of swing_sar.interval (15m), for each SAR coin (default: the monitored coins):
 *  - an opposite flip (also a "?") closes the open SAR trade;
 *  - a confirmed flip opens the new side with balance x fund_share / max_open margin ("?" only when
 *    confirmed_only is off); the stop sits 1 ATR beyond the SuperTrend line, capped at max_sl_pct from entry;
 *  - otherwise the stop trails the line; every 5s DynamicTradeManager::manageSwingSar moves it to breakeven
 *    at +1R and then trails the best price.
 *
 * 12-month test, 1h, top 20 + NEAR + SUI, $10 x 5x, fees + slippage, at most 2 open (2026-10-18, bt_swing_sar*.php):
 *  - confirmed flips, exit on the opposite flip, no stop:  PF 1.05 (months 1-10) / 1.10 (last 2)
 *  - every flip ("BUY?" too): all coins PF 0.89 / 0.86 (lost)
 *  - stop trailing AT the line (intrabar): PF 0.91 / 1.66
 *  - DEFAULT: confirmed + 4h SuperTrend agrees, stop trailing 1 ATR beyond the line: PF 1.18 / 1.80,
 *    +$20.8 in 10 months and +$16 in 2, ~4-5 trades a week, max drawdown $25 (months 1-10) / $10.
 * NEAR + SUI alone with the 4h filter: under 1 trade a week.
 * 2026-10-19 (bt_sar_tp.php), every flip incl. "?", 8 watch coins, 2 slots, stop capped at 4%:
 *  - no TP: PF 0.88 / 1.25; TP1 1R half: 0.86 / 1.11; TP1 1.5R half: 0.85 / 1.20, ~8-9 trades a week,
 *    max drawdown $53 in months 1-10 (more than the $24 account; the daily stop / loss-streak pause cut it short).
 *  The user chose every flip with TP1 anyway.
 * 2026-10-20 (bt_sar_15m.php), 15m, confirmed only, NEAR + SUI, $30 trades: every stop variant lost on months 1-10
 *  (PF 0.72-0.85, ~15 trades a week). Shipped: 2% cap + breakeven at 1R + 1.5 ATR trail, PF 0.85 / 0.83, max
 *  drawdown $37. The user chose 15m anyway.
 */
class SwingSarTrader
{
    public const SETUP = 'SWING_SAR';

    public const LABEL = 'Swing SAR (SuperTrend flip, exit on the opposite flip)';

    public function __construct(
        protected BinanceClient $market,
        protected StrategyEngine $engine,
        protected OrderExecutor $executor,
        protected DynamicTradeManager $tradeManager,
        protected TelegramGateway $telegram
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('trading.swing_sar.enabled', false);
    }

    /**
     * @return array<int, string>
     */
    public static function symbols(): array
    {
        $symbols = (array) config('trading.swing_sar.symbols', []);
        if ($symbols === [] || in_array('WATCHLIST', $symbols, true)) {
            $symbols = array_merge(array_diff($symbols, ['WATCHLIST']), Watchlist::symbols());
        }

        return array_values(array_unique(array_map(fn (string $s): string => TradingTargetManager::normalizeSymbol($s), $symbols)));
    }

    public static function interval(): string
    {
        return (string) config('trading.swing_sar.interval', '15m');
    }

    /**
     * Candle length of the SAR interval in seconds (the engine runs SAR once per closed candle).
     */
    public static function intervalSeconds(): int
    {
        $interval = self::interval();
        $n = max(1, (int) $interval);

        return $n * match (substr($interval, -1)) {
            'm' => 60,
            'h' => 3600,
            'd' => 86400,
            default => 3600,
        };
    }

    /**
     * Margin for one SAR trade: its share of the balance split over the SAR slots (fixed margin_usd when fund_share is 0).
     */
    public static function marginPerTrade(TradingAccount $account): float
    {
        $share = (float) config('trading.swing_sar.fund_share', 0.5);
        if ($share <= 0) {
            return (float) config('trading.swing_sar.margin_usd', 10.0);
        }

        return round(max(0.0, (float) $account->balance) * $share / max(1, (int) config('trading.swing_sar.max_open', 2)), 2);
    }

    /**
     * Act on the last closed candle of every SAR coin (once per candle and coin).
     *
     * @return array<int, array{symbol: string, action: string, message: string}>
     */
    public function run(string $mode, bool $entriesAllowed, ?int $nowMs = null): array
    {
        $nowMs ??= now()->getTimestampMs();
        $results = [];
        foreach (self::symbols() as $symbol) {
            try {
                $results[] = ['symbol' => $symbol] + $this->process($symbol, $mode, $entriesAllowed, $nowMs);
            } catch (Throwable $e) {
                Log::warning("[SwingSar] {$symbol}: {$e->getMessage()}");
                $results[] = ['symbol' => $symbol, 'action' => 'error', 'message' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * @return array{action: string, message: string}
     */
    public function process(string $symbol, string $mode, bool $entriesAllowed, int $nowMs): array
    {
        $interval = self::interval();
        $c = CandleSanitizer::onlyClosedCandles($this->market->klines($symbol, $interval, 300), $nowMs);
        $k = count($c['closes'] ?? []) - 1;
        if ($k < 60) {
            return ['action' => 'none', 'message' => 'Not enough candles.'];
        }
        if (! Cache::add("swing-sar:{$mode}:{$symbol}:{$c['closeTimes'][$k]}", true, now()->addSeconds(3 * self::intervalSeconds()))) {
            return ['action' => 'none', 'message' => 'Candle already handled.'];
        }

        $st = StrategyEngine::supertrendOf($c);
        $line = $st['line'][$k] ?? null;
        $open = Trade::where('mode', $mode)->where('symbol', $symbol)->where('status', 'OPEN')->where('setup_tag', self::SETUP)->first();
        $flip = $this->engine->swingSignals($c, $k, true)[0] ?? null;

        if ($flip === null || ($open !== null && $open->side === $flip['side'])) {
            if ($open !== null) {
                // Last swing: low / high of the last 2 closed candles (used once the trade is well in profit).
                $meta = $open->meta ?? [];
                $meta['sar_swing_low'] = min($c['lows'][$k], $c['lows'][$k - 1]);
                $meta['sar_swing_high'] = max($c['highs'][$k], $c['highs'][$k - 1]);
                if ($line !== null && config('trading.swing_sar.trail', true)) {
                    $meta['sar_trail'] = $this->stopFrom((string) $open->side, (float) $line, (float) ($meta['atr'] ?? 0));
                }
                $open->meta = $meta;
                $open->save();

                if (isset($meta['sar_trail'])) {
                    return ['action' => 'trail', 'message' => "Stop target {$meta['sar_trail']} (SuperTrend line)."];
                }
            }

            return ['action' => 'none', 'message' => 'No flip.'];
        }

        $closedNote = null;
        if ($open !== null) {
            $price = $this->price($symbol, (float) $c['closes'][$k]);
            $result = $this->tradeManager->closeTrade($open, $price, 'OPPOSITE_SIGNAL');
            $closedNote = "Closed {$open->side}: {$result['message']}";
        }

        $label = sprintf('%s swing %s%s', $symbol, $flip['side'] === 'LONG' ? 'BUY' : 'SELL', $flip['confirmed'] ? '' : '?');
        $skip = $this->entryBlock($symbol, $flip, $entriesAllowed, $nowMs);
        if ($skip !== null) {
            // "?" flips are skipped silently (on 15m there are many); a close is always reported.
            if ($closedNote === null && ! $flip['confirmed'] && config('trading.swing_sar.confirmed_only', true)) {
                return ['action' => 'skipped', 'message' => 'New entry skipped: '.$skip];
            }
            $this->telegram->send($closedNote !== null
                ? sprintf('🔁 <b>%s</b> (%s): the %s was closed. No new trade: %s', e($label), $interval, $open->side, e($skip))
                : sprintf('⏭ <b>%s</b> (%s) not traded: %s', e($label), $interval, e($skip)));

            return ['action' => $closedNote !== null ? 'closed' : 'skipped', 'message' => trim(($closedNote ?? '').' New entry skipped: '.$skip)];
        }

        $price = $this->price($symbol, (float) $flip['entry']);
        $sl = $this->cappedStop($flip['side'], $price, $this->stopFrom($flip['side'], (float) $flip['sl'], (float) $flip['atr']));
        $result = $this->executor->executeSignal([
            'symbol' => $symbol,
            'side' => $flip['side'],
            'price' => $price,
            'initial_sl' => $sl,
            'setup' => self::SETUP,
            'setup_label' => self::LABEL.($flip['confirmed'] ? '' : ' · unconfirmed flip "?"'),
            'interval' => $interval,
            'grade' => 'B',
            'indicators' => ['atr' => (float) $flip['atr'], 'rsi' => $flip['rsi'], 'volume_ratio' => $flip['volume_ratio']],
            'fixed_margin' => self::marginPerTrade(TradingAccount::getForMode($mode)),
            'fixed_leverage' => (int) config('trading.swing_sar.leverage', 5),
        ], $mode);

        if ($result['status'] !== 'opened') {
            $this->telegram->send($closedNote !== null
                ? sprintf('🔁 <b>%s</b>: the %s was closed. New %s not opened: %s', e($label), $open->side, $flip['side'], e($result['message']))
                : sprintf('⏭ <b>%s</b> (%s) not traded: %s', e($label), $interval, e($result['message'])));

            return ['action' => $closedNote !== null ? 'closed' : 'skipped', 'message' => trim(($closedNote ?? '').' '.$result['message'])];
        }

        if (($trade = $result['trade'] ?? null) instanceof Trade) {
            // The chart signal this trade came from (the flip candle's close), to show the entry gap on the chart.
            $trade->meta = array_merge($trade->meta ?? [], ['signal_price' => (float) $flip['entry'], 'signal_time' => (int) $flip['time']]);
            $trade->save();
        }

        return ['action' => $closedNote !== null ? 'reversed' : 'opened', 'message' => trim(($closedNote ?? '').' '.$result['message'])];
    }

    /**
     * Why a flip must not open a trade, or null when it may.
     *
     * @param  array<string, mixed>  $flip  From StrategyEngine::swingSignalAt()
     */
    public function entryBlock(string $symbol, array $flip, bool $entriesAllowed, int $nowMs): ?string
    {
        if (! $entriesAllowed) {
            return 'auto-trading is stopped or paused';
        }
        if (config('trading.swing_sar.confirmed_only', true) && ! $flip['confirmed']) {
            return sprintf('unconfirmed flip (volume %.1fx, stop %.2f%%; needs >= 1.5x and <= 4%%)', $flip['volume_ratio'], $flip['sl_pct']);
        }
        if (config('trading.swing_sar.require_4h_trend', false)) {
            $h4 = CandleSanitizer::onlyClosedCandles($this->market->klines($symbol, '4h', 120), $nowMs);
            $dir = StrategyEngine::supertrendOf($h4)['dir'];
            $last = $dir[count($dir) - 1] ?? null;
            if ($last !== ($flip['side'] === 'LONG' ? 1 : -1)) {
                return 'the 4h SuperTrend points the other way';
            }
        }

        return null;
    }

    /**
     * Stop at the SuperTrend line, pushed out by the configured ATR buffer.
     */
    protected function stopFrom(string $side, float $line, float $atr): float
    {
        $buffer = (float) config('trading.swing_sar.stop_buffer_atr', 0.0) * $atr;

        return round($side === 'LONG' ? $line - $buffer : $line + $buffer, MarketScanService::priceDecimals($line) + 2);
    }

    /**
     * Keep the stop at most max_sl_pct from entry (a wide "?" flip gets a tighter stop instead of being skipped).
     */
    protected function cappedStop(string $side, float $price, float $stop): float
    {
        $cap = $price * (1 + ($side === 'LONG' ? -1 : 1) * (float) config('trading.swing_sar.max_sl_pct', 4.0) / 100);

        return $side === 'LONG' ? max($stop, $cap) : min($stop, $cap);
    }

    protected function price(string $symbol, float $fallback): float
    {
        try {
            $price = (float) $this->market->tickerPrice($symbol);
        } catch (Throwable) {
            $price = 0.0;
        }

        return $price > 0 ? $price : $fallback;
    }
}
