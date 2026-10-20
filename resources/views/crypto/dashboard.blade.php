@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 py-5 sm:py-10 overflow-x-hidden">
    <!-- Header banner -->
    <div class="p-4 sm:p-8 rounded-2xl bg-gradient-to-r from-slate-900 via-slate-850 to-slate-900 border border-slate-800 shadow-xl mb-6 sm:mb-8 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl sm:text-3xl font-extrabold text-white tracking-tight">SignalAlgo Pro: Signals &amp; Charts</h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                    Live Engine
                </span>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider bg-cyan-950 text-cyan-300 border border-cyan-800/80">
                    {{ $cryptoConfig['market_label'] }}
                </span>
                @if ($user->isAdmin())
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider bg-emerald-950 text-emerald-300 border border-emerald-800/80">
                        Admin
                    </span>
                @endif
            </div>
            <p class="mt-2 text-slate-400 text-xs sm:text-sm break-words">
                Welcome back, <strong class="text-white">{{ $user->name }}</strong>. Live candles from <strong>{{ $cryptoConfig['market_label'] }}</strong>. Charts are read-only: trades are placed only by the background engine or your explicit Place Trade click.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2 sm:gap-3">
            <a href="{{ route('alerts.index') }}" class="px-4 py-2 text-xs font-semibold bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-xl border border-slate-700 shadow transition flex items-center space-x-1.5">
                <span>📡 Alert History</span>
            </a>
            @if ($user->isAdmin())
                <a href="{{ route('admin.users.index') }}" class="px-4 py-2 text-xs font-semibold bg-purple-600 hover:bg-purple-500 text-white rounded-xl shadow transition flex items-center space-x-1.5">
                    <span>+ Manage Users</span>
                </a>
            @endif
        </div>
    </div>

    <!-- Overview Grid -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-6 mb-6 sm:mb-8">
        <!-- Card 1: Timeframes -->
        <div class="p-4 sm:p-6 rounded-2xl bg-slate-900/80 border border-slate-800 shadow min-w-0">
            <div class="text-[10px] sm:text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">Strategy Timeframe</div>
            <div class="text-2xl font-bold text-white flex items-baseline space-x-2">
                <span>{{ $cryptoConfig['interval'] }}</span>
                <span class="text-sm font-normal text-slate-400">/ trend filter {{ \App\Services\Strategy\SymbolAnalyzer::regimeInterval($cryptoConfig['interval']) }}</span>
            </div>
            <p class="text-xs text-cyan-400 mt-2 font-mono">{{ $cryptoConfig['market_label'] }}</p>
        </div>

        <!-- Card 2: Measured Accuracy -->
        <div class="p-4 sm:p-6 rounded-2xl bg-slate-900/80 border border-slate-800 shadow min-w-0">
            <div class="text-[10px] sm:text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">Measured Accuracy (90d)</div>
            <div id="card-accuracy" class="text-2xl font-bold text-emerald-400">--</div>
            <p id="card-accuracy-sub" class="text-xs text-slate-500 mt-2">Win rate of core setups, fees included</p>
        </div>

        <!-- Card 3: Trading Mode -->
        <div class="p-4 sm:p-6 rounded-2xl bg-slate-900/80 border border-slate-800 shadow min-w-0">
            <div class="text-[10px] sm:text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">Engine Trading Mode</div>
            <div class="text-2xl font-bold {{ $tradingMode === 'live' ? 'text-rose-400' : 'text-amber-300' }}">{{ strtoupper($tradingMode) }}</div>
            <p class="text-xs text-slate-500 mt-2">Change it from the trading terminal. Viewing charts never places orders.</p>
        </div>

        <!-- Card 4: Telegram Channel -->
        <div class="p-4 sm:p-6 rounded-2xl bg-slate-900/80 border border-slate-800 shadow min-w-0">
            <div class="text-[10px] sm:text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">Telegram Alerts</div>
            <div class="flex items-center space-x-2 mt-1">
                @if ($cryptoConfig['telegram_ready'])
                    <span class="w-3 h-3 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span class="text-lg font-bold text-emerald-400">Connected</span>
                @else
                    <span class="w-3 h-3 rounded-full bg-amber-400"></span>
                    <span class="text-lg font-bold text-amber-400">Credentials Pending</span>
                @endif
            </div>
            <p class="text-xs text-slate-500 mt-2">
                {{ $cryptoConfig['telegram_ready'] ? 'Verified & active in .env' : 'Configure TELEGRAM_BOT_TOKEN in .env' }}
            </p>
        </div>
    </div>

    <!-- Automated Candle Close Signal Dispatcher (Telegram alerts from the cron engine) -->
    <div class="p-4 sm:p-6 rounded-2xl bg-gradient-to-r from-slate-900 via-indigo-950/40 to-slate-900 border border-indigo-500/30 shadow-2xl mb-6 sm:mb-8 relative overflow-hidden">
        <div class="absolute -right-16 -top-16 w-64 h-64 bg-indigo-500/10 rounded-full blur-3xl pointer-events-none"></div>
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 relative">
            <div class="w-full lg:w-auto min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center px-3 py-1 rounded-lg text-xs font-black tracking-wider bg-indigo-500/20 text-indigo-300 border border-indigo-500/40 uppercase shadow-sm">
                        📡 Telegram Signal Alerts
                    </span>
                    <span id="daemonBadge" class="inline-flex items-center space-x-1.5 px-3 py-1 rounded-full text-xs font-bold bg-slate-800 text-slate-400 border border-slate-700">
                        <span id="daemonDot" class="w-2.5 h-2.5 rounded-full bg-slate-500"></span>
                        <span id="daemonStatusText">Checking...</span>
                    </span>
                </div>
                <h3 class="text-base sm:text-lg font-bold text-white mt-2">
                    Automated Candle Close Signal Dispatcher
                </h3>
                <p class="text-xs text-slate-400 max-w-2xl mt-1">
                    After every 1h candle closes, the background engine scans the liquid market with the same strategy as the auto-trader and sends <strong>Grade A/B tradable signals</strong> to Telegram, plus every signal on your <strong>alert coins</strong>. Each alert's result (TP / SL) is posted as a reply. It runs from the server cron, so no browser is needed.
                </p>
                <div class="grid grid-cols-2 sm:flex sm:flex-wrap items-center gap-2 mt-3 text-[11px] sm:text-xs font-mono text-slate-400" id="daemonStatsRow">
                    <span class="bg-slate-950/60 px-2.5 py-1 rounded-md border border-slate-800">Engine: <strong id="daemonEngine" class="text-slate-200">--</strong></span>
                    <span class="bg-slate-950/60 px-2.5 py-1 rounded-md border border-slate-800">Heartbeat: <strong id="daemonHeartbeat" class="text-cyan-400">--</strong></span>
                    <span class="bg-slate-950/60 px-2.5 py-1 rounded-md border border-slate-800">Last scan: <strong id="daemonLastScan" class="text-slate-200">--</strong></span>
                    <span class="bg-slate-950/60 px-2.5 py-1 rounded-md border border-slate-800">Coins scanned: <strong id="daemonUniverse" class="text-emerald-400">--</strong></span>
                    <span class="bg-slate-950/60 px-2.5 py-1 rounded-md border border-slate-800">Signals last scan: <strong id="daemonSignals" class="text-emerald-400">--</strong></span>
                    <span class="bg-slate-950/60 px-2.5 py-1 rounded-md border border-slate-800">Alerts sent 24h: <strong id="daemonAlerts24h" class="text-indigo-300">--</strong></span>
                    <span class="bg-slate-950/60 px-2.5 py-1 rounded-md border border-slate-800 col-span-2 sm:col-span-1">Telegram: <strong id="daemonTelegram" class="text-slate-200">--</strong></span>
                </div>
                <div id="tradingPauseBar" class="hidden mt-3 p-2.5 rounded-lg bg-amber-950/40 border border-amber-700/60 text-[11px] sm:text-xs text-amber-200 flex flex-col sm:flex-row sm:items-center gap-2">
                    <span id="tradingPauseText" class="flex-1"></span>
                    @if (Auth::user()?->isAdmin())
                        <button type="button" id="tradingPauseBtn" onclick="resumeAutoTrading()" class="px-3 py-1.5 rounded-lg bg-amber-500/20 hover:bg-amber-500/30 border border-amber-500/50 text-amber-200 font-bold whitespace-nowrap">⚡ Reset & Resume Auto-Trading</button>
                    @endif
                </div>
                <div id="daemonCronHint" class="hidden mt-3 p-2.5 rounded-lg bg-rose-950/40 border border-rose-800/60 text-[11px] text-rose-200">
                    The engine is not running. Add this to your hosting panel's cron jobs (every minute):
                    <code id="daemonCronCommand" class="block mt-1 p-2 rounded bg-slate-950 text-slate-200 font-mono break-all select-all"></code>
                </div>
            </div>

            <div class="grid grid-cols-2 sm:flex sm:flex-wrap items-stretch sm:items-center gap-2 w-full lg:w-auto">
                <button type="button" id="btnStartDaemon" onclick="startDaemon()"
                    class="col-span-2 sm:col-span-1 px-4 py-2.5 text-xs sm:text-sm font-bold rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white border border-emerald-500 transition flex items-center justify-center space-x-2 touch-manipulation">
                    <span>🔔</span><span id="btnStartDaemonText">Turn Alerts ON</span>
                </button>
                <button type="button" id="btnStopDaemon" onclick="stopDaemon()"
                    class="hidden col-span-2 sm:col-span-1 px-4 py-2.5 text-xs sm:text-sm font-bold rounded-xl bg-rose-600/80 hover:bg-rose-600 text-white border border-rose-500 transition flex items-center justify-center space-x-2 touch-manipulation">
                    <span>🔕</span><span id="btnStopDaemonText">Turn Alerts OFF</span>
                </button>
                <button type="button" onclick="toggleCoinManager()" title="Coins that always alert"
                    class="px-3 py-2.5 text-xs font-semibold text-emerald-300 hover:text-white bg-slate-800 hover:bg-slate-700 rounded-xl border border-emerald-500/40 transition flex items-center justify-center space-x-1.5 touch-manipulation">
                    <span>🪙</span><span>Alert Coins</span>
                    <span id="daemonCoinCountBadge" class="ml-1 px-1.5 py-0.5 rounded-full bg-emerald-500/20 text-[10px] text-emerald-300 font-mono">0</span>
                </button>
                <button type="button" onclick="sendTestAlert()" id="btnTestAlert" title="Send a test message to Telegram"
                    class="px-3 py-2.5 text-xs font-semibold text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-700 rounded-xl border border-slate-700 transition flex items-center justify-center space-x-1.5 touch-manipulation">
                    <span>✉️</span><span>Test Alert</span>
                </button>
                <button type="button" onclick="pollDaemonStatus()" title="Refresh"
                    class="col-span-2 sm:col-span-1 p-2.5 text-slate-400 hover:text-white bg-slate-800 hover:bg-slate-700 rounded-xl border border-slate-700 transition touch-manipulation flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                </button>
            </div>
        </div>

        <!-- Inline Message / Feedback Box -->
        <div id="daemonInlineFeedback" class="hidden mt-4 p-3.5 rounded-xl text-xs font-medium border flex items-start justify-between">
            <div id="daemonFeedbackContent" class="flex items-start space-x-2"></div>
            <button type="button" onclick="document.getElementById('daemonInlineFeedback').classList.add('hidden')" class="text-slate-400 hover:text-white font-bold text-sm ml-2">&times;</button>
        </div>

        <!-- Alert coins (watchlist) manager -->
        <div id="coinManagerPanel" class="hidden mt-4 p-4 sm:p-5 rounded-xl bg-slate-950/95 border border-emerald-500/30 text-xs shadow-xl">
            <div class="flex flex-wrap justify-between items-center gap-2 mb-3 pb-3 border-b border-slate-800">
                <div class="font-bold text-white flex flex-wrap items-center gap-2 text-sm">
                    <span>🪙 Alert Coins</span>
                    <span id="coinManagerCountBadge" class="text-xs px-2.5 py-0.5 rounded-full bg-emerald-950 text-emerald-300 border border-emerald-800 font-mono">0 coins</span>
                </div>
                <div class="flex items-center space-x-2">
                    <button type="button" onclick="resetMonitoredCoins()" class="px-2.5 py-1.5 text-[11px] font-semibold text-slate-300 hover:text-rose-300 bg-slate-900 border border-slate-700 rounded-lg transition">↺ Reset</button>
                    <button type="button" onclick="toggleCoinManager()" class="text-slate-400 hover:text-white font-bold text-base p-1">&times;</button>
                </div>
            </div>
            <p class="text-[11px] text-slate-400 mb-3">These coins are always scanned, <strong>every</strong> signal on them is sent to Telegram, and the swing bot auto-trades their <strong>confirmed</strong> 15m BUY/SELL flips (half of the fund, up to 4 trades at once). They are also the coin buttons on the chart.</p>
            <form onsubmit="event.preventDefault(); addMonitoredCoin();" class="flex flex-col sm:flex-row gap-2 mb-3">
                <input type="text" id="newCoinInput" placeholder="Coin symbol, e.g. ADA, LINK, DOGEUSDT"
                    class="flex-1 min-w-0 uppercase px-3.5 py-2 text-xs rounded-xl bg-slate-900 text-white placeholder-slate-500 border border-slate-700 focus:outline-none focus:border-emerald-500 font-mono">
                <button type="button" onclick="addMonitoredCoin()" class="px-4 py-2 text-xs font-bold rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white border border-emerald-500 transition">+ Add Coin</button>
            </form>
            <div id="monitoredCoinsPillContainer" class="flex flex-wrap gap-2 min-h-[48px] p-3 rounded-xl bg-slate-900/60 border border-slate-800">
                <span class="text-slate-500 text-xs italic">Loading coins...</span>
            </div>

        </div>
    </div>

    <!-- The Whole-Market Scanner moved to its own page (Market Scanner in the menu) -->
    <a href="{{ route('scanner.index') }}" class="block p-3.5 sm:p-4 rounded-2xl bg-slate-900 border border-slate-800 hover:border-cyan-500/40 mb-6 sm:mb-8 text-xs sm:text-sm text-slate-300 transition">
        ⚡ <strong class="text-cyan-300">Whole-Market Scanner</strong>, breakout watch and setups in play are now on the <strong class="text-white">Market Scanner</strong> page →
    </a>

    <!-- Real-Time SignalAlgo PRO Command Center & Binance Futures Chart -->
    <div class="p-3 sm:p-6 rounded-2xl bg-slate-900/90 border border-slate-800 shadow-2xl mb-6 sm:mb-8">
        <!-- SignalAlgo PRO Header -->
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-5 pb-5 border-b border-slate-800/80">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center px-3 py-1 rounded-lg text-xs font-black tracking-wider bg-gradient-to-r from-emerald-500/20 to-cyan-500/20 text-emerald-400 border border-emerald-500/30 uppercase shadow-sm">
                        ⚡ SignalAlgo Pro v4
                    </span>
                    <span class="inline-flex items-center space-x-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-800 text-slate-300 border border-slate-700">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
                        <span>Perpetual Engine Live</span>
                    </span>
                    <span class="hidden sm:inline text-xs text-slate-400">• Signals print on closed candles only</span>
                </div>
                <h2 class="text-xl sm:text-2xl font-black text-white mt-1.5 tracking-tight flex flex-wrap items-baseline gap-x-2">
                    <span id="headerSymbolTitle">BTCUSDT</span>
                    <span class="text-xs sm:text-sm font-medium text-slate-400">USDⓈ-M Perpetual</span>
                </h2>
            </div>

            <!-- Quick Switcher & Custom Coin Search -->
            <div class="flex flex-wrap items-center gap-2 w-full lg:w-auto min-w-0">
                <div id="headerCoinButtons" class="flex items-center bg-slate-950/80 p-1 rounded-xl border border-slate-800 text-xs shadow-inner overflow-x-auto max-w-full touch-pan-x select-none">
                    @foreach ($monitoredCoins as $quickSymbol)
                        <button type="button" onclick="loadFuturesChart('{{ $quickSymbol }}', true)"
                            class="px-3 py-1.5 rounded-lg text-slate-300 hover:text-white hover:bg-slate-800 font-semibold transition symbol-btn whitespace-nowrap active:scale-95 touch-manipulation cursor-pointer {{ ($quickSymbol === 'BTCUSDT') ? 'bg-emerald-600 text-white shadow' : '' }}"
                            id="btn-{{ $quickSymbol }}">
                            {{ str_replace(['1000', 'USDT'], '', $quickSymbol) }}
                        </button>
                    @endforeach
                </div>

                <!-- Custom Coin Search/Input -->
                <div class="flex items-center space-x-1 w-full sm:w-auto">
                    <input type="text" id="customSymbolInput" placeholder="e.g. WIF, APT"
                        onkeydown="if (event.key === 'Enter') loadCustomFuturesChart();"
                        class="px-3 py-1.5 bg-slate-950 border border-slate-700 rounded-xl text-xs text-white uppercase placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 flex-1 sm:flex-none sm:w-28 min-w-0">
                    <button type="button" onclick="loadCustomFuturesChart()"
                        class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-semibold shadow transition">
                        Load
                    </button>
                </div>
            </div>
        </div>

        <!-- Notification Toast Container -->
        <div id="toastNotification" class="hidden mb-4 p-3 rounded-xl text-xs font-semibold flex items-center justify-between transition-all"></div>

        <!-- SignalAlgo PRO Live Perpetual Setup & Trade Options HUD -->
        <div class="mb-5 p-4 rounded-xl bg-slate-950/90 border border-slate-800/90 shadow-inner">
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 items-center">
                <!-- 1. Active Contract & Live Signal -->
                <div class="p-3 rounded-xl bg-slate-900/80 border border-slate-800">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs font-semibold text-slate-400">SignalAlgo Signal</span>
                        <span id="hud-price" class="text-xs font-mono font-bold text-white">Loading...</span>
                    </div>
                    <div class="flex items-center space-x-2">
                        <div id="hud-signal-badge" class="px-2.5 py-1 rounded-lg text-xs font-black tracking-wide bg-slate-800 text-slate-300 border border-slate-700 flex items-center space-x-1.5">
                            <span class="w-2 h-2 rounded-full bg-slate-400"></span>
                            <span>SCANNING MARKET...</span>
                        </div>
                    </div>
                    <div class="text-[11px] text-slate-400 mt-1.5 flex items-center justify-between">
                        <span>Contract: <strong id="hud-contract" class="text-slate-200">SOLUSDT.P</strong></span>
                        <span id="hud-score-label" class="text-emerald-400 font-bold">--</span>
                    </div>
                    <div class="mt-1.5 pt-1.5 border-t border-slate-800/80 flex items-center justify-between text-[10px]">
                        <span id="hud-btc-macro" class="text-slate-400 font-mono">BTC Macro: <strong class="text-slate-300">Checking...</strong></span>
                        <span id="hud-setup-type" class="text-slate-400">Setup: <strong class="text-slate-300">--</strong></span>
                    </div>
                </div>

                <!-- 2. Perpetual Trade Options -->
                <div class="p-3 rounded-xl bg-slate-900/80 border border-slate-800">
                    <div class="text-xs font-semibold text-slate-400 mb-1 flex items-center justify-between">
                        <span>Trade Plan</span>
                        <span class="text-[10px] px-1.5 py-0.5 rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">Isolated · risk-sized</span>
                    </div>
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <span class="text-slate-500 block text-[10px]">Your size</span>
                            <span id="hud-leverage" class="font-bold text-amber-400 text-xs">--</span>
                        </div>
                        <div>
                            <span class="text-slate-500 block text-[10px]">Risk / Reward</span>
                            <span id="hud-rr" class="font-bold text-emerald-400 text-xs">--</span>
                        </div>
                        <div class="col-span-2 pt-1 border-t border-slate-800 flex justify-between text-[11px]">
                            <span class="text-slate-400">Entry: <code id="hud-entry" class="text-white font-mono">--</code></span>
                            <span class="text-rose-400 font-semibold">SL: <code id="hud-sl" class="text-rose-300 font-mono">--</code></span>
                        </div>
                    </div>
                </div>

                <!-- 3. Take Profit Targets -->
                <div class="p-3 rounded-xl bg-slate-900/80 border border-slate-800">
                    <div class="text-xs font-semibold text-slate-400 mb-1 flex items-center justify-between">
                        <span>Exit Plan</span>
                        <span class="text-[10px] text-slate-400">Breakeven at +1R</span>
                    </div>
                    <div class="space-y-1 text-xs">
                        <div class="flex justify-between items-center text-[11px]">
                            <span class="text-emerald-400 font-medium">TP1: <code id="hud-tp1" class="text-slate-200 font-mono">--</code></span>
                            <span class="text-slate-500 text-[10px]">(book 50%, stop +0.5R)</span>
                        </div>
                        <div class="flex justify-between items-center text-[11px]">
                            <span class="text-emerald-400 font-medium">TP2: <code id="hud-tp2" class="text-slate-200 font-mono">--</code></span>
                            <span class="text-slate-500 text-[10px]">(stop locks at TP1)</span>
                        </div>
                        <div class="flex justify-between items-center text-[11px]">
                            <span class="text-emerald-400 font-medium">TP3: <code id="hud-tp3" class="text-slate-200 font-mono">--</code></span>
                            <span class="text-slate-500 text-[10px]">(runner trails 1.5×ATR)</span>
                        </div>
                    </div>
                </div>

                <!-- 4. Quick Actions -->
                <div class="p-3 rounded-xl bg-slate-900/80 border border-slate-800 flex flex-col justify-between space-y-2">
                    <button type="button" id="btnSendAlert" onclick="triggerTelegramAlert()"
                        class="w-full py-2 px-3 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white font-bold text-xs rounded-xl shadow-lg shadow-emerald-900/30 flex items-center justify-center space-x-1.5 transition">
                        <span>⚡</span>
                        <span>Send Alert to Telegram</span>
                    </button>
                    <div class="flex items-center space-x-2">
                        <a id="btnBinanceFuturesLink" href="https://www.binance.com/en/futures/BTCUSDT" target="_blank" rel="noopener noreferrer"
                            class="flex-1 py-1.5 px-2 bg-slate-800 hover:bg-slate-700 text-amber-400 hover:text-amber-300 font-semibold text-[11px] rounded-lg border border-slate-700 text-center transition flex items-center justify-center space-x-1">
                            <span>Open Futures</span>
                            <span>↗</span>
                        </a>
                        <button type="button" onclick="refreshAnalysis()" title="Refresh SignalAlgo Analysis"
                            class="py-1.5 px-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white rounded-lg border border-slate-700 text-xs transition">
                            🔄
                        </button>
                    </div>
                </div>
            </div>

            <!-- Indicator Confluence Ticker Strip -->
            <div class="mt-3 pt-3 border-t border-slate-800/80 flex flex-wrap items-center justify-between gap-2 text-[11px] text-slate-400">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span>RSI(14): <strong id="hud-rsi" class="text-slate-200">--</strong></span>
                    <span>ADX(14): <strong id="hud-adx" class="text-slate-200">--</strong></span>
                    <span>Volume: <strong id="hud-vol" class="text-slate-200">--</strong></span>
                    <span>ATR: <strong id="hud-atr" class="text-slate-200">--</strong></span>
                </div>
                <div class="text-slate-500 text-[10px]">
                    Analysis Timeframe: <span id="hud-active-tf" class="text-emerald-400 font-bold uppercase">{{ $cryptoConfig['interval'] }}</span> | Trend Filter: <span id="hud-active-htf" class="text-slate-300 font-medium uppercase">{{ \App\Services\Strategy\SymbolAnalyzer::regimeInterval($cryptoConfig['interval']) }}</span>
                </div>
            </div>
        </div>

        <!-- Chart Controls Toolbar: Mode Switcher, Timeframe Selector & Indicator Legend -->
        <div class="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-3 mb-3 px-1">
            <div class="flex flex-wrap items-center gap-2 w-full lg:w-auto min-w-0">
                <!-- Mode Switcher -->
                <div class="flex items-center bg-slate-950 p-1 rounded-xl border border-slate-800 text-xs shadow-inner">
                    <button type="button" id="tabAlgoChart" onclick="switchChartMode('algo')"
                        class="px-3 py-1.5 rounded-lg font-bold transition flex items-center space-x-1.5 bg-emerald-600 text-white shadow">
                        <span>⚡</span>
                        <span>Signals<span class="hidden sm:inline"> Chart</span></span>
                        <span id="markersCountBadge" class="ml-1 px-1.5 py-0.5 rounded-full text-[10px] bg-slate-900 text-emerald-300 font-mono border border-emerald-500/30">0</span>
                    </button>
                    <button type="button" id="tabTvChart" onclick="switchChartMode('tv')"
                        class="px-3 py-1.5 rounded-lg font-medium transition text-slate-400 hover:text-white flex items-center space-x-1.5">
                        <span>📊</span>
                        <span>TradingView</span>
                    </button>
                </div>

                <!-- Engine Mode Pill (charts are read-only; the background engine trades) -->
                <div class="flex items-center space-x-1.5 px-3 py-1.5 rounded-xl bg-slate-950 border border-slate-800 text-xs shadow-inner" title="The background engine trades in this mode. Viewing a chart never places orders.">
                    <span class="w-2 h-2 rounded-full {{ ($tradingMode ?? 'paper') === 'live' ? 'bg-rose-400' : 'bg-emerald-400' }} animate-pulse"></span>
                    <span class="font-bold text-[11px] {{ ($tradingMode ?? 'paper') === 'live' ? 'text-rose-400' : 'text-emerald-400' }}">ENGINE:</span>
                    <span class="font-mono uppercase text-[10px] font-black px-1.5 py-0.5 rounded {{ ($tradingMode ?? 'paper') === 'live' ? 'bg-rose-500/20 text-rose-300 border border-rose-500/40' : 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/40' }}">{{ strtoupper($tradingMode ?? 'paper') }}</span>
                </div>

                <!-- Timeframe Selector -->
                <div class="flex items-center bg-slate-950 p-1 rounded-xl border border-slate-800 text-xs shadow-inner">
                    <span class="text-slate-500 text-[10px] px-2 font-bold uppercase tracking-wider">TF</span>
                    @foreach (['15m' => '15m', '1h' => '1h (strategy)', '4h' => '4h'] as $tfKey => $tfLabel)
                        <button type="button" onclick="switchTimeframe('{{ $tfKey }}')" id="tf-{{ $tfKey }}"
                            class="tf-btn px-2.5 py-1 rounded-lg font-bold text-xs transition {{ ($cryptoConfig['interval'] === $tfKey) ? 'bg-emerald-600 text-white shadow' : 'text-slate-400 hover:text-white hover:bg-slate-900' }}">
                            {{ $tfLabel }}
                        </button>
                    @endforeach
                </div>

                <!-- In-Chart Quick Coin Selector (Mobile & Desktop) with 5+ Monitored Highlights -->
                <div class="flex items-center bg-slate-950 p-1 rounded-xl border border-slate-800 text-xs shadow-inner overflow-x-auto max-w-full touch-pan-x select-none">
                    <span class="text-slate-500 text-[10px] px-2 font-bold uppercase tracking-wider">COIN</span>
                    <div id="chartCoinButtons" class="flex items-center">
                        @foreach ($monitoredCoins as $quickSymbol)
                            <span class="inline-flex items-center group">
                                <button type="button" onclick="loadFuturesChart('{{ $quickSymbol }}', false)"
                                    class="pl-2.5 pr-1 py-1 rounded-lg text-slate-400 hover:text-white font-bold text-xs transition chart-symbol-btn whitespace-nowrap active:scale-95 touch-manipulation cursor-pointer inline-flex items-center space-x-1 {{ ($quickSymbol === 'BTCUSDT') ? 'bg-emerald-600 text-white shadow' : '' }}"
                                    id="chart-btn-{{ $quickSymbol }}"
                                    title="Monitored: alerts + swing auto-trade (15m confirmed flips)">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                                    <span>{{ str_replace(['1000', 'USDT'], '', $quickSymbol) }}</span>
                                </button>
                                <button type="button" onclick="removeCoinFromChart('{{ $quickSymbol }}')" title="Remove {{ $quickSymbol }}"
                                    class="px-1 text-slate-600 hover:text-rose-400 text-[10px] font-bold cursor-pointer">✕</button>
                            </span>
                        @endforeach
                    </div>
                    <form onsubmit="event.preventDefault(); addCoinFromChart();" class="flex items-center ml-1">
                        <input type="text" id="chartAddCoinInput" placeholder="+ coin"
                            class="w-20 uppercase px-2 py-1 text-[11px] rounded-lg bg-slate-900 text-white placeholder-slate-500 border border-slate-700 focus:outline-none focus:border-emerald-500 font-mono">
                        <button type="submit" title="Add coin" class="ml-1 px-2 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs">+</button>
                    </form>
                </div>
            </div>

            <!-- Marker toggles -->
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] sm:text-xs text-slate-300">
                <label class="inline-flex items-center gap-1 cursor-pointer"><input type="checkbox" id="sapToggle-results" class="accent-emerald-500" checked onchange="SignalAlgoPro.setToggle('results', this.checked)"> Results</label>
                <label class="inline-flex items-center gap-1 cursor-pointer"><input type="checkbox" id="sapToggle-filtered" class="accent-emerald-500" checked onchange="SignalAlgoPro.setToggle('filtered', this.checked)"> Filtered</label>
                <label class="inline-flex items-center gap-1 cursor-pointer"><input type="checkbox" id="sapToggle-shadow" class="accent-emerald-500" onchange="SignalAlgoPro.setToggle('shadow', this.checked)"> Shadow</label>
                <label class="inline-flex items-center gap-1 cursor-pointer"><input type="checkbox" id="sapToggle-levels" class="accent-emerald-500" checked onchange="SignalAlgoPro.setToggle('levels', this.checked)"> Levels</label>
                <label class="inline-flex items-center gap-1 cursor-pointer" title="SuperTrend flip on volume. Confirmed flips (no ?) on the 15m chart of the swing coins are auto-traded by Swing SAR."><input type="checkbox" id="sapToggle-swings" class="accent-emerald-500" checked onchange="SignalAlgoPro.setToggle('swings', this.checked)"> Swing BUY/SELL</label>
                <label class="inline-flex items-center gap-1 cursor-pointer" title="The bot's own trades on this coin (last 7 days): IN / OUT markers with the result, and the entry and stop lines of an open trade."><input type="checkbox" id="sapToggle-trades" class="accent-emerald-500" checked onchange="SignalAlgoPro.setToggle('trades', this.checked)"> My trades <span id="sapTradesNote" class="text-[10px] text-slate-400"></span></label>
                <button type="button" id="sap-alerts-btn" onclick="SignalAlgoPro.enableAlerts()" class="px-2 py-0.5 rounded border border-amber-500/40 bg-amber-500/10 text-amber-300 font-bold" title="Pop-up, sound and browser notification when a new BUY/SELL prints (this coin and every scanned coin)">🔔 Enable alerts</button>
            </div>

            <div id="tvLabelsNote" class="hidden text-[11px] sm:text-xs text-amber-300">Bot BUY/SELL labels are shown on the Signals Chart tab only (TradingView can't display them).</div>

            <!-- Indicator Color Legend -->
            <div id="algoIndicatorLegend" class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] sm:text-xs text-slate-400">
                <span class="flex items-center space-x-1.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-cyan-400"></span>
                    <span>EMA 9</span>
                </span>
                <span class="flex items-center space-x-1.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-amber-400"></span>
                    <span>EMA 21</span>
                </span>
                <span class="flex items-center space-x-1.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-orange-400"></span>
                    <span>EMA 50</span>
                </span>
                <span class="flex items-center space-x-1.5">
                    <span class="w-4 h-1 rounded bg-gradient-to-r from-emerald-500 to-rose-500"></span>
                    <span>Trend ribbon</span>
                </span>
                <span class="flex items-center space-x-1.5">
                    <span class="w-4 h-0.5 bg-yellow-400"></span>
                    <span>Squeeze box</span>
                </span>
                <span class="flex items-center space-x-1.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span class="font-semibold text-emerald-400">▲ Buy Signal</span>
                </span>
                <span class="flex items-center space-x-1.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-rose-400 animate-pulse"></span>
                    <span class="font-semibold text-rose-400">▼ Sell Signal</span>
                </span>
            </div>
        </div>

        <!-- Chart Containers -->
        <div id="chartMainSection" class="relative rounded-xl overflow-hidden border border-slate-800 bg-slate-950 h-[460px] sm:h-[540px] lg:h-[600px]">
            <!-- Instant Chart Loading Overlay -->
            <div id="chartLoadingOverlay" class="absolute inset-0 bg-slate-950/85 backdrop-blur-sm z-30 flex flex-col items-center justify-center space-y-3 transition-opacity duration-150 opacity-0 pointer-events-none">
                <div class="relative w-12 h-12 flex items-center justify-center">
                    <div class="w-12 h-12 rounded-full border-2 border-slate-700 border-t-emerald-400 animate-spin"></div>
                    <span class="absolute text-lg">⚡</span>
                </div>
                <div class="text-center px-4">
                    <div id="chartLoadingCoin" class="text-sm font-bold text-white tracking-wide">Loading Coin Data...</div>
                    <div class="text-[11px] text-slate-400 mt-0.5">Fetching Binance candles &amp; evaluating SignalAlgo PRO...</div>
                </div>
            </div>

            <!-- Floating SignalAlgo PRO In-Chart Watermark Badge -->
            <div class="absolute top-3 right-4 z-20 pointer-events-none flex items-center space-x-2 bg-slate-950/85 backdrop-blur-md px-3 py-1.5 rounded-xl border border-slate-800/80 text-xs shadow-lg">
                <span class="text-emerald-400 font-black">⚡ SignalAlgo PRO™ v3</span>
                <span class="text-slate-500">|</span>
                <span id="chartWatermarkSymbol" class="font-mono text-slate-300 font-bold">BINANCE:BTCUSDT.P</span>
                <span class="text-slate-500">•</span>
                <span id="chartWatermarkTf" class="font-mono text-emerald-400 font-bold uppercase">{{ $cryptoConfig['interval'] }}</span>
            </div>

            <!-- Floating OHLC & Indicator Crosshair Tooltip -->
            <div id="algoChartTooltip" class="absolute top-3 left-4 z-20 pointer-events-none bg-slate-950/90 backdrop-blur-md px-3 py-2 rounded-xl border border-slate-800 text-[11px] text-slate-300 shadow-xl space-y-0.5 min-w-[220px]">
                <div class="font-bold text-white flex items-center justify-between border-b border-slate-800 pb-1 mb-1">
                    <span id="ttSymbol">BTCUSDT</span>
                    <span id="ttTime" class="text-[10px] text-slate-400 font-mono">--</span>
                </div>
                <div class="grid grid-cols-4 gap-1 text-[10px] font-mono">
                    <span>O: <strong id="ttOpen" class="text-slate-200">--</strong></span>
                    <span>H: <strong id="ttHigh" class="text-slate-200">--</strong></span>
                    <span>L: <strong id="ttLow" class="text-slate-200">--</strong></span>
                    <span>C: <strong id="ttClose" class="text-slate-200">--</strong></span>
                </div>
                <div id="ttSignalInfo" class="hidden mt-1.5 pt-1 border-t border-slate-800/80 text-[10px]">
                    <div id="ttSignalTitle" class="font-black"></div>
                    <div id="ttSignalTargets" class="text-slate-400"></div>
                </div>
            </div>

            <!-- Floating SignalAlgo PRO Active Position HUD (Visible when an automated trade is running on this chart) -->
            <div id="chartActivePositionHud" class="hidden absolute top-14 left-4 z-20 pointer-events-auto flex items-center space-x-3 bg-slate-950/95 backdrop-blur-md px-3.5 py-2 rounded-xl border border-emerald-500/40 text-xs shadow-2xl transition-all duration-300">
                <div class="flex items-center space-x-2">
                    <span id="posHudDot" class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-ping"></span>
                    <span id="posHudDirection" class="font-black text-xs font-mono text-emerald-400">LONG</span>
                </div>
                <div class="h-4 w-px bg-slate-800"></div>
                <div class="text-[11px] font-mono space-x-2 text-slate-300">
                    <span>Entry: <strong id="posHudEntry" class="text-white font-bold">$0.00</strong></span>
                    <span>SL: <strong id="posHudSl" class="text-rose-400 font-bold">$0.00</strong></span>
                    <span>TP1: <strong id="posHudTp1" class="text-emerald-400 font-bold">$0.00</strong></span>
                    <span>PnL: <strong id="posHudPnl" class="font-bold text-emerald-400">+0.00%</strong></span>
                </div>
                <div class="h-4 w-px bg-slate-800"></div>
                <div class="text-[10px] px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-300 border border-emerald-500/30 font-bold">
                    SignalAlgo PRO Position
                </div>
            </div>

            <!-- 1. SignalAlgo PRO Native Canvas Chart (Active by default) -->
            <div id="signalalgo_canvas_chart" class="w-full h-full"></div>

            <!-- 2. TradingView Iframe Widget (Alternative mode) -->
            <div id="tradingview_futures_chart" class="hidden w-full h-full relative"></div>

        </div>
    </div>

    <!-- SignalAlgo Pro v4: stats strip + Signal Inspector -->
    <div id="sap-stats-strip" class="mb-3 px-4 py-2 rounded-xl bg-slate-950 border border-slate-800 text-xs text-slate-300 font-mono">Loading signal statistics...</div>
    <div id="sap-inspector" class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-8 text-xs">
        <div class="p-4 rounded-2xl bg-slate-900/80 border border-slate-800 space-y-3">
            <div class="flex items-center justify-between">
                <h3 class="font-bold text-white text-sm">Signal Inspector</h3>
                <span id="sap-status" class="text-sm">--</span>
            </div>
            <p id="sap-reason" class="text-slate-300 leading-relaxed">Loading...</p>
            <ul id="sap-checklist" class="space-y-1"></ul>
            <p class="text-[10px] text-slate-500">Signals print only on closed candles and never repaint.</p>
        </div>
        <div class="p-4 rounded-2xl bg-slate-900/80 border border-slate-800 space-y-3">
            <h3 class="font-bold text-white text-sm">Trend &amp; Track Record</h3>
            <div id="sap-mtf" class="grid grid-cols-2 gap-x-4 gap-y-1"></div>
            <div class="pt-2 border-t border-slate-800 space-y-1">
                <div class="text-slate-400 font-semibold">Setup track record</div>
                <div id="sap-record" class="space-y-0.5"></div>
            </div>
            <div id="sap-model" class="pt-2 border-t border-slate-800 text-slate-400"></div>
        </div>
        <div class="p-4 rounded-2xl bg-slate-900/80 border border-slate-800 space-y-3">
            <h3 class="font-bold text-white text-sm">Position Calculator</h3>
            <div id="sap-sizing" class="text-slate-300">--</div>
            <button type="button" id="sap-place-trade" onclick="SignalAlgoPro.placeTrade()" disabled class="w-full py-2 rounded-lg font-bold text-xs bg-slate-800 text-slate-500">No signal</button>
            <button type="button" id="sap-alert-toggle" onclick="SignalAlgoPro.toggleAlert()" class="w-full py-1.5 rounded-lg text-xs font-semibold border border-slate-700 text-slate-300">Alert me on this coin</button>
            <div class="pt-2 border-t border-slate-800">
                <div class="text-slate-400 font-semibold mb-1">Recent signals (click to inspect)</div>
                <div id="sap-history" class="space-y-0.5 max-h-56 overflow-y-auto"></div>
            </div>
        </div>
    </div>

    <!-- Recent Telegram Alerts Section -->
    <div class="p-4 sm:p-6 rounded-2xl bg-slate-900/90 border border-slate-800 shadow-2xl mb-6 sm:mb-8">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-5 pb-4 border-b border-slate-800">
            <div>
                <div class="flex items-center space-x-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    <h3 class="text-lg font-bold text-white tracking-tight">Recent Telegram Alerts</h3>
                </div>
                <p class="text-xs text-slate-400 mt-1">Signals actually delivered to your Telegram chat, with their tracked result.</p>
            </div>
            <a href="{{ route('alerts.index') }}" class="inline-flex items-center space-x-1.5 px-3 py-1.5 text-xs font-semibold bg-emerald-950 text-emerald-300 hover:bg-emerald-900/60 border border-emerald-800 rounded-lg transition">
                <span>View Full Alert History</span>
                <span>→</span>
            </a>
        </div>

        @if(isset($recentAlerts) && $recentAlerts->isNotEmpty())
            <div class="overflow-x-auto rounded-xl border border-slate-800">
                <table class="min-w-full divide-y divide-slate-800 text-left text-xs font-mono">
                    <thead class="bg-slate-950 text-slate-400 font-sans font-semibold uppercase tracking-wider">
                        <tr>
                            <th class="py-3 px-4">Time</th>
                            <th class="py-3 px-4">Contract</th>
                            <th class="py-3 px-4">TF</th>
                            <th class="py-3 px-4">Direction</th>
                            <th class="py-3 px-4">Setup</th>
                            <th class="py-3 px-4">Grade</th>
                            <th class="py-3 px-4">Result</th>
                            <th class="py-3 px-4">Entry</th>
                            <th class="py-3 px-4">Stop Loss</th>
                            <th class="py-3 px-4">TP1</th>
                            <th class="py-3 px-4 text-right font-sans">Market</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        @foreach($recentAlerts as $alert)
                            <tr class="hover:bg-slate-800/40 transition">
                                <td class="py-2.5 px-4 text-slate-300 whitespace-nowrap font-sans">
                                    {{ $alert->sent_at ? $alert->sent_at->diffForHumans() : 'Just now' }}
                                </td>
                                <td class="py-2.5 px-4 font-bold text-white whitespace-nowrap">
                                    #{{ $alert->symbol }}
                                </td>
                                <td class="py-2.5 px-4 whitespace-nowrap">
                                    <span class="px-1.5 py-0.5 rounded bg-slate-800 border border-slate-700 text-slate-300 text-[11px]">
                                        {{ $alert->interval }}
                                    </span>
                                </td>
                                <td class="py-2.5 px-4 whitespace-nowrap font-sans">
                                    @if($alert->isBuy())
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-emerald-950 text-emerald-300 border border-emerald-800">🟢 BUY</span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-rose-950 text-rose-300 border border-rose-800">🔴 SELL</span>
                                    @endif
                                </td>
                                <td class="py-2.5 px-4 whitespace-nowrap font-sans">
                                    <span class="text-[10px] text-cyan-400 font-bold uppercase">{{ \App\Services\Strategy\StrategyEngine::SETUP_LABELS[$alert->setup] ?? $alert->setup_type }}</span>
                                </td>
                                <td class="py-2.5 px-4 whitespace-nowrap font-sans font-bold {{ $alert->grade === 'A' ? 'text-emerald-400' : ($alert->grade === 'B' ? 'text-cyan-400' : 'text-slate-300') }}">
                                    Grade {{ $alert->grade }}@if($alert->ai_probability !== null) <span class="text-[10px] text-slate-400">AI {{ round($alert->ai_probability * 100) }}%</span>@endif
                                </td>
                                <td class="py-2.5 px-4 whitespace-nowrap font-sans">
                                    @if($alert->outcome === 'OPEN' || $alert->outcome === null)
                                        <span class="text-slate-400">open</span>
                                    @else
                                        <span class="{{ (float) $alert->r_multiple > 0 ? 'text-emerald-400' : 'text-rose-400' }} font-bold">{{ $alert->outcome }} {{ sprintf('%+.2fR', $alert->r_multiple) }}</span>
                                    @endif
                                </td>
                                <td class="py-2.5 px-4 text-white font-bold whitespace-nowrap">
                                    ${{ $alert->entry_price < 1 ? number_format($alert->entry_price, 6) : number_format($alert->entry_price, 4) }}
                                </td>
                                <td class="py-2.5 px-4 text-rose-400 whitespace-nowrap">
                                    ${{ $alert->stop_loss < 1 ? number_format($alert->stop_loss, 6) : number_format($alert->stop_loss, 4) }}
                                </td>
                                <td class="py-2.5 px-4 text-emerald-400 whitespace-nowrap">
                                    ${{ $alert->take_profit_1 < 1 ? number_format($alert->take_profit_1, 6) : number_format($alert->take_profit_1, 4) }}
                                </td>
                                <td class="py-2.5 px-4 text-right whitespace-nowrap font-sans">
                                    <a href="https://www.binance.com/en/futures/{{ $alert->symbol }}" target="_blank" rel="noopener noreferrer" class="text-xs text-emerald-400 hover:underline">
                                        Binance ↗
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="py-8 text-center text-slate-500 text-xs font-sans">
                No Telegram alerts sent yet. Alerts are sent after a 1h candle closes with a Grade A/B tradable signal (or any signal on your alert coins).
            </div>
        @endif
    </div>
