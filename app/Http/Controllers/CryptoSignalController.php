<?php

namespace App\Http\Controllers;

use App\Models\CryptoSignal;
use App\Models\Trade;
use App\Models\TradingAccount;
use App\Models\User;
use App\Services\Crypto\BinanceClient;
use App\Services\Crypto\Indicators;
use App\Services\Notifications\SignalAlerts;
use App\Services\Strategy\ChartOverlayBuilder;
use App\Services\Strategy\SetupStats;
use App\Services\Strategy\Signal;
use App\Services\Strategy\SignalLedger;
use App\Services\Strategy\SignalModel;
use App\Services\Strategy\SymbolAnalyzer;
use App\Services\Strategy\Watchlist;
use App\Services\Trading\RiskManager;
use App\Services\Trading\TradingModeManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Throwable;

/**
 * "SignalAlgo Pro v4" chart: StrategyEngine signals with AI confidence, measured outcomes,
 * trade plan overlays and the Signal Inspector. Read-only: viewing a chart never places orders.
 */
class CryptoSignalController extends Controller
{
    public const CHART_INTERVALS = ['15m', '1h', '4h'];

    public function __construct(
        protected SymbolAnalyzer $analyzer,
        protected ChartOverlayBuilder $overlays,
        protected SetupStats $setupStats,
        protected SignalModel $model,
        protected RiskManager $riskManager,
        protected TradingModeManager $modeManager
    ) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = Auth::user();
        $market = (string) config('crypto.market', 'futures');

        $cryptoConfig = [
            'symbols' => config('crypto.symbols', []),
            'market' => $market,
            'market_label' => $market === 'spot' ? 'Binance Spot' : 'Binance USDⓈ-M Futures',
            'interval' => (string) $request->query('interval', config('trading.strategy.base_interval', '1h')),
            'intervals' => self::CHART_INTERVALS,
            'initial_symbol' => strtoupper((string) $request->query('symbol', '')),
            'telegram_ready' => filled(config('trading.telegram.bot_token')) && filled(config('trading.telegram.chat_id')),
        ];

