<?php

namespace App\Http\Controllers;

use App\Models\CryptoSignal;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AlertHistoryController extends Controller
{
    /**
     * Display a paginated history of all signals sent to Telegram.
     */
    public function index(Request $request): View
    {
        $symbol = (string) $request->query('symbol', '');
        $side = (string) $request->query('side', '');
        $interval = (string) $request->query('interval', '');
        $setupType = (string) $request->query('setup_type', '');

        $query = CryptoSignal::query()
            ->bySymbol($symbol)
            ->bySide($side)
            ->byInterval($interval)
            ->bySetupType($setupType)
            ->recent();

        $alerts = $query->paginate(15)->withQueryString();

        // High-level statistics
        $stats = [
            'total' => CryptoSignal::count(),
            'buy' => CryptoSignal::where('side', 'BUY')->count(),
            'sell' => CryptoSignal::where('side', 'SELL')->count(),
            'grade_a' => CryptoSignal::where('grade', 'A')->count(),
        ];

        // Available symbols and intervals for filter dropdowns
        $availableSymbols = CryptoSignal::query()
            ->select('symbol')
            ->distinct()
            ->orderBy('symbol')
            ->pluck('symbol')
            ->toArray();

        $availableIntervals = CryptoSignal::query()
            ->select('interval')
            ->distinct()
            ->orderBy('interval')
            ->pluck('interval')
            ->toArray();

        return view('alerts.index', compact(
            'alerts',
            'stats',
            'symbol',
            'side',
            'interval',
            'setupType',
            'availableSymbols',
            'availableIntervals'
        ));
    }
}
