<?php

namespace App\Http\Controllers;

use App\Models\Trade;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TradeHistoryController extends Controller
{
    /**
     * Display the full trade history ledger with analytics and filters.
     */
    public function index(Request $request): View
    {
        $mode = $request->query('mode', config('trading.mode', 'paper'));
        $symbol = $request->query('symbol');
        $outcome = $request->query('outcome', 'all');
        $dateRange = $request->query('range', 'all');

        $query = Trade::where('mode', $mode)
            ->where('status', 'CLOSED');

        // Filter by Coin / Symbol
        if (! empty($symbol)) {
            $query->where('symbol', strtoupper($symbol));
        }

        // Filter by Outcome
        if ($outcome === 'wins') {
            $query->where('realized_pnl', '>', 0);
        } elseif ($outcome === 'losses') {
            $query->where('realized_pnl', '<=', 0);
        }

        // Filter by Date Range
        if ($dateRange === 'today') {
            $query->whereDate('closed_at', Carbon::today());
        } elseif ($dateRange === '7days') {
            $query->where('closed_at', '>=', Carbon::now()->subDays(7));
        } elseif ($dateRange === '30days') {
            $query->where('closed_at', '>=', Carbon::now()->subDays(30));
        }

        // Clone query for computing comprehensive summary statistics before pagination
        $statsQuery = clone $query;
        $allMatching = $statsQuery->get();

        $totalTrades = $allMatching->count();
        $winningTrades = $allMatching->where('realized_pnl', '>', 0)->count();
        $losingTrades = $allMatching->where('realized_pnl', '<=', 0)->count();
        $winRate = $totalTrades > 0 ? round(($winningTrades / $totalTrades) * 100, 2) : 0.0;
        $netPnl = round($allMatching->sum('realized_pnl'), 4);
        $totalFees = round($allMatching->sum('fee_paid'), 4);

        $grossProfit = (float) $allMatching->where('realized_pnl', '>', 0)->sum('realized_pnl');
        $grossLoss = abs((float) $allMatching->where('realized_pnl', '<=', 0)->sum('realized_pnl'));
        $profitFactor = $grossLoss > 0 ? round($grossProfit / $grossLoss, 2) : ($grossProfit > 0 ? 99.9 : 0.0);

        $bestTrade = $allMatching->max('realized_pnl') ?? 0.0;
        $worstTrade = $allMatching->min('realized_pnl') ?? 0.0;

        // Paginated results (25 per page)
        $trades = $query->orderByDesc('closed_at')->paginate(25)->withQueryString();

        // Get list of all distinct traded coins for dropdown
        $availableCoins = Trade::where('mode', $mode)
            ->where('status', 'CLOSED')
            ->distinct()
            ->pluck('symbol')
            ->sort()
            ->values();

        return view('dashboard.history', [
            'trades' => $trades,
            'mode' => $mode,
            'selectedSymbol' => $symbol,
            'selectedOutcome' => $outcome,
            'selectedRange' => $dateRange,
            'availableCoins' => $availableCoins,
            'stats' => [
                'total_trades' => $totalTrades,
                'winning_trades' => $winningTrades,
                'losing_trades' => $losingTrades,
                'win_rate' => $winRate,
                'net_pnl' => $netPnl,
                'profit_factor' => $profitFactor,
                'best_trade' => round((float) $bestTrade, 2),
                'worst_trade' => round((float) $worstTrade, 2),
                'total_fees' => $totalFees,
            ],
        ]);
    }

    /**
     * Export trade ledger to downloadable CSV.
     */
    public function exportCsv(Request $request): StreamedResponse
    {
        $mode = $request->query('mode', config('trading.mode', 'paper'));
        $symbol = $request->query('symbol');

        $query = Trade::where('mode', $mode)->where('status', 'CLOSED');
        if (! empty($symbol)) {
            $query->where('symbol', strtoupper($symbol));
        }

        $trades = $query->orderByDesc('closed_at')->get();

        $filename = 'afte-trade-history-'.strtolower($mode).'-'.date('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($trades): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Trade ID',
                'Coin / Symbol',
                'Side',
                'Mode',
                'Entry Price',
                'Exit Price',
                'Quantity',
                'Margin (USD)',
                'Leverage',
                'Realized PnL (USD)',
                'ROE (%)',
                'Fee Paid (USD)',
                'Exit Reason',
                'Opened At',
                'Closed At',
                'Duration (Minutes)',
            ]);

            foreach ($trades as $t) {
                $duration = ($t->opened_at && $t->closed_at)
                    ? round($t->opened_at->diffInMinutes($t->closed_at), 1)
                    : 0;

                fputcsv($handle, [
                    $t->id,
                    $t->symbol,
                    $t->side,
                    strtoupper($t->mode),
                    $t->entry_price,
                    $t->exit_price ?? 0,
                    $t->quantity,
                    $t->margin_used,
                    $t->leverage.'x',
                    $t->realized_pnl,
                    $t->pnl_percent.'%',
                    $t->fee_paid,
                    $t->exit_reason,
                    $t->opened_at?->toDateTimeString(),
                    $t->closed_at?->toDateTimeString(),
                    $duration,
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