        return view('crypto.dashboard', [
            'user' => $user,
            'cryptoConfig' => $cryptoConfig,
            'allUsers' => $user->isAdmin() ? User::orderBy('created_at', 'desc')->get() : collect([$user]),
            'recentAlerts' => CryptoSignal::recent()->take(6)->get(),
            'monitoredCoins' => Watchlist::symbols(),
            'tradingMode' => $this->modeManager->activeMode(),
        ]);
    }

    /**
     * Chart data + Signal Inspector for one symbol.
     */
    public function analyze(Request $request, BinanceClient $market): JsonResponse
    {
        $symbol = $this->normalizeSymbol((string) $request->query('symbol', 'BTCUSDT'));
        $interval = strtolower((string) $request->query('interval', config('trading.strategy.base_interval', '1h')));
        if (! in_array($interval, self::CHART_INTERVALS, true)) {
            $interval = '1h';
        }

        try {
            $analysis = $this->analyzer->analyze($symbol, $interval, lookback: 300);
            $chart = $this->overlays->build($analysis['candles'], $analysis['signals'], $interval);
            $activeSignal = $this->activeSignal($chart['signal_history'], (int) $request->query('signal_time', 0));
            $mode = $this->modeManager->activeMode();
            $state = $analysis['state'];
            $closes = $analysis['candles']['closes'];

            return response()->json(array_merge($chart, [
                'success' => true,
                'symbol' => $symbol,
                'tv_symbol' => 'BINANCE:'.$symbol.'.P',
                'interval' => $interval,
                'regime_interval' => SymbolAnalyzer::regimeInterval($interval),
                'market' => $market->getMarketLabel(),
                'price' => (float) end($closes),
                'mode' => $mode,
                'state' => $state,
                'signal' => $activeSignal,
                'sizing' => $activeSignal !== null ? $this->sizing($activeSignal, $mode) : null,
                'setup_stats' => $activeSignal !== null ? [
                    'd30' => $this->setupStats->forSetup($activeSignal['setup'], null, 30),
                    'd90' => $this->setupStats->forSetup($activeSignal['setup'], null, 90),
                ] : null,
                'all_setup_stats' => $this->setupStats->all(),
                'model' => $this->model->metrics(),
                'mtf' => $this->multiTimeframe($symbol, $market),
                'active_trade' => $this->activeTrade($symbol),
                'btc_macro' => [
                    'state' => $state['btc'] ?? 'n/a',
                    'trend' => match ($state['btc'] ?? '') {
                        'BTC uptrend', 'BTC firm' => 'BULLISH',
                        'BTC downtrend', 'BTC soft' => 'BEARISH',
                        default => 'NEUTRAL',
                    },
                ],
                'diagnostics' => [
                    'rejection' => $activeSignal === null ? ($state['reason'] ?? null) : null,
                    'rsi' => $state['rsi'] ?? null,
                    'adx' => $state['adx'] ?? null,
                    'atr_pct' => $state['atr_pct'] ?? null,
                    'volume_ratio' => $activeSignal['indicators']['volume_ratio'] ?? null,
                ],
                'monitored_coins' => Watchlist::symbols(),
                'binance_url' => "https://www.binance.com/en/futures/{$symbol}",
            ]));
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => "Failed to analyze {$symbol}: ".$e->getMessage()], 422);
        }
    }

    /**
     * Send the current signal for a symbol to Telegram on demand.
     */
    public function sendAlert(Request $request, SignalLedger $ledger, SignalAlerts $alerts): JsonResponse
    {
        $symbol = $this->normalizeSymbol((string) $request->input('symbol', 'BTCUSDT'));
        $interval = (string) $request->input('interval', config('trading.strategy.base_interval', '1h'));

        try {
            $analysis = $this->analyzer->analyze($symbol, $interval, lookback: 3);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Error: '.$e->getMessage()], 422);
        }

        /** @var Signal|null $signal */
        $signal = collect($analysis['signals'])->reverse()->first(fn (Signal $s): bool => time() - $s->time <= 2 * 3600 + 300);

        if ($signal === null) {
            return response()->json(['success' => false, 'message' => "No current signal on {$symbol} ({$interval}). ".($analysis['state']['reason'] ?? '')], 422);
        }

        if (! $signal->isTradable()) {
            return response()->json(['success' => false, 'message' => 'Signal did not pass: '.implode('; ', $signal->failedFilters())], 422);
        }

        $record = $ledger->record($signal, 'manual_alert');
        $sent = $alerts->announceSignal($signal, $record, null);

        return response()->json([
            'success' => $sent,
            'message' => $sent ? "{$signal->side} {$symbol} alert sent to Telegram." : 'Alert not sent: Telegram is not configured, the grade is below the alert threshold, or it was already sent.',
        ], $sent ? 200 : 422);
    }

    /**
     * The signal to draw as the current trade plan: the requested one, or the newest still-open signal.
     *
     * @param  array<int, array<string, mixed>>  $history
     * @return array<string, mixed>|null
     */
    protected function activeSignal(array $history, int $requestedTime): ?array
    {
        $candidates = array_reverse($history);

        if ($requestedTime > 0) {
            foreach ($candidates as $signal) {
                if ($signal['time'] === $requestedTime) {
                    return $this->withLegacyFields($signal);
                }
            }
        }

        // The trade plan shows real strategy signals only; shadow setups are tracked, not traded.
        foreach ($candidates as $signal) {
            if (! $signal['is_shadow'] && $signal['outcome'] === 'OPEN' && time() - $signal['time'] <= 48 * 3600) {
                return $this->withLegacyFields($signal);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $signal
     * @return array<string, mixed>
     */
    protected function withLegacyFields(array $signal): array
    {
        $entry = (float) $signal['entry'];
        $pct = fn (float $price): float => $entry > 0 ? round(abs($price - $entry) / $entry * 100, 2) : 0.0;

        return array_merge($signal, [
            'side' => $signal['order_side'],
            'direction' => $signal['side'],
            'score' => $signal['ai_probability'] !== null ? (int) round($signal['ai_probability'] * 100) : null,
            'candle_close_time' => $signal['time'] * 1000,
            'perpetual_options' => [
                'margin_mode' => 'Isolated Margin',
                'risk_per_trade' => config('trading.sizing.risk_per_trade_pct', 2.0).'% of equity at the stop',
                'risk_reward' => '1 : '.$signal['risk_reward'],
                'sl_pct' => $signal['sl_pct'],
                'tp1_pct' => $pct((float) $signal['tp1']),
                'tp2_pct' => $pct((float) $signal['tp2']),
                'tp3_pct' => $pct((float) $signal['tp3']),
            ],
        ]);
    }

    /**
     * Position calculator for the active signal under the account's risk rules.
     *
     * @param  array<string, mixed>  $signal
     * @return array<string, mixed>
     */
    protected function sizing(array $signal, string $mode): array
    {
        try {
            $account = TradingAccount::getForMode($mode);

            return array_merge($this->riskManager->calculatePositionSize($account, $signal['symbol'], (float) $signal['entry'], (float) $signal['sl']), [
                'mode' => $mode,
                'balance' => $account->balance,
            ]);
        } catch (Throwable $e) {
            return ['allowed' => false, 'reason' => $e->getMessage(), 'mode' => $mode];
        }
    }

    /**
     * Trend direction on 15m / 1h / 4h / 1d (close vs EMA50, EMA21 vs EMA50).
     *
     * @return array<int, array{interval: string, trend: string}>
     */
    protected function multiTimeframe(string $symbol, BinanceClient $market): array
    {
        return Cache::remember("chart:mtf:{$symbol}", 60, function () use ($symbol, $market): array {
            $out = [];
            foreach (['15m', '1h', '4h', '1d'] as $interval) {
                try {
                    $closes = array_slice($market->klines($symbol, $interval, 120)['closes'], 0, -1);
                    $ema21 = Indicators::ema($closes, 21);
                    $ema50 = Indicators::ema($closes, 50);
                    $last = count($closes) - 1;
                    $trend = match (true) {
                        $ema50[$last] === null => 'n/a',
                        $closes[$last] > $ema50[$last] && $ema21[$last] > $ema50[$last] => 'UP',
                        $closes[$last] < $ema50[$last] && $ema21[$last] < $ema50[$last] => 'DOWN',
                        default => 'FLAT',
                    };
                } catch (Throwable) {
                    $trend = 'n/a';
                }
                $out[] = ['interval' => $interval, 'trend' => $trend];
            }

            return $out;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function activeTrade(string $symbol): ?array
    {
        $trade = Trade::where('symbol', $symbol)->where('status', 'OPEN')->orderByDesc('opened_at')->first();

        return $trade === null ? null : [
            'id' => $trade->id,
            'symbol' => $trade->symbol,
            'side' => $trade->side,
            'mode' => $trade->mode,
            'stage' => $trade->stage,
            'entry_price' => (float) $trade->entry_price,
            'initial_sl' => (float) $trade->initial_sl,
            'current_sl' => (float) $trade->current_sl,
            'tp1_price' => (float) $trade->tp1_price,
            'tp2_price' => (float) $trade->tp2_price,
            'tp1_hit' => (bool) $trade->tp1_hit,
            'be_locked' => (bool) $trade->be_locked,
            'margin_used' => (float) $trade->margin_used,
            'leverage' => $trade->leverage,
            'setup_tag' => $trade->setup_tag,
            'opened_at' => $trade->opened_at?->toIso8601String(),
        ];
    }

    protected function normalizeSymbol(string $raw): string
    {
        $symbol = strtoupper(str_replace('.P', '', trim($raw)));

        return str_ends_with($symbol, 'USDT') ? $symbol : $symbol.'USDT';
    }
}
