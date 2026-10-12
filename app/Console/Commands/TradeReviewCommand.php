<?php

namespace App\Console\Commands;

use App\Models\CryptoSignal;
use App\Models\Trade;
use Illuminate\Console\Command;

/**
 * Read-only report of the bot's real trades: how late each entry was against its breakout level,
 * how far price went for and against it, and how it ended. Used to compare live trades with the backtest.
 */
class TradeReviewCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'trade:review {--days=7 : How many days back to look} {--mode= : Only paper or live trades}';

    /**
     * @var string
     */
    protected $description = 'List recent trades with entry lateness, best/worst excursion and result in R';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $trades = Trade::where('opened_at', '>=', now()->subDays($days))
            ->when($this->option('mode'), fn ($q, $mode) => $q->where('mode', strtolower((string) $mode)))
            ->orderBy('opened_at')
            ->get();

        if ($trades->isEmpty()) {
            $this->info("No trades in the last {$days} days.");

            return self::SUCCESS;
        }

        $signals = CryptoSignal::whereIn('id', $trades->map(fn (Trade $t) => $t->meta['signal_id'] ?? null)->filter()->all())
            ->get(['id', 'entry_price', 'features'])
            ->keyBy('id');

        $rows = [];
        $results = [];
        foreach ($trades as $trade) {
            $dir = $trade->side === 'LONG' ? 1 : -1;
            $risk = abs((float) $trade->entry_price - (float) $trade->initial_sl);
            $signal = $signals->get($trade->meta['signal_id'] ?? null) ?? $this->signalFor($trade);
            $edge = (float) ($trade->meta['breakout_box'] ?? ($signal?->features ?? [])['box_edge'] ?? 0);
            $late = $edge > 0 && $risk > 0 ? ((float) $trade->entry_price - $edge) * $dir / $risk : null;
            $slip = $signal && (float) $signal->entry_price > 0 ? ((float) $trade->entry_price / (float) $signal->entry_price - 1) * 100 * $dir : null;
            $best = $dir > 0 ? (float) $trade->highest_price : (float) $trade->lowest_price;
            $worst = $dir > 0 ? (float) $trade->lowest_price : (float) $trade->highest_price;
            $riskUsd = (float) ($trade->meta['risk_usd'] ?? 0);
            $r = $trade->status === 'CLOSED' && $riskUsd > 0 ? (float) $trade->net_pnl / $riskUsd : null;
            if ($r !== null) {
                $results[] = $r;
            }

            $rows[] = [
                $trade->opened_at?->format('m-d H:i'),
                $trade->mode,
                $trade->symbol,
                $trade->setup_tag,
                $trade->side,
                $trade->meta['grade'] ?? '-',
                $this->num($trade->entry_price),
                $late === null ? '-' : sprintf('%+.2fR', $late),
                $slip === null ? '-' : sprintf('%+.2f%%', $slip),
                $risk > 0 ? sprintf('%.2f%%', $risk / (float) $trade->entry_price * 100) : '-',
                $risk > 0 && $best > 0 ? sprintf('%+.2fR', ($best - (float) $trade->entry_price) * $dir / $risk) : '-',
                $risk > 0 && $worst > 0 ? sprintf('%+.2fR', ($worst - (float) $trade->entry_price) * $dir / $risk) : '-',
                $trade->status === 'CLOSED' ? (string) $trade->exit_reason : 'OPEN',
                $r === null ? '-' : sprintf('%+.2fR', $r),
                $trade->status === 'CLOSED' ? sprintf('%+.2f', (float) $trade->net_pnl) : '-',
            ];
        }

        $this->table(['Opened (UTC)', 'Mode', 'Symbol', 'Setup', 'Side', 'Grade', 'Entry', 'Past box', 'Slip', 'Stop', 'Best', 'Worst', 'Exit', 'R', 'Net $'], $rows);
        $this->line('Past box = entry beyond the breakout box edge, in R (stop distances). Above ~+0.3R is a late entry.');
        $this->line('Slip = fill vs the signal price (positive = worse fill). Best/Worst = furthest price went for/against the trade.');

        if ($results !== []) {
            $wins = count(array_filter($results, fn (float $r): bool => $r > 0));
            $this->info(sprintf('Closed: %d, wins %d (%.0f%%), avg %+.2fR, total %+.2fR, net $%+.2f',
                count($results), $wins, $wins / count($results) * 100, array_sum($results) / count($results), array_sum($results),
                (float) $trades->where('status', 'CLOSED')->sum('net_pnl')));
        }

        return self::SUCCESS;
    }

    /**
     * The signal a trade came from when its id was not stored: same coin, setup and side, recorded just before the fill.
     */
    protected function signalFor(Trade $trade): ?CryptoSignal
    {
        if ($trade->opened_at === null) {
            return null;
        }

        return CryptoSignal::where('symbol', $trade->symbol)
            ->where('setup', $trade->setup_tag)
            ->whereIn('side', $trade->side === 'LONG' ? ['LONG', 'BUY'] : ['SHORT', 'SELL'])
            ->whereBetween('created_at', [$trade->opened_at->copy()->subMinutes(3), $trade->opened_at->copy()->addMinute()])
            ->latest('created_at')
            ->first(['id', 'entry_price', 'features']);
    }

    protected function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, $value >= 100 ? 2 : 6, '.', ''), '0'), '.');
    }
}
