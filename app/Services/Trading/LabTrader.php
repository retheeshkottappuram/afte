<?php

namespace App\Services\Trading;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Crypto\BinanceClient;
use App\Services\Crypto\CandleSanitizer;
use App\Services\Crypto\Indicators;
use App\Services\Notifications\TelegramGateway;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One-week live trial of the two strategy-lab candidates (user's decision 2026-10-21), on 4h candles:
 *  - SOLUSDT "tsmom": time-series momentum. Long when the 7-day (42 bars) and 30-day (180 bars) returns are both
 *    up, short when both down; exit when the 7-day return turns, or on a 4.5 ATR trailing stop.
 *  - NEARUSDT "squeeze": after a Bollinger squeeze (band width in the lowest 35% of 100 bars, within the last 5),
 *    a close beyond the 20-bar range with the EMA200 trend; 2 ATR initial stop, then a 2.5 ATR chandelier trail.
 * Lab tests (lab.php, 12 months cached): train / validation / last 2 months PF 1.13 / 1.22 / 1.87 (11 trades) for
 * SOL, 1.65 / 2.11 / 1.68 (5 trades) for NEAR - too few trades to call proven. Sized to lose lab.risk_pct of equity
 * at the stop (the stops are wide, 8-12%). New entries stop after lab.until; open trades keep their exits.
 */
class LabTrader
{
    public const SETUP = 'LAB';

    public const INTERVAL = '4h';

    public function __construct(
        protected BinanceClient $market,
        protected OrderExecutor $executor,
        protected DynamicTradeManager $tradeManager,
        protected TelegramGateway $telegram,
    ) {}

    public static function enabled(): bool
    {
        return BotSwitches::on('lab') && self::strategies() !== [];
    }

    /** Open lab trades still need their 4h trail and momentum exit when the switch is off. */
    public static function hasOpenTrades(): bool
    {
        return Trade::where('status', 'OPEN')->where('setup_tag', self::SETUP)->exists();
    }

    /** New entries allowed until lab.until (UTC). */
    public static function entriesOpen(): bool
    {
        $until = config('trading.lab.until');

        return self::enabled() && ($until === null || now()->lt(Carbon::parse((string) $until, 'UTC')));
    }

    /**
     * @return array<string, array<string, mixed>> symbol => strategy params
     */
    public static function strategies(): array
    {
        return (array) config('trading.lab.strategies', []);
    }

    /**
     * Coins the lab owns while it is on (Swing SAR leaves them alone so the two bots never share a position).
     *
     * @return array<int, string>
     */
    public static function activeSymbols(): array
    {
        $open = Trade::where('status', 'OPEN')->where('setup_tag', self::SETUP)->pluck('symbol')->all();
        if (! self::enabled()) {
            return array_values(array_unique($open));
        }

        return array_values(array_unique(array_merge(self::entriesOpen() ? array_keys(self::strategies()) : [], $open)));
    }

    /**
     * Once per closed 4h candle: exits and trailing stops for open lab trades, then new entries.
     *
     * @return array<int, array{symbol: string, action: string, message: string}>
     */
    public function run(string $mode, bool $entriesAllowed): array
    {
        $rows = [];
        foreach (self::strategies() as $symbol => $params) {
            try {
                $rows[] = ['symbol' => $symbol] + $this->process($symbol, (array) $params, $mode, $entriesAllowed && self::entriesOpen());
            } catch (Throwable $e) {
                Log::warning("[Lab] {$symbol}: {$e->getMessage()}");
                $rows[] = ['symbol' => $symbol, 'action' => 'error', 'message' => $e->getMessage()];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array{action: string, message: string}
     */
    public function process(string $symbol, array $p, string $mode, bool $entriesAllowed): array
    {
        $c = CandleSanitizer::onlyClosedCandles($this->market->klines($symbol, self::INTERVAL, 300), now()->getTimestampMs());
        $k = count($c['closes'] ?? []) - 1;
        if ($k < 220) {
            return ['action' => 'none', 'message' => 'Not enough 4h candles.'];
        }
        if (! Cache::add("lab:{$mode}:{$symbol}:{$c['closeTimes'][$k]}", true, now()->addHours(8))) {
            return ['action' => 'none', 'message' => 'Candle already handled.'];
        }

        $atr = Indicators::atr($c['highs'], $c['lows'], $c['closes'], 14);
        $open = Trade::where('mode', $mode)->where('symbol', $symbol)->where('status', 'OPEN')->where('setup_tag', self::SETUP)->first();
        $type = (string) ($p['type'] ?? '');

        if ($open !== null) {
            $d = $open->isLong() ? 1 : -1;
            if ($type === 'tsmom' && $this->momentum($c, $k, (int) $p['fast']) * $d < 0) {
                $result = $this->tradeManager->closeTrade($open, (float) $c['closes'][$k], 'MOMENTUM_TURNED');

                return ['action' => 'closed', 'message' => "7-day momentum turned: {$result['message']}"];
            }
            // Chandelier trail from the best price since entry (highest / lowest kept by the trade manager).
            $best = $d > 0 ? max((float) $open->highest_price, (float) $c['highs'][$k]) : min((float) $open->lowest_price ?: (float) $c['lows'][$k], (float) $c['lows'][$k]);
            $trail = $best - $d * (float) $p['trail'] * (float) $atr[$k];
            $moved = $this->tradeManager->trailStopTo($open, $trail, 'lab trailing stop');

            return ['action' => $moved ? 'trail' : 'none', 'message' => $moved ? "Stop trailed to {$open->fresh()->current_sl}." : 'Stop unchanged.'];
        }

        $side = match ($type) {
            'tsmom' => $this->tsmomSide($c, $k, $p),
            'squeeze' => $this->squeezeSide($c, $k, $p),
            default => null,
        };
        if ($side === null) {
            return ['action' => 'none', 'message' => 'No entry.'];
        }
        if (! $entriesAllowed) {
            return ['action' => 'skipped', 'message' => "{$side} setup, but new lab entries are off (paused, stopped or the trial ended)."];
        }

        $d = $side === 'LONG' ? 1 : -1;
        $price = (float) ($this->market->tickerPrice($symbol) ?: $c['closes'][$k]);
        $stopAtr = $type === 'tsmom' ? (float) $p['trail'] : (float) ($p['stop'] ?? 2.0);
        $sl = $price - $d * $stopAtr * (float) $atr[$k];
        $account = TradingAccount::getForMode($mode);

        $result = $this->executor->executeSignal([
            'symbol' => $symbol,
            'side' => $side,
            'price' => $price,
            'initial_sl' => $sl,
            'setup' => self::SETUP,
            'setup_label' => sprintf('Lab %s 4h (1-week live trial)', $type === 'tsmom' ? 'momentum' : 'squeeze breakout'),
            'interval' => self::INTERVAL,
            'grade' => 'B',
            'indicators' => ['atr' => (float) $atr[$k]],
            'fixed_margin' => $this->marginFor($account, $price, $sl),
            'fixed_leverage' => (int) config('trading.lab.leverage', 5),
        ], $mode);

        if ($result['status'] !== 'opened') {
            $this->telegram->send(sprintf('⏭ <b>Lab %s %s</b> (4h) not traded: %s', e($symbol), $side, e($result['message'])));

            return ['action' => 'skipped', 'message' => $result['message']];
        }

        return ['action' => 'opened', 'message' => $result['message']];
    }

    /**
     * Margin so the stop loses about lab.risk_pct of equity, never below the $5 Binance minimum notional.
     */
    public function marginFor(TradingAccount $account, float $price, float $sl): float
    {
        $lev = max(1, (int) config('trading.lab.leverage', 5));
        $stopPct = abs($price - $sl) / $price;
        $risk = max(0.0, (float) $account->balance) * (float) config('trading.lab.risk_pct', 3.0) / 100;
        $notional = $stopPct > 0 ? $risk / $stopPct : 0.0;
        $notional = min(max($notional, 5.5), max(0.0, (float) $account->balance) * $lev);

        return round($notional / $lev, 2);
    }

    protected function momentum(array $c, int $k, int $bars): float
    {
        return (float) $c['closes'][$k] / (float) $c['closes'][$k - $bars] - 1;
    }

    /**
     * @param  array<string, mixed>  $p
     */
    public function tsmomSide(array $c, int $k, array $p): ?string
    {
        $fast = $this->momentum($c, $k, (int) $p['fast']);
        $slow = $this->momentum($c, $k, min((int) $p['slow'], $k));

        return $fast > 0 && $slow > 0 ? 'LONG' : ($fast < 0 && $slow < 0 ? 'SHORT' : null);
    }

    /**
     * @param  array<string, mixed>  $p
     */
    public function squeezeSide(array $c, int $k, array $p): ?string
    {
        $closes = $c['closes'];
        $ema200 = Indicators::ema($closes, 200)[$k] ?? null;
        $bb = Indicators::bbWidthPercent($closes, 20, 2.0);
        $window = array_values(array_filter(array_slice($bb, $k - 100, 100), fn ($x) => $x !== null));
        if ($ema200 === null || count($window) < 50) {
            return null;
        }
        sort($window);
        $threshold = $window[(int) floor((float) $p['sqz'] * (count($window) - 1))];
        $squeezed = false;
        for ($j = $k - 5; $j < $k; $j++) {
            $squeezed = $squeezed || ($bb[$j] !== null && $bb[$j] <= $threshold);
        }
        if (! $squeezed) {
            return null;
        }
        $len = (int) $p['len'];
        $high = max(array_slice($c['highs'], $k - $len, $len));
        $low = min(array_slice($c['lows'], $k - $len, $len));
        $close = (float) $closes[$k];

        return $close > $high && $close > $ema200 ? 'LONG' : ($close < $low && $close < $ema200 ? 'SHORT' : null);
    }
}
