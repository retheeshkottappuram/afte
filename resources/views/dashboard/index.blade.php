<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AFTE • Binance Futures AI Engine | $5 to $500 Challenge</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        cyber: {
                            900: '#070a12',
                            800: '#0d1322',
                            700: '#151f38',
                            600: '#1e2c4f',
                            border: '#233358',
                            accent: '#06b6d4',
                            success: '#10b981',
                            danger: '#f43f5e',
                            warning: '#f59e0b'
                        }
                    },
                    fontFamily: {
                        mono: ['JetBrains Mono', 'Fira Code', 'monospace', 'sans-serif'],
                        sans: ['Inter', 'system-ui', 'sans-serif']
                    }
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        @keyframes pulse-slow {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }
        .animate-pulse-slow { animation: pulse-slow 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        .glass-panel {
            background: rgba(13, 19, 34, 0.85);
            backdrop-filter: blur(12px);
            border: 1px solid #233358;
        }
    </style>
</head>
<body class="bg-cyber-900 text-slate-200 min-h-screen">
    <div id="app" class="flex flex-col min-h-screen">
        <!-- Top Navigation Bar -->
        <header class="border-b border-cyber-border bg-cyber-800/90 sticky top-0 z-50 backdrop-blur-md px-4 lg:px-8 py-3">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center space-x-3">
                    <div class="w-9 h-9 rounded-lg bg-cyan-500/10 border border-cyan-500/30 flex items-center justify-center text-cyan-400 font-bold font-mono">
                        ⚡
                    </div>
                    <div>
                        <div class="flex items-center space-x-2">
                            <span class="font-bold text-lg tracking-wider text-white">AFTE</span>
                            <span class="text-xs px-2 py-0.5 rounded bg-cyan-500/20 text-cyan-400 font-mono font-medium border border-cyan-500/30">v2.5 PRO</span>
                            <span id="wss-badge" class="inline-flex items-center text-xs font-mono text-emerald-400">
                                <span id="wss-dot" class="w-2 h-2 rounded-full bg-emerald-400 mr-1.5 animate-pulse"></span>
                                <span id="wss-text">WSS: CONNECTING</span>
                            </span>
                        </div>
                        <p class="text-xs text-slate-400">Binance Futures USDS-M • Autonomous Quantitative Engine</p>
                    </div>
                </div>

                <!-- Mode Selector -->
                <div class="flex items-center bg-cyber-900 border border-cyber-border rounded-lg p-1 space-x-1">
                    <button onclick="switchMode('paper')" id="btn-mode-paper" class="px-3 py-1 text-xs font-mono rounded font-medium transition-all">PAPER</button>
                    <button onclick="switchMode('testnet')" id="btn-mode-testnet" class="px-3 py-1 text-xs font-mono rounded font-medium transition-all">TESTNET</button>
                    <button onclick="switchMode('shadow')" id="btn-mode-shadow" class="px-3 py-1 text-xs font-mono rounded font-medium transition-all">SHADOW</button>
                    <button onclick="switchMode('live')" id="btn-mode-live" class="px-3 py-1 text-xs font-mono rounded font-medium transition-all">LIVE</button>
                </div>

                <!-- Global Actions & Navigation -->
                <div class="flex items-center space-x-3">
                    <a id="link-trade-history" href="{{ route('history.index', ['mode' => $mode]) }}" class="px-3 py-1.5 text-xs font-mono bg-cyber-700 hover:bg-cyber-600 border border-cyber-border rounded-md text-cyan-300 transition flex items-center space-x-1.5">
                        <span>📜 Trade History</span>
                    </a>
                    <button onclick="triggerScan(true)" id="btn-scan" class="px-3 py-1.5 text-xs font-mono bg-cyber-700 hover:bg-cyber-600 border border-cyber-border rounded-md text-slate-200 transition flex items-center space-x-1.5">
                        <svg class="w-3.5 h-3.5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <span>Scan Market</span>
                    </button>

                    @if (Auth::user()?->isAdmin())
                        <button onclick="toggleAutoTrading()" id="btn-auto-trading" class="px-3 py-1.5 text-xs font-mono font-bold rounded-md transition flex items-center space-x-1.5 shadow-sm border {{ $account->is_running ? 'bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 border-amber-500/40 shadow-amber-500/10' : 'bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-300 border-emerald-500/40 shadow-emerald-500/10' }}">
                            <span id="auto-trading-dot" class="w-2 h-2 rounded-full {{ $account->is_running ? 'bg-amber-400 animate-pulse' : 'bg-emerald-400' }}"></span>
                            <span id="auto-trading-text">{{ $account->is_running ? 'STOP AUTO TRADING' : 'START AUTO TRADING' }}</span>
                        </button>
                    @else
                        <div id="badge-auto-trading" class="px-3 py-1.5 text-xs font-mono font-medium rounded-md border flex items-center space-x-1.5 {{ $account->is_running ? 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30' : 'bg-slate-800 text-slate-400 border-slate-700' }}" title="Admin permission required to toggle">
                            <span id="auto-trading-dot" class="w-2 h-2 rounded-full {{ $account->is_running ? 'bg-emerald-400 animate-ping' : 'bg-slate-500' }}"></span>
                            <span id="auto-trading-text">AUTO: {{ $account->is_running ? 'ACTIVE' : 'STOPPED' }}</span>
                        </div>
                    @endif

                    <button onclick="toggleKillSwitch()" id="btn-kill-switch" class="px-3 py-1.5 text-xs font-mono font-bold bg-rose-500/20 hover:bg-rose-500/30 text-rose-400 border border-rose-500/40 rounded-md transition flex items-center space-x-1.5">
                        <span class="w-2 h-2 rounded-full bg-rose-500 animate-pulse"></span>
                        <span id="kill-switch-text">KILL SWITCH</span>
                    </button>

                    <!-- Authenticated User & Logout -->
                    <div class="flex items-center space-x-2 pl-3 border-l border-cyber-border text-xs font-mono">
                        <div class="flex items-center space-x-1.5">
                            <span class="text-slate-200 font-medium">{{ Auth::user()->name ?? 'Trader' }}</span>
                            <span class="px-1.5 py-0.5 rounded text-[10px] uppercase font-bold {{ Auth::user()?->isAdmin() ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30' : 'bg-cyan-500/20 text-cyan-300 border border-cyan-500/30' }}">
                                {{ Auth::user()->role ?? 'TRADER' }}
                            </span>
                        </div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="px-2 py-1 text-[11px] text-rose-400 hover:text-rose-300 hover:bg-rose-500/10 rounded transition">
                                Logout
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Workspace -->
        <main class="flex-1 px-4 lg:px-8 py-6 space-y-6 max-w-7xl mx-auto w-full">
            <!-- $5 to $500 Compounding Challenge Banner -->
            <div class="glass-panel rounded-xl p-5 relative overflow-hidden">
                <div class="absolute -right-10 -bottom-10 w-64 h-64 bg-cyan-500/5 rounded-full blur-3xl pointer-events-none"></div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <!-- Balance & Equity -->
                    <div class="space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="text-xs uppercase tracking-wider text-slate-400 font-mono">Wallet Equity</span>
                            <span id="stage-badge" class="text-[10px] px-2 py-0.5 rounded bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 font-mono">Stage 1</span>
                        </div>
                        <div class="flex items-baseline space-x-3">
                            <span id="stat-equity" class="text-3xl font-bold font-mono text-white">$5.00</span>
                            <span id="stat-unrealized-pnl" class="text-xs font-mono font-medium text-emerald-400">+0.00 (0.00%)</span>
                        </div>
                        <div class="text-xs text-slate-400">Balance: <span id="stat-balance" class="font-mono text-slate-200">$5.00</span></div>
                    </div>

                    <!-- Progress to $500 -->
                    <div class="space-y-2">
                        <div class="flex items-center justify-between text-xs font-mono">
                            <span class="text-slate-400">Target: $500.00</span>
                            <span id="stat-progress-pct" class="text-cyan-400 font-bold">0.0%</span>
                        </div>
                        <div class="w-full bg-cyber-900 rounded-full h-2.5 border border-cyber-border overflow-hidden">
                            <div id="stat-progress-bar" class="bg-gradient-to-r from-cyan-500 to-emerald-400 h-2.5 rounded-full transition-all duration-500" style="width: 0%"></div>
                        </div>
                        <p class="text-[11px] text-slate-400 flex items-center justify-between">
                            <span>Seed: <strong class="text-slate-300 font-mono">$5.00</strong></span>
                            <span>Multiplier: <strong class="text-cyan-300 font-mono" id="stat-multiplier">1.0x</strong></span>
                        </p>
                    </div>

                    <!-- Win Rate & Stats -->
                    <div class="grid grid-cols-2 gap-3 border-l border-cyber-border/60 pl-4">
                        <div>
                            <span class="text-[11px] text-slate-400 font-mono uppercase block">Win Rate</span>
                            <span id="stat-win-rate" class="text-xl font-bold font-mono text-white">0.0%</span>
                            <span id="stat-trade-counts" class="text-[11px] text-slate-400 block font-mono">0W / 0L</span>
                        </div>
                        <div>
                            <span class="text-[11px] text-slate-400 font-mono uppercase block">Streak</span>
                            <span id="stat-streak" class="text-xl font-bold font-mono text-slate-300">0</span>
                            <span class="text-[11px] text-slate-400 block font-mono">Consecutive</span>
                        </div>
                    </div>

                    <!-- Engine & Circuit Breaker Status -->
                    <div class="border-l border-cyber-border/60 pl-4 flex flex-col justify-between">
                        <div class="space-y-1">
                            <div class="flex items-center justify-between">
                                <span class="text-[11px] text-slate-400 font-mono uppercase">Auto Trading</span>
                                <span id="engine-status-pill" class="text-[10px] px-2 py-0.5 rounded font-mono font-bold {{ $account->is_running ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' : 'bg-slate-700/60 text-slate-400 border border-slate-600' }}">
                                    {{ $account->is_running ? 'RUNNING' : 'STOPPED' }}
                                </span>
                            </div>
                            <div id="guard-status" class="inline-flex items-center text-xs font-mono text-emerald-400 font-medium pt-1">
                                <span class="w-2 h-2 rounded-full bg-emerald-400 mr-2"></span>
                                Circuit Guard (0/2 Losses)
                            </div>
                        </div>
                        <div class="text-[11px] text-slate-400 pt-2 border-t border-cyber-border/40 flex items-center justify-between">
                            <span>Max Risk: <strong class="text-slate-300 font-mono">10% ($0.50)</strong></span>
                            <span id="terminal-sync-indicator" class="text-cyan-400 font-mono text-[10px]">● SYNCED</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Active Open Positions Matrix -->
            <div class="glass-panel rounded-xl overflow-hidden">
                <div class="px-5 py-4 border-b border-cyber-border flex items-center justify-between">
                    <div class="flex items-center space-x-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-cyan-400 animate-pulse"></span>
                        <h2 class="font-bold text-sm tracking-wide text-white font-mono uppercase">Active Open Positions</h2>
                        <span id="positions-count-badge" class="px-2 py-0.5 rounded-full text-xs font-mono bg-cyber-700 text-cyan-300">0</span>
                    </div>
                    <span class="text-xs text-slate-400 font-mono">Autonomous Breakeven & Trailing Enabled</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-cyber-800/80 text-slate-400 font-mono uppercase border-b border-cyber-border">
                            <tr>
                                <th class="px-5 py-3">Symbol / Side</th>
                                <th class="px-4 py-3">Stage & Status</th>
                                <th class="px-4 py-3">Entry / Mark Price</th>
                                <th class="px-4 py-3">Margin (Lev)</th>
                                <th class="px-4 py-3">Current SL</th>
                                <th class="px-4 py-3">Targets (TP1 / TP2)</th>
                                <th class="px-4 py-3">Unrealized PnL (ROE)</th>
                                <th class="px-5 py-3 text-right">Controls</th>
                            </tr>
                        </thead>
                        <tbody id="positions-tbody" class="divide-y divide-cyber-border/50 font-mono">
                            <tr>
                                <td colspan="8" class="px-5 py-8 text-center text-slate-400">
                                    No active positions currently open. The engine is patiently scanning for institutional breakout confluence.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Split Section: Market Breakout Scanner & Strategy Studio -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Market Breakout Scanner (2 cols) -->
                <div class="lg:col-span-2 glass-panel rounded-xl overflow-hidden flex flex-col">
                    <div class="px-5 py-4 border-b border-cyber-border flex items-center justify-between">
                        <div class="flex items-center space-x-2">
                            <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                            <h2 class="font-bold text-sm tracking-wide text-white font-mono uppercase">Breakout Scanner Radar</h2>
                        </div>
                        <span class="text-xs text-slate-400 font-mono" id="scanner-last-update">Auto-scanned</span>
                    </div>

                    <div class="overflow-x-auto flex-1">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-cyber-800/80 text-slate-400 font-mono uppercase border-b border-cyber-border">
                                <tr>
                                    <th class="px-4 py-3">Pair</th>
                                    <th class="px-3 py-3">Dir</th>
                                    <th class="px-3 py-3">Price</th>
                                    <th class="px-3 py-3">Score</th>
                                    <th class="px-3 py-3">Volume</th>
                                    <th class="px-3 py-3">RSI</th>
                                    <th class="px-3 py-3">AI Verdict</th>
                                    <th class="px-4 py-3">Reasoning</th>
                                </tr>
                            </thead>
                            <tbody id="scanner-tbody" class="divide-y divide-cyber-border/40 font-mono">
                                <tr>
                                    <td colspan="8" class="px-4 py-8 text-center text-slate-400">
                                        Loading market opportunities... Click "Scan Market" to refresh.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Backtesting & Strategy Studio (1 col) -->
                <div class="glass-panel rounded-xl p-5 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between border-b border-cyber-border pb-3 mb-4">
                            <h2 class="font-bold text-sm tracking-wide text-white font-mono uppercase flex items-center space-x-2">
                                <span class="text-cyan-400 font-bold">🧪</span>
                                <span>Backtesting Studio</span>
                            </h2>
                            <span class="text-[10px] px-2 py-0.5 rounded bg-cyan-500/10 text-cyan-400 font-mono border border-cyan-500/20">Binance Klines</span>
                        </div>

                        <div class="space-y-3 text-xs font-mono">
                            <div>
                                <label class="text-slate-400 block mb-1">Trading Pair</label>
                                <select id="bt-symbol" class="w-full bg-cyber-900 border border-cyber-border rounded px-3 py-1.5 text-slate-200">
                                    <option value="SOLUSDT">SOLUSDT (High Momentum)</option>
                                    <option value="BTCUSDT">BTCUSDT (Benchmark)</option>
                                    <option value="ETHUSDT">ETHUSDT (Macro)</option>
                                    <option value="SUIUSDT">SUIUSDT (Breakout Layer 1)</option>
                                    <option value="DOGEUSDT">DOGEUSDT (High Volatility)</option>
                                    <option value="NEARUSDT">NEARUSDT (Trend Follow)</option>
                                </select>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="text-slate-400 block mb-1">Timeframe</label>
                                    <select id="bt-interval" class="w-full bg-cyber-900 border border-cyber-border rounded px-3 py-1.5 text-slate-200">
                                        <option value="15m" selected>15m (Recommended)</option>
                                        <option value="1h">1h (Swing Trend)</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-slate-400 block mb-1">Candles</label>
                                    <select id="bt-limit" class="w-full bg-cyber-900 border border-cyber-border rounded px-3 py-1.5 text-slate-200">
                                        <option value="300">300 Bars (~3 Days)</option>
                                        <option value="600" selected>600 Bars (~6 Days)</option>
                                        <option value="1000">1000 Bars (~10 Days)</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label class="text-slate-400 block mb-1">Starting Capital</label>
                                <input type="number" id="bt-balance" value="5.0" step="0.5" class="w-full bg-cyber-900 border border-cyber-border rounded px-3 py-1.5 text-slate-200">
                            </div>

                            <button onclick="runBacktest()" id="btn-run-backtest" class="w-full py-2 px-4 rounded bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-500 hover:to-blue-500 text-white font-bold transition flex items-center justify-center space-x-2">
                                <span>Simulate Strategy</span>
                            </button>
                        </div>
                    </div>

                    <!-- Backtest Results Display -->
                    <div id="bt-results" class="mt-4 pt-4 border-t border-cyber-border/60 text-xs font-mono space-y-2 hidden">
                        <div class="flex justify-between">
                            <span class="text-slate-400">Simulated Return:</span>
                            <span id="bt-return" class="font-bold text-emerald-400">+0.00%</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400">Final Balance:</span>
                            <span id="bt-final-bal" class="font-bold text-white">$0.00</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400">Win Rate:</span>
                            <span id="bt-winrate" class="text-slate-200">0%</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400">Profit Factor:</span>
                            <span id="bt-pf" class="text-slate-200">0.0</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400">Max Drawdown:</span>
                            <span id="bt-dd" class="text-rose-400">0%</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Closed Trades Audit Journal -->
            <div class="glass-panel rounded-xl overflow-hidden">
                <div class="px-5 py-4 border-b border-cyber-border flex items-center justify-between">
                    <h2 class="font-bold text-sm tracking-wide text-white font-mono uppercase flex items-center space-x-2">
                        <span>📜</span>
                        <span>Trade Audit Log & History</span>
                    </h2>
                    <span class="text-xs text-slate-400 font-mono">Immutable execution ledger</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-cyber-800/80 text-slate-400 font-mono uppercase border-b border-cyber-border">
                            <tr>
                                <th class="px-5 py-3">Symbol / Side</th>
                                <th class="px-4 py-3">Entry Price</th>
                                <th class="px-4 py-3">Exit Price</th>
                                <th class="px-4 py-3">Realized PnL</th>
                                <th class="px-4 py-3">ROE %</th>
                                <th class="px-4 py-3">Exit Reason</th>
                                <th class="px-5 py-3 text-right">Closed At</th>
                            </tr>
                        </thead>
                        <tbody id="history-tbody" class="divide-y divide-cyber-border/40 font-mono">
                            <tr>
                                <td colspan="7" class="px-5 py-6 text-center text-slate-400">
                                    No closed trades yet in this session.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <footer class="border-t border-cyber-border bg-cyber-800/50 py-4 px-8 text-center text-xs text-slate-500 font-mono">
            AFTE Binance Futures AI Engine • Micro-Capital Dynamic Compounding Protocol • Strict Risk Containment
        </footer>
    </div>

    <!-- Frontend Live Polling & Interaction Scripts -->
    <script>
        let statsAbort = null;
        let posAbort = null;
        let historyAbort = null;

        // 1. Resolve mode preference: URL param > localStorage > server blade variable
        const urlParams = new URLSearchParams(window.location.search);
        const urlMode = urlParams.get('mode');
        const savedMode = localStorage.getItem('afte_trading_mode');
        
        let currentMode = '{{ $mode }}';
        if (urlMode && ['paper', 'testnet', 'shadow', 'live'].includes(urlMode)) {
            currentMode = urlMode;
        } else if (savedMode && ['paper', 'testnet', 'shadow', 'live'].includes(savedMode)) {
            currentMode = savedMode;
        }

        // Keep LocalStorage, Cookie, and URL strictly synchronized
        localStorage.setItem('afte_trading_mode', currentMode);
        document.cookie = `afte_trading_mode=${currentMode};path=/;max-age=2592000`;
        window.history.replaceState({}, '', `/?mode=${currentMode}`);

        function updateModeUI(mode) {
            ['paper', 'testnet', 'shadow', 'live'].forEach(m => {
                const btn = document.getElementById(`btn-mode-${m}`);
                if (!btn) return;
                if (m === mode) {
                    btn.className = 'px-3 py-1 text-xs font-mono rounded font-bold bg-cyan-500 text-cyber-900 shadow-sm transition-all';
                } else {
                    btn.className = 'px-3 py-1 text-xs font-mono rounded font-medium text-slate-400 hover:text-white transition-all';
                }
            });
            const linkHistory = document.getElementById('link-trade-history');
            if (linkHistory) linkHistory.href = `/history?mode=${mode}`;
        }

        function switchMode(mode) {
            if (currentMode === mode) return;
            currentMode = mode;
            localStorage.setItem('afte_trading_mode', mode);
            document.cookie = `afte_trading_mode=${mode};path=/;max-age=2592000`;
            window.history.replaceState({}, '', `/?mode=${mode}`);
            updateModeUI(mode);

            // Abort previous in-flight requests to eliminate lag / race conditions
            if (statsAbort) statsAbort.abort();
            if (posAbort) posAbort.abort();
            if (historyAbort) historyAbort.abort();

            // Instant responsive UI feedback
            const statEquity = document.getElementById('stat-equity');
            const statBal = document.getElementById('stat-balance');
            const unPnl = document.getElementById('stat-unrealized-pnl');
            if (statEquity) statEquity.style.opacity = '0.35';
            if (statBal) statBal.style.opacity = '0.35';
            if (unPnl) unPnl.style.opacity = '0.35';

            const tbody = document.getElementById('positions-tbody');
            if (tbody) {
                tbody.innerHTML = `<tr><td colspan="8" class="px-5 py-6 text-center text-cyan-400 font-mono text-xs"><span class="inline-block animate-pulse mr-2">⚡</span> Connecting to ${mode.toUpperCase()} feed...</td></tr>`;
            }

            fetchStats();
            fetchPositions();
            fetchHistory();
        }

        async function fetchStats() {
            try {
                if (statsAbort) statsAbort.abort();
                statsAbort = new AbortController();
                const res = await fetch(`/api/stats?mode=${currentMode}`, { signal: statsAbort.signal });
                const data = await res.json();

                // Discard stale response if user switched mode while fetching
                if (data.mode !== currentMode) return;

                const statEquity = document.getElementById('stat-equity');
                const statBal = document.getElementById('stat-balance');
                const unPnl = document.getElementById('stat-unrealized-pnl');
                if (statEquity) {
                    statEquity.textContent = `$${data.equity.toFixed(2)}`;
                    statEquity.style.opacity = '1';
                }
                if (statBal) {
                    statBal.textContent = `$${data.balance.toFixed(2)}`;
                    statBal.style.opacity = '1';
                }
                
                if (unPnl) {
                    const sign = data.unrealized_pnl >= 0 ? '+' : '';
                    unPnl.textContent = `${sign}$${data.unrealized_pnl.toFixed(2)}`;
                    unPnl.className = `text-xs font-mono font-medium ${data.unrealized_pnl >= 0 ? 'text-emerald-400' : 'text-rose-400'}`;
                    unPnl.style.opacity = '1';
                }

                // Progress Bar
                document.getElementById('stat-progress-pct').textContent = `${data.progress_pct.toFixed(1)}%`;
                document.getElementById('stat-progress-bar').style.width = `${Math.min(100, Math.max(1, data.progress_pct))}%`;

                // Multiplier
                const mult = (data.equity / Math.max(0.1, data.initial_balance)).toFixed(2);
                document.getElementById('stat-multiplier').textContent = `${mult}x`;

                // Win rate & counts
                document.getElementById('stat-win-rate').textContent = `${data.win_rate.toFixed(1)}%`;
                document.getElementById('stat-trade-counts').textContent = `${data.winning_trades}W / ${data.losing_trades}L`;

                // Streak
                document.getElementById('stat-streak').textContent = `${data.consecutive_losses > 0 ? -data.consecutive_losses : data.winning_trades}`;

                // Stage
                document.getElementById('stage-badge').textContent = data.stage;

                // Sync indicator
                const syncIndicator = document.getElementById('terminal-sync-indicator');
                if (syncIndicator) {
                    if (data.live_synced) {
                        syncIndicator.innerHTML = '● BINANCE LIVE';
                        syncIndicator.className = 'text-emerald-400 font-mono text-[10px] font-bold animate-pulse';
                    } else if (data.mode === 'live' || data.mode === 'testnet') {
                        syncIndicator.innerHTML = '○ LOCAL DATA';
                        syncIndicator.className = 'text-amber-400 font-mono text-[10px]';
                    } else {
                        syncIndicator.innerHTML = '● SIMULATED';
                        syncIndicator.className = 'text-cyan-400 font-mono text-[10px]';
                    }
                }

                // Kill switch button status
                const killBtn = document.getElementById('btn-kill-switch');
                const killText = document.getElementById('kill-switch-text');
                if (data.kill_switch) {
                    killBtn.className = 'px-3 py-1.5 text-xs font-mono font-bold bg-rose-600 text-white border border-rose-400 rounded-md transition animate-pulse flex items-center space-x-1.5';
                    killText.textContent = 'HALTED (RESUME)';
                } else {
                    killBtn.className = 'px-3 py-1.5 text-xs font-mono font-bold bg-rose-500/20 hover:bg-rose-500/30 text-rose-400 border border-rose-500/40 rounded-md transition flex items-center space-x-1.5';
                    killText.textContent = 'KILL SWITCH';
                }

                // Auto Trading button / badge update
                window.isAutoTradingRunning = Boolean(data.is_running);
                const autoBtn = document.getElementById('btn-auto-trading');
                const autoDot = document.getElementById('auto-trading-dot');
                const autoText = document.getElementById('auto-trading-text');
                const enginePill = document.getElementById('engine-status-pill');

                if (enginePill) {
                    if (data.is_running) {
                        enginePill.textContent = 'RUNNING';
                        enginePill.className = 'text-[10px] px-2 py-0.5 rounded font-mono font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30';
                    } else {
                        enginePill.textContent = 'STOPPED';
                        enginePill.className = 'text-[10px] px-2 py-0.5 rounded font-mono font-bold bg-slate-700/60 text-slate-400 border border-slate-600';
                    }
                }

                if (autoBtn) {
                    if (data.is_running) {
                        autoBtn.className = 'px-3 py-1.5 text-xs font-mono font-bold bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 border border-amber-500/40 rounded-md transition flex items-center space-x-1.5 shadow-sm shadow-amber-500/10';
                        if (autoDot) autoDot.className = 'w-2.5 h-2.5 rounded-full bg-amber-400 animate-pulse';
                        if (autoText) autoText.textContent = 'STOP AUTO TRADING';
                    } else {
                        autoBtn.className = 'px-3 py-1.5 text-xs font-mono font-bold bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-300 border border-emerald-500/40 rounded-md transition flex items-center space-x-1.5 shadow-sm shadow-emerald-500/10';
                        if (autoDot) autoDot.className = 'w-2.5 h-2.5 rounded-full bg-emerald-400';
                        if (autoText) autoText.textContent = 'START AUTO TRADING';
                    }
                } else if (autoText) {
                    if (data.is_running) {
                        autoText.textContent = 'AUTO: ACTIVE';
                        if (autoDot) autoDot.className = 'w-2 h-2 rounded-full bg-emerald-400 animate-ping';
                    } else {
                        autoText.textContent = 'AUTO: STOPPED';
                        if (autoDot) autoDot.className = 'w-2 h-2 rounded-full bg-slate-500';
                    }
                }

                // Guard status
                const guard = document.getElementById('guard-status');
                if (data.kill_switch) {
                    guard.innerHTML = '<span class="w-2 h-2 rounded-full bg-rose-500 mr-2 animate-pulse"></span>EMERGENCY HALTED';
                    guard.className = 'inline-flex items-center text-xs font-mono text-rose-400 font-bold';
                } else if (!data.is_running) {
                    guard.innerHTML = '<span class="w-2 h-2 rounded-full bg-slate-400 mr-2"></span>AUTO-TRADING STOPPED';
                    guard.className = 'inline-flex items-center text-xs font-mono text-slate-400 font-medium';
                } else if (!data.can_trade) {
                    guard.innerHTML = '<span class="w-2 h-2 rounded-full bg-amber-400 mr-2 animate-pulse"></span>COOLDOWN PAUSED';
                    guard.className = 'inline-flex items-center text-xs font-mono text-amber-400 font-bold';
                } else {
                    guard.innerHTML = `<span class="w-2 h-2 rounded-full bg-emerald-400 mr-2 animate-pulse"></span>ACTIVE (${data.consecutive_losses}/2 Losses)`;
                    guard.className = 'inline-flex items-center text-xs font-mono text-emerald-400 font-medium';
                }

            } catch (err) {
                if (err.name === 'AbortError') return;
                console.error("Stats fetch error:", err);
            }
        }

        let activePositionsData = [];
        let isPositionsFetching = false;

        async function fetchPositions() {
            if (isPositionsFetching) return;
            isPositionsFetching = true;

            try {
                if (posAbort) posAbort.abort();
                posAbort = new AbortController();
                const res = await fetch(`/api/positions?mode=${currentMode}`, { signal: posAbort.signal });
                const data = await res.json();
                activePositionsData = Array.isArray(data) ? data : [];

                document.getElementById('positions-count-badge').textContent = data.length;
                const tbody = document.getElementById('positions-tbody');

                if (data.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="8" class="px-5 py-8 text-center text-slate-400">No active positions currently open in ${currentMode.toUpperCase()} mode.</td></tr>`;
                    return;
                }

                tbody.innerHTML = data.map(pos => {
                    const isLong = pos.side === 'LONG';
                    const sideColor = isLong ? 'text-emerald-400 bg-emerald-500/10 border-emerald-500/30' : 'text-rose-400 bg-rose-500/10 border-rose-500/30';
                    const pnlColor = pos.unrealized_pnl >= 0 ? 'text-emerald-400' : 'text-rose-400';
                    const sign = pos.unrealized_pnl >= 0 ? '+' : '';

                    let stageBadge = `<span class="px-2 py-0.5 rounded text-[10px] bg-cyan-500/10 text-cyan-300 border border-cyan-500/30">${pos.stage}</span>`;
                    if (pos.be_locked) {
                        stageBadge = `<span class="px-2 py-0.5 rounded text-[10px] bg-amber-500/10 text-amber-300 border border-amber-500/30 font-bold">BE LOCKED 🛡️</span>`;
                    }
                    if (pos.stage === 'TRAILING') {
                        stageBadge = `<span class="px-2 py-0.5 rounded text-[10px] bg-emerald-500/10 text-emerald-300 border border-emerald-500/30 font-bold">TRAILING 🚀</span>`;
                    }

                    return `
                        <tr class="hover:bg-cyber-800/40 transition">
                            <td class="px-5 py-3">
                                <div class="flex items-center space-x-2">
                                    <span class="font-bold text-white">${pos.symbol}</span>
                                    <span class="px-1.5 py-0.5 rounded text-[10px] border ${sideColor}">${pos.side}</span>
                                </div>
                                <span class="text-[10px] text-slate-400">${pos.opened_at || 'just now'}</span>
                            </td>
                            <td class="px-4 py-3">${stageBadge}</td>
                            <td class="px-4 py-3">
                                <span class="text-slate-200 block">$${pos.entry_price}</span>
                                <span id="pos-mark-${pos.id}" class="text-[11px] text-cyan-400 font-mono">Mark: $${pos.mark_price}</span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-slate-200 block">$${pos.margin_used}</span>
                                <span class="text-[10px] text-slate-400">${pos.leverage}x ISOLATED</span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-rose-300 font-bold">$${pos.current_sl}</span>
                                ${pos.has_exchange_sl 
                                    ? `<span class="text-[9px] px-1.5 py-0.5 mt-0.5 rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 block font-mono font-medium" title="Active STOP_MARKET conditional order on Binance exchange">🛡️ SL on Binance: $${pos.exchange_sl_price || pos.current_sl}</span>` 
                                    : (pos.be_locked ? '<span class="text-[10px] text-amber-400 block">Risk Free</span>' : '<span class="text-[10px] text-slate-400 block">Trailing Soft SL</span>')}
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-[11px]">
                                    <span class="${pos.tp1_hit ? 'line-through text-slate-500' : 'text-emerald-300'}">TP1: $${pos.tp1_price}</span>
                                    <span class="${pos.tp2_hit ? 'line-through text-slate-500' : 'text-emerald-400'} block">TP2: $${pos.tp2_price}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span id="pos-pnl-${pos.id}" class="${pnlColor} font-bold text-sm block">${sign}$${pos.unrealized_pnl.toFixed(2)}</span>
                                <span id="pos-roe-${pos.id}" class="${pnlColor} text-[11px]">ROE: ${sign}${pos.roe.toFixed(2)}%</span>
                            </td>
                            <td class="px-5 py-3 text-right space-x-1">
                                ${!pos.be_locked ? `<button onclick="lockBreakeven(${pos.id})" class="px-2 py-1 text-[10px] bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 border border-amber-500/30 rounded transition">Lock BE</button>` : ''}
                                <button onclick="closePosition(${pos.id})" class="px-2 py-1 text-[10px] bg-rose-500/20 hover:bg-rose-500/30 text-rose-300 border border-rose-500/30 rounded transition">Close</button>
                            </td>
                        </tr>
                    `;
                }).join('');
            } catch (err) {
                if (err.name === 'AbortError') return;
                console.error("Positions fetch error:", err);
            } finally {
                isPositionsFetching = false;
            }
        }

        async function fetchHistory() {
            try {
                if (historyAbort) historyAbort.abort();
                historyAbort = new AbortController();
                const res = await fetch(`/api/history?mode=${currentMode}`, { signal: historyAbort.signal });
                const data = await res.json();
                const tbody = document.getElementById('history-tbody');

                if (data.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="7" class="px-5 py-6 text-center text-slate-400">No closed trades yet in ${currentMode.toUpperCase()} mode.</td></tr>`;
                    return;
                }

                tbody.innerHTML = data.map(t => {
                    const isLong = t.side === 'LONG';
                    const sideColor = isLong ? 'text-emerald-400' : 'text-rose-400';
                    const pnlColor = t.realized_pnl >= 0 ? 'text-emerald-400 font-bold' : 'text-rose-400 font-bold';
                    const sign = t.realized_pnl >= 0 ? '+' : '';
                    const dateStr = t.closed_at ? new Date(t.closed_at).toLocaleTimeString() : '-';

                    return `
                        <tr class="hover:bg-cyber-800/40 transition">
                            <td class="px-5 py-2.5">
                                <span class="font-bold text-white">${t.symbol}</span>
                                <span class="ml-1 text-[10px] ${sideColor}">${t.side}</span>
                            </td>
                            <td class="px-4 py-2.5 text-slate-300">$${t.entry_price}</td>
                            <td class="px-4 py-2.5 text-slate-300">$${t.exit_price || '-'}</td>
                            <td class="px-4 py-2.5 ${pnlColor}">${sign}$${t.realized_pnl.toFixed(2)}</td>
                            <td class="px-4 py-2.5 ${pnlColor}">${sign}${t.pnl_percent.toFixed(2)}%</td>
                            <td class="px-4 py-2.5">
                                <span class="px-1.5 py-0.5 rounded text-[10px] bg-cyber-700 text-slate-300 border border-cyber-border">${t.exit_reason || 'CLOSED'}</span>
                            </td>
                            <td class="px-5 py-2.5 text-right text-slate-400 text-[11px]">${dateStr}</td>
                        </tr>
                    `;
                }).join('');
            } catch (err) {
                if (err.name === 'AbortError') return;
                console.error("History fetch error:", err);
            }
        }

        async function triggerScan(fresh = false) {
            const btn = document.getElementById('btn-scan');
            btn.innerHTML = `<span>Scanning...</span>`;
            btn.disabled = true;

            try {
                const url = fresh ? `/api/scan?limit=10&fresh=1` : `/api/scan?limit=10`;
                const res = await fetch(url);
                const data = await res.json();
                const tbody = document.getElementById('scanner-tbody');
                const cacheNotice = data.cached_at ? ' (Instant Cached)' : '';
                document.getElementById('scanner-last-update').textContent = `Scanned ${data.total_scanned} pairs${cacheNotice} at ${new Date().toLocaleTimeString()}`;

                if (data.opportunities.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="8" class="px-4 py-6 text-center text-slate-400">No high-conviction breakout setup detected right now. Markets are in range; capital protected.</td></tr>`;
                } else {
                    tbody.innerHTML = data.opportunities.map(op => {
                        const isLong = op.direction === 'LONG';
                        const dirBadge = isLong ? 'text-emerald-400 bg-emerald-500/10 border-emerald-500/30' : 'text-rose-400 bg-rose-500/10 border-rose-500/30';
                        const aiBadge = op.ai_approved ? 'text-emerald-400 font-bold' : 'text-rose-400 font-medium';

                        return `
                            <tr class="hover:bg-cyber-800/40 transition">
                                <td class="px-4 py-2.5 font-bold text-white">${op.symbol}</td>
                                <td class="px-3 py-2.5"><span class="px-1.5 py-0.5 rounded text-[10px] border ${dirBadge}">${op.direction}</span></td>
                                <td class="px-3 py-2.5 text-slate-300">$${op.price}</td>
                                <td class="px-3 py-2.5 font-bold text-cyan-300">${op.score}/100</td>
                                <td class="px-3 py-2.5 text-slate-300">${op.indicators.volume_ratio}x</td>
                                <td class="px-3 py-2.5 text-slate-300">${op.indicators.rsi}</td>
                                <td class="px-3 py-2.5 ${aiBadge}">${op.ai_approved ? '✅ APPROVED' : '❌ REJECTED'}</td>
                                <td class="px-4 py-2.5 text-[11px] text-slate-400 truncate max-w-xs" title="${op.ai_reason}">${op.ai_reason}</td>
                            </tr>
                        `;
                    }).join('');
                }
            } catch (err) {
                console.error("Scan error:", err);
            } finally {
                btn.innerHTML = `<svg class="w-3.5 h-3.5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg><span>Scan Market</span>`;
                btn.disabled = false;
            }
        }

        async function lockBreakeven(tradeId) {
            try {
                const res = await fetch('/api/lock-breakeven', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ trade_id: tradeId })
                });
                const data = await res.json();
                if (data.success) {
                    fetchPositions();
                    fetchStats();
                }
            } catch (err) {
                console.error("Lock BE error:", err);
            }
        }

        async function closePosition(tradeId) {
            if (!confirm('Are you sure you want to market-close this position?')) return;
            try {
                const res = await fetch('/api/close-position', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ trade_id: tradeId })
                });
                const data = await res.json();
                if (data.success) {
                    fetchPositions();
                    fetchStats();
                    fetchHistory();
                }
            } catch (err) {
                console.error("Close position error:", err);
            }
        }

        async function toggleKillSwitch() {
            if (!confirm('EMERGENCY: Toggle Kill Switch? When activated, ALL active trades are liquidated immediately and the bot halts.')) return;
            try {
                const res = await fetch('/api/kill-switch', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ mode: currentMode })
                });
                const data = await res.json();
                alert(data.message);
                fetchStats();
                fetchPositions();
                fetchHistory();
            } catch (err) {
                console.error("Kill switch error:", err);
            }
        }

        async function toggleAutoTrading() {
            try {
                const res = await fetch('/api/toggle-auto-trading', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ mode: currentMode })
                });
                const data = await res.json();
                if (data.success) {
                    fetchStats();
                    fetchPositions();
                    if (data.is_running) {
                        runAutoTick();
                    }
                } else {
                    alert(data.message || 'Error updating auto-trading state.');
                }
            } catch (err) {
                console.error("Auto-trading toggle error:", err);
            }
        }

        let isTicking = false;
        async function runAutoTick() {
            if (isTicking || !window.isAutoTradingRunning) return;
            isTicking = true;
            try {
                const res = await fetch('/api/auto-tick', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ mode: currentMode })
                });
                const data = await res.json();
                if (data.opened_trade) {
                    fetchPositions();
                    fetchStats();
                    triggerScan();
                }
                if (data.closed_positions && data.closed_positions.length > 0) {
                    fetchPositions();
                    fetchStats();
                    fetchHistory();
                }
            } catch (err) {
                console.error("Auto-tick error:", err);
            } finally {
                isTicking = false;
            }
        }

        async function runBacktest() {
            const btn = document.getElementById('btn-run-backtest');
            btn.innerHTML = '<span>Simulating...</span>';
            btn.disabled = true;

            const symbol = document.getElementById('bt-symbol').value;
            const interval = document.getElementById('bt-interval').value;
            const limit = document.getElementById('bt-limit').value;
            const balance = document.getElementById('bt-balance').value;

            try {
                const res = await fetch('/api/backtest', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ symbol, interval, limit, balance })
                });
                const resData = await res.json();
                if (resData.success) {
                    const d = resData.data;
                    document.getElementById('bt-results').classList.remove('hidden');
                    const sign = d.net_profit >= 0 ? '+' : '';
                    document.getElementById('bt-return').textContent = `${sign}${d.net_profit_pct.toFixed(2)}% (${sign}$${d.net_profit.toFixed(2)})`;
                    document.getElementById('bt-return').className = d.net_profit >= 0 ? 'font-bold text-emerald-400' : 'font-bold text-rose-400';
                    document.getElementById('bt-final-bal').textContent = `$${d.final_balance.toFixed(2)}`;
                    document.getElementById('bt-winrate').textContent = `${d.win_rate}% (${d.wins}W / ${d.losses}L)`;
                    document.getElementById('bt-pf').textContent = `${d.profit_factor}`;
                    document.getElementById('bt-dd').textContent = `${d.max_drawdown_pct}%`;
                } else {
                    alert('Backtest error: ' + resData.message);
                }
            } catch (err) {
                console.error("Backtest error:", err);
            } finally {
                btn.innerHTML = '<span>Simulate Strategy</span>';
                btn.disabled = false;
            }
        }

        // Direct Binance Futures WebSocket Integration (Zero Server Load)
        let binanceWs = null;
        let wsReconnectTimeout = null;

        function initBinanceWebSocket() {
            if (binanceWs) {
                try { binanceWs.close(); } catch (_) {}
            }

            const wssBadge = document.getElementById('wss-badge');
            const wssDot = document.getElementById('wss-dot');
            const wssText = document.getElementById('wss-text');

            try {
                // Connect to Binance USD-M Futures MiniTicker array stream for all symbols
                binanceWs = new WebSocket('wss://fstream.binance.com/ws/!miniTicker@arr');

                binanceWs.onopen = () => {
                    if (wssBadge) wssBadge.className = 'inline-flex items-center text-xs font-mono text-emerald-400 font-medium';
                    if (wssDot) wssDot.className = 'w-2 h-2 rounded-full bg-emerald-400 mr-1.5 animate-pulse';
                    if (wssText) wssText.textContent = 'WSS: LIVE STREAM';
                };

                binanceWs.onmessage = (event) => {
                    try {
                        const tickers = JSON.parse(event.data);
                        if (!Array.isArray(tickers)) return;

                        // Create quick symbol -> close price map
                        const priceMap = {};
                        for (let i = 0; i < tickers.length; i++) {
                            priceMap[tickers[i].s] = parseFloat(tickers[i].c);
                        }

                        // Update open positions table in real time directly in the DOM
                        updateLivePositionsWithWs(priceMap);
                    } catch (e) {
                        // Ignore parse error
                    }
                };

                binanceWs.onerror = () => {
                    if (wssBadge) wssBadge.className = 'inline-flex items-center text-xs font-mono text-amber-400';
                    if (wssDot) wssDot.className = 'w-2 h-2 rounded-full bg-amber-400 mr-1.5';
                    if (wssText) wssText.textContent = 'WSS: RECONNECTING';
                };

                binanceWs.onclose = () => {
                    if (wssBadge) wssBadge.className = 'inline-flex items-center text-xs font-mono text-slate-400';
                    if (wssDot) wssDot.className = 'w-2 h-2 rounded-full bg-slate-500 mr-1.5';
                    if (wssText) wssText.textContent = 'WSS: OFFLINE';
                    clearTimeout(wsReconnectTimeout);
                    wsReconnectTimeout = setTimeout(initBinanceWebSocket, 4000);
                };
            } catch (err) {
                console.warn("WebSocket init error:", err);
            }
        }

        function updateLivePositionsWithWs(priceMap) {
            if (!activePositionsData || activePositionsData.length === 0) return;

            activePositionsData.forEach(pos => {
                const livePrice = priceMap[pos.symbol];
                if (!livePrice || isNaN(livePrice)) return;

                const markEl = document.getElementById(`pos-mark-${pos.id}`);
                const pnlEl = document.getElementById(`pos-pnl-${pos.id}`);
                const roeEl = document.getElementById(`pos-roe-${pos.id}`);

                if (markEl) {
                    markEl.textContent = `Mark: $${livePrice.toFixed(4)}`;
                }

                const isLong = pos.side === 'LONG';
                const qty = parseFloat(pos.quantity) || 0;
                const entry = parseFloat(pos.entry_price) || 0;
                const margin = parseFloat(pos.margin_used) || 1;

                const unPnl = isLong ? (livePrice - entry) * qty : (entry - livePrice) * qty;
                const roe = margin > 0 ? (unPnl / margin) * 100 : 0;
                const sign = unPnl >= 0 ? '+' : '';
                const pnlColor = unPnl >= 0 ? 'text-emerald-400 font-bold text-sm block' : 'text-rose-400 font-bold text-sm block';
                const roeColor = unPnl >= 0 ? 'text-emerald-400 text-[11px]' : 'text-rose-400 text-[11px]';

                if (pnlEl) {
                    pnlEl.textContent = `${sign}$${unPnl.toFixed(2)}`;
                    pnlEl.className = pnlColor;
                }
                if (roeEl) {
                    roeEl.textContent = `ROE: ${sign}${roe.toFixed(2)}%`;
                    roeEl.className = roeColor;
                }
            });
        }

        // Initialize and start live polling cycle
        updateModeUI(currentMode);
        initBinanceWebSocket();
        fetchStats();
        fetchPositions();
        fetchHistory();

        // 6-second refresh cycle for server-side balance & margin sync
        setInterval(() => {
            fetchStats();
            fetchPositions();
        }, 6000);

        // 5-second autonomous tick cycle when auto-trading is active
        setInterval(() => {
            if (window.isAutoTradingRunning) {
                runAutoTick();
            }
        }, 5000);

        // 30-second trade history refresh
        setInterval(fetchHistory, 30000);
    </script>
</body>
</html>