</div>

<!-- TradingView & Lightweight Charts Scripts with Fail-Safe Fallback -->
<script type="text/javascript" src="{{ asset('js/lightweight-charts.standalone.production.js') }}"></script>
<script type="text/javascript" src="{{ asset('js/signalalgo-chart.js') }}?v={{ @filemtime(public_path('js/signalalgo-chart.js')) ?: 8 }}"></script>
<script type="text/javascript">
    if (typeof LightweightCharts === 'undefined') {
        const s = document.createElement('script');
        s.src = 'https://unpkg.com/lightweight-charts@4.1.3/dist/lightweight-charts.standalone.production.js';
        s.async = false;
        document.head.appendChild(s);
    }
</script>
<script type="text/javascript" src="https://s3.tradingview.com/tv.js"></script>

<script type="text/javascript">
    let chart = null;
    let candleSeries = null;
    let volumeSeries = null;
    let lineEma9 = null;
    let lineEma21 = null;
    let lineEma200 = null;
    let entryLine = null;
    let slLine = null;
    let tp1Line = null;
    let tp2Line = null;
    let tp3Line = null;
    let chartMode = 'algo'; // 'algo' or 'tv'
    let historicalMarkers = [];
    let activeInstitutionalSignal = null;
    let currentWidget = null;
    let currentAbortController = null;
    let activeSymbol = '{{ $cryptoConfig['initial_symbol'] ?: ($cryptoConfig['symbols'][0] ?? 'BTCUSDT') }}';
    let activeInterval = '{{ $cryptoConfig['interval'] ?? '1h' }}';
    let requestedSignalTime = {{ (int) request()->query('signal_time', 0) }};
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    function showToast(message, isSuccess = true) {
        const toast = document.getElementById('toastNotification');
        if (!toast) return;

        toast.className = isSuccess
            ? 'mb-4 p-3 rounded-xl text-xs font-semibold flex items-center justify-between transition-all bg-emerald-500/20 border border-emerald-500/40 text-emerald-300'
            : 'mb-4 p-3 rounded-xl text-xs font-semibold flex items-center justify-between transition-all bg-rose-500/20 border border-rose-500/40 text-rose-300';

        toast.innerHTML = `
            <div class="flex items-center space-x-2">
                <span>${isSuccess ? '✅' : '⚠️'}</span>
                <span>${message}</span>
            </div>
            <button type="button" onclick="this.parentElement.classList.add('hidden')" class="text-slate-400 hover:text-white text-sm font-bold ml-4">&times;</button>
        `;
        toast.classList.remove('hidden');

        setTimeout(() => {
            if (toast) toast.classList.add('hidden');
        }, 6000);
    }

    function showChartLoading(coin, tf) {
        const overlay = document.getElementById('chartLoadingOverlay');
        const coinText = document.getElementById('chartLoadingCoin');
        if (overlay) {
            if (coinText) {
                coinText.textContent = `Loading ${coin} (${(tf || activeInterval).toUpperCase()})...`;
            }
            overlay.classList.remove('opacity-0', 'pointer-events-none');
            overlay.classList.add('opacity-100');
        }
    }

    function hideChartLoading() {
        const overlay = document.getElementById('chartLoadingOverlay');
        if (overlay) {
            overlay.classList.remove('opacity-100');
            overlay.classList.add('opacity-0', 'pointer-events-none');
        }
    }

    function switchChartMode(mode) {
        chartMode = mode;
        const algoTab = document.getElementById('tabAlgoChart');
        const tvTab = document.getElementById('tabTvChart');
        const algoContainer = document.getElementById('signalalgo_canvas_chart');
        const tvContainer = document.getElementById('tradingview_futures_chart');
        const tooltip = document.getElementById('algoChartTooltip');
        const legend = document.getElementById('algoIndicatorLegend');

        if (mode === 'algo') {
            algoTab.className = 'px-3 py-1.5 rounded-lg font-bold transition flex items-center space-x-1.5 bg-emerald-600 text-white shadow';
            tvTab.className = 'px-3 py-1.5 rounded-lg font-medium transition text-slate-400 hover:text-white flex items-center space-x-1.5';
            algoContainer.classList.remove('hidden');
            tvContainer.classList.add('hidden');
            if (tooltip) tooltip.classList.remove('hidden');
            if (legend) legend.classList.remove('hidden');
            document.getElementById('tvLabelsNote')?.classList.add('hidden');

            if (chart && algoContainer) {
                chart.applyOptions({ width: algoContainer.clientWidth, height: algoContainer.clientHeight });
                chart.timeScale().fitContent();
            }
            window.SignalAlgoPro?.relayout();
            startLiveCandles(activeSymbol, activeInterval);
        } else {
            tvTab.className = 'px-3 py-1.5 rounded-lg font-bold transition flex items-center space-x-1.5 bg-emerald-600 text-white shadow';
            algoTab.className = 'px-3 py-1.5 rounded-lg font-medium transition text-slate-400 hover:text-white flex items-center space-x-1.5';
            algoContainer.classList.add('hidden');
            tvContainer.classList.remove('hidden');
            if (tooltip) tooltip.classList.add('hidden');
            if (legend) legend.classList.add('hidden');
            document.getElementById('tvLabelsNote')?.classList.remove('hidden');
            window.SignalAlgoPro?.relayout();
            stopLiveCandles();

            initTradingViewWidget(activeSymbol);
        }
    }

    function mapIntervalToTv(tf) {
        switch (tf.toLowerCase()) {
            case '1m': return '1';
            case '3m': return '3';
            case '5m': return '5';
            case '15m': return '15';
            case '30m': return '30';
            case '1h': return '60';
            case '4h': return '240';
            case '1d': return 'D';
            default: return '15';
        }
    }

    function switchTimeframe(tf) {
        activeInterval = tf.toLowerCase();

        // Update UI active buttons
        document.querySelectorAll('.tf-btn').forEach(btn => {
            btn.classList.remove('bg-emerald-600', 'text-white', 'shadow');
            btn.classList.add('text-slate-400', 'hover:text-white', 'hover:bg-slate-900');
        });
        const activeBtn = document.getElementById('tf-' + activeInterval);
        if (activeBtn) {
            activeBtn.classList.add('bg-emerald-600', 'text-white', 'shadow');
            activeBtn.classList.remove('text-slate-400', 'hover:text-white', 'hover:bg-slate-900');
        }

        // Update HUD active TF
        const hudTf = document.getElementById('hud-active-tf');
        if (hudTf) hudTf.textContent = activeInterval.toUpperCase();

        const chartWmtf = document.getElementById('chartWatermarkTf');
        if (chartWmtf) chartWmtf.textContent = activeInterval.toUpperCase();

        // Re-init TV widget if currently in TV mode
        if (chartMode === 'tv') {
            initTradingViewWidget(activeSymbol);
        }

        // Refresh SignalAlgo analysis & canvas chart
        loadFuturesChart(activeSymbol, false);
    }

    function initAlgoCanvasChart() {
        const container = document.getElementById('signalalgo_canvas_chart');
        if (!container || chart !== null) return;

        if (typeof LightweightCharts === 'undefined') {
            setTimeout(initAlgoCanvasChart, 80);
            return;
        }

        const w = container.clientWidth > 50 ? container.clientWidth : (window.innerWidth < 768 ? window.innerWidth - 32 : 800);
        const h = container.clientHeight > 50 ? container.clientHeight : (window.innerWidth < 768 ? 460 : 600);

        chart = LightweightCharts.createChart(container, {
            width: w,
            height: h,
            layout: {
                background: { color: '#090d16' },
                textColor: '#94a3b8',
                fontSize: 12,
            },
            grid: {
                vertLines: { color: 'rgba(30, 41, 59, 0.4)' },
                horzLines: { color: 'rgba(30, 41, 59, 0.4)' },
            },
            crosshair: {
                mode: LightweightCharts.CrosshairMode.Normal,
                vertLine: { color: 'rgba(56, 189, 248, 0.6)', width: 1, style: 3 },
                horzLine: { color: 'rgba(56, 189, 248, 0.6)', width: 1, style: 3 },
            },
            rightPriceScale: {
                borderColor: '#1e293b',
                scaleMargins: { top: 0.1, bottom: 0.22 },
            },
            timeScale: {
                borderColor: '#1e293b',
                timeVisible: true,
                secondsVisible: false,
            },
            handleScroll: {
                mouseWheel: true,
                pressedMouseMove: true,
                horzTouchDrag: true,
                vertTouchDrag: false,
            },
            handleScale: {
                axisPressedMouseMove: true,
                mouseWheel: true,
                pinch: true,
            },
        });

        // 1. Candlestick Series
        candleSeries = chart.addCandlestickSeries({
            upColor: '#10b981',
            downColor: '#ef4444',
            borderUpColor: '#10b981',
            borderDownColor: '#ef4444',
            wickUpColor: '#10b981',
            wickDownColor: '#ef4444',
        });

        // 2. Volume Histogram Series
        volumeSeries = chart.addHistogramSeries({
            priceFormat: { type: 'volume' },
            priceScaleId: '',
            scaleMargins: { top: 0.82, bottom: 0 },
        });

        // 3. EMA Overlay Lines (EMA 9: Cyan, EMA 21: Amber, EMA 200: Purple)
        lineEma9 = chart.addLineSeries({ color: '#22d3ee', lineWidth: 1.5, title: 'EMA 9' });
        lineEma21 = chart.addLineSeries({ color: '#fbbf24', lineWidth: 1.5, title: 'EMA 21' });
        lineEma200 = chart.addLineSeries({ color: '#a855f7', lineWidth: 2, title: 'EMA 200' });

        // Tooltip Crosshair Subscription
        chart.subscribeCrosshairMove(param => {
            updateTooltip(param);
        });

        // Click to inspect historical signal levels on chart or restore verified active signal / position
        chart.subscribeClick(param => {
            if (!param || !param.time) {
                if (window.activePositionTrade) {
                    renderTradeLevels(window.activePositionTrade);
                } else if (activeInstitutionalSignal && (activeInstitutionalSignal.entry || activeInstitutionalSignal.sl)) {
                    renderTradeLevels(activeInstitutionalSignal);
                } else {
                    clearTradeLevels();
                }
                return;
            }
            if (historicalMarkers && historicalMarkers.length) {
                const marker = historicalMarkers.find(m => m.time === param.time);
                if (marker) {
                    renderTradeLevels(marker);
                    return;
                }
            }
            // Clicked empty bar / whitespace - restore live active trade or verified active signal or clear
            if (window.activePositionTrade) {
                renderTradeLevels(window.activePositionTrade);
            } else if (activeInstitutionalSignal && (activeInstitutionalSignal.entry || activeInstitutionalSignal.sl)) {
                renderTradeLevels(activeInstitutionalSignal);
            } else {
                clearTradeLevels();
            }
        });

        // Responsive auto-resizing with ResizeObserver
        if (window.ResizeObserver) {
            const resizeObserver = new ResizeObserver(entries => {
                if (!entries || !entries.length || !chart || !container) return;
                const { width, height } = entries[0].contentRect;
                if (width > 50 && height > 50) {
                    chart.applyOptions({ width, height });
                }
            });
            resizeObserver.observe(container);
        } else {
            window.addEventListener('resize', () => {
                if (chart && container) {
                    chart.applyOptions({ width: container.clientWidth, height: container.clientHeight });
                }
            });
        }
    }

    function clearTradeLevels() {
        if (candleSeries) {
            if (entryLine) { try { candleSeries.removePriceLine(entryLine); } catch (e) {} entryLine = null; }
            if (slLine) { try { candleSeries.removePriceLine(slLine); } catch (e) {} slLine = null; }
            if (tp1Line) { try { candleSeries.removePriceLine(tp1Line); } catch (e) {} tp1Line = null; }
            if (tp2Line) { try { candleSeries.removePriceLine(tp2Line); } catch (e) {} tp2Line = null; }
            if (tp3Line) { try { candleSeries.removePriceLine(tp3Line); } catch (e) {} tp3Line = null; }
        }
    }

    function renderTradeLevels(setup) {
        clearTradeLevels();
        if (!setup || !candleSeries || typeof LightweightCharts === 'undefined') return;

        const entry = Number(setup.entry || setup.entry_price || 0);
        const sl = Number(setup.sl || setup.current_sl || setup.initial_sl || 0);
        const tp1 = Number(setup.tp1 || setup.tp1_price || 0);
        const tp2 = Number(setup.tp2 || setup.tp2_price || 0);
        const tp3 = Number(setup.tp3 || setup.tp3_price || 0);
        const rawSide = String(setup.side || (setup.direction === 'LONG' ? 'BUY' : 'SELL') || 'BUY').toUpperCase();
        const isLong = rawSide === 'LONG' || rawSide === 'BUY';
        const isLiveTrade = Boolean(setup.is_live_position || setup.id);

        const fmtP = (p) => p < 1 ? p.toFixed(5) : p.toFixed(2);

        if (entry > 0) {
            entryLine = candleSeries.createPriceLine({
                price: entry,
                color: isLiveTrade ? '#38bdf8' : '#06b6d4',
                lineWidth: 2,
                lineStyle: isLiveTrade ? 0 : 2, // Solid for active position, dashed for signal
                axisLabelVisible: true,
                title: `${isLiveTrade ? '⚡ ACTIVE POSITION' : 'ENTRY'} (${isLong ? 'LONG' : 'SHORT'}): $${fmtP(entry)}`,
            });
        }

        if (sl > 0) {
            slLine = candleSeries.createPriceLine({
                price: sl,
                color: '#f43f5e',
                lineWidth: 2,
                lineStyle: 1, // Dotted
                axisLabelVisible: true,
                title: `${isLiveTrade ? '🛡️ LIVE SL' : 'SL'}: $${fmtP(sl)}`,
            });
        }

        if (tp1 > 0) {
            tp1Line = candleSeries.createPriceLine({
                price: tp1,
                color: '#10b981',
                lineWidth: 1.5,
                lineStyle: 1, // Dotted
                axisLabelVisible: true,
                title: `TP1: $${fmtP(tp1)}`,
            });
        }

        if (tp2 > 0) {
            tp2Line = candleSeries.createPriceLine({
                price: tp2,
                color: '#10b981',
                lineWidth: 1.5,
                lineStyle: 1, // Dotted
                axisLabelVisible: true,
                title: `TP2: $${fmtP(tp2)}`,
            });
        }

        if (tp3 > 0) {
            tp3Line = candleSeries.createPriceLine({
                price: tp3,
                color: '#10b981',
                lineWidth: 1.5,
                lineStyle: 1, // Dotted
                axisLabelVisible: true,
                title: `TP3: $${fmtP(tp3)}`,
            });
        }
    }

    function updateTooltip(param) {
        const tooltip = document.getElementById('algoChartTooltip');
        if (!tooltip || !param || !param.time || !param.seriesData) return;

        const candleData = param.seriesData.get(candleSeries);
        if (!candleData) return;

        document.getElementById('ttSymbol').textContent = activeSymbol;
        const d = new Date(param.time * 1000);
        document.getElementById('ttTime').textContent = d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

        const fmt = (v) => v !== undefined ? (Number(v) < 1 ? Number(v).toFixed(5) : Number(v).toFixed(2)) : '--';
        document.getElementById('ttOpen').textContent = fmt(candleData.open);
        document.getElementById('ttHigh').textContent = fmt(candleData.high);
        document.getElementById('ttLow').textContent = fmt(candleData.low);
        document.getElementById('ttClose').textContent = fmt(candleData.close);

        // Check if there is a SignalAlgo PRO marker on this candle
        const marker = historicalMarkers.find(m => m.time === param.time);
        const signalBox = document.getElementById('ttSignalInfo');
        const signalTitle = document.getElementById('ttSignalTitle');
        const signalTargets = document.getElementById('ttSignalTargets');

        if (marker && signalBox && signalTitle && signalTargets) {
            signalBox.classList.remove('hidden');
            const isBuy = marker.side === 'BUY';
            signalTitle.className = isBuy ? 'font-black text-emerald-400' : 'font-black text-rose-400';
            signalTitle.textContent = `${isBuy ? '🟢' : '🔴'} ${marker.side} · ${marker.setup_label || 'Signal'} · Grade ${marker.grade || '-'}${marker.outcome ? ' · ' + marker.outcome + (marker.r_multiple !== null && marker.r_multiple !== undefined ? ' ' + Number(marker.r_multiple).toFixed(2) + 'R' : '') : ''}`;
            signalTargets.innerHTML = `Entry: ${fmt(marker.entry)} | SL: ${fmt(marker.sl)}<br>TP1: ${fmt(marker.tp1)} | TP2: ${fmt(marker.tp2)}`;
        } else if (signalBox) {
            signalBox.classList.add('hidden');
        }
    }

    // Coin and timeframe changes can both rebuild the widget at once; only the latest request may render,
    // into a fresh inner container, so a slower earlier widget can't leave the wrong coin on screen.
    let tvWidgetTimer = null;
    let tvWidgetSeq = 0;

    function initTradingViewWidget(symbol) {
        clearTimeout(tvWidgetTimer);
        tvWidgetTimer = setTimeout(() => renderTradingViewWidget(symbol), 150);
    }

    function renderTradingViewWidget(symbol) {
        const host = document.getElementById('tradingview_futures_chart');
        if (!host || typeof TradingView === 'undefined') return;

        const containerId = 'tradingview_widget_' + (++tvWidgetSeq);
        host.innerHTML = `<div id="${containerId}" class="w-full h-full"></div>`;

        const tvSymbol = 'BINANCE:' + symbol + '.P';
        const tvInterval = mapIntervalToTv(activeInterval);

        new TradingView.widget({
            "autosize": true,
            "symbol": tvSymbol,
            "interval": tvInterval,
            "timezone": "Etc/UTC",
            "theme": "dark",
            "style": "1",
            "locale": "en",
            "toolbar_bg": "#0f172a",
            "enable_publishing": false,
            "withdateranges": true,
            "hide_side_toolbar": false,
            // The coin buttons pick the symbol; TradingView's own search kept a different coin on screen.
            "allow_symbol_change": false,
            "details": true,
            "hotlist": false,
            "calendar": false,
            "studies": [
                "MASimple@tv-basicstudies",
                "RSI@tv-basicstudies"
            ],
            "container_id": containerId
        });
    }

    // Last response per coin + timeframe: a switch back to a coin renders instantly, then refreshes in the background.
    const chartCache = new Map();

    /**
     * Load the chart data. silent = background refresh: no loading overlay, the view (zoom / scroll) is kept.
     * A coin or timeframe switch with a cached response renders that first and refreshes silently.
     */
    function fetchSignalAlgoData(symbol, abortSignal = null, silent = false) {
        const badge = document.getElementById('hud-signal-badge');
        const interval = activeInterval;
        const cacheKey = `${symbol}|${interval}`;

        if (!silent) {
            const cached = chartCache.get(cacheKey);
            if (cached) {
                renderSignalAlgoData(cached, { fit: true });
                silent = true;
            } else {
                if (badge) {
                    badge.className = 'px-2.5 py-1 rounded-lg text-xs font-black tracking-wide bg-slate-800 text-slate-300 border border-slate-700 flex items-center space-x-1.5';
                    badge.innerHTML = '<span class="w-2 h-2 rounded-full bg-amber-400 animate-pulse"></span><span>EVALUATING ALGO...</span>';
                }
                showChartLoading(symbol, interval);
            }
        }
        const fit = !silent;
        startLiveCandles(symbol, interval);

        const fetchOptions = abortSignal ? { signal: abortSignal } : {};

        // Relative URL: an absolute one can come out as http:// behind the host's SSL proxy and be blocked.
        fetch(`{{ route('signals.chart_trades', [], false) }}?symbol=${encodeURIComponent(symbol)}`, Object.assign({ headers: { 'Accept': 'application/json' } }, fetchOptions))
            .then(res => res.ok ? res.json() : Promise.reject(new Error(`HTTP ${res.status}`)))
            .then(tr => { if (window.SignalAlgoPro && symbol === activeSymbol) window.SignalAlgoPro.setTrades(tr.symbol, tr.trades); })
            .catch(err => {
                if (err.name === 'AbortError' || !window.SignalAlgoPro) return;
                console.warn('My trades layer:', err);
                window.SignalAlgoPro.setTradesStatus(`unavailable (${err.message})`);
            });

        fetch(`/dashboard/analyze?symbol=${encodeURIComponent(symbol)}&interval=${encodeURIComponent(interval)}&signal_time=${requestedSignalTime || 0}`, fetchOptions)
            .then(res => res.json())
            .then(data => {
                if (symbol !== activeSymbol || interval !== activeInterval) {
                    return;
                }
                if (data && data.success) {
                    chartCache.set(cacheKey, data);
                }
                if (data && !data.success && silent) {
                    return; // keep the chart on screen; the next refresh retries
                }
                renderSignalAlgoData(data, { fit });
            })
            .catch(err => {
                if (err.name === 'AbortError') {
                    return;
                }
                if (silent) {
                    return;
                }
                hideChartLoading();
                console.error('SignalAlgo PRO analysis error:', err);
                if (badge) {
                    badge.className = 'px-2.5 py-1 rounded-lg text-xs font-bold bg-slate-800 text-slate-400 border border-slate-700';
                    badge.textContent = 'STATUS: STANDBY';
                }
            });
    }

    function renderSignalAlgoData(data, { fit = false } = {}) {
                const badge = document.getElementById('hud-signal-badge');
                const priceEl = document.getElementById('hud-price');

                hideChartLoading();

                if (!data.success) {
                    if (badge) {
                        badge.className = 'px-2.5 py-1 rounded-lg text-xs font-black tracking-wide bg-rose-950/60 text-rose-400 border border-rose-800';
                        badge.textContent = 'DATA UNAVAILABLE';
                    }
                    return;
                }

                // Render into Native Canvas Chart
                if (data.candles && data.candles.length) {
                    renderedKey = data.symbol + '|' + data.interval;
                    initAlgoCanvasChart();

                    const container = document.getElementById('signalalgo_canvas_chart');
                    if (chart && container) {
                        const cw = container.clientWidth > 50 ? container.clientWidth : (window.innerWidth < 768 ? window.innerWidth - 32 : 800);
                        const ch = container.clientHeight > 50 ? container.clientHeight : (window.innerWidth < 768 ? 460 : 600);
                        chart.applyOptions({ width: cw, height: ch });
                    }

                    // Candlesticks
                    candleSeries.setData(data.candles);

                    // Volume with dynamic green/red colors
                    const volData = data.candles.map(c => ({
                        time: c.time,
                        value: c.volume,
                        color: c.close >= c.open ? 'rgba(16, 185, 129, 0.35)' : 'rgba(239, 68, 68, 0.35)',
                    }));
                    volumeSeries.setData(volData);

                    // EMAs
                    if (data.ema9) lineEma9.setData(data.ema9);
                    if (data.ema21) lineEma21.setData(data.ema21);
                    if (data.ema200) lineEma200.setData(data.ema200);

                    // Plot SignalAlgo PRO v3 Historical Markers directly on the candles!
                    historicalMarkers = (data.signal_history || []).map(h => Object.assign({}, h, {
                        side: h.order_side,
                        score: h.ai_probability !== null ? Math.round(h.ai_probability * 100) : '--',
                    }));
                    candleSeries.setMarkers(data.markers || []);

                    // Update Markers Count Badge
                    const markersBadge = document.getElementById('markersCountBadge');
                    if (markersBadge) {
                        markersBadge.textContent = historicalMarkers.length;
                    }

                    if (fit) {
                        chart.timeScale().fitContent();
                    }

                    // Store active position on window for click-restores and tooltips
                    window.activePositionTrade = data.active_trade ? Object.assign({}, data.active_trade, { is_live_position: true }) : null;
                    const posHud = document.getElementById('chartActivePositionHud');

                    if (window.activePositionTrade) {
                        renderTradeLevels(window.activePositionTrade);
                        if (posHud) {
                            posHud.classList.remove('hidden');
                            const isLong = window.activePositionTrade.side === 'LONG';
                            const dotEl = document.getElementById('posHudDot');
                            const dirEl = document.getElementById('posHudDirection');
                            const pnlEl = document.getElementById('posHudPnl');
                            const pnl = Number(window.activePositionTrade.pnl_percent || 0);

                            if (dotEl) dotEl.className = `w-2.5 h-2.5 rounded-full ${isLong ? 'bg-emerald-400' : 'bg-rose-400'} animate-ping`;
                            if (dirEl) {
                                dirEl.className = `font-black text-xs font-mono ${isLong ? 'text-emerald-400' : 'text-rose-400'}`;
                                dirEl.textContent = `ACTIVE ${window.activePositionTrade.side}`;
                            }
                            const fmtH = (val) => val ? (Number(val) < 1 ? Number(val).toFixed(5) : Number(val).toFixed(2)) : '--';
                            document.getElementById('posHudEntry').textContent = '$' + fmtH(window.activePositionTrade.entry_price);
                            document.getElementById('posHudSl').textContent = '$' + fmtH(window.activePositionTrade.current_sl);
                            document.getElementById('posHudTp1').textContent = '$' + fmtH(window.activePositionTrade.tp1_price);
                            if (pnlEl) {
                                pnlEl.textContent = (pnl >= 0 ? '+' : '') + pnl.toFixed(2) + '%';
                                pnlEl.className = `font-bold ${pnl >= 0 ? 'text-emerald-400' : 'text-rose-400'}`;
                            }
                        }
                    } else {
                        if (posHud) posHud.classList.add('hidden');

                        // Render trade levels ONLY if an institutional setup is genuinely active and verified.
                        // Never render artificial or expired setup lines on the chart!
                        activeInstitutionalSignal = data.signal;
                        if (activeInstitutionalSignal && (activeInstitutionalSignal.entry || activeInstitutionalSignal.sl)) {
                            renderTradeLevels(activeInstitutionalSignal);
                        } else {
                            clearTradeLevels();
                        }
                    }
                }

                // Update Price
                if (priceEl && data.price) {
                    priceEl.textContent = '$' + Number(data.price).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 4 });
                }

                const sig = data.signal;
                const diag = data.diagnostics || {};
                const perp = (data.signal && data.signal.perpetual_options) || {};
                const sizeText = (data.sizing && data.sizing.allowed) ? `${data.sizing.leverage}x · risk $${data.sizing.risk_usd}` : (data.sizing ? 'too small / too wide' : '--');
                const btcMacro = data.btc_macro || {};
                const activeTrade = data.active_trade;

                // Update BTC Macro Indicator
                const btcMacroEl = document.getElementById('hud-btc-macro');
                if (btcMacroEl) {
                    const btcTrendStr = btcMacro.trend || 'UNKNOWN';
                    const btcColor = btcTrendStr === 'BULLISH' ? 'text-emerald-400' : (btcTrendStr === 'BEARISH' ? 'text-rose-400' : 'text-amber-400');
                    const btcDot = btcTrendStr === 'BULLISH' ? '🟢' : (btcTrendStr === 'BEARISH' ? '🔴' : '🟡');
                    const btcPriceFormatted = btcMacro.btc_price ? ' ($' + Number(btcMacro.btc_price).toLocaleString(undefined, { maximumFractionDigits: 0 }) + ')' : '';
                    btcMacroEl.innerHTML = `${btcDot} BTC Macro: <strong class="${btcColor}">${btcTrendStr}</strong>${btcPriceFormatted}`;
                }

                // Update Setup Type
                const setupTypeEl = document.getElementById('hud-setup-type');
                if (setupTypeEl) {
                    if (activeTrade) {
                        const isLong = activeTrade.side === 'LONG';
                        setupTypeEl.innerHTML = `Setup: <strong class="${isLong ? 'text-emerald-400' : 'text-rose-400'} font-bold">SIGNALALGO PRO (${activeTrade.side} POSITION)</strong>`;
                    } else if (sig && sig.setup_type) {
                        setupTypeEl.innerHTML = `Setup: <strong class="text-emerald-400 font-bold">${sig.setup_type}</strong>`;
                    } else {
                        setupTypeEl.innerHTML = 'Setup: <strong class="text-slate-500">STANDBY (NO TRADE)</strong>';
                    }
                }

                const fmt = (val) => val ? (Number(val) < 1 ? Number(val).toFixed(6) : Number(val).toFixed(4)) : '--';

                // Update Signal Badge & Perpetual Trade Options
                const hudLeverage = document.getElementById('hud-leverage');
                const hudRr = document.getElementById('hud-rr');
                const scoreLabel = document.getElementById('hud-score-label');

                if (activeTrade) {
                    const isLong = activeTrade.side === 'LONG';
                    const pnl = Number(activeTrade.pnl_percent || 0);
                    const pnlColor = pnl >= 0 ? 'text-emerald-300' : 'text-rose-300';
                    const pnlStr = (pnl >= 0 ? '+' : '') + pnl.toFixed(2) + '%';
                    const badgeClass = isLong
                        ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/50 shadow-sm shadow-emerald-500/20'
                        : 'bg-rose-500/20 text-rose-300 border-rose-500/50 shadow-sm shadow-rose-500/20';

                    if (badge) {
                        badge.className = `px-2.5 py-1 rounded-lg text-xs font-black tracking-wide border flex items-center space-x-1.5 ${badgeClass}`;
                        badge.innerHTML = `<span class="w-2 h-2 rounded-full ${isLong ? 'bg-emerald-400' : 'bg-rose-400'} animate-ping"></span><span>⚡ ACTIVE ${activeTrade.side} @ $${fmt(activeTrade.entry_price)} | SL: $${fmt(activeTrade.current_sl)} | PnL: <span class="${pnlColor}">${pnlStr}</span></span>`;
                    }

                    if (scoreLabel) {
                        const trScore = activeTrade.meta?.score || 94;
                        scoreLabel.textContent = `Open ${String(activeTrade.mode || '').toUpperCase()} position · ${activeTrade.stage || ''}`;
                        scoreLabel.className = 'text-emerald-400 font-bold';
                    }

                    if (hudLeverage) hudLeverage.textContent = `${activeTrade.leverage || 10}x Isolated`;
                    if (hudRr) hudRr.textContent = 'TP1 1.5R · TP2 3R';

                    document.getElementById('hud-entry').textContent = fmt(activeTrade.entry_price);
                    document.getElementById('hud-sl').textContent = fmt(activeTrade.current_sl);
                    document.getElementById('hud-tp1').textContent = fmt(activeTrade.tp1_price);
                    document.getElementById('hud-tp2').textContent = fmt(activeTrade.tp2_price);
                    document.getElementById('hud-tp3').textContent = activeTrade.tp2_price ? fmt(activeTrade.tp2_price * 1.015) : '--';
                } else if (sig && sig.side === 'BUY') {
                    const activeTag = sig.is_active_trade ? ' (ACTIVE SETUP)' : '';
                    badge.className = 'px-2.5 py-1 rounded-lg text-xs font-black tracking-wide bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 shadow-sm shadow-emerald-500/20 flex items-center space-x-1.5';
                    badge.innerHTML = `<span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span><span>🟢 STRONG BUY / LONG${activeTag} (SCORE ${sig.score})</span>`;

                    if (scoreLabel) {
                        scoreLabel.textContent = `Grade ${sig.grade || '-'}`;
                        scoreLabel.className = 'text-emerald-400 font-bold';
                    }

                    if (hudLeverage) hudLeverage.textContent = sizeText;
                    if (hudRr) hudRr.textContent = perp.risk_reward || '--';

                    const entryVal = sig.entry;
                    document.getElementById('hud-entry').textContent = fmt(entryVal);
                    const slVal = sig.sl;
                    const slPct = perp.sl_pct ? `(-${perp.sl_pct}%)` : (entryVal > 0 && slVal > 0 ? `(-${Math.abs((entryVal - slVal) / entryVal * 100).toFixed(2)}%)` : '');
                    document.getElementById('hud-sl').textContent = fmt(slVal) + (slPct ? ' ' + slPct : '');
                    const tp1Val = sig.tp1;
                    const tp1Pct = perp.tp1_pct ? `(+${perp.tp1_pct}%)` : (entryVal > 0 && tp1Val > 0 ? `(+${Math.abs((tp1Val - entryVal) / entryVal * 100).toFixed(2)}%)` : '');
                    document.getElementById('hud-tp1').textContent = fmt(tp1Val) + (tp1Pct ? ' ' + tp1Pct : '');
                    const tp2Val = sig.tp2;
                    const tp2Pct = perp.tp2_pct ? `(+${perp.tp2_pct}%)` : (entryVal > 0 && tp2Val > 0 ? `(+${Math.abs((tp2Val - entryVal) / entryVal * 100).toFixed(2)}%)` : '');
                    document.getElementById('hud-tp2').textContent = fmt(tp2Val) + (tp2Pct ? ' ' + tp2Pct : '');
                    const tp3Val = sig.tp3;
                    const tp3Pct = perp.tp3_pct ? `(+${perp.tp3_pct}%)` : (entryVal > 0 && tp3Val > 0 ? `(+${Math.abs((tp3Val - entryVal) / entryVal * 100).toFixed(2)}%)` : '');
                    document.getElementById('hud-tp3').textContent = fmt(tp3Val) + (tp3Pct ? ' ' + tp3Pct : '');
                } else if (sig && sig.side === 'SELL') {
                    const activeTag = sig.is_active_trade ? ' (ACTIVE SETUP)' : '';
                    badge.className = 'px-2.5 py-1 rounded-lg text-xs font-black tracking-wide bg-rose-500/20 text-rose-300 border border-rose-500/40 shadow-sm shadow-rose-500/20 flex items-center space-x-1.5';
                    badge.innerHTML = `<span class="w-2 h-2 rounded-full bg-rose-400 animate-ping"></span><span>🔴 STRONG SELL / SHORT${activeTag} (SCORE ${sig.score})</span>`;

                    if (scoreLabel) {
                        scoreLabel.textContent = `Grade ${sig.grade || '-'}`;
                        scoreLabel.className = 'text-emerald-400 font-bold';
                    }

                    if (hudLeverage) hudLeverage.textContent = sizeText;
                    if (hudRr) hudRr.textContent = perp.risk_reward || '--';

                    const entryVal = sig.entry;
                    document.getElementById('hud-entry').textContent = fmt(entryVal);
                    const slVal = sig.sl;
                    const slPct = perp.sl_pct ? `(-${perp.sl_pct}%)` : (entryVal > 0 && slVal > 0 ? `(-${Math.abs((entryVal - slVal) / entryVal * 100).toFixed(2)}%)` : '');
                    document.getElementById('hud-sl').textContent = fmt(slVal) + (slPct ? ' ' + slPct : '');
                    const tp1Val = sig.tp1;
                    const tp1Pct = perp.tp1_pct ? `(+${perp.tp1_pct}%)` : (entryVal > 0 && tp1Val > 0 ? `(+${Math.abs((tp1Val - entryVal) / entryVal * 100).toFixed(2)}%)` : '');
                    document.getElementById('hud-tp1').textContent = fmt(tp1Val) + (tp1Pct ? ' ' + tp1Pct : '');
                    const tp2Val = sig.tp2;
                    const tp2Pct = perp.tp2_pct ? `(+${perp.tp2_pct}%)` : (entryVal > 0 && tp2Val > 0 ? `(+${Math.abs((tp2Val - entryVal) / entryVal * 100).toFixed(2)}%)` : '');
                    document.getElementById('hud-tp2').textContent = fmt(tp2Val) + (tp2Pct ? ' ' + tp2Pct : '');
                    const tp3Val = sig.tp3;
                    const tp3Pct = perp.tp3_pct ? `(+${perp.tp3_pct}%)` : (entryVal > 0 && tp3Val > 0 ? `(+${Math.abs((tp3Val - entryVal) / entryVal * 100).toFixed(2)}%)` : '');
                    document.getElementById('hud-tp3').textContent = fmt(tp3Val) + (tp3Pct ? ' ' + tp3Pct : '');
                } else {
                    const buyScore = diag.buy_score || 0;
                    const sellScore = diag.sell_score || 0;
                    const isBtcBlocked = (buyScore >= sellScore && btcMacro.allow_long === false) || (sellScore > buyScore && btcMacro.allow_short === false);

                    if (isBtcBlocked) {
                        badge.className = 'px-2.5 py-1 rounded-lg text-xs font-black tracking-wide bg-amber-500/20 text-amber-300 border border-amber-500/40 flex items-center space-x-1.5';
                        badge.innerHTML = `<span class="w-2 h-2 rounded-full bg-amber-400"></span><span>🛡️ BLOCKED (COUNTER BTC ${btcMacro.trend || 'MACRO'})</span>`;
                    } else {
                        badge.className = 'px-2.5 py-1 rounded-lg text-xs font-bold tracking-wide bg-slate-800 text-slate-300 border border-slate-700 flex items-center space-x-1.5';
                        badge.innerHTML = `<span class="w-2 h-2 rounded-full bg-slate-400"></span><span>⚪ WAIT · ${(data.state && data.state.reason) ? data.state.reason : 'no valid setup right now'}</span>`;
                    }

                    if (scoreLabel) {
                        const rawScore = Math.max(diag.buy_score || 0, diag.sell_score || 0);
                        scoreLabel.textContent = 'No active signal';
                        scoreLabel.className = rawScore >= 80 ? 'text-amber-400 font-bold' : 'text-slate-400 font-bold';
                    }

                    if (hudLeverage) hudLeverage.textContent = 'Standby';
                    if (hudRr) hudRr.textContent = '--';

                    document.getElementById('hud-entry').textContent = '--';
                    document.getElementById('hud-sl').textContent = '--';
                    document.getElementById('hud-tp1').textContent = '--';
                    document.getElementById('hud-tp2').textContent = '--';
                    document.getElementById('hud-tp3').textContent = '--';
                }

                // Ticker stats
                document.getElementById('hud-rsi').textContent = diag.rsi !== undefined ? diag.rsi : '--';
                document.getElementById('hud-adx').textContent = diag.adx !== undefined ? diag.adx : '--';
                document.getElementById('hud-vol').textContent = diag.volume_ratio !== undefined ? diag.volume_ratio + 'x' : '--';
                document.getElementById('hud-atr').textContent = diag.atr_pct !== undefined ? diag.atr_pct + '%' : '--';

                // Timeframe indicator synchronization
                if (data.interval) {
                    const hudTf = document.getElementById('hud-active-tf');
                    if (hudTf) hudTf.textContent = data.interval.toUpperCase();
                }
                if (data.htf_interval) {
                    const hudHtf = document.getElementById('hud-active-htf');
                    if (hudHtf) hudHtf.textContent = data.htf_interval.toUpperCase();
                }

                // SignalAlgo Pro v4 overlays, inspector and grade/AI badge (charts are read-only: no auto orders)
                if (window.SignalAlgoPro) {
                    SignalAlgoPro.render(data, chart, candleSeries);
                }
                if (!activeTrade && sig && badge) {
                    const stars = '★'.repeat(sig.stars || 1);
                    const ai = sig.ai_probability !== null && sig.ai_probability !== undefined ? ` · AI ${Math.round(sig.ai_probability * 100)}%` : '';
                    const tradable = sig.tradable ? '' : ' · NOT TRADABLE';
                    badge.innerHTML = `<span class="w-2 h-2 rounded-full ${sig.direction === 'LONG' ? 'bg-emerald-400' : 'bg-rose-400'} animate-ping"></span><span>${sig.direction === 'LONG' ? '🟢 BUY' : '🔴 SELL'} · ${sig.setup_label} · ${sig.grade} ${stars}${ai}${tradable}</span>`;
                }
                if (scoreLabel) {
                    if (!activeTrade) scoreLabel.textContent = sig ? `Grade ${sig.grade}${sig.ai_probability !== null && sig.ai_probability !== undefined ? ' · AI ' + Math.round(sig.ai_probability * 100) + '%' : ''}` : 'No active signal';
                }
    }

    // Live forming candle from Binance's public futures kline stream (market data only, no API key, no REST calls).
    let liveSocket = null;
    let liveKey = null;
    let liveRetry = 0;
    let liveRetryTimer = null;
    let renderedKey = null;

    function stopLiveCandles() {
        clearTimeout(liveRetryTimer);
        if (liveSocket) {
            liveSocket.onclose = null;
            liveSocket.close();
        }
        liveSocket = null;
        liveKey = null;
    }

    function startLiveCandles(symbol, interval) {
        const key = `${symbol}|${interval}`;
        if (typeof WebSocket === 'undefined' || chartMode !== 'algo' || document.visibilityState !== 'visible') {
            stopLiveCandles();
            return;
        }
        if (liveSocket && liveKey === key && liveSocket.readyState <= 1) {
            return;
        }
        stopLiveCandles();
        liveKey = key;
        const ws = new WebSocket(`wss://fstream.binance.com/ws/${symbol.toLowerCase()}@kline_${interval}`);
        liveSocket = ws;
        ws.onopen = () => { liveRetry = 0; };
        ws.onmessage = (event) => {
            // Only patch the series when it holds this coin / timeframe (a switch may still be loading).
            if (liveKey !== `${activeSymbol}|${activeInterval}` || liveKey !== renderedKey || !candleSeries) return;
            let k;
            try { k = JSON.parse(event.data).k; } catch (e) { return; }
            if (!k) return;
            const time = Math.floor(Number(k.T) / 1000);
            const bar = { time, open: Number(k.o), high: Number(k.h), low: Number(k.l), close: Number(k.c) };
            try {
                candleSeries.update(bar);
                if (volumeSeries) {
                    volumeSeries.update({ time, value: Number(k.v), color: bar.close >= bar.open ? 'rgba(16, 185, 129, 0.35)' : 'rgba(239, 68, 68, 0.35)' });
                }
            } catch (e) {
                return; // older bar than the chart's last one (stale message)
            }
            const priceEl = document.getElementById('hud-price');
            if (priceEl) {
                priceEl.textContent = '$' + bar.close.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 4 });
            }
            if (k.x) {
                // Candle closed: new BUY/SELL labels may exist now; give the server a moment, then refresh quietly.
                setTimeout(() => fetchSignalAlgoData(activeSymbol, null, true), 2500);
            }
        };
        ws.onclose = () => {
            if (liveSocket !== ws) return;
            liveSocket = null;
            liveRetry = Math.min(liveRetry + 1, 6);
            liveRetryTimer = setTimeout(() => startLiveCandles(activeSymbol, activeInterval), 1000 * 2 ** liveRetry);
        };
        ws.onerror = () => { try { ws.close(); } catch (e) { /* ignore */ } };
    }

    function showAutoOrderNotification(order) {
        if (!order) return;

        let container = document.getElementById('autoOrderToastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'autoOrderToastContainer';
            container.className = 'fixed bottom-5 right-5 z-50 flex flex-col space-y-2 pointer-events-none max-w-sm';
            document.body.appendChild(container);
        }

        const isExecuted = order.status === 'opened' || order.status === 'executed' || order.status === 'reversed_and_opened';
        const isSame = order.status === 'same_direction';

        const toast = document.createElement('div');
        const borderClass = isExecuted
            ? 'border-emerald-500 bg-slate-900/95 text-white shadow-emerald-950/50'
            : (isSame ? 'border-cyan-500 bg-slate-900/95 text-slate-200 shadow-cyan-950/50' : 'border-slate-700 bg-slate-900/95 text-slate-300');

        toast.className = `p-3.5 rounded-xl shadow-2xl border text-xs pointer-events-auto transform transition-all duration-300 translate-y-3 opacity-0 flex items-start space-x-3 ${borderClass}`;

        const icon = isExecuted ? '🚀' : (isSame ? '🛡️' : 'ℹ️');
        const modeBadge = `<span class="px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider ${
            order.mode === 'live' ? 'bg-rose-500/20 text-rose-300 border border-rose-500/40' : 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/40'
        }">${order.mode || 'PAPER'} ORDER</span>`;

        toast.innerHTML = `
            <span class="text-xl leading-none mt-0.5">${icon}</span>
            <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between gap-2 mb-1">
                    <strong class="font-bold text-slate-100">${isExecuted ? 'Auto-Trade Placed' : 'Position Protected'}</strong>
                    ${modeBadge}
                </div>
                <div class="text-[11px] text-slate-300 leading-snug break-words">${order.message}</div>
                <div class="text-[10px] text-slate-400 font-mono mt-1">${order.symbol} • ${new Date().toLocaleTimeString()}</div>
            </div>
            <button type="button" class="text-slate-400 hover:text-white font-bold ml-1 text-sm cursor-pointer" onclick="this.parentElement.remove()">&times;</button>
        `;

        container.appendChild(toast);
        requestAnimationFrame(() => {
            toast.classList.remove('translate-y-3', 'opacity-0');
        });

        setTimeout(() => {
            toast.classList.add('opacity-0', 'translate-y-3');
            setTimeout(() => toast.remove(), 400);
        }, 8000);
    }

    window.loadSignalAt = function (time) {
        requestedSignalTime = time;
        loadFuturesChart(activeSymbol, false);
    };

    /** Used by the signal pop-up's "Open chart" button. */
    window.selectSymbol = function (symbol) {
        loadFuturesChart(String(symbol), true);
    };

    function loadFuturesChart(rawSymbol, autoScroll = true) {
        let clean = rawSymbol.toUpperCase().replace('.P', '').trim();
        if (!clean.endsWith('USDT') && !clean.includes(':')) {
            clean += 'USDT';
        }
        if (clean !== activeSymbol) {
            requestedSignalTime = 0;
        }
        activeSymbol = clean;

        // Clear existing trade levels from chart
        clearTradeLevels();

        // Abort previous pending fetch
        if (currentAbortController) {
            currentAbortController.abort();
        }
        currentAbortController = new AbortController();

        // Update titles & watermark immediately
        const titleEl = document.getElementById('headerSymbolTitle');
        if (titleEl) titleEl.textContent = activeSymbol;

        const watermarkEl = document.getElementById('chartWatermarkSymbol');
        if (watermarkEl) watermarkEl.textContent = 'BINANCE:' + activeSymbol + '.P';

        const chartWmtf = document.getElementById('chartWatermarkTf');
        if (chartWmtf) chartWmtf.textContent = activeInterval.toUpperCase();

        const contractEl = document.getElementById('hud-contract');
        if (contractEl) contractEl.textContent = activeSymbol + '.P';

        const binanceLink = document.getElementById('btnBinanceFuturesLink');
        if (binanceLink) binanceLink.href = 'https://www.binance.com/en/futures/' + activeSymbol;

        // Update button active state across all button groups
        document.querySelectorAll('.symbol-btn, .chart-symbol-btn').forEach(btn => {
            btn.classList.remove('bg-emerald-600', 'text-white', 'shadow');
            btn.classList.add('text-slate-300', 'text-slate-400');
        });
        document.querySelectorAll(`#btn-${activeSymbol}, #chart-btn-${activeSymbol}`).forEach(btn => {
            btn.classList.add('bg-emerald-600', 'text-white', 'shadow');
            btn.classList.remove('text-slate-300', 'text-slate-400');
        });

        // Loading overlay only when this coin / timeframe has never been loaded (otherwise it renders from memory)
        if (!chartCache.has(activeSymbol + '|' + activeInterval)) {
            showChartLoading(activeSymbol, activeInterval);
        }

        // On mobile devices, smoothly scroll to chart
        if (autoScroll && window.innerWidth < 1024) {
            const chartSection = document.getElementById('chartMainSection');
            if (chartSection) {
                chartSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        if (chartMode === 'tv') {
            initTradingViewWidget(activeSymbol);
            hideChartLoading();
        }

        // Trigger real-time SignalAlgo evaluation and plot signals on canvas
        fetchSignalAlgoData(activeSymbol, currentAbortController.signal);
    }

    function loadCustomFuturesChart() {
        const input = document.getElementById('customSymbolInput');
        if (input && input.value.trim()) {
            loadFuturesChart(input.value.trim(), true);
        }
    }

    function refreshAnalysis() {
        loadFuturesChart(activeSymbol, false);
    }

    function triggerTelegramAlert() {
        const btn = document.getElementById('btnSendAlert');
        const originalText = btn ? btn.innerHTML : '';

        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span>⏳</span><span>Dispatching Alert...</span>';
        }

        fetch('/dashboard/send-alert', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                symbol: activeSymbol,
                interval: activeInterval
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, true);
            } else {
                showToast(data.message || 'Failed to dispatch alert.', false);
            }
        })
        .catch(err => {
            showToast('Network error while dispatching alert.', false);
        })
        .finally(() => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        });
    }

    // ==========================================
    // 24/7 Sentinel Background Watcher Functions
    // ==========================================
    function resumeAutoTrading() {
        const bar = document.getElementById('tradingPauseBar');
        if (!confirm('Reset the pause and let the bot open new trades again now?')) return;
        fetch('{{ route('api.resume_cooldown', [], false) }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            body: JSON.stringify({ mode: bar ? bar.dataset.mode : undefined })
        })
        .then(res => res.json())
        .then(data => {
            showToast(data.message || (data.success ? 'Auto-trading resumed.' : 'Could not resume.'), !!data.success);
            pollDaemonStatus();
        })
        .catch(() => showToast('Network error while resuming auto-trading.', false));
    }

    function pollDaemonStatus() {
        fetch('{{ route('daemon.status') }}', { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.json())
        .then(data => {
            const st = data.stats || {};
            const set = (id, text, cls) => { const el = document.getElementById(id); if (el) { el.textContent = text; if (cls) el.className = cls; } };
            const engineOk = data.is_running;
            const alertsOn = data.sentinel_enabled;

            // Badge: alerts only really go out when the engine runs AND alerts are on AND Telegram is configured
            let label; let badgeCls; let dotCls;
            if (!engineOk) {
                label = 'ENGINE NOT RUNNING'; badgeCls = 'bg-rose-500/20 text-rose-300 border-rose-500/30'; dotCls = 'bg-rose-400';
            } else if (!alertsOn) {
                label = 'ALERTS OFF'; badgeCls = 'bg-slate-800 text-slate-300 border-slate-700'; dotCls = 'bg-slate-400';
            } else if (!st.telegram_configured) {
                label = 'TELEGRAM NOT CONFIGURED'; badgeCls = 'bg-amber-500/20 text-amber-300 border-amber-500/30'; dotCls = 'bg-amber-400';
            } else {
                label = 'ACTIVE · ALERTS ON'; badgeCls = 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30'; dotCls = 'bg-emerald-400 animate-pulse';
            }
            set('daemonStatusText', label);
            const badge = document.getElementById('daemonBadge');
            if (badge) badge.className = `inline-flex items-center space-x-1.5 px-3 py-1 rounded-full text-xs font-bold border ${badgeCls}`;
            const dot = document.getElementById('daemonDot');
            if (dot) dot.className = `w-2.5 h-2.5 rounded-full ${dotCls}`;

            document.getElementById('btnStartDaemon')?.classList.toggle('hidden', !!alertsOn);
            document.getElementById('btnStopDaemon')?.classList.toggle('hidden', !alertsOn);

            set('daemonEngine', String(st.engine_state || 'unknown').toUpperCase(), engineOk ? 'text-emerald-400' : 'text-rose-400');
            const age = st.heartbeat_age_seconds;
            set('daemonHeartbeat', (age !== undefined && age < 9999) ? `${age}s ago` : 'never', (age !== undefined && age <= 90) ? 'text-cyan-400' : 'text-rose-400');
            set('daemonLastScan', st.last_scan_at ? new Date(st.last_scan_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : 'not yet');
            set('daemonUniverse', st.universe_size ?? '--');
            set('daemonSignals', st.signals_last_scan ?? '--');
            set('daemonAlerts24h', st.alerts_sent_24h ?? 0);
            set('daemonTelegram', st.telegram_problem ? 'NOT delivering' : (st.telegram_configured ? 'connected' : 'not configured'), st.telegram_problem || !st.telegram_configured ? 'text-amber-300' : 'text-emerald-400');
            const tgEl = document.getElementById('daemonTelegram');
            if (tgEl) tgEl.title = st.telegram_problem || '';
            set('daemonCoinCountBadge', st.watchlist_count ?? 0);

            const pause = st.trading || null;
            const pauseBar = document.getElementById('tradingPauseBar');
            if (pauseBar) {
                pauseBar.classList.toggle('hidden', !pause);
                pauseBar.dataset.mode = pause ? pause.mode : '';
                set('tradingPauseText', pause ? `⏸️ [${pause.mode.toUpperCase()}] ${pause.reason}` : '');
                const pauseBtn = document.getElementById('tradingPauseBtn');
                if (pauseBtn) pauseBtn.classList.toggle('hidden', !pause || pause.kind === 'kill_switch');
            }

            const hint = document.getElementById('daemonCronHint');
            if (hint) hint.classList.toggle('hidden', !!engineOk);
            set('daemonCronCommand', '* * * * * ' + (st.cron_command || 'php artisan schedule:run'));
        })
        .catch(() => {});
    }

    function sendTestAlert() {
        const btn = document.getElementById('btnTestAlert');
        if (btn) btn.disabled = true;
        fetch('{{ route('daemon.test-alert') }}', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' } })
            .then(async res => {
                const data = await res.json().catch(() => ({}));
                const ok = res.ok && data.success;
                showToast(data.message || (ok ? 'Test alert sent.' : 'Test alert failed.'), ok);
                showDaemonFeedback(data.message || (ok ? 'Test alert sent.' : 'Test alert failed.'), ok);
            })
            .catch(() => showToast('Network error sending test alert.', false))
            .finally(() => { if (btn) btn.disabled = false; });
    }

    function showDaemonFeedback(msg, isSuccess = true) {
        const box = document.getElementById('daemonInlineFeedback');
        const content = document.getElementById('daemonFeedbackContent');
        if (!box || !content) return;

        box.className = isSuccess
            ? 'mt-4 p-3.5 rounded-xl text-xs font-medium border flex items-start justify-between bg-emerald-950/60 border-emerald-800/60 text-emerald-300 shadow'
            : 'mt-4 p-3.5 rounded-xl text-xs font-medium border flex items-start justify-between bg-amber-950/60 border-amber-800/60 text-amber-300 shadow';

        content.innerHTML = `
            <span class="text-base mr-1">${isSuccess ? '✅' : '⚠️'}</span>
            <div class="flex-1">${msg}</div>
        `;
        box.classList.remove('hidden');
    }

    function copyToClipboard(text, successMsg = 'Copied to clipboard!') {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(() => {
                showToast(successMsg, true);
            }).catch(() => {
                showToast('Command: ' + text, true);
            });
        } else {
            showToast('Command: ' + text, true);
        }
    }

    function startDaemon() {
        const btn = document.getElementById('btnStartDaemon');
        const textSpan = document.getElementById('btnStartDaemonText');
        if (btn) btn.disabled = true;
        if (textSpan) textSpan.innerHTML = 'Turning on...';

        
        fetch('{{ route('daemon.start') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            }
        })
        .then(async res => {
            const data = await res.json();
            if (res.ok && data.success) {
                showToast(data.message, true);
                showDaemonFeedback(data.message, true);
                pollDaemonStatus();
                setTimeout(pollDaemonStatus, 2000);
            } else {
                showToast(data.message || 'Could not turn alerts on.', false);
                showDaemonFeedback(data.message || 'Could not turn alerts on.', false);
            }
        })
        .catch(err => {
            showToast('Network error.', false);
        })
        .finally(() => {
            if (btn) btn.disabled = false;
            if (textSpan) textSpan.innerHTML = 'Turn Alerts ON';
        });
    }

    function stopDaemon() {
        const btn = document.getElementById('btnStopDaemon');
        const textSpan = document.getElementById('btnStopDaemonText');
        if (btn) btn.disabled = true;
        if (textSpan) textSpan.innerHTML = 'Turning off...';

        
        fetch('{{ route('daemon.stop') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            }
        })
        .then(res => res.json())
        .then(data => {
            showToast(data.message, data.success);
            showDaemonFeedback(data.message, data.success);
            setTimeout(pollDaemonStatus, 1500);
        })
        .catch(err => {
            showToast('Could not turn alerts off.', false);
        })
        .finally(() => {
            if (btn) btn.disabled = false;
            if (textSpan) textSpan.innerHTML = 'Turn Alerts OFF';
        });
    }

    // ==========================================
    // 24/7 Monitored Coins Management Functions
    // ==========================================
    let cachedMonitoredCoins = [];
    let isCoinsLoaded = false;

    function toggleCoinManager() {
        const panel = document.getElementById('coinManagerPanel');
        if (!panel) return;
        panel.classList.toggle('hidden');
        if (!panel.classList.contains('hidden') && !isCoinsLoaded) {
            loadMonitoredCoins();
        }
    }

    function loadMonitoredCoins() {
        fetch('{{ route('daemon.monitored-coins') }}', {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(res => res.json())
        .then(data => {
            if (!data.success) return;
            isCoinsLoaded = true;
            cachedMonitoredCoins = data.coins || [];
            renderMonitoredCoinsPills(cachedMonitoredCoins);
            updateMonitoredCoinsUI(data.coins, data.scan_mode);
        })
        .catch(() => {});
    }

    function coinLabel(symbol) {
        return symbol.replace('1000', '').replace('USDT', '');
    }

    /** Rebuild the header and in-chart coin buttons from the monitored list (same markup as the Blade loops). */
    function renderCoinButtons(coins) {
        const active = (c) => c === activeSymbol ? 'bg-emerald-600 text-white shadow' : '';
        const header = document.getElementById('headerCoinButtons');
        if (header) {
            header.innerHTML = coins.map(c => `
                <button type="button" onclick="loadFuturesChart('${c}', true)" id="btn-${c}"
                    class="px-3 py-1.5 rounded-lg text-slate-300 hover:text-white hover:bg-slate-800 font-semibold transition symbol-btn whitespace-nowrap active:scale-95 touch-manipulation cursor-pointer ${active(c)}">${coinLabel(c)}</button>`).join('');
        }
        const row = document.getElementById('chartCoinButtons');
        if (row) {
            row.innerHTML = coins.map(c => `
                <span class="inline-flex items-center group">
                    <button type="button" onclick="loadFuturesChart('${c}', false)" id="chart-btn-${c}" title="Monitored: alerts + swing auto-trade (15m confirmed flips)"
                        class="pl-2.5 pr-1 py-1 rounded-lg text-slate-400 hover:text-white font-bold text-xs transition chart-symbol-btn whitespace-nowrap active:scale-95 touch-manipulation cursor-pointer inline-flex items-center space-x-1 ${active(c)}">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span><span>${coinLabel(c)}</span>
                    </button>
                    <button type="button" onclick="removeCoinFromChart('${c}')" title="Remove ${c}" class="px-1 text-slate-600 hover:text-rose-400 text-[10px] font-bold cursor-pointer">✕</button>
                </span>`).join('');
        }
    }

    function addCoinFromChart() {
        const input = document.getElementById('chartAddCoinInput');
        const value = input ? input.value.trim() : '';
        if (!value) return;
        addMonitoredCoin(value);
        input.value = '';
    }

    function removeCoinFromChart(symbol) {
        if (!confirm(`Remove ${symbol} from the monitored coins? Alerts and new swing trades on it stop (an open trade is still managed).`)) {
            return;
        }
        removeMonitoredCoin(symbol);
    }

    function updateMonitoredCoinsUI(coins) {
        cachedMonitoredCoins = coins || [];
        renderCoinButtons(cachedMonitoredCoins);
        const countBadge = document.getElementById('daemonCoinCountBadge');
        const managerBadge = document.getElementById('coinManagerCountBadge');
        if (countBadge) countBadge.textContent = cachedMonitoredCoins.length;
        if (managerBadge) managerBadge.textContent = `${cachedMonitoredCoins.length} coins`;
        renderMonitoredCoinsPills(cachedMonitoredCoins);
    }

    function renderMonitoredCoinsPills(coins) {
        const container = document.getElementById('monitoredCoinsPillContainer');
        if (!container) return;

        if (!coins || coins.length === 0) {
            container.innerHTML = '<span class="text-amber-400 text-xs italic">No coins currently in list. Add coins above.</span>';
            return;
        }

        let html = '';
        coins.forEach(symbol => {
            const clean = symbol.replace('USDT', '');
            html += `
                <span class="inline-flex items-center space-x-1.5 px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-slate-800 text-slate-200 border border-slate-700 hover:border-emerald-500/50 transition shadow-sm group">
                    <span class="text-emerald-400">${clean}</span>
                    <span class="text-[10px] text-slate-500 font-normal">USDT</span>
                    <button type="button" onclick="removeMonitoredCoin('${symbol}')" title="Remove ${symbol}"
                        class="ml-1 text-slate-500 hover:text-rose-400 transition font-bold text-xs p-0.5 rounded cursor-pointer leading-none">
                        ✕
                    </button>
                </span>
            `;
        });
        container.innerHTML = html;
    }

    function addMonitoredCoin(customSymbol = null) {
        const input = document.getElementById('newCoinInput');
        const symbol = customSymbol || (input ? input.value : '');

        if (!symbol || !symbol.trim()) {
            showToast('Please enter a coin symbol (e.g. ADA or ADAUSDT).', false);
            return;
        }

        fetch('{{ route('daemon.monitored-coins.add') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ symbol: symbol.trim() })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, true);
                if (input) input.value = '';
                cachedMonitoredCoins = data.coins || [];
                renderMonitoredCoinsPills(cachedMonitoredCoins);
                updateMonitoredCoinsUI(data.coins, data.scan_mode);
                pollDaemonStatus();
            } else {
                showToast(data.message || 'Failed to add coin.', false);
            }
        })
        .catch(() => {
            showToast('Network error while adding coin.', false);
        });
    }

    function quickAddCoin(symbol) {
        addMonitoredCoin(symbol);
    }

    function removeMonitoredCoin(symbol) {
        fetch('{{ route('daemon.monitored-coins.remove') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ symbol: symbol })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, true);
                cachedMonitoredCoins = data.coins || [];
                renderMonitoredCoinsPills(cachedMonitoredCoins);
                updateMonitoredCoinsUI(data.coins, data.scan_mode);
                pollDaemonStatus();
            } else {
                showToast(data.message || 'Failed to remove coin.', false);
            }
        })
        .catch(() => {
            showToast('Network error while removing coin.', false);
        });
    }

    function resetMonitoredCoins() {
        if (!confirm('Reset monitored coins back to the default 10 institutional core pairs (BTC, ETH, SOL, BNB, XRP, DOGE, NEAR, AVAX, SUI, PEPE)?')) {
            return;
        }

        fetch('{{ route('daemon.monitored-coins.reset') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, true);
                cachedMonitoredCoins = data.coins || [];
                renderMonitoredCoinsPills(cachedMonitoredCoins);
                updateMonitoredCoinsUI(data.coins, data.scan_mode);
                pollDaemonStatus();
            } else {
                showToast(data.message || 'Failed to reset coins.', false);
            }
        })
        .catch(() => {
            showToast('Network error while resetting coins.', false);
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        initAlgoCanvasChart();
        loadFuturesChart(activeSymbol);
        pollDaemonStatus();
        loadMonitoredCoins();

        // Check daemon status every 10 seconds
        setInterval(pollDaemonStatus, 10000);

        // Auto-refresh chart & synchronize signals every 60 seconds
        setInterval(function() {
            if (document.visibilityState === 'visible' && chartMode === 'algo') {
                fetchSignalAlgoData(activeSymbol, null, true);
            }
        }, 60000);

        // Instant wakeup on mobile tab re-open / screen un-lock
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'visible') {
                pollDaemonStatus();
                fetchSignalAlgoData(activeSymbol, null, true);
            } else {
                stopLiveCandles();
            }
        });
    });
</script>
@endsection
