<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AFTE</title>
    <link rel="icon" type="image/png" href="{{ asset('asset/logo.png') }}">
    <link rel="shortcut icon" type="image/png" href="{{ asset('asset/logo.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('asset/logo.png') }}">
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
<body class="bg-cyber-900 text-slate-200 min-h-screen overflow-x-hidden">
    @include('layouts.sidebar')

    <div id="app" class="lg:pl-64 flex flex-col min-h-screen w-full max-w-full overflow-x-hidden">
        <!-- Top Navigation Bar -->
        <header class="border-b border-cyber-border bg-cyber-800/95 sticky top-0 z-20 backdrop-blur-md px-3 sm:px-4 lg:px-8 py-2.5 sm:py-3 w-full max-w-full overflow-hidden">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3 w-full max-w-full">
                <!-- Top / Left Row: Hamburger + Brand + Live Feed Indicator + Mobile User/Logout Bar -->
                <div class="flex items-center justify-between w-full lg:w-auto min-w-0">
                    <div class="flex items-center space-x-2.5 sm:space-x-3 min-w-0">
                        <button type="button" onclick="toggleSidebar()" class="lg:hidden p-2 rounded-xl text-slate-300 hover:text-white bg-cyber-700/60 border border-cyber-border focus:outline-none flex-shrink-0" aria-label="Toggle Navigation Menu">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                            </svg>
                        </button>
                        <div class="w-8 h-8 sm:w-9 sm:h-9 rounded-lg bg-cyan-500/10 border border-cyan-500/30 p-1 flex items-center justify-center shadow-[0_0_12px_rgba(6,182,212,0.2)] flex-shrink-0">
                            <img src="{{ asset('asset/logo.png') }}" alt="AFTE Logo" class="w-full h-full object-contain">
                        </div>
                        <div class="min-w-0">
                            <div class="flex items-center space-x-1.5 sm:space-x-2">
                                <span class="font-bold text-base sm:text-lg tracking-wider text-white">AFTE</span>
                                <span class="text-[10px] sm:text-xs px-1.5 sm:px-2 py-0.5 rounded bg-cyan-500/20 text-cyan-400 font-mono font-medium border border-cyan-500/30">v2.5 PRO</span>
                                <span id="wss-badge" class="inline-flex items-center text-[11px] sm:text-xs font-mono text-emerald-400">
                                    <span id="wss-dot" class="w-1.5 h-1.5 sm:w-2 sm:h-2 rounded-full bg-emerald-400 mr-1 sm:mr-1.5 animate-pulse"></span>
                                    <span id="wss-text" class="hidden xs:inline">WSS: CONNECTING</span>
                                </span>
                            </div>
                            <p class="text-xs text-slate-400 hidden sm:block truncate">Binance Futures USDS-M • Autonomous Quantitative Engine</p>
                        </div>
                    </div>

                    <!-- Mobile-Only User Pill & Fast Logout (< lg screens) -->
                    <div class="flex lg:hidden items-center space-x-1.5 sm:space-x-2 flex-shrink-0 pl-2">
                        <span class="px-2 py-0.5 text-[10px] font-mono font-bold uppercase rounded tracking-wider {{ Auth::user()?->isAdmin() ? 'bg-purple-950/90 text-purple-300 border border-purple-700/60 shadow-[0_0_8px_rgba(168,85,247,0.2)]' : 'bg-cyan-950/90 text-cyan-300 border border-cyan-700/60' }}">
                            {{ Auth::user()->role ?? 'TRADER' }}
                        </span>
                        <form method="POST" action="{{ route('logout') }}" class="inline m-0">
                            @csrf
                            <button type="submit" title="Logout ({{ Auth::user()->name }})" class="p-1.5 sm:p-2 text-rose-400 hover:text-rose-300 bg-rose-950/30 hover:bg-rose-950/60 border border-rose-900/40 rounded-xl transition flex items-center justify-center">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                                </svg>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Secondary Row / Right Controls: Mode Selector & Global Trading Actions -->
                <div class="flex flex-wrap items-center justify-between lg:justify-end gap-2 sm:gap-2.5 w-full lg:w-auto min-w-0">
                    <!-- Mode Selector -->
                    <div class="flex items-center bg-cyber-900 border border-cyber-border rounded-lg p-0.5 sm:p-1 space-x-0.5 sm:space-x-1 flex-shrink-0">
                        <button onclick="switchMode('paper')" id="btn-mode-paper" class="px-2.5 sm:px-3 py-1 text-[11px] sm:text-xs font-mono rounded font-medium transition-all">PAPER</button>
                        <button onclick="switchMode('live')" id="btn-mode-live" class="px-2.5 sm:px-3 py-1 text-[11px] sm:text-xs font-mono rounded font-medium transition-all">LIVE</button>
                    </div>

                    <!-- Trading Action Buttons -->
                    <div class="flex flex-wrap items-center gap-1.5 sm:gap-2 min-w-0">
                        <a id="link-trade-history" href="{{ route('history.index', ['mode' => $mode]) }}" class="hidden md:inline-flex px-2.5 sm:px-3 py-1.5 text-xs font-mono bg-cyber-700 hover:bg-cyber-600 border border-cyber-border rounded-lg text-cyan-300 transition items-center space-x-1.5 flex-shrink-0">
                            <span>📜 Trade History</span>
                        </a>
                        <button onclick="triggerScan(true)" id="btn-scan" class="px-2.5 sm:px-3 py-1.5 text-xs font-mono bg-cyber-700 hover:bg-cyber-600 border border-cyber-border rounded-lg text-slate-200 transition flex items-center space-x-1.5 flex-shrink-0">
                            <svg class="w-3.5 h-3.5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            <span>Scan Market</span>
                        </button>

                        @if (Auth::user()?->isAdmin())
                            <button onclick="toggleAutoTrading()" id="btn-auto-trading" class="px-2.5 sm:px-3 py-1.5 text-xs font-mono font-bold rounded-lg transition flex items-center space-x-1.5 shadow-sm border flex-shrink-0 {{ $account->is_running ? 'bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 border-amber-500/40 shadow-amber-500/10' : 'bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-300 border-emerald-500/40 shadow-emerald-500/10' }}">
                                <span id="auto-trading-dot" class="w-2 h-2 rounded-full {{ $account->is_running ? 'bg-amber-400 animate-pulse' : 'bg-emerald-400' }}"></span>
                                <span id="auto-trading-text">{{ $account->is_running ? 'STOP AUTO TRADING' : 'START AUTO TRADING' }}</span>
                            </button>
                        @else
                            <div id="badge-auto-trading" class="px-2.5 sm:px-3 py-1.5 text-xs font-mono font-medium rounded-lg border flex items-center space-x-1.5 flex-shrink-0 {{ $account->is_running ? 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30' : 'bg-slate-800 text-slate-400 border-slate-700' }}" title="Admin permission required to toggle">
                                <span id="auto-trading-dot" class="w-2 h-2 rounded-full {{ $account->is_running ? 'bg-emerald-400 animate-ping' : 'bg-slate-500' }}"></span>
                                <span id="auto-trading-text">AUTO: {{ $account->is_running ? 'ACTIVE' : 'STOPPED' }}</span>
                            </div>
                        @endif

                        <button onclick="toggleKillSwitch()" id="btn-kill-switch" class="px-2.5 sm:px-3 py-1.5 text-xs font-mono font-bold bg-rose-500/20 hover:bg-rose-500/30 text-rose-400 border border-rose-500/40 rounded-lg transition flex items-center space-x-1.5 flex-shrink-0">
                            <span class="w-2 h-2 rounded-full bg-rose-500 animate-pulse"></span>
                            <span id="kill-switch-text">KILL SWITCH</span>
                        </button>

                        <!-- Desktop Authenticated User & Logout (Visible on lg+ screens) -->
                        <div class="hidden lg:flex items-center space-x-2 pl-3 border-l border-cyber-border text-xs font-mono min-w-0 flex-shrink-0">
                            <div class="flex items-center space-x-1.5 min-w-0">
                                <span class="text-slate-200 font-medium truncate max-w-[130px]" title="{{ Auth::user()->name ?? 'Trader' }}">{{ Auth::user()->name ?? 'Trader' }}</span>
                                <span class="px-1.5 py-0.5 rounded text-[10px] uppercase font-bold {{ Auth::user()?->isAdmin() ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30' : 'bg-cyan-500/20 text-cyan-300 border border-cyan-500/30' }}">
                                    {{ Auth::user()->role ?? 'TRADER' }}
                                </span>
                            </div>
                            <form method="POST" action="{{ route('logout') }}" class="m-0">
                                @csrf
                                <button type="submit" class="px-2 py-1 text-[11px] text-rose-400 hover:text-rose-300 hover:bg-rose-500/10 rounded transition">
                                    Logout
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Workspace -->
        <main class="flex-1 px-4 lg:px-8 py-6 space-y-6 max-w-7xl mx-auto w-full">
            <!-- 24/7 Background Trading Daemon Status Bar -->
            <div id="daemon-banner" class="glass-panel rounded-xl p-4 border border-cyan-500/30 bg-cyber-800/90 shadow-[0_0_20px_rgba(6,182,212,0.08)]">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center space-x-3.5">
                        <div class="relative flex items-center justify-center">
                            <span id="daemon-pulse-ring" class="absolute w-8 h-8 rounded-full bg-emerald-400/20 animate-ping"></span>
                            <span id="daemon-dot" class="w-3.5 h-3.5 rounded-full bg-emerald-400 shadow-[0_0_10px_#10b981]"></span>
                        </div>
                        <div>
                            <div class="flex items-center space-x-2">
                                <span class="font-bold text-sm text-white font-mono tracking-wide" id="daemon-title">24/7 AUTONOMOUS TRADING DAEMON</span>
                                <span id="daemon-status-badge" class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">
                                    ACTIVE (BACKGROUND)
                                </span>
                            </div>
                            <p class="text-xs text-slate-400 font-mono flex flex-wrap items-center gap-x-3 gap-y-1 mt-0.5">
                                <span>Worker: <span id="daemon-health-desc" class="text-slate-300 font-semibold">Continuous Engine</span></span>
                                <span>•</span>
                                <span>Heartbeat: <span id="daemon-heartbeat-text" class="text-cyan-300 font-bold">Live</span></span>
                                <span>•</span>
                                <span>Cycles: <span id="daemon-cycles-text" class="text-slate-200">0</span></span>
                                <span>•</span>
                                <span class="text-emerald-400/90 font-medium">Runs 24/7 Without User Activity</span>
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center space-x-2.5">
                        <button onclick="openDaemonLogsModal()" class="px-3 py-1.5 text-xs font-mono bg-cyber-700 hover:bg-cyber-600 border border-cyber-border rounded-md text-cyan-300 hover:text-cyan-200 transition flex items-center space-x-1.5 shadow-sm">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            <span>Live Logs</span>
                        </button>

                        @if (Auth::user()?->isAdmin())
                            <button onclick="restartDaemon()" id="btn-restart-daemon" class="px-3 py-1.5 text-xs font-mono bg-cyber-700/80 hover:bg-cyber-600 border border-cyber-border rounded-md text-slate-300 hover:text-white transition flex items-center space-x-1" title="Restart Background Daemon Process">
                                <svg class="w-3.5 h-3.5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                <span>Restart Daemon</span>
                            </button>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Portfolio Balance & Margin Utilization Matrix -->
            <div class="glass-panel rounded-xl p-5 border border-cyber-border">
                <div class="flex flex-wrap items-center justify-between gap-2 pb-3 mb-4 border-b border-cyber-border/60">
                    <div class="flex items-center space-x-2">
                        <span class="text-cyan-400 text-base">📊</span>
                        <h3 class="font-bold text-sm tracking-wide text-white font-mono uppercase">Portfolio Capital & Margin Allocation Matrix</h3>
                    </div>
                    <div class="flex items-center space-x-2">
                        <span class="text-xs text-slate-400 font-mono">Strict Liquidation Shield</span>
                        <button onclick="toggleAllocationModal()" class="text-xs font-mono text-cyan-400 hover:text-cyan-300 underline font-semibold flex items-center space-x-1">
                            <span>Strategy & Sizing Explained</span>
                            <span class="text-[10px]">ℹ️</span>
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- Total Equity & Wallet Balance -->
                    <div class="bg-cyber-800/60 rounded-lg p-3.5 border border-cyber-border/60 space-y-1">
                        <div class="flex items-center justify-between text-slate-400 text-xs font-mono">
                            <span>TOTAL WALLET EQUITY</span>
                            <span id="alloc-stage-text" class="text-cyan-300 font-bold">Stage 1</span>
                        </div>
                        <div class="text-2xl font-bold font-mono text-white" id="alloc-equity">${{ number_format($account->equity, 2) }}</div>
                        <div class="text-[11px] text-slate-400 font-mono flex justify-between">
                            <span>Wallet Cash: <strong id="alloc-balance" class="text-slate-200 font-mono">${{ number_format($account->balance, 2) }}</strong></span>
                            <span id="alloc-unrealized-pnl" class="text-emerald-400 font-bold">+0.00</span>
                        </div>
                    </div>

                    <!-- Used Margin in Active Trades -->
                    <div class="bg-cyber-800/60 rounded-lg p-3.5 border border-cyber-border/60 space-y-1">
                        <div class="flex items-center justify-between text-slate-400 text-xs font-mono">
                            <span>USED MARGIN (IN TRADES)</span>
                            <span id="stat-margin-util-badge" class="px-1.5 py-0.5 rounded text-[10px] font-mono bg-cyan-500/10 text-cyan-300 border border-cyan-500/30">0.0% Used</span>
                        </div>
                        <div class="text-2xl font-bold font-mono text-cyan-300" id="stat-used-margin">$0.00</div>
                        <div class="text-[11px] text-slate-400 font-mono flex justify-between">
                            <span>Active Positions: <strong id="alloc-pos-count" class="text-slate-200">0</strong></span>
                            <span>Max Concurrent: <strong class="text-slate-300">3</strong></span>
                        </div>
                    </div>

                    <!-- Available Free Margin (Safety Buffer) -->
                    <div class="bg-cyber-800/60 rounded-lg p-3.5 border border-cyber-border/60 space-y-1">
                        <div class="flex items-center justify-between text-slate-400 text-xs font-mono">
                            <span>FREE SAFETY BUFFER</span>
                            <span class="text-emerald-400 text-[10px] font-mono font-bold">PROTECTED</span>
                        </div>
                        <div class="text-2xl font-bold font-mono text-emerald-400" id="stat-free-buffer">${{ number_format($account->balance, 2) }}</div>
                        <div class="text-[11px] text-slate-400 font-mono flex justify-between">
                            <span>Liquidation Guard: <strong class="text-emerald-300 font-mono">Safe (>85%)</strong></span>
                            <span>Buffer Pct: <strong id="alloc-buffer-pct" class="text-slate-300 font-mono">100%</strong></span>
                        </div>
                    </div>

                    <!-- Trade Sizing Rule for Current Balance -->
                    <div class="bg-cyber-800/60 rounded-lg p-3.5 border border-cyber-border/60 space-y-1 flex flex-col justify-between">
                        <div>
                            <div class="flex items-center justify-between text-slate-400 text-xs font-mono">
                                <span>PER-TRADE ALLOCATION</span>
                                <span class="text-[10px] px-1.5 py-0.5 rounded bg-blue-500/10 text-blue-300 border border-blue-500/30 font-mono">10x LEV</span>
                            </div>
                            <div class="text-2xl font-bold font-mono text-white" id="stat-alloc-amount">~$0.52 <span class="text-xs text-slate-400 font-normal">USDT</span></div>
                        </div>
                        <div class="text-[11px] text-slate-400 font-mono">
                            Target Notional: <strong class="text-slate-300 font-mono">$5.20</strong> (Binance Min $5)
                        </div>
                    </div>
                </div>

                <!-- Visual Margin Utilization Bar -->
                <div class="mt-4 pt-3 border-t border-cyber-border/40">
                    <div class="flex items-center justify-between text-xs font-mono mb-1.5">
                        <div class="flex items-center space-x-2">
                            <span class="text-slate-400">Margin Utilization:</span>
                            <span id="alloc-bar-pct-text" class="text-cyan-400 font-bold">0.0%</span>
                            <span class="text-slate-500 text-[11px] hidden sm:inline">(Capital active in positions vs total account buffer)</span>
                        </div>
                        <div class="flex items-center space-x-4 text-[11px]">
                            <span class="flex items-center space-x-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-cyan-500"></span><span class="text-slate-400 font-mono">Used Margin</span></span>
                            <span class="flex items-center space-x-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-emerald-500/60"></span><span class="text-slate-400 font-mono">Free Buffer (>85%)</span></span>
                        </div>
                    </div>
                    <div class="w-full bg-cyber-900 rounded-full h-3 border border-cyber-border overflow-hidden flex">
                        <div id="alloc-bar-used" class="bg-gradient-to-r from-cyan-500 to-blue-500 h-3 transition-all duration-500" style="width: 0%"></div>
                        <div id="alloc-bar-free" class="bg-gradient-to-r from-emerald-500/50 to-emerald-500/30 h-3 transition-all duration-500" style="width: 100%"></div>
                    </div>
                </div>
            </div>

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
                            <span id="stat-equity" class="text-3xl font-bold font-mono text-white">${{ number_format($account->equity, 2) }}</span>
                            <span id="stat-unrealized-pnl" class="text-xs font-mono font-medium text-emerald-400">+0.00 (0.00%)</span>
                        </div>
                        <div class="text-xs text-slate-400">Wallet: <span id="stat-balance" class="font-mono text-slate-200">${{ number_format($account->balance, 2) }}</span> | Avail: <span id="stat-available-margin" class="font-mono text-cyan-300 font-semibold">${{ number_format($account->balance, 2) }}</span></div>
                    </div>

                    <!-- Stage 1 Milestone ($25.00) & Progress to $500 -->
                    <div class="space-y-2">
                        <div class="flex items-center justify-between text-xs font-mono">
                            <span class="text-cyan-300 font-bold flex items-center">
                                <span class="inline-block w-2 h-2 rounded-full bg-cyan-400 mr-1.5 animate-pulse"></span>
                                STAGE 1 TARGET: $25.00
                            </span>
                            <span id="stat-stage-progress-pct" class="text-cyan-400 font-bold">{{ number_format(min(100.0, max(0.0, ($account->equity / 25.0) * 100)), 1) }}%</span>
                        </div>
                        <div class="w-full bg-cyber-900 rounded-full h-2.5 border border-cyan-500/30 overflow-hidden">
                            <div id="stat-stage-progress-bar" class="bg-gradient-to-r from-cyan-500 via-blue-500 to-emerald-400 h-2.5 rounded-full transition-all duration-500" style="width: {{ min(100.0, max(0.0, ($account->equity / 25.0) * 100)) }}%"></div>
                        </div>
                        <div class="flex items-center justify-between text-[11px] text-slate-400 font-mono">
                            <span>Seed: <strong class="text-slate-300 font-mono">${{ number_format($account->initial_balance, 2) }}</strong></span>
                            <span>Final: <strong class="text-emerald-400 font-mono">$500</strong> (<span id="stat-progress-pct">{{ number_format($account->target_progress, 1) }}%</span>)</span>
                        </div>
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
                            <span>Added/Trade: <strong id="stat-amount-per-trade" class="text-cyan-300 font-mono">~$0.55</strong></span>
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
                                <th class="px-4 py-3">Amount Added (Margin / Size)</th>
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
                                    <th class="px-3 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody id="scanner-tbody" class="divide-y divide-cyber-border/40 font-mono">
                                <tr>
                                    <td colspan="9" class="px-4 py-8 text-center text-slate-400">
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
                                <th class="px-4 py-3">Amount Added</th>
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
                                <td colspan="8" class="px-5 py-6 text-center text-slate-400">
                                    No closed trades yet in this session.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <!-- Strategy Explanation Modal -->
            <div id="strategy-info-modal" class="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm hidden flex items-center justify-center p-4">
                <div class="bg-cyber-900 border border-cyber-border rounded-xl max-w-2xl w-full max-h-[90vh] flex flex-col shadow-2xl overflow-hidden">
                    <div class="px-5 py-4 border-b border-cyber-border bg-cyber-800/90 flex items-center justify-between">
                        <div class="flex items-center space-x-2">
                            <span class="text-cyan-400 text-lg">💡</span>
                            <h3 class="text-sm font-bold font-mono text-white uppercase tracking-wider">Trading Strategy & Capital Utilization</h3>
                        </div>
                        <button onclick="toggleAllocationModal()" class="text-slate-400 hover:text-white text-lg font-mono px-2">✕</button>
                    </div>
                    <div class="p-6 space-y-4 overflow-y-auto text-xs text-slate-300 font-mono leading-relaxed">
                        <div class="p-3.5 rounded-lg bg-cyber-800/80 border border-cyan-500/30">
                            <h4 class="text-cyan-300 font-bold text-sm mb-1.5">Why is only a small portion of the balance used per trade?</h4>
                            <p class="text-slate-300 leading-normal">
                                Binance USDS-M Futures enforces a strict <strong class="text-white">minimum order notional of $5.00</strong> per trade (<code class="text-cyan-300 font-mono">minNotional</code>). At 10x leverage, opening a $5.50 trade requires only <strong class="text-cyan-300">~$0.55 USDT margin</strong> (approximately 11% of a $5.00 account).
                            </p>
                        </div>

                        <div class="space-y-2">
                            <h4 class="text-white font-bold uppercase tracking-wider text-xs">Where does the remaining 85%–90% balance go?</h4>
                            <ul class="space-y-2 list-disc pl-4 text-slate-300">
                                <li>
                                    <strong class="text-emerald-400">Liquidation Safety Shield:</strong> Crypto futures contracts experience sudden 5%–10% wick fluctuations. Leaving ~85% in unallocated free margin ensures the liquidation price remains extremely far from the entry, preventing flash-crash liquidations.
                                </li>
                                <li>
                                    <strong class="text-emerald-400">Multi-Position Capacity:</strong> The engine allows up to <strong class="text-white">3 concurrent open positions</strong> across uncorrelated pairs (e.g. SOL, BTC, NEAR). Unallocated capital guarantees that when high-conviction breakout setups trigger simultaneously, sufficient margin is available.
                                </li>
                                <li>
                                    <strong class="text-emerald-400">Mandatory 15% Free Buffer:</strong> The system enforces a hard risk check where total used margin cannot exceed 85% of available funds (<code class="text-slate-200 font-mono">$availMargin * 0.85</code>). If margin approaches 85%, new entries are strictly blocked.
                                </li>
                            </ul>
                        </div>

                        <div class="space-y-2 pt-2 border-t border-cyber-border">
                            <h4 class="text-white font-bold uppercase tracking-wider text-xs">Dynamic 3-Stage Compounding Model:</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-2.5 pt-1">
                                <div class="p-2.5 rounded bg-cyber-800 border border-cyber-border">
                                    <span class="text-cyan-400 font-bold block text-[11px]">STAGE 1: SEED</span>
                                    <span class="text-slate-400 text-[10px] block">$5 – $25 Balance</span>
                                    <p class="text-[10px] text-slate-300 mt-1">Fixed $5.50 notional (~$0.55 margin, 10x). Strict preservation to build initial capital cushion.</p>
                                </div>
                                <div class="p-2.5 rounded bg-cyber-800 border border-cyber-border">
                                    <span class="text-emerald-400 font-bold block text-[11px]">STAGE 2: ACCELERATION</span>
                                    <span class="text-slate-400 text-[10px] block">$25 – $100 Balance</span>
                                    <p class="text-[10px] text-slate-300 mt-1">Dynamic 3.0% risk per trade. Trade size and margin scale proportionally with equity growth.</p>
                                </div>
                                <div class="p-2.5 rounded bg-cyber-800 border border-cyber-border">
                                    <span class="text-amber-400 font-bold block text-[11px]">STAGE 3: COMPOUNDING</span>
                                    <span class="text-slate-400 text-[10px] block">$100 – $500 Balance</span>
                                    <p class="text-[10px] text-slate-300 mt-1">2.5% risk per trade, trailing stop-losses, breakeven locks (+1.2%), and multi-tier profit taking.</p>
                                </div>
                            </div>
                        </div>

                        <div class="p-3 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-[11px]">
                            <span class="text-emerald-300 font-bold">24/7 Autonomous Operation:</span>
                            The background daemon service executes continuously on the server independently of your browser session, monitoring breakout signals, moving stop-losses to breakeven, and booking profits 24/7.
                        </div>
                    </div>
                    <div class="px-5 py-3 border-t border-cyber-border bg-cyber-800/60 text-right">
                        <button onclick="toggleAllocationModal()" class="px-4 py-1.5 text-xs font-mono bg-cyan-600 hover:bg-cyan-500 text-white rounded font-medium transition">
                            Close
                        </button>
                    </div>
                </div>
            </div>

            <!-- Daemon Logs Viewer Modal -->
            <div id="daemon-logs-modal" class="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm hidden flex items-center justify-center p-4">
                <div class="bg-cyber-900 border border-cyber-border rounded-xl max-w-4xl w-full max-h-[85vh] flex flex-col shadow-2xl overflow-hidden">
                    <div class="px-5 py-3.5 border-b border-cyber-border bg-cyber-800/90 flex items-center justify-between">
                        <div class="flex items-center space-x-2.5">
                            <span class="w-3 h-3 rounded-full bg-emerald-400 animate-pulse"></span>
                            <h3 class="text-sm font-bold font-mono text-white uppercase tracking-wider">Trading Daemon Live Execution Stream</h3>
                            <span class="text-[10px] font-mono px-2 py-0.5 rounded bg-cyan-500/10 text-cyan-300 border border-cyan-500/30">storage/logs/trading_daemon.log</span>
                        </div>
                        <div class="flex items-center space-x-3">
                            <button onclick="fetchDaemonLogs()" class="px-2.5 py-1 text-xs font-mono bg-cyber-700 hover:bg-cyber-600 border border-cyber-border rounded text-slate-200 transition flex items-center space-x-1">
                                <svg class="w-3.5 h-3.5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                <span>Refresh</span>
                            </button>
                            <button onclick="closeDaemonLogsModal()" class="text-slate-400 hover:text-white text-lg font-mono px-2">✕</button>
                        </div>
                    </div>
                    <div class="p-4 flex-1 overflow-y-auto bg-black/75 font-mono text-[11px] text-slate-300 leading-relaxed space-y-1 h-96" id="daemon-logs-content">
                        <div class="text-slate-500 text-center py-8">Loading daemon logs...</div>
                    </div>
                    <div class="px-5 py-2.5 border-t border-cyber-border bg-cyber-800/60 flex items-center justify-between text-[11px] font-mono text-slate-400">
                        <span id="daemon-logs-updated-at">Last updated: Just now</span>
                        <span class="text-emerald-400 font-medium">● 24/7 Autonomous Background Service</span>
                    </div>
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
        if (urlMode && ['paper', 'live'].includes(urlMode)) {
            currentMode = urlMode;
        } else if (savedMode && ['paper', 'live'].includes(savedMode)) {
            currentMode = savedMode;
        } else {
            currentMode = 'paper';
        }

        // Keep LocalStorage, Cookie, and URL strictly synchronized
        localStorage.setItem('afte_trading_mode', currentMode);
        document.cookie = `afte_trading_mode=${currentMode};path=/;max-age=2592000`;
        window.history.replaceState({}, '', `/?mode=${currentMode}`);

        function updateModeUI(mode) {
            ['paper', 'live'].forEach(m => {
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

            liveSync();
        }

        let liveSyncAbort = null;
        let isSyncing = false;
        let isManualScanning = false;
        let activePositionsData = [];

        async function liveSync() {
            if (isSyncing) return;
            isSyncing = true;

            try {
                if (liveSyncAbort) liveSyncAbort.abort();
                liveSyncAbort = new AbortController();

                const res = await fetch(`/api/live-sync?mode=${currentMode}`, { signal: liveSyncAbort.signal });
                const data = await res.json();

                // Discard stale response if user switched mode while request was in-flight
                const respMode = data.mode || (data.stats && data.stats.mode);
                if (respMode && respMode !== currentMode) return;

                // 1. Update Core Stats & Compounding Banner
                updateStatsUI(data.stats);

                // 2. Update 24/7 Daemon Status Bar
                updateDaemonUI(data.daemon, data.stats);

                // 3. Update Capital & Margin Utilization Matrix
                updateMarginMatrixUI(data.stats, data.positions);

                // 4. Update Active Open Positions
                updatePositionsUI(data.positions);

                // 5. Update Breakout Scanner Radar (if not running a manual fresh scan)
                if (!isManualScanning && data.opportunities) {
                    renderScannerTable(data.opportunities);
                }

                // 6. Update Closed Trades Audit Log
                if (data.history) {
                    renderHistoryTable(data.history);
                }

            } catch (err) {
                if (err.name === 'AbortError') return;
                console.error("Live Sync error:", err);
            } finally {
                isSyncing = false;
            }
        }

        function updateStatsUI(stats) {
            if (!stats) return;

            const statEquity = document.getElementById('stat-equity');
            const statBal = document.getElementById('stat-balance');
            const unPnl = document.getElementById('stat-unrealized-pnl');
            if (statEquity) {
                statEquity.textContent = `$${stats.equity.toFixed(2)}`;
                statEquity.style.opacity = '1';
            }
            if (statBal) {
                statBal.textContent = `$${stats.balance.toFixed(2)}`;
                statBal.style.opacity = '1';
            }
            const availMarginEl = document.getElementById('stat-available-margin');
            if (availMarginEl && stats.available_margin !== undefined) {
                availMarginEl.textContent = `$${Number(stats.available_margin).toFixed(2)}`;
            }
            const amountPerTradeEl = document.getElementById('stat-amount-per-trade');
            if (amountPerTradeEl && stats.amount_per_trade !== undefined) {
                amountPerTradeEl.textContent = `~$${Number(stats.amount_per_trade).toFixed(2)}`;
            }
            
            if (unPnl) {
                const sign = stats.unrealized_pnl >= 0 ? '+' : '';
                unPnl.textContent = `${sign}$${stats.unrealized_pnl.toFixed(2)}`;
                unPnl.className = `text-xs font-mono font-medium ${stats.unrealized_pnl >= 0 ? 'text-emerald-400' : 'text-rose-400'}`;
                unPnl.style.opacity = '1';
            }

            // Stage 1 Milestone Progress Bar ($25.00 Target)
            const stageProgressPctEl = document.getElementById('stat-stage-progress-pct');
            const stageProgressBarEl = document.getElementById('stat-stage-progress-bar');
            if (stageProgressPctEl && stats.stage_progress_pct !== undefined) {
                stageProgressPctEl.textContent = `${Number(stats.stage_progress_pct).toFixed(1)}%`;
            }
            if (stageProgressBarEl && stats.stage_progress_pct !== undefined) {
                stageProgressBarEl.style.width = `${Math.min(100, Math.max(1, stats.stage_progress_pct))}%`;
            }

            // Progress Bar & Multiplier ($500.00 Final Target)
            const progressPctEl = document.getElementById('stat-progress-pct');
            const progressBarEl = document.getElementById('stat-progress-bar');
            if (progressPctEl) progressPctEl.textContent = `${stats.progress_pct.toFixed(1)}%`;
            if (progressBarEl) progressBarEl.style.width = `${Math.min(100, Math.max(1, stats.progress_pct))}%`;

            const mult = (stats.equity / Math.max(0.1, stats.initial_balance)).toFixed(2);
            const multEl = document.getElementById('stat-multiplier');
            if (multEl) multEl.textContent = `${mult}x`;

            // Win rate & counts
            const winRateEl = document.getElementById('stat-win-rate');
            const tradeCountsEl = document.getElementById('stat-trade-counts');
            if (winRateEl) winRateEl.textContent = `${stats.win_rate.toFixed(1)}%`;
            if (tradeCountsEl) tradeCountsEl.textContent = `${stats.winning_trades}W / ${stats.losing_trades}L`;

            // Streak & Stage
            const streakEl = document.getElementById('stat-streak');
            if (streakEl) streakEl.textContent = `${stats.consecutive_losses > 0 ? -stats.consecutive_losses : stats.winning_trades}`;
            const stageBadgeEl = document.getElementById('stage-badge');
            if (stageBadgeEl) stageBadgeEl.textContent = stats.stage;

            // Sync indicator
            const syncIndicator = document.getElementById('terminal-sync-indicator');
            if (syncIndicator) {
                if (stats.live_synced) {
                    syncIndicator.innerHTML = '● BINANCE LIVE';
                    syncIndicator.className = 'text-emerald-400 font-mono text-[10px] font-bold animate-pulse';
                } else if (stats.mode === 'live') {
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
            if (killBtn && killText) {
                if (stats.kill_switch) {
                    killBtn.className = 'px-3 py-1.5 text-xs font-mono font-bold bg-rose-600 text-white border border-rose-400 rounded-md transition animate-pulse flex items-center space-x-1.5';
                    killText.textContent = 'HALTED (RESUME)';
                } else {
                    killBtn.className = 'px-3 py-1.5 text-xs font-mono font-bold bg-rose-500/20 hover:bg-rose-500/30 text-rose-400 border border-rose-500/40 rounded-md transition flex items-center space-x-1.5';
                    killText.textContent = 'KILL SWITCH';
                }
            }

            // Auto Trading button / badge update
            window.isAutoTradingRunning = Boolean(stats.is_running);
            const autoBtn = document.getElementById('btn-auto-trading');
            const autoDot = document.getElementById('auto-trading-dot');
            const autoText = document.getElementById('auto-trading-text');
            const enginePill = document.getElementById('engine-status-pill');

            if (enginePill) {
                if (stats.is_running) {
                    enginePill.textContent = 'RUNNING';
                    enginePill.className = 'text-[10px] px-2 py-0.5 rounded font-mono font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30';
                } else {
                    enginePill.textContent = 'STOPPED';
                    enginePill.className = 'text-[10px] px-2 py-0.5 rounded font-mono font-bold bg-slate-700/60 text-slate-400 border border-slate-600';
                }
            }

            if (autoBtn) {
                if (stats.is_running) {
                    autoBtn.className = 'px-3 py-1.5 text-xs font-mono font-bold bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 border border-amber-500/40 rounded-md transition flex items-center space-x-1.5 shadow-sm shadow-amber-500/10';
                    if (autoDot) autoDot.className = 'w-2.5 h-2.5 rounded-full bg-amber-400 animate-pulse';
                    if (autoText) autoText.textContent = 'STOP AUTO TRADING';
                } else {
                    autoBtn.className = 'px-3 py-1.5 text-xs font-mono font-bold bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-300 border border-emerald-500/40 rounded-md transition flex items-center space-x-1.5 shadow-sm shadow-emerald-500/10';
                    if (autoDot) autoDot.className = 'w-2.5 h-2.5 rounded-full bg-emerald-400';
                    if (autoText) autoText.textContent = 'START AUTO TRADING';
                }
            } else if (autoText) {
                if (stats.is_running) {
                    autoText.textContent = 'AUTO: ACTIVE';
                    if (autoDot) autoDot.className = 'w-2 h-2 rounded-full bg-emerald-400 animate-ping';
                } else {
                    autoText.textContent = 'AUTO: STOPPED';
                    if (autoDot) autoDot.className = 'w-2 h-2 rounded-full bg-slate-500';
                }
            }

            // Guard status
            const guard = document.getElementById('guard-status');
            if (guard) {
                if (stats.kill_switch) {
                    guard.innerHTML = '<span class="w-2 h-2 rounded-full bg-rose-500 mr-2 animate-pulse"></span>EMERGENCY HALTED';
                    guard.className = 'inline-flex items-center text-xs font-mono text-rose-400 font-bold';
                } else if (!stats.is_running) {
                    guard.innerHTML = '<span class="w-2 h-2 rounded-full bg-slate-400 mr-2"></span>AUTO-TRADING STOPPED';
                    guard.className = 'inline-flex items-center text-xs font-mono text-slate-400 font-medium';
                } else if (!stats.can_trade) {
                    guard.innerHTML = '<span class="w-2 h-2 rounded-full bg-amber-400 mr-2 animate-pulse"></span>COOLDOWN PAUSED';
                    guard.className = 'inline-flex items-center text-xs font-mono text-amber-400 font-bold';
                } else {
                    guard.innerHTML = `<span class="w-2 h-2 rounded-full bg-emerald-400 mr-2 animate-pulse"></span>ACTIVE (${stats.consecutive_losses}/2 Losses)`;
                    guard.className = 'inline-flex items-center text-xs font-mono text-emerald-400 font-medium';
                }
            }
        }

        function updateDaemonUI(daemon, stats) {
            const badge = document.getElementById('daemon-status-badge');
            const dot = document.getElementById('daemon-dot');
            const ring = document.getElementById('daemon-pulse-ring');
            const desc = document.getElementById('daemon-health-desc');
            const hb = document.getElementById('daemon-heartbeat-text');
            const cycles = document.getElementById('daemon-cycles-text');

            if (!daemon) return;

            if (daemon.is_active) {
                if (badge) {
                    badge.textContent = 'ACTIVE (24/7 BACKGROUND)';
                    badge.className = 'px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 shadow-sm shadow-emerald-500/10';
                }
                if (dot) dot.className = 'w-3.5 h-3.5 rounded-full bg-emerald-400 shadow-[0_0_10px_#10b981]';
                if (ring) ring.className = 'absolute w-8 h-8 rounded-full bg-emerald-400/25 animate-ping';
                if (desc) desc.textContent = `PID #${daemon.pid || '-'} (${daemon.uptime_human || 'Live'})`;
            } else if (stats && stats.is_running) {
                if (badge) {
                    badge.textContent = 'SYNCING / REVIVING';
                    badge.className = 'px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-amber-500/20 text-amber-300 border border-amber-500/40';
                }
                if (dot) dot.className = 'w-3.5 h-3.5 rounded-full bg-amber-400';
                if (ring) ring.className = 'absolute w-8 h-8 rounded-full bg-amber-400/20 animate-pulse';
                if (desc) desc.textContent = 'Auto-Scheduler Watchdog Active';
            } else {
                if (badge) {
                    badge.textContent = 'STANDBY (PAUSED)';
                    badge.className = 'px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-slate-700/60 text-slate-400 border border-slate-600';
                }
                if (dot) dot.className = 'w-3.5 h-3.5 rounded-full bg-slate-500';
                if (ring) ring.className = 'hidden';
                if (desc) desc.textContent = 'Waiting for Auto Trading activation';
            }

            if (hb) {
                const hbAge = (daemon.heartbeat_ago_sec !== null && daemon.heartbeat_ago_sec !== undefined)
                    ? daemon.heartbeat_ago_sec
                    : (daemon.heartbeat_age_seconds !== undefined && daemon.last_heartbeat ? daemon.heartbeat_age_seconds : null);

                if (hbAge !== null && hbAge < 9999) {
                    hb.textContent = `${hbAge}s ago`;
                    hb.className = hbAge <= 30 ? 'text-cyan-300 font-bold' : 'text-amber-400 font-bold';
                } else {
                    hb.textContent = 'Never (Worker Inactive)';
                    hb.className = 'text-slate-400';
                }
            }

            if (cycles) {
                cycles.textContent = daemon.cycles_count !== undefined ? daemon.cycles_count : '0';
            }
        }

        function updateMarginMatrixUI(stats, positions) {
            if (!stats) return;

            const equityEl = document.getElementById('alloc-equity');
            const balanceEl = document.getElementById('alloc-balance');
            const pnlEl = document.getElementById('alloc-unrealized-pnl');
            const stageTextEl = document.getElementById('alloc-stage-text');

            if (equityEl) equityEl.textContent = `$${stats.equity.toFixed(2)}`;
            if (balanceEl) balanceEl.textContent = `$${stats.balance.toFixed(2)}`;
            if (stageTextEl) stageTextEl.textContent = stats.stage;

            if (pnlEl) {
                const sign = stats.unrealized_pnl >= 0 ? '+' : '';
                pnlEl.textContent = `${sign}$${stats.unrealized_pnl.toFixed(2)}`;
                pnlEl.className = stats.unrealized_pnl >= 0 ? 'text-emerald-400 font-bold' : 'text-rose-400 font-bold';
            }

            // Margin Used & Free Buffer
            const usedMarginEl = document.getElementById('stat-used-margin');
            const utilBadgeEl = document.getElementById('stat-margin-util-badge');
            const freeBufferEl = document.getElementById('stat-free-buffer');
            const posCountEl = document.getElementById('alloc-pos-count');
            const bufferPctEl = document.getElementById('alloc-buffer-pct');
            const allocAmountEl = document.getElementById('stat-alloc-amount');

            const usedMargin = stats.used_margin || 0;
            const utilPct = stats.margin_utilization_pct || 0;
            const freeBuffer = stats.available_margin || 0;
            const posCount = Array.isArray(positions) ? positions.length : 0;

            if (usedMarginEl) usedMarginEl.textContent = `$${Number(usedMargin).toFixed(2)}`;
            if (utilBadgeEl) utilBadgeEl.textContent = `${utilPct.toFixed(1)}% Used`;
            if (freeBufferEl) freeBufferEl.textContent = `$${Number(freeBuffer).toFixed(2)}`;
            if (posCountEl) posCountEl.textContent = posCount;
            if (bufferPctEl) bufferPctEl.textContent = `${Math.max(0, 100 - utilPct).toFixed(1)}%`;
            if (allocAmountEl) allocAmountEl.innerHTML = `~$${Number(stats.amount_per_trade || 0.55).toFixed(2)} <span class="text-xs text-slate-400 font-normal">USDT</span>`;

            // Progress Bars
            const barPctText = document.getElementById('alloc-bar-pct-text');
            const barUsed = document.getElementById('alloc-bar-used');
            const barFree = document.getElementById('alloc-bar-free');

            if (barPctText) barPctText.textContent = `${utilPct.toFixed(1)}%`;
            if (barUsed) barUsed.style.width = `${Math.min(100, Math.max(0, utilPct))}%`;
            if (barFree) barFree.style.width = `${Math.max(0, 100 - utilPct)}%`;
        }

        function updatePositionsUI(data) {
            activePositionsData = Array.isArray(data) ? data : [];
            const badge = document.getElementById('positions-count-badge');
            if (badge) badge.textContent = activePositionsData.length;

            const tbody = document.getElementById('positions-tbody');
            if (!tbody) return;

            if (activePositionsData.length === 0) {
                tbody.innerHTML = `<tr><td colspan="8" class="px-5 py-8 text-center text-slate-400">No active positions currently open in ${currentMode.toUpperCase()} mode.</td></tr>`;
                return;
            }

            tbody.innerHTML = activePositionsData.map(pos => {
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
                            <span class="text-slate-200 block font-mono">$${pos.entry_price}</span>
                            <span id="pos-mark-${pos.id}" class="text-[11px] text-cyan-400 font-mono">Mark: $${pos.mark_price}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center space-x-1">
                                <span class="text-white font-bold text-xs font-mono">$${Number(pos.amount_added || pos.margin_used).toFixed(2)}</span>
                                <span class="text-[10px] text-cyan-400">USDT</span>
                            </div>
                            <span class="text-[10px] text-slate-400 block font-mono">Size: <strong class="text-slate-300 font-mono">$${Number(pos.position_size_usd || (pos.quantity * pos.mark_price)).toFixed(2)}</strong> (${pos.leverage}x)</span>
                            ${pos.initial_amount_added && Math.abs(pos.initial_amount_added - (pos.amount_added || pos.margin_used)) > 0.05 
                                ? `<span class="text-[9px] text-amber-400/90 block font-mono">Init: $${Number(pos.initial_amount_added).toFixed(2)}</span>` 
                                : ''}
                        </td>
                        <td class="px-4 py-3 font-mono">
                            <span class="text-rose-300 font-bold">$${pos.current_sl}</span>
                            ${pos.has_exchange_sl 
                                ? `<span class="text-[9px] px-1.5 py-0.5 mt-0.5 rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 block font-mono font-medium" title="Active STOP_MARKET conditional order on Binance exchange">🛡️ SL on Binance: $${pos.exchange_sl_price || pos.current_sl}</span>` 
                                : (pos.be_locked ? '<span class="text-[10px] text-amber-400 block">Risk Free</span>' : '<span class="text-[10px] text-slate-400 block">Trailing Soft SL</span>')}
                        </td>
                        <td class="px-4 py-3 font-mono">
                            <div class="text-[11px]">
                                <span class="${pos.tp1_hit ? 'line-through text-slate-500' : 'text-emerald-300'}">TP1: $${pos.tp1_price}</span>
                                <span class="${pos.tp2_hit ? 'line-through text-slate-500' : 'text-emerald-400'} block">TP2: $${pos.tp2_price}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 font-mono">
                            <span id="pos-pnl-${pos.id}" class="${pnlColor} font-bold text-sm block">${sign}$${pos.unrealized_pnl.toFixed(2)}</span>
                            <span id="pos-roe-${pos.id}" class="${pnlColor} text-[11px]">ROE: ${sign}${pos.roe.toFixed(2)}%</span>
                        </td>
                        <td class="px-5 py-3 text-right space-x-1 font-mono">
                            ${!pos.be_locked ? `<button onclick="lockBreakeven(${pos.id})" class="px-2 py-1 text-[10px] bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 border border-amber-500/30 rounded transition">Lock BE</button>` : ''}
                            <button onclick="closePosition(${pos.id})" class="px-2 py-1 text-[10px] bg-rose-500/20 hover:bg-rose-500/30 text-rose-300 border border-rose-500/30 rounded transition">Close</button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function renderScannerTable(opportunities, totalScanned = null, cachedAt = null) {
            const tbody = document.getElementById('scanner-tbody');
            if (!tbody) return;

            if (totalScanned !== null) {
                const cacheNotice = cachedAt ? ' (Instant Cached)' : '';
                const updateEl = document.getElementById('scanner-last-update');
                if (updateEl) updateEl.textContent = `Scanned ${totalScanned} pairs${cacheNotice} at ${new Date().toLocaleTimeString()}`;
            }

            if (!opportunities || opportunities.length === 0) {
                tbody.innerHTML = `<tr><td colspan="9" class="px-4 py-6 text-center text-slate-400">No high-conviction breakout setup detected right now. Markets are in range; capital protected.</td></tr>`;
                return;
            }

            tbody.innerHTML = opportunities.map(op => {
                const isLong = op.direction === 'LONG';
                const dirBadge = isLong ? 'text-emerald-400 bg-emerald-500/10 border-emerald-500/30' : 'text-rose-400 bg-rose-500/10 border-rose-500/30';
                const aiBadge = op.ai_approved ? 'text-emerald-400 font-bold' : 'text-rose-400 font-medium';

                return `
                    <tr class="hover:bg-cyber-800/40 transition">
                        <td class="px-4 py-2.5 font-bold text-white">${op.symbol}</td>
                        <td class="px-3 py-2.5"><span class="px-1.5 py-0.5 rounded text-[10px] border ${dirBadge}">${op.direction}</span></td>
                        <td class="px-3 py-2.5 text-slate-300 font-mono">$${op.price}</td>
                        <td class="px-3 py-2.5 font-bold text-cyan-300 font-mono">${op.score}/100</td>
                        <td class="px-3 py-2.5 text-slate-300 font-mono">${op.indicators.volume_ratio}x</td>
                        <td class="px-3 py-2.5 text-slate-300 font-mono">${op.indicators.rsi}</td>
                        <td class="px-3 py-2.5 ${aiBadge}">${op.ai_approved ? '✅ APPROVED' : '❌ REJECTED'}</td>
                        <td class="px-4 py-2.5 text-[11px] text-slate-400 truncate max-w-xs" title="${op.ai_reason}">${op.ai_reason}</td>
                        <td class="px-3 py-2.5 text-right whitespace-nowrap">
                            <button onclick="executeRadarTrade('${op.symbol}', '${op.direction}', this)" class="px-2.5 py-1 text-[11px] font-mono font-bold rounded bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-300 border border-emerald-500/40 transition inline-flex items-center space-x-1 shadow-sm shadow-emerald-500/10">
                                <span>⚡</span><span>Trade Now</span>
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function renderHistoryTable(data) {
            const tbody = document.getElementById('history-tbody');
            if (!tbody) return;

            if (!data || data.length === 0) {
                tbody.innerHTML = `<tr><td colspan="8" class="px-5 py-6 text-center text-slate-400">No closed trades yet in ${currentMode.toUpperCase()} mode.</td></tr>`;
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
                        <td class="px-4 py-2.5 text-slate-300">
                            <div class="font-bold text-white text-xs font-mono">$${Number(t.amount_added || t.margin_used || 0).toFixed(2)}</div>
                            <span class="text-[10px] text-slate-400 font-mono">Size: $${Number(t.position_size_usd || 0).toFixed(2)}</span>
                        </td>
                        <td class="px-4 py-2.5 text-slate-300 font-mono">$${t.entry_price}</td>
                        <td class="px-4 py-2.5 text-slate-300 font-mono">$${t.exit_price || '-'}</td>
                        <td class="px-4 py-2.5 ${pnlColor} font-mono">${sign}$${t.realized_pnl.toFixed(2)}</td>
                        <td class="px-4 py-2.5 ${pnlColor} font-mono">${sign}${t.pnl_percent.toFixed(2)}%</td>
                        <td class="px-4 py-2.5">
                            <span class="px-1.5 py-0.5 rounded text-[10px] bg-cyber-700 text-slate-300 border border-cyber-border font-mono">${t.exit_reason || 'CLOSED'}</span>
                        </td>
                        <td class="px-5 py-2.5 text-right text-slate-400 text-[11px] font-mono">${dateStr}</td>
                    </tr>
                `;
            }).join('');
        }

        async function triggerScan(fresh = false) {
            const btn = document.getElementById('btn-scan');
            if (btn) {
                btn.innerHTML = `<span>Scanning...</span>`;
                btn.disabled = true;
            }
            isManualScanning = true;

            try {
                const url = fresh ? `/api/scan?limit=10&fresh=1` : `/api/scan?limit=10`;
                const res = await fetch(url);
                const data = await res.json();
                renderScannerTable(data.opportunities, data.total_scanned, data.cached_at);
            } catch (err) {
                console.error("Scan error:", err);
            } finally {
                if (btn) {
                    btn.innerHTML = `<svg class="w-3.5 h-3.5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg><span>Scan Market</span>`;
                    btn.disabled = false;
                }
                isManualScanning = false;
            }
        }

        async function executeRadarTrade(symbol, direction, btnEl = null) {
            const originalHtml = btnEl ? btnEl.innerHTML : null;
            if (btnEl) {
                btnEl.innerHTML = `<span class="inline-block animate-spin mr-1">⌛</span> Placing...`;
                btnEl.disabled = true;
            }

            try {
                const res = await fetch('/api/execute-radar-trade', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        symbol: symbol,
                        direction: direction,
                        mode: currentMode
                    })
                });

                const data = await res.json();
                if (data.success) {
                    alert(`✅ ${data.message}`);
                    liveSync();
                } else {
                    alert(`❌ Trade Failed: ${data.message}`);
                }
            } catch (err) {
                console.error("Execute radar trade error:", err);
                alert(`Execution error: ${err.message}`);
            } finally {
                if (btnEl && originalHtml) {
                    btnEl.innerHTML = originalHtml;
                    btnEl.disabled = false;
                }
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
                    liveSync();
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
                    liveSync();
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
                liveSync();
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
                    liveSync();
                } else {
                    alert(data.message || 'Error updating auto-trading state.');
                }
            } catch (err) {
                console.error("Auto-trading toggle error:", err);
            }
        }

        // Modals & Daemon Controls
        function toggleAllocationModal() {
            const modal = document.getElementById('strategy-info-modal');
            if (modal) {
                modal.classList.toggle('hidden');
            }
        }

        function openDaemonLogsModal() {
            const modal = document.getElementById('daemon-logs-modal');
            if (modal) {
                modal.classList.remove('hidden');
                fetchDaemonLogs();
            }
        }

        function closeDaemonLogsModal() {
            const modal = document.getElementById('daemon-logs-modal');
            if (modal) {
                modal.classList.add('hidden');
            }
        }

        async function fetchDaemonLogs() {
            const content = document.getElementById('daemon-logs-content');
            const updatedAt = document.getElementById('daemon-logs-updated-at');
            if (content) content.innerHTML = '<div class="text-cyan-400 text-center py-8">Fetching live daemon log stream...</div>';

            try {
                const res = await fetch('/api/trading-daemon/logs?lines=100');
                const data = await res.json();

                if (content) {
                    if (data.logs && data.logs.length > 0) {
                        content.innerHTML = data.logs.map(line => {
                            let colorClass = 'text-slate-300';
                            if (line.includes('ERROR') || line.includes('FAILED')) colorClass = 'text-rose-400 font-bold';
                            else if (line.includes('WARNING')) colorClass = 'text-amber-400';
                            else if (line.includes('Opened') || line.includes('SUCCESS') || line.includes('Take-Profit')) colorClass = 'text-emerald-400 font-semibold';
                            else if (line.includes('Breakeven') || line.includes('Trailing')) colorClass = 'text-cyan-300';

                            return `<div class="${colorClass}">${escapeHtml(line)}</div>`;
                        }).join('');
                        content.scrollTop = content.scrollHeight;
                    } else {
                        content.innerHTML = '<div class="text-slate-500 text-center py-8">No daemon logs recorded yet. Start auto trading to initialize worker stream.</div>';
                    }
                }
                if (updatedAt) updatedAt.textContent = `Last updated: ${new Date().toLocaleTimeString()}`;
            } catch (err) {
                if (content) content.innerHTML = `<div class="text-rose-400 text-center py-8">Failed to fetch logs: ${err.message}</div>`;
            }
        }

        async function restartDaemon() {
            const btn = document.getElementById('btn-restart-daemon');
            if (btn) btn.disabled = true;

            try {
                const res = await fetch('/api/trading-daemon/start', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ mode: currentMode })
                });
                const data = await res.json();
                liveSync();
            } catch (err) {
                console.error("Restart daemon error:", err);
            } finally {
                if (btn) btn.disabled = false;
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
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

        let lastAutoTickTime = 0;
        async function runAutoTickFallback() {
            if (!window.isAutoTradingRunning) return;
            const now = Date.now();
            if (now - lastAutoTickTime < 30000) return;
            lastAutoTickTime = now;

            try {
                const res = await fetch('/api/auto-tick', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ mode: currentMode })
                });
                const data = await res.json();
                if (data && data.opened_trade) {
                    console.log("[Auto-Tick] Trade opened:", data.opened_trade);
                    liveSync();
                }
            } catch (e) {
                // Ignore background tick network issues
            }
        }

        // Initialize and start live sync & WebSocket streaming
        updateModeUI(currentMode);
        initBinanceWebSocket();
        liveSync();

        // Real-time dynamic sync every 3 seconds (updates balance, margin, positions, signals, history & daemon without page refresh)
        setInterval(() => {
            liveSync();
            runAutoTickFallback();
        }, 3000);
    </script>
</body>
</html>
