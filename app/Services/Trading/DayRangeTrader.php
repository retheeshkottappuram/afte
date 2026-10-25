<?php

namespace App\Services\Trading;

use App\Models\Trade;
use App\Services\Crypto\BinanceClient;
use App\Services\Crypto\CandleSanitizer;
use App\Services\Crypto\Indicators;
use App\Services\Notifications\TelegramGateway;
use App\Services\Strategy\MarketScanService;
use App\Services\Strategy\Watchlist;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Day dip BUY (user's decision 2026-10-25): on coins in a daily uptrend, buy a dip of k x the average daily range
 * below the UTC day open, target tp x ADR above the buy, stop sl x ADR below it, close at the end of the day.
 * A "virtual limit": levels are sent to Telegram after the day opens, and the engine buys at market when the price
 * trades at or below the level (no resting Binance order).
 *
 * 12-month test (dayrange.py, 30 coins, 1h, limit fill at the level, fees): best of 216 variants, PF 1.10 / 1.36 /
 * 1.07 (train / validation / last 3 months), ~1 fill a day over 30 coins, +0.02R per trade. No variant stayed above
 * PF 1.1 in all three periods: a thin, unproven edge. Live fills are at market (taker fee, some slippage).
 */
class DayRangeTrader
{
    public const SETUP = 'DAY_RANGE';

    public function __construct(
        protected BinanceClient $market,
        protected OrderExecutor $executor,
        protected TelegramGateway $telegram,
    ) {}

    public static function enabled(): bool
    {
        return BotSwitches::on('dayrange');
    }

    public static function hasOpenTrades(): bool
    {
        return Trade::where('status', 'OPEN')->where('setup_tag', self::SETUP)->exists();
    }

    /**
     * @return array<int, string>
     */
    public static function symbols(): array
    {
        $symbols = (array) config('trading.day_range.symbols', ['WATCHLIST']);
        if ($symbols === [] || in_array('WATCHLIST', $symbols, true)) {
            $symbols = array_merge(array_diff($symbols, ['WATCHLIST']), Watchlist::symbols());
        }

        return array_values(array_unique(array_map(fn (string $s): string => TradingTargetManager::normalizeSymbol($s), $symbols)));
    }

    public static function day(int $nowMs): int
    {
        return intdiv($nowMs, 86_400_000);
    }

    /**
     * Today's levels for one coin, or the reason there are none.
     *
     * @return array{symbol: string, ok: bool, reason: ?string, open?: float, adr?: float, buy?: float, sl?: float, target?: float, day?: int}
     */
    public function levels(string $symbol, int $nowMs): array
    {
        $days = max(5, (int) config('trading.day_range.adr_days', 14));
        $emaLen = max(5, (int) config('trading.day_range.ema', 20));
        $raw = $this->market->klines($symbol, '1d', $emaLen + $days + 10);
        $today = count($raw['closes'] ?? []) - 1;
        // The last row is today's forming candle: its open is the day open.
        if ($today < 0 || (int) ($raw['closeTimes'][$today] ?? 0) < $nowMs) {
            return ['symbol' => $symbol, 'ok' => false, 'reason' => 'today\'s daily candle is missing'];
        }
        $open = (float) $raw['opens'][$today];
        $c = CandleSanitizer::onlyClosedCandles($raw, $nowMs);
        $k = count($c['closes']) - 1;
        if ($k < $emaLen + 1 || $k < $days) {
            return ['symbol' => $symbol, 'ok' => false, 'reason' => 'not enough daily history'];
        }

        $ema = Indicators::ema($c['closes'], $emaLen);
        if (! ((float) $c['closes'][$k] > (float) $ema[$k] && (float) $ema[$k] > (float) $ema[$k - 1])) {
            return ['symbol' => $symbol, 'ok' => false, 'reason' => 'no daily uptrend (close above a rising EMA'.$emaLen.')'];
        }

        $ranges = [];
        for ($i = $k - $days + 1; $i <= $k; $i++) {
            $ranges[] = (float) $c['highs'][$i] - (float) $c['lows'][$i];
        }
        $adr = array_sum($ranges) / count($ranges);
        $buy = $open - (float) config('trading.day_range.k', 0.8) * $adr;
        $round = fn (float $p): float => round($p, MarketScanService::priceDecimals($open) + 1);

        return [
            'symbol' => $symbol, 'ok' => $buy > 0, 'reason' => $buy > 0 ? null : 'range too wide', 'day' => self::day($nowMs),
            'open' => $open, 'adr' => $adr, 'buy' => $round($buy),
            'sl' => $round($buy - (float) config('trading.day_range.sl', 0.8) * $adr),
            'target' => $round($buy + (float) config('trading.day_range.tp', 0.6) * $adr),
        ];
    }

    /**
     * Once per UTC day (from plan_after_minutes past 00:00): work out the levels and send them to Telegram.
     *
     * @return array<int, array<string, mixed>>
     */
    public function plan(int $nowMs, bool $send = true): array
    {
        $day = self::day($nowMs);
        $key = "day-range:levels:{$day}";
        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }

        $rows = [];
        foreach (self::symbols() as $symbol) {
            try {
                $rows[] = $this->levels($symbol, $nowMs);
            } catch (Throwable $e) {
                Log::warning("[DayRange] {$symbol}: {$e->getMessage()}");
            }
        }
        Cache::put($key, $rows, now()->addHours(26));

        $ok = array_values(array_filter($rows, fn (array $r): bool => $r['ok']));
        if ($send && $ok !== [] && Cache::add("day-range:alert:{$day}", true, now()->addHours(26))) {
            $this->telegram->send($this->message($ok));
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function message(array $rows): string
    {
        $lines = ['📍 <b>Day dip BUY levels</b> (UTC day, valid until 23:55 UTC)'];
        foreach ($rows as $r) {
            $lines[] = sprintf('<b>%s</b> buy ≤ <code>%s</code> · target <code>%s</code> · stop <code>%s</code>', e($r['symbol']), $r['buy'], $r['target'], $r['sl']);
        }
        $lines[] = sprintf('Coins in a daily uptrend; buy %s x the average daily range below the day open, closed at the day end.', (float) config('trading.day_range.k', 0.8));
        $lines[] = self::enabled()
            ? sprintf('The bot buys at market when the price trades at the level (%s%% risk, at most %d open).', (float) config('trading.day_range.risk_pct', 1.0), (int) config('trading.day_range.max_open', 2))
            : 'Alert only: the Day range bot is switched off.';
        $lines[] = '📉 <i>Backtest (12 months, 30 coins): PF 1.10 / 1.36 / 1.07, about +0.02R a trade. Thin edge, unproven.</i>';

        return implode("\n", $lines);
    }

    /**
     * Every engine pass: buy any coin whose price is at or below today's level (once per coin per day).
     *
     * @return array<int, array{symbol: string, action: string, message: string}>
     */
    public function check(string $mode, bool $entriesAllowed, int $nowMs): array
    {
        $day = self::day($nowMs);
        $secondOfDay = intdiv($nowMs, 1000) % 86400;
        if ($secondOfDay < 60 * (int) config('trading.day_range.plan_after_minutes', 5) || $secondOfDay >= 3600 * (int) config('trading.day_range.last_entry_hour', 23)) {
            return [];
        }

        $levels = $this->plan($nowMs);
        if (! $entriesAllowed || ! self::enabled()) {
            return [];
        }

        $prices = [];
        foreach ($this->market->get24hrTickers() as $t) {
            $prices[$t['symbol'] ?? ''] = (float) ($t['lastPrice'] ?? 0);
        }

        $rows = [];
        foreach ($levels as $l) {
            $price = $prices[$l['symbol']] ?? 0.0;
            if (! $l['ok'] || $price <= 0 || $price > $l['buy'] || $price <= $l['sl'] || Cache::has("day-range:done:{$day}:{$l['symbol']}")) {
                continue;
            }
            if (Trade::where('status', 'OPEN')->where('setup_tag', self::SETUP)->count() >= (int) config('trading.day_range.max_open', 2)) {
                break;
            }
            Cache::put("day-range:done:{$day}:{$l['symbol']}", true, now()->addHours(26));
            $rows[] = ['symbol' => $l['symbol']] + $this->enter($l, $price, $mode, $nowMs);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $l
     * @return array{action: string, message: string}
     */
    protected function enter(array $l, float $price, string $mode, int $nowMs): array
    {
        $result = $this->executor->executeSignal([
            'symbol' => $l['symbol'],
            'side' => 'LONG',
            'price' => $price,
            'initial_sl' => (float) $l['sl'],
            'setup' => self::SETUP,
            'setup_label' => sprintf('Day dip BUY (level %s, target %s, closes 23:55 UTC)', $l['buy'], $l['target']),
            'interval' => '1d',
            'grade' => 'C',
            'indicators' => ['adr' => $l['adr']],
            'risk_pct' => (float) config('trading.day_range.risk_pct', 1.0),
        ], $mode);

        if ($result['status'] !== 'opened') {
            $this->telegram->send(sprintf('⏭ <b>Day dip BUY %s</b> not traded: %s', e($l['symbol']), e($result['message'])));

            return ['action' => 'skipped', 'message' => $result['message']];
        }

        if (($trade = $result['trade'] ?? null) instanceof Trade) {
            $trade->tp1_price = (float) $l['target'];
            $trade->tp2_price = (float) $l['target'];
            $trade->meta = array_merge($trade->meta ?? [], [
                'day_target' => (float) $l['target'],
                'day_close_at' => (self::day($nowMs) * 86400 + 86400 - 300) * 1000,
            ]);
            $trade->save();
        }

        return ['action' => 'opened', 'message' => $result['message']];
    }
}
