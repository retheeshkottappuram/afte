<?php

namespace App\Http\Controllers;

use App\Models\CryptoSignal;
use App\Services\Notifications\SignalAlerts;
use App\Services\Notifications\TelegramGateway;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AlertHistoryController extends Controller
{
    /**
     * Signals recorded by the scanner, with whether each one was sent to Telegram (and why not).
     */
    public function index(Request $request, SignalAlerts $alerts, TelegramGateway $gateway): View
    {
        $symbol = (string) $request->query('symbol', '');
        $side = (string) $request->query('side', '');
        $interval = (string) $request->query('interval', '');
        $setupType = (string) $request->query('setup_type', '');
        $delivery = in_array($request->query('delivery'), ['sent', 'not_sent', 'all'], true) ? (string) $request->query('delivery') : 'sent';

        $query = CryptoSignal::query()
            ->where('source', '!=', 'backtest')
            ->bySymbol($symbol)
            ->bySide($side)
            ->byInterval($interval)
            ->bySetupType($setupType)
            ->when($delivery === 'sent', fn ($q) => $q->where('telegram_sent', true))
            ->when($delivery === 'not_sent', fn ($q) => $q->where('telegram_sent', false))
            ->recent();

        $alerts_ = $query->paginate(15)->withQueryString();
        $decisions = $alerts_->getCollection()->mapWithKeys(fn (CryptoSignal $a): array => [$a->id => $alerts->sendDecision($a)]);

        $live = CryptoSignal::where('source', '!=', 'backtest');
        $stats = [
            'recorded' => (clone $live)->count(),
            'sent' => (clone $live)->where('telegram_sent', true)->count(),
            'sent_24h' => (clone $live)->where('telegram_sent', true)->where('created_at', '>=', now()->subDay())->count(),
            'grade_a' => (clone $live)->where('grade', 'A')->count(),
        ];

        return view('alerts.index', [
            'alerts' => $alerts_,
            'decisions' => $decisions,
            'stats' => $stats,
            'telegram' => $gateway->health(),
            'symbol' => $symbol,
            'side' => $side,
            'interval' => $interval,
            'setupType' => $setupType,
            'delivery' => $delivery,
        ]);
    }
}
