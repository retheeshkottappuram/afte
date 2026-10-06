<?php

namespace App\Console\Commands;

use App\Models\CryptoSignal;
use App\Models\Trade;
use App\Services\Strategy\SetupStats;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Read-only report: what the auto-trader did with recent signals, and how the skipped ones actually ended.
 * Shows which rule is blocking winners (or correctly blocking losers) using the server's real data.
 */
class DiagnoseTradingCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'trade:diagnose {--days=7 : How many days back to look}';

    /**
     * @var string
     */
    protected $description = 'Report auto-trader decisions on recent signals and how the skipped signals ended';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $signals = CryptoSignal::where('source', '!=', 'backtest')
            ->where('sent_at', '>=', now()->subDays($days))
            ->get(['symbol', 'interval', 'setup', 'side', 'passed_filters', 'outcome', 'r_multiple', 'auto_trade_status', 'telegram_sent', 'source']);

        $this->info("Signals recorded in the last {$days} days: {$signals->count()} (".$signals->where('passed_filters', true)->count().' passed the market filters, '.$signals->where('telegram_sent', true)->count().' sent to Telegram)');

        $this->newLine();
        $this->info('What the auto-trader decided, and how those signals ended (R after fees, as if entered at the signal price)');
        $this->table(['Decision', 'Signals', 'Resolved', 'Win %', 'Avg R', 'Total R'], $this->rows(
            $signals->groupBy(fn (CryptoSignal $s): string => self::decision($s->auto_trade_status, (bool) $s->passed_filters))
        ));

        $this->info('By setup (signals that passed the market filters)');
        $this->table(['Setup', 'Signals', 'Resolved', 'Win %', 'Avg R', 'Total R'], $this->rows(
            $signals->where('passed_filters', true)->groupBy(fn (CryptoSignal $s): string => $s->setup.' '.$s->interval)
        ));

        $trades = Trade::where('status', 'CLOSED')->where('closed_at', '>=', now()->subDays($days))->orderBy('closed_at')->get();
        $this->info("Closed bot trades in the last {$days} days: {$trades->count()}");
        if ($trades->isNotEmpty()) {
            $this->table(['Closed (UTC)', 'Mode', 'Symbol', 'Side', 'Setup', 'Exit', 'Net PnL $'], $trades->map(fn (Trade $t): array => [
                (string) $t->closed_at, $t->mode, $t->symbol, $t->side, $t->setup_tag, $t->exit_reason, round((float) $t->net_pnl, 4),
            ])->all());
        }

        $this->line('Paste this whole output back to get the strategy reviewed against real results.');

        return self::SUCCESS;
    }

    /**
     * Group key for a recorded decision: "taken", the skip reason without numbers, or why it never reached the bot.
     */
    public static function decision(?string $status, bool $passedFilters): string
    {
        $status = trim((string) preg_replace('/^\[\w+\] /', '', (string) $status));

        if ($status === '') {
            return $passedFilters ? '(not handled by the engine: earlier candle / manual scan)' : '(filters failed: never an entry)';
        }
        if (! str_starts_with($status, 'skipped: ')) {
            return $status === 'taken' ? 'TAKEN' : mb_substr($status, 0, 60);
        }

        return 'skipped: '.rtrim(mb_substr((string) preg_replace('/\s*\(.*$|:.*$/', '', substr($status, 9)), 0, 60), '. ');
    }

    /**
     * @param  Collection<string, Collection<int, CryptoSignal>>  $groups
     * @return array<int, array<int, mixed>>
     */
    protected function rows(Collection $groups): array
    {
        return $groups->map(function (Collection $group, string $key): array {
            $r = $group->whereIn('outcome', SetupStats::RESOLVED)->whereNotNull('r_multiple')->pluck('r_multiple')->map(fn ($v): float => (float) $v)->values()->all();
            $summary = SetupStats::summarize($r);

            return [$key, $group->count(), $summary['n'], $summary['win_rate'] ?? '-', $summary['expectancy'] ?? '-', round(array_sum($r), 2)];
        })->sortByDesc(fn (array $row): int => $row[1])->values()->all();
    }
}
