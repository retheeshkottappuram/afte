<?php

namespace App\Console\Commands;

use App\Services\Trading\DayRangeTrader;
use Illuminate\Console\Command;

/**
 * Shows today's Day dip BUY levels per coin (the trade engine sends and trades them; this only lists them).
 */
class DayRangeScanCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'day-range:scan {--dry-run : List the levels without sending Telegram}';

    /**
     * @var string
     */
    protected $description = 'List today\'s Day dip BUY levels (buy / target / stop) for the Watchlist coins';

    public function handle(DayRangeTrader $trader): int
    {
        $now = now()->getTimestampMs();
        $rows = $this->option('dry-run')
            ? array_map(fn (string $s): array => $trader->levels($s, $now), DayRangeTrader::symbols())
            : $trader->plan($now);

        $this->table(['Coin', 'Day open', 'ADR', 'Buy at', 'Target', 'Stop', 'Note'], array_map(fn (array $r): array => [
            $r['symbol'], $r['open'] ?? '-', isset($r['adr']) ? round($r['adr'], 6) : '-', $r['buy'] ?? '-', $r['target'] ?? '-', $r['sl'] ?? '-', $r['reason'] ?? 'in a daily uptrend',
        ], $rows));
        $this->line('Bot: '.(DayRangeTrader::enabled() ? 'ON' : 'off').' · risk '.config('trading.day_range.risk_pct').'% · max open '.config('trading.day_range.max_open'));

        return self::SUCCESS;
    }
}
