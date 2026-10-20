<?php

namespace App\Console\Commands;

use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Crypto\BinanceClient;
use App\Services\Crypto\CandleSanitizer;
use App\Services\Strategy\StrategyEngine;
use App\Services\Trading\SwingSarTrader;
use App\Services\Trading\TradingModeManager;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only: every swing flip the chart printed on the SAR coins in the last N hours, and what Swing SAR did
 * with it (the trade it opened, or the most likely reason it did not). Answers "the chart showed BUY, why no trade?".
 */
class SarCheckCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'trade:sar-check {--hours=24 : How far back to look}';

    /**
     * @var string
     */
    protected $description = 'List recent chart swing flips and what the Swing SAR bot did with each';

    public function handle(BinanceClient $market, StrategyEngine $engine, SwingSarTrader $sar, TradingModeManager $modeManager): int
    {
        $mode = $modeManager->activeMode();
        $hours = max(1, min(240, (int) $this->option('hours')));
        $since = now()->subHours($hours)->timestamp;
        $maxOpen = (int) config('trading.swing_sar.max_open', 2);

        $bars = (int) ceil($hours * 3600 / SwingSarTrader::intervalSeconds());
        $this->info(sprintf('Swing SAR %s · %s candles · mode %s · %s · config cached: %s',
            SwingSarTrader::enabled() ? 'ON' : 'OFF (set SWING_SAR_ENABLED=true, then php artisan config:clear)', SwingSarTrader::interval(), strtoupper($mode),
            config('trading.swing_sar.confirmed_only', true) ? 'confirmed flips only' : 'every flip incl. "?"',
            app()->configurationIsCached() ? 'yes' : 'no'));
        $account = TradingAccount::getForMode($mode);
        if (! $account->canTrade()) {
            $this->warn('  New entries are blocked now: '.($account->kill_switch ? 'kill switch on' : (! $account->is_running ? 'auto-trading stopped' : 'paused until '.$account->paused_until)));
        }

        $rows = [];
        foreach (SwingSarTrader::symbols() as $symbol) {
            try {
                $c = CandleSanitizer::onlyClosedCandles($market->klines($symbol, SwingSarTrader::interval(), min(1500, max(300, $bars + 100))), now()->getTimestampMs());
            } catch (Throwable $e) {
                $rows[] = [$symbol, '-', '-', '-', '-', 'candles failed: '.$e->getMessage()];

                continue;
            }
            $from = max(60, count($c['closes'] ?? []) - $bars - 1);
            foreach ($engine->swingSignals($c, $from, true) as $flip) {
                if ($flip['time'] < $since) {
                    continue;
                }
                $rows[] = [
                    $symbol,
                    Carbon::createFromTimestamp($flip['time'])->format('m-d H:i'),
                    ($flip['side'] === 'LONG' ? 'BUY' : 'SELL').($flip['confirmed'] ? '' : '?'),
                    $flip['entry'],
                    sprintf('%.2f%%', $flip['sl_pct']),
                    $this->outcome($sar, $mode, $symbol, $flip, $maxOpen),
                ];
            }
        }

        if ($rows === []) {
            $this->line("No swing flips on the SAR coins in the last {$hours}h.");

            return self::SUCCESS;
        }
        $this->table(['Coin', 'Candle close', 'Flip', 'Price', 'Stop', 'What the bot did'], $rows);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $flip
     */
    protected function outcome(SwingSarTrader $sar, string $mode, string $symbol, array $flip, int $maxOpen): string
    {
        $at = Carbon::createFromTimestamp($flip['time']);
        $window = intdiv(SwingSarTrader::intervalSeconds(), 60) + 30;
        $sarTrades = Trade::where('mode', $mode)->where('setup_tag', SwingSarTrader::SETUP);

        $opened = (clone $sarTrades)->where('symbol', $symbol)->where('side', $flip['side'])
            ->whereBetween('opened_at', [$at->copy()->subMinutes(5), $at->copy()->addMinutes($window)])->first();
        if ($opened !== null) {
            return "opened trade #{$opened->id} ({$opened->status}".($opened->status === 'CLOSED' ? ', $'.round((float) $opened->net_pnl, 2) : '').')';
        }

        $notes = [];
        $closed = (clone $sarTrades)->where('symbol', $symbol)->where('exit_reason', 'OPPOSITE_SIGNAL')
            ->whereBetween('closed_at', [$at->copy()->subMinutes(5), $at->copy()->addMinutes($window)])->first();
        if ($closed !== null) {
            $notes[] = "closed #{$closed->id}";
        }

        $openAt = fn ($q) => $q->where('opened_at', '<=', $at)->where(fn ($w) => $w->whereNull('closed_at')->orWhere('closed_at', '>', $at));
        $block = $sar->entryBlock($symbol, $flip, true, ($flip['time'] + 60) * 1000);
        if ($block !== null) {
            $notes[] = 'skipped: '.$block;
        } elseif ($openAt(Trade::where('mode', $mode)->where('symbol', $symbol)->where('setup_tag', '!=', SwingSarTrader::SETUP))->exists()) {
            $notes[] = 'skipped: the main bot already had a trade on this coin';
        } elseif ($openAt((clone $sarTrades)->where('symbol', '!=', $symbol))->count() >= $maxOpen) {
            $notes[] = "skipped: all {$maxOpen} SAR slots were in use";
        } elseif (! SwingSarTrader::enabled()) {
            $notes[] = 'not traded: Swing SAR is off';
        } else {
            $notes[] = 'not traded: paused / daily stop / margin / error, or the coin or interval was different then (see the engine log or the Telegram ⏭ message)';
        }

        return implode(' · ', $notes);
    }
}
