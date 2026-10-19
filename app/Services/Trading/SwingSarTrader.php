<?php

namespace App\Services\Trading;

use App\Models\Trade;
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
 * After every closed 1h candle, for each SAR coin (default: the monitored alert coins):
 *  - an opposite flip closes the open SAR trade;
 *  - the flip opens the new side with a fixed margin (every flip incl. "BUY?"/"SELL?" unless confirmed_only);
 *    the stop sits 1 ATR beyond the SuperTrend line, capped at max_sl_pct from entry;
 *  - otherwise the stop trails the line; half is booked at TP1 (DynamicTradeManager::manageSwingSar).
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
        $interval = (string) config('trading.swing_sar.interval', '1h');
        $c = CandleSanitizer::onlyClosedCandles($this->market->klines($symbol, $interval, 300), $nowMs);
        $k = count($c['closes'] ?? []) - 1;
        if ($k < 60) {
            return ['action' => 'none', 'message' => 'Not enough candles.'];
        }
        if (! Cache::add("swing-sar:{$mode}:{$symbol}:{$c['closeTimes'][$k]}", true, now()->addHours(3))) {
            return ['action' => 'none', 'message' => 'Candle already handled.'];
        }

        $st = StrategyEngine::supertrendOf($c);
        $line = $st['line'][$k] ?? null;
        $open = Trade::where('mode', $mode)->where('symbol', $symbol)->where('status', 'OPEN')->where('setup_tag', self::SETUP)->first();
        $flip = $this->engine->swingSignals($c, $k, true)[0] ?? null;

        if ($flip === null || ($open !== null && $open->side === $flip['side'])) {
            if ($open !== null && $line !== null && config('trading.swing_sar.trail', true)) {
                $meta = $open->meta ?? [];
                $meta['sar_trail'] = $this->stopFrom((string) $open->side, (float) $line, (float) ($meta['atr'] ?? 0));
                $open->meta = $meta;
                $open->save();

                return ['action' => 'trail', 'message' => "Stop target {$meta['sar_trail']} (SuperTrend line)."];
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
            'fixed_margin' => (float) config('trading.swing_sar.margin_usd', 10.0),
            'fixed_leverage' => (int) config('trading.swing_sar.leverage', 5),
        ], $mode);

        if ($result['status'] !== 'opened') {
            $this->telegram->send($closedNote !== null
                ? sprintf('🔁 <b>%s</b>: the %s was closed. New %s not opened: %s', e($label), $open->side, $flip['side'], e($result['message']))
                : sprintf('⏭ <b>%s</b> (%s) not traded: %s', e($label), $interval, e($result['message'])));

            return ['action' => $closedNote !== null ? 'closed' : 'skipped', 'message' => trim(($closedNote ?? '').' '.$result['message'])];
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
