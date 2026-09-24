@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <!-- Header banner -->
    <div class="p-8 rounded-2xl bg-gradient-to-r from-slate-900 via-slate-850 to-slate-900 border border-slate-800 shadow-xl mb-8 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <div class="flex items-center space-x-3">
                <h1 class="text-3xl font-extrabold text-white tracking-tight">Signal Monitoring Dashboard</h1>
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
            <p class="mt-2 text-slate-400 text-sm">
                Welcome back, <strong class="text-white">{{ $user->name }}</strong> ({{ $user->email }}). Polling live candlestick data from <strong>{{ $cryptoConfig['market_label'] }}</strong>.
            </p>
        </div>

        <div class="flex items-center space-x-3">
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
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <!-- Card 1: Timeframes -->
        <div class="p-6 rounded-2xl bg-slate-900/80 border border-slate-800 shadow">
            <div class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">Market &amp; Timeframe</div>
            <div class="text-2xl font-bold text-white flex items-baseline space-x-2">
                <span>{{ $cryptoConfig['interval'] }}</span>
                <span class="text-sm font-normal text-slate-400">/ HTF {{ $cryptoConfig['htf_interval'] }}</span>
            </div>
            <p class="text-xs text-cyan-400 mt-2 font-mono">{{ $cryptoConfig['market_label'] }}</p>
        </div>

        <!-- Card 2: Confidence Threshold -->
        <div class="p-6 rounded-2xl bg-slate-900/80 border border-slate-800 shadow">
            <div class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">Min Confidence Score</div>
            <div class="text-2xl font-bold text-emerald-400">{{ $cryptoConfig['min_score'] }} <span class="text-sm text-slate-400 font-normal">/ 100</span></div>
            <p class="text-xs text-slate-500 mt-2">Requires multi-factor confluence</p>
        </div>

        <!-- Card 3: Alert Cooldown -->
        <div class="p-6 rounded-2xl bg-slate-900/80 border border-slate-800 shadow">
            <div class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">Alert Cooldown</div>
            <div class="text-2xl font-bold text-white">{{ $cryptoConfig['cooldown_minutes'] }} <span class="text-sm text-slate-400 font-normal">mins</span></div>
            <p class="text-xs text-slate-500 mt-2">~5 candles on 15m timeframe</p>
        </div>

        <!-- Card 4: Telegram Channel -->
        <div class="p-6 rounded-2xl bg-slate-900/80 border border-slate-800 shadow">
            <div class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">Telegram Alerts</div>
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

    <!-- 24/7 Automated Background Watcher Sentinel -->
    <div class="p-5 sm:p-6 rounded-2xl bg-gradient-to-r from-slate-900 via-indigo-950/40 to-slate-900 border border-indigo-500/30 shadow-2xl mb-8 relative overflow-hidden">
        <div class="absolute -right-16 -top-16 w-64 h-64 bg-indigo-500/10 rounded-full blur-3xl pointer-events-none"></div>
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4">
            <div class="w-full lg:w-auto">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center px-3 py-1 rounded-lg text-xs font-black tracking-wider bg-indigo-500/20 text-indigo-300 border border-indigo-500/40 uppercase shadow-sm">
                        🤖 24/7 Sentinel Watcher
                    </span>
                    <span id="daemonBadge" class="inline-flex items-center space-x-1.5 px-3 py-1 rounded-full text-xs font-bold bg-slate-800 text-slate-400 border border-slate-700">
                        <span id="daemonDot" class="w-2.5 h-2.5 rounded-full bg-slate-500"></span>
                        <span id="daemonStatusText">Checking Sentinel...</span>
                    </span>
                </div>
                <h3 class="text-lg font-bold text-white mt-2">
                    Automated Candle Close Signal Dispatcher
                </h3>
                <p class="text-xs text-slate-400 max-w-2xl mt-1">
                    Continuously scans your chosen monitored coins and whole-market breakouts in the background. As soon as a candle closes with a verified SignalAlgo PRO BUY or SELL condition, it automatically records it and dispatches an instant Telegram alert—<strong>without needing your browser open</strong>.
                </p>
                <div class="flex flex-wrap items-center gap-2 sm:gap-3 mt-3 text-xs font-mono text-slate-400" id="daemonStatsRow">
                    <span class="bg-slate-950/60 px-2.5 py-1 rounded-md border border-slate-800">
                        Cycles: <strong id="daemonCycles" class="text-indigo-300">--</strong>
                    </span>
                    <span class="bg-slate-950/60 px-2.5 py-1 rounded-md border border-slate-800">
                        Monitored: <strong id="daemonMonitoredCount" class="text-emerald-400">10 Coins</strong>
                    </span>
                    <span class="bg-slate-950/60 px-2.5 py-1 rounded-md border border-slate-800">
                        Mode: <strong id="daemonScanModeLabel" class="text-cyan-300">Monitored + Breakouts</strong>
                    </span>
                    <span class="bg-slate-950/60 px-2.5 py-1 rounded-md border border-slate-800">
                        Last Scan: <strong id="daemonLastScan" class="text-slate-200">--</strong>
                    </span>
                    <span class="bg-slate-950/60 px-2.5 py-1 rounded-md border border-slate-800">
                        Heartbeat: <strong id="daemonHeartbeat" class="text-cyan-400">--</strong>
                    </span>
                </div>
            </div>

            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2.5 w-full lg:w-auto justify-start sm:justify-end">
                <button type="button" id="btnStartDaemon" onclick="startDaemon()"
                    class="w-full sm:w-auto px-5 py-2.5 text-xs sm:text-sm font-bold rounded-xl bg-emerald-600 hover:bg-emerald-500 active:bg-emerald-700 text-white shadow-lg shadow-emerald-900/30 border border-emerald-500 transition flex items-center justify-center space-x-2 touch-manipulation cursor-pointer">
                    <span>▶</span>
                    <span id="btnStartDaemonText">Start Sentinel Watcher</span>
                </button>
                <button type="button" id="btnStopDaemon" onclick="stopDaemon()"
                    class="hidden w-full sm:w-auto px-5 py-2.5 text-xs sm:text-sm font-bold rounded-xl bg-rose-600/80 hover:bg-rose-600 active:bg-rose-700 text-white shadow-lg shadow-rose-900/30 border border-rose-500 transition flex items-center justify-center space-x-2 touch-manipulation cursor-pointer">
                    <span>⏹</span>
                    <span id="btnStopDaemonText">Stop Sentinel</span>
                </button>
                <div class="flex items-center space-x-2 justify-end">
                    <button type="button" onclick="toggleCoinManager()" title="Manage Monitored Coins"
                        class="px-3 py-2.5 text-xs font-semibold text-emerald-300 hover:text-white bg-slate-800 hover:bg-slate-700 rounded-xl border border-emerald-500/40 hover:border-emerald-400 transition flex items-center space-x-1.5 touch-manipulation cursor-pointer">
                        <span>🪙</span>
                        <span>Manage Coins</span>
                        <span id="daemonCoinCountBadge" class="ml-1 px-1.5 py-0.5 rounded-full bg-emerald-500/20 text-[10px] text-emerald-300 font-mono">10</span>
                    </button>
                    <button type="button" onclick="toggleServerInstructions()" title="Server Setup (Cron / Terminal)"
                        class="px-3 py-2.5 text-xs font-semibold text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-700 rounded-xl border border-slate-700 transition flex items-center space-x-1.5 touch-manipulation">
                        <span>📋</span>
                        <span>Server CLI / Cron</span>
                    </button>
                    <button type="button" onclick="pollDaemonStatus()" title="Refresh Status"
                        class="p-2.5 text-slate-400 hover:text-white bg-slate-800 hover:bg-slate-700 rounded-xl border border-slate-700 transition touch-manipulation">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Inline Message / Feedback Box -->
        <div id="daemonInlineFeedback" class="hidden mt-4 p-3.5 rounded-xl text-xs font-medium border flex items-start justify-between">
            <div id="daemonFeedbackContent" class="flex items-start space-x-2"></div>
            <button type="button" onclick="document.getElementById('daemonInlineFeedback').classList.add('hidden')" class="text-slate-400 hover:text-white font-bold text-sm ml-2">&times;</button>
        </div>

        <!-- Collapsible Server Setup & Cron Commands Panel -->
        <div id="serverInstructionsPanel" class="hidden mt-4 p-4 rounded-xl bg-slate-950/80 border border-slate-800 text-xs">
            <div class="flex justify-between items-center mb-3">
                <div class="font-bold text-slate-200 flex items-center space-x-1.5">
                    <span>⚡ Production Server 24/7 Setup Guide</span>
                    <span class="text-[10px] px-2 py-0.5 rounded bg-cyan-950 text-cyan-300 border border-cyan-800/60 font-mono">Linux / VPS / cPanel / Windows</span>
                </div>
                <button type="button" onclick="toggleServerInstructions()" class="text-slate-400 hover:text-white">&times;</button>
            </div>
            <p class="text-slate-400 mb-3">
                In production (cPanel, Ubuntu, VPS, AWS, Docker), web servers often restrict long-running processes spawned via browser clicks. Use either of these standard server methods to ensure 100% 24/7 uptime without needing a browser open:
            </p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <!-- Option A: Standard Cron -->
                <div class="p-3 rounded-lg bg-slate-900 border border-slate-800">
                    <div class="font-semibold text-emerald-400 mb-1 flex items-center justify-between">
                        <span>Option 1: Server Crontab (Recommended)</span>
                        <button type="button" onclick="copyToClipboard('* * * * * cd {{ base_path() }} && php artisan schedule:run >> /dev/null 2>&1', 'Cron command copied!')" class="text-[10px] text-slate-400 hover:text-emerald-300">Copy</button>
                    </div>
                    <p class="text-[11px] text-slate-400 mb-2">Runs every minute via Laravel Scheduler. Scans all 10 coins automatically:</p>
                    <code class="block p-2 rounded bg-slate-950 text-slate-300 font-mono text-[11px] select-all break-all border border-slate-800">
                        * * * * * cd {{ base_path() }} && php artisan schedule:run >> /dev/null 2>&1
                    </code>
                </div>

                <!-- Option B: Continuous Background CLI -->
                <div class="p-3 rounded-lg bg-slate-900 border border-slate-800">
                    <div class="font-semibold text-cyan-400 mb-1 flex items-center justify-between">
                        <span>Option 2: Terminal / SSH Daemon</span>
                        <button type="button" onclick="copyToClipboard('nohup php artisan crypto:watch-signals --sleep=25 > storage/logs/watcher.log 2>&1 &', 'CLI command copied!')" class="text-[10px] text-slate-400 hover:text-cyan-300">Copy</button>
                    </div>
                    <p class="text-[11px] text-slate-400 mb-2">Continuous 25s loop running in background:</p>
                    <code class="block p-2 rounded bg-slate-950 text-slate-300 font-mono text-[11px] select-all break-all border border-slate-800">
                        nohup php artisan crypto:watch-signals --sleep=25 > storage/logs/watcher.log 2>&1 &
                    </code>
                </div>
            </div>
        </div>

        <!-- Collapsible Monitored Coins Management Panel -->
        <div id="coinManagerPanel" class="hidden mt-4 p-5 rounded-xl bg-slate-950/95 border border-emerald-500/30 text-xs shadow-xl">
            <div class="flex flex-wrap justify-between items-center gap-2 mb-4 pb-3 border-b border-slate-800">
                <div class="font-bold text-white flex items-center space-x-2 text-sm">
                    <span>🪙 24/7 Monitored Coins Management</span>
                    <span id="coinManagerCountBadge" class="text-xs px-2.5 py-0.5 rounded-full bg-emerald-950 text-emerald-300 border border-emerald-800 font-mono">10 Coins Active</span>
                </div>
                <div class="flex items-center space-x-2">
                    <button type="button" onclick="resetMonitoredCoins()"
                        class="px-2.5 py-1.5 text-[11px] font-semibold text-slate-300 hover:text-rose-300 bg-slate-900 hover:bg-rose-950/40 border border-slate-700 hover:border-rose-700/50 rounded-lg transition flex items-center space-x-1 cursor-pointer">
                        <span>↺</span>
                        <span>Reset to Core 10</span>
                    </button>
                    <button type="button" onclick="toggleCoinManager()" class="text-slate-400 hover:text-white font-bold text-base p-1 cursor-pointer">&times;</button>
                </div>
            </div>

            <!-- Scan Mode Selector -->
            <div class="p-3 mb-4 rounded-xl bg-slate-900/80 border border-slate-800 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2.5">
                <div>
                    <div class="font-semibold text-slate-200 text-xs">Sentinel Operational Mode:</div>
                    <div class="text-[11px] text-slate-400">Choose whether to scan only your chosen coins or also detect breakouts market-wide.</div>
                </div>
                <div class="flex items-center space-x-1.5 bg-slate-950 p-1 rounded-lg border border-slate-800 text-xs">
                    <button type="button" id="btnModeBoth" onclick="changeScanMode('both')" class="px-2.5 py-1 rounded font-semibold transition text-emerald-300 bg-emerald-950/60 border border-emerald-800/60 cursor-pointer">
                        ⚡ Monitored + Breakouts
                    </button>
                    <button type="button" id="btnModeMonitored" onclick="changeScanMode('monitored_only')" class="px-2.5 py-1 rounded font-semibold transition text-slate-400 hover:text-white cursor-pointer">
                        🎯 Monitored Only
                    </button>
                </div>
            </div>

            <!-- Add Coin Input Form -->
            <div class="mb-4">
                <form onsubmit="event.preventDefault(); addMonitoredCoin();" class="flex flex-col sm:flex-row gap-2">
                    <div class="relative flex-1">
                        <input type="text" id="newCoinInput" placeholder="Enter coin symbol (e.g. ADAUSDT, LINK, DOT, PEPE)"
                            class="w-full uppercase px-3.5 py-2 text-xs rounded-xl bg-slate-900 text-white placeholder-slate-500 border border-slate-700 focus:outline-none focus:border-emerald-500 font-mono tracking-wider">
                    </div>
                    <button type="button" onclick="addMonitoredCoin()"
                        class="px-4 py-2 text-xs font-bold rounded-xl bg-emerald-600 hover:bg-emerald-500 active:bg-emerald-700 text-white shadow-md shadow-emerald-950/40 border border-emerald-500 transition flex items-center justify-center space-x-1.5 cursor-pointer">
                        <span>+</span>
                        <span>Add Coin</span>
                    </button>
                </form>
                <div class="flex flex-wrap items-center gap-1.5 mt-2 text-[11px] text-slate-400">
                    <span class="text-slate-500">Quick add:</span>
                    <button type="button" onclick="quickAddCoin('ADAUSDT')" class="px-2 py-0.5 rounded bg-slate-900 hover:bg-emerald-950/50 hover:text-emerald-300 border border-slate-800 text-slate-300 font-mono text-[10px] transition cursor-pointer">+ ADA</button>
                    <button type="button" onclick="quickAddCoin('LINKUSDT')" class="px-2 py-0.5 rounded bg-slate-900 hover:bg-emerald-950/50 hover:text-emerald-300 border border-slate-800 text-slate-300 font-mono text-[10px] transition cursor-pointer">+ LINK</button>
                    <button type="button" onclick="quickAddCoin('DOTUSDT')" class="px-2 py-0.5 rounded bg-slate-900 hover:bg-emerald-950/50 hover:text-emerald-300 border border-slate-800 text-slate-300 font-mono text-[10px] transition cursor-pointer">+ DOT</button>
                    <button type="button" onclick="quickAddCoin('UNIUSDT')" class="px-2 py-0.5 rounded bg-slate-900 hover:bg-emerald-950/50 hover:text-emerald-300 border border-slate-800 text-slate-300 font-mono text-[10px] transition cursor-pointer">+ UNI</button>
                    <button type="button" onclick="quickAddCoin('PEPEUSDT')" class="px-2 py-0.5 rounded bg-slate-900 hover:bg-emerald-950/50 hover:text-emerald-300 border border-slate-800 text-slate-300 font-mono text-[10px] transition cursor-pointer">+ PEPE</button>
                    <button type="button" onclick="quickAddCoin('XLMUSDT')" class="px-2 py-0.5 rounded bg-slate-900 hover:bg-emerald-950/50 hover:text-emerald-300 border border-slate-800 text-slate-300 font-mono text-[10px] transition cursor-pointer">+ XLM</button>
                </div>
            </div>

            <!-- Active Monitored Coins Grid / Pills -->
            <div>
                <div class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-2 flex items-center justify-between">
                    <span>Currently Monitored Coins:</span>
                    <span class="text-slate-500 font-normal">Click ✕ to remove any coin</span>
                </div>
                <div id="monitoredCoinsPillContainer" class="flex flex-wrap gap-2 min-h-[48px] p-3 rounded-xl bg-slate-900/60 border border-slate-800">
                    <span class="text-slate-500 text-xs italic">Loading coins...</span>
                </div>
            </div>
        </div>
    </div>

    <!-- On-Demand Whole-Market Scanner (php artisan crypto:check-signals --all --dry-run) -->
    <div class="p-5 sm:p-6 rounded-2xl bg-gradient-to-r from-slate-900 via-slate-900 to-indigo-950/40 border border-slate-800 hover:border-indigo-500/30 shadow-2xl mb-8 relative overflow-hidden transition">
        <div class="absolute -right-16 -top-16 w-64 h-64 bg-cyan-500/10 rounded-full blur-3xl pointer-events-none"></div>

        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4">
            <div class="w-full lg:w-auto">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center px-3 py-1 rounded-lg text-xs font-black tracking-wider bg-cyan-500/20 text-cyan-300 border border-cyan-500/40 uppercase shadow-sm">
                        ⚡ Whole-Market Scanner
                    </span>
                    <span id="marketScanStatusBadge" class="inline-flex items-center space-x-1.5 px-3 py-1 rounded-full text-xs font-bold bg-slate-800 text-slate-400 border border-slate-700">
                        <span id="marketScanStatusDot" class="w-2.5 h-2.5 rounded-full bg-slate-500"></span>
                        <span id="marketScanStatusText">IDLE</span>
                    </span>
                    <span class="text-[11px] font-mono px-2 py-0.5 rounded bg-slate-950 text-slate-400 border border-slate-800 hidden sm:inline">
                        php artisan crypto:check-signals --all --dry-run
                    </span>
                </div>
                <h3 class="text-lg font-bold text-white mt-2">
                    On-Demand Institutional Setup Scanner
                </h3>
                <p class="text-xs text-slate-400 max-w-2xl mt-1">
                    Scan all ~350 Binance USDT Perpetual contracts in real-time. Detects breakout setups, multi-timeframe HTF alignment, momentum confluence, and displays trade levels (Entry, SL, TP1–3) without sending Telegram alerts.
                </p>

                <!-- Scan Metrics Bar -->
                <div class="flex flex-wrap items-center gap-2 sm:gap-3 mt-3 text-xs font-mono text-slate-400">
                    <span class="bg-slate-950/60 px-2.5 py-1 rounded-md border border-slate-800">
                        Scanned: <strong id="scanProgressCount" class="text-cyan-400">0 / 0</strong>
                    </span>
                    <span class="bg-slate-950/60 px-2.5 py-1 rounded-md border border-slate-800">
                        Current: <strong id="scanCurrentSymbol" class="text-slate-200">None</strong>
                    </span>
                    <span class="bg-slate-950/60 px-2.5 py-1 rounded-md border border-slate-800">
                        Setups Found: <strong id="scanSignalsCount" class="text-emerald-400">0</strong>
                    </span>
                    <span class="bg-slate-950/60 px-2.5 py-1 rounded-md border border-slate-800">
                        Started: <strong id="scanStartedAt" class="text-indigo-300">--</strong>
                    </span>
                </div>
            </div>

            <!-- Controls -->
            <div class="flex flex-wrap items-center gap-2.5 w-full lg:w-auto justify-start sm:justify-end">
                <button type="button" id="btnStartMarketScan" onclick="startMarketScan()"
                    class="px-5 py-2.5 text-xs sm:text-sm font-bold rounded-xl bg-gradient-to-r from-cyan-600 to-indigo-600 hover:from-cyan-500 hover:to-indigo-500 active:from-cyan-700 active:to-indigo-700 text-white shadow-lg shadow-cyan-900/30 border border-cyan-400/40 transition flex items-center justify-center space-x-2 touch-manipulation cursor-pointer">
                    <span>▶</span>
                    <span id="btnStartMarketScanText">Run Whole-Market Scan</span>
                </button>
                <button type="button" id="btnStopMarketScan" onclick="stopMarketScan()"
                    class="hidden px-5 py-2.5 text-xs sm:text-sm font-bold rounded-xl bg-rose-600 hover:bg-rose-500 active:bg-rose-700 text-white shadow-lg shadow-rose-900/30 border border-rose-500 transition flex items-center justify-center space-x-2 touch-manipulation cursor-pointer">
                    <span class="animate-pulse">⏹</span>
                    <span>Stop Scan</span>
                </button>
                <button type="button" id="btnClearMarketScan" onclick="clearMarketScan()" title="Reset Results and Logs"
                    class="px-3.5 py-2.5 text-xs font-semibold text-slate-400 hover:text-white bg-slate-800 hover:bg-slate-700 rounded-xl border border-slate-700 transition flex items-center space-x-1 touch-manipulation cursor-pointer">
                    <span>🗑</span>
                    <span class="hidden sm:inline">Clear</span>
                </button>
                <button type="button" onclick="toggleTerminalConsole()" title="Show/Hide Terminal Console"
                    class="px-3.5 py-2.5 text-xs font-semibold text-slate-400 hover:text-white bg-slate-800 hover:bg-slate-700 rounded-xl border border-slate-700 transition flex items-center space-x-1.5 touch-manipulation cursor-pointer">
                    <span>💻</span>
                    <span id="btnTerminalToggleText">Console</span>
                </button>
            </div>
        </div>

        <!-- Animated Progress Bar -->
        <div id="scanProgressBarContainer" class="mt-4 pt-3 border-t border-slate-800/80">
            <div class="flex justify-between items-center text-xs mb-1.5">
                <span class="text-slate-400 font-medium flex items-center space-x-1.5">
                    <span id="scanProgressSpinner" class="hidden w-2 h-2 rounded-full bg-cyan-400 animate-ping"></span>
                    <span id="scanProgressStatusLabel">Market Scan Progress</span>
                </span>
                <span id="scanProgressPercent" class="font-mono font-bold text-cyan-400">0%</span>
            </div>
            <div class="w-full h-2 bg-slate-950 rounded-full overflow-hidden border border-slate-800 p-0.5">
                <div id="scanProgressBarFill" class="h-full bg-gradient-to-r from-cyan-500 via-indigo-500 to-emerald-500 rounded-full transition-all duration-300 w-0"></div>
            </div>
        </div>

        <!-- Detected Signals Container -->
        <div class="mt-5">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center space-x-2">
                    <h4 class="text-sm font-bold text-slate-200">
                        🎯 Detected Institutional Setups
                    </h4>
                    <span id="signalsBadgeCount" class="text-xs px-2 py-0.5 rounded-full font-bold bg-slate-800 text-slate-400 border border-slate-700">0 Found</span>
                </div>
                <span class="text-[11px] text-slate-500 hidden sm:inline">Click "Inspect Chart" on any card to view interactive chart</span>
            </div>

            <div id="scanSignalsGrid" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3.5">
                <!-- Empty state shown by default -->
                <div id="scanSignalsEmptyState" class="col-span-full py-8 text-center rounded-xl bg-slate-950/40 border border-dashed border-slate-800 text-slate-500 text-xs">
                    <div class="text-2xl mb-1.5">🔭</div>
                    No setups detected yet. Click <strong class="text-slate-400">"Run Whole-Market Scan"</strong> to evaluate all ~350 USDT Perpetual contracts.
                </div>
            </div>
        </div>

        <!-- Collapsible Raw Terminal Output Console -->
        <div id="terminalConsolePanel" class="hidden mt-5 pt-4 border-t border-slate-800/80">
            <div class="flex justify-between items-center mb-2">
                <div class="flex items-center space-x-2 font-mono text-xs text-slate-300">
                    <span class="w-2.5 h-2.5 rounded-full bg-slate-600"></span>
                    <span class="font-semibold">CLI Output Stream (storage/logs/manual_scan.log)</span>
                </div>
                <div class="flex items-center space-x-2">
                    <button type="button" onclick="copyMarketScanLog()" class="text-xs px-2.5 py-1 rounded bg-slate-800 hover:bg-slate-700 text-slate-300 transition">
                        Copy Log
                    </button>
                    <button type="button" onclick="toggleTerminalConsole()" class="text-slate-400 hover:text-white font-bold text-sm ml-2">&times;</button>
                </div>
            </div>
            <pre id="marketScanTerminalLog" class="p-4 rounded-xl bg-slate-950 text-slate-300 font-mono text-[11px] leading-relaxed max-h-72 overflow-y-auto border border-slate-800 select-all whitespace-pre-wrap">Terminal stream ready...</pre>
        </div>
    </div>

    <!-- Real-Time SignalAlgo PRO Command Center & Binance Futures Chart -->
    <div class="p-6 rounded-2xl bg-slate-900/90 border border-slate-800 shadow-2xl mb-8">
        <!-- SignalAlgo PRO Header -->
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-5 pb-5 border-b border-slate-800/80">
            <div>
                <div class="flex items-center space-x-2.5">
                    <span class="inline-flex items-center px-3 py-1 rounded-lg text-xs font-black tracking-wider bg-gradient-to-r from-emerald-500/20 to-cyan-500/20 text-emerald-400 border border-emerald-500/30 uppercase shadow-sm">
                        ⚡ SignalAlgo PRO™ v3.2
                    </span>
                    <span class="inline-flex items-center space-x-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-800 text-slate-300 border border-slate-700">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
                        <span>Perpetual Engine Live</span>
                    </span>
                    <span class="hidden sm:inline text-xs text-slate-400">• Multi-Factor Futures Confluence</span>
                </div>
                <h2 class="text-xl sm:text-2xl font-black text-white mt-1.5 tracking-tight flex items-center space-x-2">
                    <span id="headerSymbolTitle">BTCUSDT</span>
                    <span class="text-sm font-medium text-slate-400">USDⓈ-M Perpetual Contract</span>
                </h2>
            </div>

            <!-- Quick Switcher & Custom Coin Search -->
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex items-center bg-slate-950/80 p-1 rounded-xl border border-slate-800 text-xs shadow-inner overflow-x-auto max-w-full touch-pan-x select-none">
                    @foreach (['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT', 'DOGEUSDT', 'NEARUSDT', 'AVAXUSDT', 'SUIUSDT', '1000PEPEUSDT'] as $quickSymbol)
                        <button type="button" onclick="loadFuturesChart('{{ $quickSymbol }}', true)"
                            class="px-3 py-1.5 rounded-lg text-slate-300 hover:text-white hover:bg-slate-800 font-semibold transition symbol-btn whitespace-nowrap active:scale-95 touch-manipulation cursor-pointer {{ ($quickSymbol === 'BTCUSDT') ? 'bg-emerald-600 text-white shadow' : '' }}"
                            id="btn-{{ $quickSymbol }}">
                            {{ str_replace(['1000', 'USDT'], '', $quickSymbol) }}
                        </button>
                    @endforeach
                </div>

                <!-- Custom Coin Search/Input -->
                <div class="flex items-center space-x-1">
                    <input type="text" id="customSymbolInput" placeholder="e.g. WIF, APT"
                        onkeydown="if (event.key === 'Enter') loadCustomFuturesChart();"
                        class="px-3 py-1.5 bg-slate-950 border border-slate-700 rounded-xl text-xs text-white uppercase placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 w-28">
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
                        <span id="hud-score-label" class="text-emerald-400 font-bold">Score: --/100</span>
                    </div>
                </div>

                <!-- 2. Perpetual Trade Options -->
                <div class="p-3 rounded-xl bg-slate-900/80 border border-slate-800">
                    <div class="text-xs font-semibold text-slate-400 mb-1 flex items-center justify-between">
                        <span>Perpetual Trade Options</span>
                        <span class="text-[10px] px-1.5 py-0.5 rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">Isolated Margin</span>
                    </div>
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <span class="text-slate-500 block text-[10px]">Rec. Leverage</span>
                            <span id="hud-leverage" class="font-bold text-amber-400 text-xs">5x - 10x</span>
                        </div>
                        <div>
                            <span class="text-slate-500 block text-[10px]">Risk / Reward</span>
                            <span id="hud-rr" class="font-bold text-emerald-400 text-xs">1 : 2.5</span>
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
                        <span>Execution Targets</span>
                        <span class="text-[10px] text-slate-400">Multi-TP Plan</span>
                    </div>
                    <div class="space-y-1 text-xs">
                        <div class="flex justify-between items-center text-[11px]">
                            <span class="text-emerald-400 font-medium">TP1: <code id="hud-tp1" class="text-slate-200 font-mono">--</code></span>
                            <span class="text-slate-500 text-[10px]">(Close 40% &amp; BE)</span>
                        </div>
                        <div class="flex justify-between items-center text-[11px]">
                            <span class="text-emerald-400 font-medium">TP2: <code id="hud-tp2" class="text-slate-200 font-mono">--</code></span>
                            <span class="text-slate-500 text-[10px]">(Close 35%)</span>
                        </div>
                        <div class="flex justify-between items-center text-[11px]">
                            <span class="text-emerald-400 font-medium">TP3: <code id="hud-tp3" class="text-slate-200 font-mono">--</code></span>
                            <span class="text-slate-500 text-[10px]">(Runner 25%)</span>
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
                <div class="flex flex-wrap items-center gap-3">
                    <span>Algorithm: <strong class="text-emerald-400">SignalAlgo PRO™</strong></span>
                    <span>•</span>
                    <span>RSI(14): <strong id="hud-rsi" class="text-slate-200">--</strong></span>
                    <span>•</span>
                    <span>ADX(14) Trend Power: <strong id="hud-adx" class="text-slate-200">--</strong></span>
                    <span>•</span>
                    <span>Vol vs SMA(20): <strong id="hud-vol" class="text-slate-200">--</strong></span>
                    <span>•</span>
                    <span>ATR Volatility: <strong id="hud-atr" class="text-slate-200">--</strong></span>
                </div>
                <div class="text-slate-500 text-[10px]">
                    Analysis Timeframe: <span id="hud-active-tf" class="text-emerald-400 font-bold uppercase">{{ $cryptoConfig['interval'] }}</span> | HTF Filter: <span id="hud-active-htf" class="text-slate-300 font-medium uppercase">{{ $cryptoConfig['htf_interval'] }}</span>
                </div>
            </div>
        </div>

        <!-- Chart Controls Toolbar: Mode Switcher, Timeframe Selector & Indicator Legend -->
        <div class="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-3 mb-3 px-1">
            <div class="flex flex-wrap items-center gap-2">
                <!-- Mode Switcher -->
                <div class="flex items-center bg-slate-950 p-1 rounded-xl border border-slate-800 text-xs shadow-inner">
                    <button type="button" id="tabAlgoChart" onclick="switchChartMode('algo')"
                        class="px-3 py-1.5 rounded-lg font-bold transition flex items-center space-x-1.5 bg-emerald-600 text-white shadow">
                        <span>⚡</span>
                        <span>SignalAlgo PRO Signals Chart</span>
                        <span id="markersCountBadge" class="ml-1 px-1.5 py-0.5 rounded-full text-[10px] bg-slate-900 text-emerald-300 font-mono border border-emerald-500/30">0</span>
                    </button>
                    <button type="button" id="tabTvChart" onclick="switchChartMode('tv')"
                        class="px-3 py-1.5 rounded-lg font-medium transition text-slate-400 hover:text-white flex items-center space-x-1.5">
                        <span>📊</span>
                        <span>TradingView Studio</span>
                    </button>
                </div>

                <!-- Timeframe Selector -->
                <div class="flex items-center bg-slate-950 p-1 rounded-xl border border-slate-800 text-xs shadow-inner">
                    <span class="text-slate-500 text-[10px] px-2 font-bold uppercase tracking-wider">TF</span>
                    @foreach (['1m' => '1m', '3m' => '3m', '5m' => '5m', '15m' => '15m', '30m' => '30m', '1h' => '1h', '4h' => '4h', '1d' => '1D'] as $tfKey => $tfLabel)
                        <button type="button" onclick="switchTimeframe('{{ $tfKey }}')" id="tf-{{ $tfKey }}"
                            class="tf-btn px-2.5 py-1 rounded-lg font-bold text-xs transition {{ ($cryptoConfig['interval'] === $tfKey) ? 'bg-emerald-600 text-white shadow' : 'text-slate-400 hover:text-white hover:bg-slate-900' }}">
                            {{ $tfLabel }}
                        </button>
                    @endforeach
                </div>

                <!-- In-Chart Quick Coin Selector (Mobile & Desktop) -->
                <div class="flex items-center bg-slate-950 p-1 rounded-xl border border-slate-800 text-xs shadow-inner overflow-x-auto max-w-full touch-pan-x select-none">
                    <span class="text-slate-500 text-[10px] px-2 font-bold uppercase tracking-wider">COIN</span>
                    @foreach (['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT', 'DOGEUSDT', 'NEARUSDT', 'AVAXUSDT', 'SUIUSDT', '1000PEPEUSDT'] as $quickSymbol)
                        <button type="button" onclick="loadFuturesChart('{{ $quickSymbol }}', false)"
                            class="px-2.5 py-1 rounded-lg text-slate-400 hover:text-white font-bold text-xs transition chart-symbol-btn whitespace-nowrap active:scale-95 touch-manipulation cursor-pointer {{ ($quickSymbol === 'BTCUSDT') ? 'bg-emerald-600 text-white shadow' : '' }}"
                            id="chart-btn-{{ $quickSymbol }}">
                            {{ str_replace(['1000', 'USDT'], '', $quickSymbol) }}
                        </button>
                    @endforeach
                </div>
            </div>

            <!-- Indicator Color Legend -->
            <div id="algoIndicatorLegend" class="flex flex-wrap items-center gap-3 text-xs text-slate-400">
                <span class="flex items-center space-x-1.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-cyan-400"></span>
                    <span>EMA 9</span>
                </span>
                <span class="flex items-center space-x-1.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-amber-400"></span>
                    <span>EMA 21</span>
                </span>
                <span class="flex items-center space-x-1.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-purple-500"></span>
                    <span>EMA 200</span>
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

            <!-- 1. SignalAlgo PRO Native Canvas Chart (Active by default) -->
            <div id="signalalgo_canvas_chart" class="w-full h-full"></div>

            <!-- 2. TradingView Iframe Widget (Alternative mode) -->
            <div id="tradingview_futures_chart" class="hidden w-full h-full"></div>
        </div>
    </div>

    <!-- Security & Hosting Status -->
    <div class="p-6 rounded-2xl bg-slate-900/80 border border-slate-800 shadow mb-8">
        <h2 class="text-lg font-bold text-white mb-4 flex items-center space-x-2">
            <svg class="w-5 h-5 text-emerald-400" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
            </svg>
            <span>Production Security &amp; Protection Status</span>
        </h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
            <div class="p-3 rounded-xl bg-slate-950 border border-slate-800">
                <div class="text-slate-400 font-semibold mb-0.5">Registration Access</div>
                <div class="text-emerald-400 font-bold">Admin-Only Protected</div>
                <div class="text-slate-500 mt-1">Public sign-up is disabled</div>
            </div>

            <div class="p-3 rounded-xl bg-slate-950 border border-slate-800">
                <div class="text-slate-400 font-semibold mb-0.5">Security Headers</div>
                <div class="text-emerald-400 font-bold">Active Middleware</div>
                <div class="text-slate-500 mt-1">CSP, X-Frame, XSS, HSTS</div>
            </div>

            <div class="p-3 rounded-xl bg-slate-950 border border-slate-800">
                <div class="text-slate-400 font-semibold mb-0.5">Brute-Force Shield</div>
                <div class="text-emerald-400 font-bold">Rate Limiting Active</div>
                <div class="text-slate-500 mt-1">Max 5 attempts / min / IP</div>
            </div>

            <div class="p-3 rounded-xl bg-slate-950 border border-slate-800">
                <div class="text-slate-400 font-semibold mb-0.5">Session Defense</div>
                <div class="text-emerald-400 font-bold">Fixation Protected</div>
                <div class="text-slate-500 mt-1">Session ID regenerated on auth</div>
            </div>
        </div>
    </div>

    <!-- Middle Section: User Management (Admin Only) -->
    @if ($user->isAdmin())
        <div class="p-6 rounded-2xl bg-slate-900/80 border border-slate-800 shadow mb-8">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-bold text-white flex items-center space-x-2">
                    <span>Authorized Users</span>
                    <span class="text-xs font-normal text-slate-400">({{ count($allUsers) }} registered)</span>
                </h2>
                <a href="{{ route('register') }}" class="text-xs text-emerald-400 hover:text-emerald-300 font-semibold transition">
                    + Add Another User →
                </a>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-800 text-xs">
                    <thead>
                        <tr class="text-left text-slate-400 uppercase tracking-wider font-semibold">
                            <th class="py-2.5 px-3">Name</th>
                            <th class="py-2.5 px-3">Email</th>
                            <th class="py-2.5 px-3">Role</th>
                            <th class="py-2.5 px-3">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        @foreach ($allUsers as $u)
                            <tr class="hover:bg-slate-800/30 transition">
                                <td class="py-2.5 px-3 font-medium text-white flex items-center space-x-2">
                                    <span>{{ $u->name }}</span>
                                    @if ($u->id === $user->id)
                                        <span class="text-[10px] text-slate-400 bg-slate-800 px-1.5 py-0.5 rounded">You</span>
                                    @endif
                                </td>
                                <td class="py-2.5 px-3 text-slate-300 font-mono">{{ $u->email }}</td>
                                <td class="py-2.5 px-3">
                                    @if ($u->isAdmin())
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-emerald-950 text-emerald-300 border border-emerald-800/60">Administrator</span>
                                    @else
                                        <span class="px-2 py-0.5 rounded text-[10px] font-medium bg-slate-800 text-slate-300">Authorized User</span>
                                    @endif
                                </td>
                                <td class="py-2.5 px-3 text-slate-400">
                                    {{ $u->created_at ? $u->created_at->format('M d, Y H:i') : '-' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Lower Section: Monitored Symbols & Manual Check -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Monitored Pairs -->
        <div class="lg:col-span-2 p-6 rounded-2xl bg-slate-900/80 border border-slate-800 shadow">
            <h2 class="text-lg font-bold text-white mb-4 flex items-center space-x-2">
                <span>Active Monitored Pairs</span>
                <span class="text-xs font-normal text-slate-400">({{ count($cryptoConfig['symbols']) }} symbols — click any coin to view chart)</span>
            </h2>

            <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                @foreach ($cryptoConfig['symbols'] as $sym)
                    <div onclick="loadFuturesChart('{{ $sym }}')"
                        class="p-4 rounded-xl bg-slate-950 border border-slate-800 hover:border-emerald-500/60 cursor-pointer transition flex items-center justify-between group">
                        <div>
                            <div class="font-bold text-white group-hover:text-emerald-400 transition">{{ $sym }}</div>
                            <div class="text-xs text-cyan-400/80 font-mono">{{ $cryptoConfig['market_label'] }}</div>
                        </div>
                        <span class="px-2 py-1 text-xs font-medium bg-emerald-950 text-emerald-400 rounded-md border border-emerald-900/50 group-hover:bg-emerald-900/60 transition">
                            Chart ↗
                        </span>
                    </div>
                @endforeach
            </div>

            <div class="mt-6 p-4 rounded-xl bg-slate-950/80 border border-slate-800 text-xs text-slate-400 space-y-1">
                <div class="font-semibold text-slate-300">Evaluating Indicator Confluence on {{ $cryptoConfig['market_label'] }}:</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 pt-1 text-slate-400">
                    <div>• Fast EMA (9) vs Slow EMA (21)</div>
                    <div>• HTF Trend Filter (1h 200 EMA)</div>
                    <div>• ADX/DMI (14) $\ge$ 18 Directional Agreement</div>
                    <div>• RSI (14) Overbought/Oversold &amp; Momentum</div>
                    <div>• Volume Spike &gt; SMA(20) &times; 1.15</div>
                    <div>• Candle Body Ratio $\ge$ 60% OR Engulfing</div>
                    <div>• Structure Breakout $\ge$ 0.15% OR HH+HL / LL+LH</div>
                    <div>• Dynamic ATR Stop-Loss &amp; Target Multiples</div>
                </div>
            </div>
        </div>

        <!-- System Commands & CLI -->
        <div class="p-6 rounded-2xl bg-slate-900/80 border border-slate-800 shadow flex flex-col justify-between">
            <div>
                <h2 class="text-lg font-bold text-white mb-2">Artisan CLI Commands</h2>
                <p class="text-xs text-slate-400 mb-4">Run these commands from the terminal or scheduler to verify signals:</p>

                <div class="space-y-3">
                    <div class="bg-slate-950 p-3 rounded-xl border border-slate-800">
                        <div class="text-xs text-slate-400 mb-1 font-semibold">Send instant test alert:</div>
                        <code class="text-xs text-emerald-400 font-mono select-all">php artisan crypto:check-signals --test-alert</code>
                    </div>

                    <div class="bg-slate-950 p-3 rounded-xl border border-slate-800">
                        <div class="text-xs text-slate-400 mb-1 font-semibold">Live evaluation with diagnostics:</div>
                        <code class="text-xs text-emerald-400 font-mono select-all">php artisan crypto:check-signals --dry-run</code>
                    </div>

                    <div class="bg-slate-950 p-3 rounded-xl border border-slate-800">
                        <div class="text-xs text-slate-400 mb-1 font-semibold">Check specific symbol:</div>
                        <code class="text-xs text-emerald-400 font-mono select-all">php artisan crypto:check-signals --symbol=SOLUSDT --dry-run</code>
                    </div>
                </div>
            </div>

            <div class="mt-6 pt-4 border-t border-slate-800 text-xs text-slate-500">
                User registered on {{ $user->created_at ? $user->created_at->format('M d, Y') : 'today' }}
            </div>
        </div>
    </div>

    <!-- Recent Telegram Alerts Section -->
    <div class="p-6 rounded-2xl bg-slate-900/90 border border-slate-800 shadow-2xl mb-8">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-5 pb-4 border-b border-slate-800">
            <div>
                <div class="flex items-center space-x-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    <h3 class="text-lg font-bold text-white tracking-tight">Recent Telegram Alerts</h3>
                </div>
                <p class="text-xs text-slate-400 mt-1">Latest trading opportunities dispatched to your Telegram channel.</p>
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
                                    @if($alert->isReversal())
                                        <span class="text-[10px] text-amber-400 font-bold uppercase">Reversal</span>
                                    @else
                                        <span class="text-[10px] text-cyan-400 font-bold uppercase">Trend</span>
                                    @endif
                                </td>
                                <td class="py-2.5 px-4 whitespace-nowrap font-sans font-bold {{ $alert->grade === 'A' ? 'text-emerald-400' : ($alert->grade === 'B' ? 'text-cyan-400' : 'text-slate-300') }}">
                                    Grade {{ $alert->grade }} <span class="text-[10px] text-slate-400">({{ $alert->score }})</span>
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
                No Telegram alerts logged yet. When alerts are dispatched, they will appear here automatically.
            </div>
        @endif
    </div>
</div>

<!-- TradingView & Lightweight Charts Scripts with Fail-Safe Fallback -->
<script type="text/javascript" src="{{ asset('js/lightweight-charts.standalone.production.js') }}"></script>
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
    let currentWidget = null;
    let currentAbortController = null;
    let activeSymbol = '{{ $cryptoConfig['symbols'][0] ?? 'BTCUSDT' }}';
    let activeInterval = '{{ $cryptoConfig['interval'] ?? '15m' }}';
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

            if (chart && algoContainer) {
                chart.applyOptions({ width: algoContainer.clientWidth, height: algoContainer.clientHeight });
                chart.timeScale().fitContent();
            }
        } else {
            tvTab.className = 'px-3 py-1.5 rounded-lg font-bold transition flex items-center space-x-1.5 bg-emerald-600 text-white shadow';
            algoTab.className = 'px-3 py-1.5 rounded-lg font-medium transition text-slate-400 hover:text-white flex items-center space-x-1.5';
            algoContainer.classList.add('hidden');
            tvContainer.classList.remove('hidden');
            if (tooltip) tooltip.classList.add('hidden');
            if (legend) legend.classList.add('hidden');

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
                fontSize: 11,
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

        // Click to inspect historical signal levels on chart
        chart.subscribeClick(param => {
            if (!param || !param.time || !historicalMarkers.length) return;
            const marker = historicalMarkers.find(m => m.time === param.time);
            if (marker) {
                renderTradeLevels(marker);
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

        const entry = Number(setup.entry);
        const sl = Number(setup.sl);
        const tp1 = Number(setup.tp1);
        const tp2 = Number(setup.tp2);
        const tp3 = Number(setup.tp3);
        const side = (setup.side || 'BUY').toUpperCase();

        const fmtP = (p) => p < 1 ? p.toFixed(5) : p.toFixed(2);

        if (entry > 0) {
            entryLine = candleSeries.createPriceLine({
                price: entry,
                color: '#06b6d4',
                lineWidth: 2,
                lineStyle: 2, // Dashed
                axisLabelVisible: true,
                title: `ENTRY (${side}): $${fmtP(entry)}`,
            });
        }

        if (sl > 0) {
            slLine = candleSeries.createPriceLine({
                price: sl,
                color: '#f43f5e',
                lineWidth: 2,
                lineStyle: 1, // Dotted
                axisLabelVisible: true,
                title: `SL: $${fmtP(sl)}`,
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
            signalTitle.textContent = `${isBuy ? '🟢' : '🔴'} SignalAlgo ${marker.side} [Score: ${marker.score}/100]`;
            signalTargets.innerHTML = `Entry: ${fmt(marker.entry)} | SL: ${fmt(marker.sl)}<br>TP1: ${fmt(marker.tp1)} | TP2: ${fmt(marker.tp2)}`;
        } else if (signalBox) {
            signalBox.classList.add('hidden');
        }
    }

    function initTradingViewWidget(symbol) {
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
            "allow_symbol_change": true,
            "details": true,
            "hotlist": false,
            "calendar": false,
            "studies": [
                "MASimple@tv-basicstudies",
                "RSI@tv-basicstudies"
            ],
            "container_id": "tradingview_futures_chart"
        });
    }

    function fetchSignalAlgoData(symbol, abortSignal = null) {
        const badge = document.getElementById('hud-signal-badge');
        const priceEl = document.getElementById('hud-price');

        if (badge) {
            badge.className = 'px-2.5 py-1 rounded-lg text-xs font-black tracking-wide bg-slate-800 text-slate-300 border border-slate-700 flex items-center space-x-1.5';
            badge.innerHTML = '<span class="w-2 h-2 rounded-full bg-amber-400 animate-pulse"></span><span>EVALUATING ALGO...</span>';
        }

        showChartLoading(symbol, activeInterval);

        const fetchOptions = abortSignal ? { signal: abortSignal } : {};

        fetch(`/dashboard/analyze?symbol=${encodeURIComponent(symbol)}&interval=${encodeURIComponent(activeInterval)}`, fetchOptions)
            .then(res => res.json())
            .then(data => {
                if (symbol !== activeSymbol) {
                    return;
                }

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
                    historicalMarkers = data.markers || [];
                    candleSeries.setMarkers(historicalMarkers);

                    // Update Markers Count Badge
                    const markersBadge = document.getElementById('markersCountBadge');
                    if (markersBadge) {
                        markersBadge.textContent = historicalMarkers.length + ' Signals';
                    }

                    chart.timeScale().fitContent();

                    // Render active or latest trade setup levels directly on the chart!
                    const latestSetup = data.signal || (historicalMarkers.length > 0 ? historicalMarkers[historicalMarkers.length - 1] : null);
                    if (latestSetup && (latestSetup.entry || latestSetup.sl)) {
                        renderTradeLevels(latestSetup);
                    } else {
                        clearTradeLevels();
                    }
                }

                // Update Price
                if (priceEl && data.price) {
                    priceEl.textContent = '$' + Number(data.price).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 4 });
                }

                const sig = data.signal;
                const diag = data.diagnostics || {};
                const perp = data.perpetual_options || {};

                // Update Signal Badge
                if (sig && sig.side === 'BUY') {
                    const activeTag = sig.is_active_trade ? ' (ACTIVE SETUP)' : '';
                    badge.className = 'px-2.5 py-1 rounded-lg text-xs font-black tracking-wide bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 shadow-sm shadow-emerald-500/20 flex items-center space-x-1.5';
                    badge.innerHTML = `<span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span><span>🟢 STRONG BUY / LONG${activeTag}</span>`;
                } else if (sig && sig.side === 'SELL') {
                    const activeTag = sig.is_active_trade ? ' (ACTIVE SETUP)' : '';
                    badge.className = 'px-2.5 py-1 rounded-lg text-xs font-black tracking-wide bg-rose-500/20 text-rose-300 border border-rose-500/40 shadow-sm shadow-rose-500/20 flex items-center space-x-1.5';
                    badge.innerHTML = `<span class="w-2 h-2 rounded-full bg-rose-400 animate-ping"></span><span>🔴 STRONG SELL / SHORT${activeTag}</span>`;
                } else {
                    const buyScore = diag.buy_score || 0;
                    const sellScore = diag.sell_score || 0;
                    badge.className = 'px-2.5 py-1 rounded-lg text-xs font-bold tracking-wide bg-slate-800 text-slate-300 border border-slate-700 flex items-center space-x-1.5';
                    badge.innerHTML = `<span class="w-2 h-2 rounded-full bg-slate-400"></span><span>⚪ SCANNING (${buyScore > sellScore ? 'BULL' : 'BEAR'} BIAS)</span>`;
                }

                // Update Score Label
                const scoreLabel = document.getElementById('hud-score-label');
                if (scoreLabel) {
                    const scoreVal = sig ? sig.score : Math.max(diag.buy_score || 0, diag.sell_score || 0);
                    scoreLabel.textContent = `Score: ${scoreVal}/100`;
                    scoreLabel.className = scoreVal >= 70 ? 'text-emerald-400 font-bold' : (scoreVal >= 50 ? 'text-amber-400 font-bold' : 'text-slate-400 font-bold');
                }

                // Update Perpetual Trade Options
                if (perp.recommended_leverage) {
                    document.getElementById('hud-leverage').textContent = perp.recommended_leverage;
                }
                if (perp.risk_reward) {
                    document.getElementById('hud-rr').textContent = perp.risk_reward;
                }

                const fmt = (val) => val ? (Number(val) < 1 ? Number(val).toFixed(6) : Number(val).toFixed(4)) : '--';

                const entryVal = sig ? sig.entry : data.price;
                document.getElementById('hud-entry').textContent = fmt(entryVal);

                const slVal = sig ? sig.sl : (perp.sl_pct ? (data.price * (1 - (perp.sl_pct / 100))) : null);
                const slPct = perp.sl_pct ? `(-${perp.sl_pct}%)` : '';
                document.getElementById('hud-sl').textContent = fmt(slVal) + ' ' + slPct;

                const tp1Val = sig ? sig.tp1 : (perp.tp1_pct ? (data.price * (1 + (perp.tp1_pct / 100))) : null);
                const tp1Pct = perp.tp1_pct ? `(+${perp.tp1_pct}%)` : '';
                document.getElementById('hud-tp1').textContent = fmt(tp1Val) + ' ' + tp1Pct;

                const tp2Val = sig ? sig.tp2 : (perp.tp2_pct ? (data.price * (1 + (perp.tp2_pct / 100))) : null);
                const tp2Pct = perp.tp2_pct ? `(+${perp.tp2_pct}%)` : '';
                document.getElementById('hud-tp2').textContent = fmt(tp2Val) + ' ' + tp2Pct;

                const tp3Val = sig ? sig.tp3 : (perp.tp3_pct ? (data.price * (1 + (perp.tp3_pct / 100))) : null);
                const tp3Pct = perp.tp3_pct ? `(+${perp.tp3_pct}%)` : '';
                document.getElementById('hud-tp3').textContent = fmt(tp3Val) + ' ' + tp3Pct;

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
            })
            .catch(err => {
                if (err.name === 'AbortError') {
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

    function loadFuturesChart(rawSymbol, autoScroll = true) {
        let clean = rawSymbol.toUpperCase().replace('.P', '').trim();
        if (!clean.endsWith('USDT') && !clean.includes(':')) {
            clean += 'USDT';
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

        // Show instant loading state
        showChartLoading(activeSymbol, activeInterval);

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
    function pollDaemonStatus() {
        fetch('{{ route('daemon.status') }}', {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(res => res.json())
        .then(data => {
            const badge = document.getElementById('daemonBadge');
            const dot = document.getElementById('daemonDot');
            const text = document.getElementById('daemonStatusText');
            const btnStart = document.getElementById('btnStartDaemon');
            const btnStop = document.getElementById('btnStopDaemon');
            const cycles = document.getElementById('daemonCycles');
            const lastScan = document.getElementById('daemonLastScan');
            const heartbeat = document.getElementById('daemonHeartbeat');

            if (data.is_running) {
                if (badge) badge.className = 'inline-flex items-center space-x-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30';
                if (dot) dot.className = 'w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse';
                const statusLabel = (data.stats && data.stats.status && data.stats.status !== 'STOPPED') 
                    ? data.stats.status 
                    : 'ACTIVE';
                if (text) text.textContent = `${statusLabel} (MONITORING 24/7)`;
                if (btnStart) btnStart.classList.add('hidden');
                if (btnStop) btnStop.classList.remove('hidden');
            } else {
                if (badge) badge.className = 'inline-flex items-center space-x-1.5 px-3 py-1 rounded-full text-xs font-bold bg-rose-500/20 text-rose-300 border border-rose-500/30';
                if (dot) dot.className = 'w-2.5 h-2.5 rounded-full bg-rose-400';
                if (text) text.textContent = 'STOPPED (CLICK START TO MONITOR)';
                if (btnStart) btnStart.classList.remove('hidden');
                if (btnStop) btnStop.classList.add('hidden');
            }

            if (data.stats) {
                if (cycles) cycles.textContent = data.stats.total_cycles !== undefined ? data.stats.total_cycles : 0;
                if (lastScan) lastScan.textContent = data.stats.last_cycle_time || '--';
                if (heartbeat) {
                    const age = data.stats.heartbeat_age_seconds;
                    heartbeat.textContent = (age !== undefined && age < 999) ? `${age}s ago` : 'Offline';
                }
                if (data.stats.monitored_coins !== undefined) {
                    const countStat = document.getElementById('daemonMonitoredCount');
                    const countBadge = document.getElementById('daemonCoinCountBadge');
                    if (countStat) countStat.textContent = `${data.stats.monitored_coins} Coins`;
                    if (countBadge) countBadge.textContent = data.stats.monitored_coins;
                }
                if (data.stats.scan_mode_label) {
                    const modeLabel = document.getElementById('daemonScanModeLabel');
                    if (modeLabel) modeLabel.textContent = data.stats.scan_mode_label;
                }
            }
        })
        .catch(() => {});
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

    function toggleServerInstructions() {
        const panel = document.getElementById('serverInstructionsPanel');
        if (panel) {
            panel.classList.toggle('hidden');
        }
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
        if (textSpan) textSpan.innerHTML = 'Starting Sentinel...';

        showToast('Initiating background sentinel watcher...', true);

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
                showToast(data.message || 'Failed to start watcher daemon on server.', false);
                showDaemonFeedback(data.message || 'Failed to start background process.', false);
                const panel = document.getElementById('serverInstructionsPanel');
                if (panel) panel.classList.remove('hidden');
            }
        })
        .catch(err => {
            showToast('Network error while starting watcher daemon.', false);
            showDaemonFeedback('Network error contacting server daemon endpoint.', false);
        })
        .finally(() => {
            if (btn) btn.disabled = false;
            if (textSpan) textSpan.innerHTML = 'Start Sentinel Watcher';
        });
    }

    function stopDaemon() {
        const btn = document.getElementById('btnStopDaemon');
        const textSpan = document.getElementById('btnStopDaemonText');
        if (btn) btn.disabled = true;
        if (textSpan) textSpan.innerHTML = 'Stopping...';

        showToast('Sending stop signal to sentinel watcher...', true);

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
            showToast('Failed to stop watcher daemon.', false);
        })
        .finally(() => {
            if (btn) btn.disabled = false;
            if (textSpan) textSpan.innerHTML = 'Stop Sentinel';
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

    function updateMonitoredCoinsUI(coins, scanMode) {
        const count = coins ? coins.length : 0;
        const countStat = document.getElementById('daemonMonitoredCount');
        const countBadge = document.getElementById('daemonCoinCountBadge');
        const managerBadge = document.getElementById('coinManagerCountBadge');
        const modeLabel = document.getElementById('daemonScanModeLabel');

        if (countStat) countStat.textContent = `${count} Coins`;
        if (countBadge) countBadge.textContent = count;
        if (managerBadge) managerBadge.textContent = `${count} Coins Active`;

        if (modeLabel && scanMode) {
            modeLabel.textContent = scanMode === 'monitored_only'
                ? 'Monitored Only'
                : (scanMode === 'whole_market' ? 'Whole Market' : 'Monitored + Breakouts');
        }

        // Update mode toggle buttons
        const btnBoth = document.getElementById('btnModeBoth');
        const btnMon = document.getElementById('btnModeMonitored');
        if (btnBoth && btnMon) {
            if (scanMode === 'monitored_only') {
                btnMon.className = 'px-2.5 py-1 rounded font-semibold transition text-emerald-300 bg-emerald-950/60 border border-emerald-800/60 cursor-pointer';
                btnBoth.className = 'px-2.5 py-1 rounded font-semibold transition text-slate-400 hover:text-white cursor-pointer';
            } else {
                btnBoth.className = 'px-2.5 py-1 rounded font-semibold transition text-emerald-300 bg-emerald-950/60 border border-emerald-800/60 cursor-pointer';
                btnMon.className = 'px-2.5 py-1 rounded font-semibold transition text-slate-400 hover:text-white cursor-pointer';
            }
        }
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

    function changeScanMode(mode) {
        fetch('{{ route('daemon.monitored-coins.mode') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ mode: mode })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, true);
                updateMonitoredCoinsUI(cachedMonitoredCoins, data.scan_mode);
                pollDaemonStatus();
            } else {
                showToast(data.message || 'Failed to change mode.', false);
            }
        })
        .catch(() => {
            showToast('Network error changing scan mode.', false);
        });
    }

    // ==========================================
    // On-Demand Whole-Market Scanner (crypto:check-signals --all --dry-run)
    // ==========================================
    let marketScanPollInterval = null;
    let isMarketScanRunning = false;

    function startMarketScan() {
        const btn = document.getElementById('btnStartMarketScan');
        const textSpan = document.getElementById('btnStartMarketScanText');
        if (btn) btn.disabled = true;
        if (textSpan) textSpan.innerHTML = 'Launching Scanner...';

        showToast('Initiating whole-market scan (dry run)...', true);

        fetch('{{ route('market-scan.start') }}', {
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
                setMarketScanRunningUI(true);
                pollMarketScanStatus();
                ensureMarketScanPolling();
            } else {
                showToast(data.message || 'Failed to start market scan.', false);
            }
        })
        .catch(err => {
            showToast('Network error while starting market scan.', false);
        })
        .finally(() => {
            if (btn) btn.disabled = false;
            if (textSpan) textSpan.innerHTML = 'Run Whole-Market Scan';
        });
    }

    function stopMarketScan() {
        const btn = document.getElementById('btnStopMarketScan');
        if (btn) btn.disabled = true;

        showToast('Stopping market scan...', true);

        fetch('{{ route('market-scan.stop') }}', {
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
            setMarketScanRunningUI(false);
            pollMarketScanStatus();
        })
        .catch(err => {
            showToast('Failed to stop market scan.', false);
        })
        .finally(() => {
            if (btn) btn.disabled = false;
        });
    }

    function clearMarketScan() {
        if (isMarketScanRunning) {
            showToast('Cannot clear while scan is running. Stop scan first.', false);
            return;
        }

        fetch('{{ route('market-scan.clear') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            }
        })
        .then(res => res.json())
        .then(data => {
            showToast('Scan results cleared.', true);
            pollMarketScanStatus();
        })
        .catch(() => {});
    }

    function ensureMarketScanPolling() {
        if (!marketScanPollInterval) {
            marketScanPollInterval = setInterval(pollMarketScanStatus, 2500);
        }
    }

    function stopMarketScanPolling() {
        if (marketScanPollInterval) {
            clearInterval(marketScanPollInterval);
            marketScanPollInterval = null;
        }
    }

    function setMarketScanRunningUI(isRunning) {
        isMarketScanRunning = isRunning;
        const btnStart = document.getElementById('btnStartMarketScan');
        const btnStop = document.getElementById('btnStopMarketScan');
        const spinner = document.getElementById('scanProgressSpinner');

        if (isRunning) {
            if (btnStart) btnStart.classList.add('hidden');
            if (btnStop) btnStop.classList.remove('hidden');
            if (spinner) spinner.classList.remove('hidden');
        } else {
            if (btnStart) btnStart.classList.remove('hidden');
            if (btnStop) btnStop.classList.add('hidden');
            if (spinner) spinner.classList.add('hidden');
        }
    }

    function pollMarketScanStatus() {
        fetch('{{ route('market-scan.status') }}', {
            headers: { 'Accept': 'application/json' }
        })
        .then(res => res.json())
        .then(data => {
            if (!data.success) return;

            setMarketScanRunningUI(data.is_running);

            // Update badge
            const badge = document.getElementById('marketScanStatusBadge');
            const dot = document.getElementById('marketScanStatusDot');
            const text = document.getElementById('marketScanStatusText');
            const statusLabel = document.getElementById('scanProgressStatusLabel');

            if (data.status === 'RUNNING') {
                if (badge) badge.className = 'inline-flex items-center space-x-1.5 px-3 py-1 rounded-full text-xs font-bold bg-cyan-500/20 text-cyan-300 border border-cyan-500/40 animate-pulse';
                if (dot) dot.className = 'w-2.5 h-2.5 rounded-full bg-cyan-400';
                if (text) text.textContent = 'SCANNING MARKET...';
                if (statusLabel) statusLabel.textContent = `Scanning: ${data.progress ? data.progress.current_symbol : '...'}`;
                ensureMarketScanPolling();
            } else if (data.status === 'COMPLETED') {
                if (badge) badge.className = 'inline-flex items-center space-x-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40';
                if (dot) dot.className = 'w-2.5 h-2.5 rounded-full bg-emerald-400';
                if (text) text.textContent = 'SCAN COMPLETED';
                if (statusLabel) statusLabel.textContent = 'Scan Finished';
                stopMarketScanPolling();
            } else if (data.status === 'STOPPED') {
                if (badge) badge.className = 'inline-flex items-center space-x-1.5 px-3 py-1 rounded-full text-xs font-bold bg-amber-500/20 text-amber-300 border border-amber-500/40';
                if (dot) dot.className = 'w-2.5 h-2.5 rounded-full bg-amber-400';
                if (text) text.textContent = 'STOPPED';
                if (statusLabel) statusLabel.textContent = 'Scan Aborted';
                stopMarketScanPolling();
            } else {
                if (badge) badge.className = 'inline-flex items-center space-x-1.5 px-3 py-1 rounded-full text-xs font-bold bg-slate-800 text-slate-400 border border-slate-700';
                if (dot) dot.className = 'w-2.5 h-2.5 rounded-full bg-slate-500';
                if (text) text.textContent = 'IDLE';
                if (statusLabel) statusLabel.textContent = 'Market Scan Progress';
                stopMarketScanPolling();
            }

            // Update stats
            const progCount = document.getElementById('scanProgressCount');
            const curSymbol = document.getElementById('scanCurrentSymbol');
            const sigsCount = document.getElementById('scanSignalsCount');
            const startedAt = document.getElementById('scanStartedAt');
            const percentSpan = document.getElementById('scanProgressPercent');
            const fillBar = document.getElementById('scanProgressBarFill');
            const badgeCount = document.getElementById('signalsBadgeCount');

            if (data.progress) {
                if (progCount) progCount.textContent = `${data.progress.index || 0} / ${data.progress.total || 0}`;
                if (curSymbol) curSymbol.textContent = data.progress.current_symbol || 'None';
                const pct = Math.min(100, Math.max(0, data.progress.percent || 0));
                if (percentSpan) percentSpan.textContent = `${pct}%`;
                if (fillBar) fillBar.style.width = `${pct}%`;
            }

            if (sigsCount) sigsCount.textContent = data.total_signals || 0;
            if (badgeCount) badgeCount.textContent = `${data.total_signals || 0} Found`;
            if (startedAt) startedAt.textContent = data.started_at || '--';

            // Update terminal log
            const term = document.getElementById('marketScanTerminalLog');
            if (term && data.log_tail) {
                const wasAtBottom = (term.scrollHeight - term.clientHeight <= term.scrollTop + 40);
                term.textContent = data.log_tail;
                if (wasAtBottom) {
                    term.scrollTop = term.scrollHeight;
                }
            }

            // Render signals cards
            renderMarketScanSignals(data.signals || []);
        })
        .catch(() => {});
    }

    function renderMarketScanSignals(signals) {
        const grid = document.getElementById('scanSignalsGrid');
        if (!grid) return;

        if (!signals || signals.length === 0) {
            grid.innerHTML = `
                <div id="scanSignalsEmptyState" class="col-span-full py-8 text-center rounded-xl bg-slate-950/40 border border-dashed border-slate-800 text-slate-500 text-xs">
                    <div class="text-2xl mb-1.5">🔭</div>
                    No setups detected yet. Click <strong class="text-slate-400">"Run Whole-Market Scan"</strong> to evaluate all ~350 USDT Perpetual contracts.
                </div>
            `;
            return;
        }

        let html = '';
        signals.forEach(s => {
            const isBuy = s.side === 'BUY';
            const borderCls = isBuy ? 'border-emerald-500/30 hover:border-emerald-500/60' : 'border-rose-500/30 hover:border-rose-500/60';
            const tagCls = isBuy ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30' : 'bg-rose-500/10 text-rose-400 border-rose-500/30';
            const scoreCls = isBuy ? 'text-emerald-400' : 'text-rose-400';
            const sideIcon = isBuy ? '🟢 LONG (BUY)' : '🔴 SHORT (SELL)';
            const gradeBadge = s.grade === 'A'
                ? '<span class="px-2 py-0.5 rounded text-[10px] font-black bg-amber-400/20 text-amber-300 border border-amber-400/40">GRADE A</span>'
                : '<span class="px-2 py-0.5 rounded text-[10px] font-black bg-indigo-500/20 text-indigo-300 border border-indigo-500/40">GRADE B</span>';

            html += `
                <div class="p-4 rounded-xl bg-slate-950/80 border ${borderCls} transition shadow-lg relative flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center space-x-2">
                                <span class="text-base font-black text-white tracking-wide">${s.symbol}</span>
                                ${gradeBadge}
                            </div>
                            <span class="text-[11px] font-mono text-slate-400">${s.time || ''}</span>
                        </div>

                        <div class="flex items-center justify-between mb-3">
                            <span class="text-xs font-bold px-2.5 py-1 rounded-lg border ${tagCls}">
                                ${sideIcon}
                            </span>
                            <span class="text-xs font-mono font-bold text-slate-200">
                                Score: <strong class="${scoreCls}">${s.score}/100</strong>
                            </span>
                        </div>

                        <!-- Price targets -->
                        <div class="grid grid-cols-2 gap-1.5 text-xs font-mono mb-3 bg-slate-900/90 p-2.5 rounded-lg border border-slate-800">
                            <div>
                                <span class="text-[10px] text-slate-500 uppercase block">Entry</span>
                                <span class="font-bold text-white">${s.entry}</span>
                            </div>
                            <div>
                                <span class="text-[10px] text-rose-400 uppercase block">Stop Loss</span>
                                <span class="font-bold text-rose-400">${s.sl}</span>
                            </div>
                            <div>
                                <span class="text-[10px] text-emerald-400 uppercase block">TP 1</span>
                                <span class="font-semibold text-emerald-300">${s.tp1}</span>
                            </div>
                            <div>
                                <span class="text-[10px] text-emerald-400 uppercase block">TP 2</span>
                                <span class="font-semibold text-emerald-300">${s.tp2}</span>
                            </div>
                        </div>

                        <!-- Technical confluence factors -->
                        <div class="flex flex-wrap gap-2 text-[11px] font-mono text-slate-400 mb-3">
                            ${s.rsi ? `<span class="bg-slate-900 px-2 py-0.5 rounded border border-slate-800">RSI: <strong class="text-slate-200">${s.rsi}</strong></span>` : ''}
                            ${s.adx ? `<span class="bg-slate-900 px-2 py-0.5 rounded border border-slate-800">ADX: <strong class="text-slate-200">${s.adx}</strong></span>` : ''}
                            ${s.volume_ratio ? `<span class="bg-slate-900 px-2 py-0.5 rounded border border-slate-800">Vol: <strong class="text-slate-200">${s.volume_ratio}x</strong></span>` : ''}
                        </div>
                    </div>

                    <button type="button" onclick="loadFuturesChart('${s.symbol}', true)"
                        class="w-full mt-2 py-2 px-3 text-xs font-bold rounded-lg bg-slate-800 hover:bg-slate-700 text-cyan-300 hover:text-white border border-slate-700 transition flex items-center justify-center space-x-1 cursor-pointer touch-manipulation">
                        <span>📊 Inspect Chart</span>
                    </button>
                </div>
            `;
        });

        grid.innerHTML = html;
    }

    function toggleTerminalConsole() {
        const panel = document.getElementById('terminalConsolePanel');
        const text = document.getElementById('btnTerminalToggleText');
        if (panel) {
            panel.classList.toggle('hidden');
            if (text) {
                text.textContent = panel.classList.contains('hidden') ? 'Console' : 'Hide Console';
            }
        }
    }

    function copyMarketScanLog() {
        const term = document.getElementById('marketScanTerminalLog');
        if (term && term.textContent) {
            copyToClipboard(term.textContent, 'Market scan log copied!');
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        initAlgoCanvasChart();
        loadFuturesChart(activeSymbol);
        pollDaemonStatus();
        loadMonitoredCoins();
        pollMarketScanStatus();

        // Check daemon status every 10 seconds
        setInterval(pollDaemonStatus, 10000);

        // Check market scan periodically if not running
        setInterval(function() {
            if (!isMarketScanRunning && document.visibilityState === 'visible') {
                pollMarketScanStatus();
            }
        }, 10000);

        // Auto-refresh chart & synchronize signals every 60 seconds
        setInterval(function() {
            if (document.visibilityState === 'visible') {
                fetchSignalAlgoData(activeSymbol);
            }
        }, 60000);

        // Instant wakeup on mobile tab re-open / screen un-lock
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'visible') {
                pollDaemonStatus();
                fetchSignalAlgoData(activeSymbol);
                pollMarketScanStatus();
            }
        });
    });
</script>
@endsection
