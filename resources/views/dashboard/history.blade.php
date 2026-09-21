<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trade Audit Ledger • AFTE Binance Futures</title>
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
                            border: '#233358',
                            accent: '#06b6d4',
                            success: '#10b981',
                            danger: '#f43f5e',
                            warning: '#f59e0b'
                        }
                    },
                    fontFamily: {
                        mono: ['JetBrains Mono', 'Fira Code', 'monospace'],
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
        .glass-panel {
            background: rgba(13, 19, 34, 0.85);
            backdrop-filter: blur(12px);
            border: 1px solid #233358;
        }
    </style>
</head>
<body class="bg-cyber-900 text-slate-200 min-h-screen">
    <div class="flex flex-col min-h-screen">
        <!-- Top Navigation -->
        <header class="border-b border-cyber-border bg-cyber-800/90 sticky top-0 z-50 backdrop-blur-md px-4 lg:px-8 py-3">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center space-x-4">
                    <a href="{{ route('dashboard') }}" class="flex items-center space-x-3 group">
                        <div class="w-9 h-9 rounded-lg bg-cyan-500/10 border border-cyan-500/30 flex items-center justify-center text-cyan-400 font-bold font-mono group-hover:bg-cyan-500/20 transition">
                            ⚡
                        </div>
                        <div>
                            <div class="flex items-center space-x-2">
                                <span class="font-bold text-lg tracking-wider text-white">AFTE</span>
                                <span class="text-xs px-2 py-0.5 rounded bg-cyan-500/20 text-cyan-400 font-mono font-medium border border-cyan-500/30">LEDGER</span>
                            </div>
                            <p class="text-xs text-slate-400">Complete Execution History & Profit Audit</p>
                        </div>
                    </a>

                    <nav class="hidden md:flex items-center space-x-2 border-l border-cyber-border pl-4">
                        <a href="{{ route('dashboard', ['mode' => $mode]) }}" class="px-3 py-1.5 rounded-md text-xs font-mono text-slate-400 hover:text-white hover:bg-cyber-700 transition">
                            ← Live Terminal
                        </a>
                        <span class="px-3 py-1.5 rounded-md text-xs font-mono text-cyan-400 bg-cyber-700/60 font-bold border border-cyan-500/30">
                            Trade History
                        </span>
                    </nav>
                </div>

                <!-- Mode & User Navigation -->
                <div class="flex items-center space-x-4">
                    <!-- Mode Pills -->
                    <div class="flex items-center bg-cyber-900 border border-cyber-border rounded-lg p-1 space-x-1">
                        @foreach (['paper', 'testnet', 'shadow', 'live'] as $m)
                            <a href="{{ route('history.index', ['mode' => $m]) }}"
                               class="px-2.5 py-1 text-xs font-mono rounded uppercase transition {{ $mode === $m ? 'bg-cyan-500 text-cyber-900 font-bold' : 'text-slate-400 hover:text-white' }}">
                                {{ $m }}
                            </a>
                        @endforeach
                    </div>

                    <!-- CSV Export Button -->
                    <a href="{{ route('history.export', ['mode' => $mode, 'symbol' => $selectedSymbol]) }}"
                       class="px-3 py-1.5 text-xs font-mono bg-cyber-700 hover:bg-cyber-600 border border-cyber-border rounded-md text-slate-200 transition flex items-center space-x-1.5">
                        <svg class="w-3.5 h-3.5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        <span>Export CSV</span>
                    </a>

                    <!-- User / Logout -->
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

        <!-- Main Content -->
        <main class="flex-1 px-4 lg:px-8 py-6 space-y-6 max-w-7xl mx-auto w-full">
            <!-- Top Summary Analytics Cards -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                <!-- Net Realized PnL -->
                <div class="glass-panel rounded-xl p-4 space-y-1">
                    <span class="text-[11px] text-slate-400 font-mono uppercase block">Net Realized PnL</span>
                    <span class="text-xl font-bold font-mono {{ $stats['net_pnl'] >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                        {{ $stats['net_pnl'] >= 0 ? '+' : '-' }}${{ number_format(abs($stats['net_pnl']), 2) }}
                    </span>
                    <span class="text-[10px] text-slate-500 block font-mono">Gross profits - losses</span>
                </div>

                <!-- Win Rate -->
                <div class="glass-panel rounded-xl p-4 space-y-1">
                    <span class="text-[11px] text-slate-400 font-mono uppercase block">Win Rate</span>
                    <span class="text-xl font-bold font-mono text-white">{{ $stats['win_rate'] }}%</span>
                    <span class="text-[10px] text-slate-400 block font-mono">{{ $stats['winning_trades'] }}W / {{ $stats['losing_trades'] }}L</span>
                </div>

                <!-- Total Closed Trades -->
                <div class="glass-panel rounded-xl p-4 space-y-1">
                    <span class="text-[11px] text-slate-400 font-mono uppercase block">Total Trades</span>
                    <span class="text-xl font-bold font-mono text-cyan-300">{{ $stats['total_trades'] }}</span>
                    <span class="text-[10px] text-slate-500 block font-mono">Executed in {{ strtoupper($mode) }}</span>
                </div>

                <!-- Profit Factor -->
                <div class="glass-panel rounded-xl p-4 space-y-1">
                    <span class="text-[11px] text-slate-400 font-mono uppercase block">Profit Factor</span>
                    <span class="text-xl font-bold font-mono text-white">{{ $stats['profit_factor'] }}</span>
                    <span class="text-[10px] text-slate-500 block font-mono">Gross Gain / Loss</span>
                </div>

                <!-- Best Trade -->
                <div class="glass-panel rounded-xl p-4 space-y-1">
                    <span class="text-[11px] text-slate-400 font-mono uppercase block">Best Trade</span>
                    <span class="text-xl font-bold font-mono text-emerald-400">+${{ number_format($stats['best_trade'], 2) }}</span>
                    <span class="text-[10px] text-slate-500 block font-mono">Peak single gain</span>
                </div>

                <!-- Worst Trade -->
                <div class="glass-panel rounded-xl p-4 space-y-1">
                    <span class="text-[11px] text-slate-400 font-mono uppercase block">Worst Trade</span>
                    <span class="text-xl font-bold font-mono text-rose-400">{{ $stats['worst_trade'] < 0 ? '-' : '' }}${{ number_format(abs($stats['worst_trade']), 2) }}</span>
                    <span class="text-[10px] text-slate-500 block font-mono">Max loss containment</span>
                </div>
            </div>

            <!-- Filter Toolbar -->
            <div class="glass-panel rounded-xl p-4">
                <form method="GET" action="{{ route('history.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 text-xs font-mono">
                    <input type="hidden" name="mode" value="{{ $mode }}">

                    <!-- Coin / Symbol Filter -->
                    <div>
                        <label class="text-slate-400 block mb-1">Coin / Symbol</label>
                        <select name="symbol" class="w-full bg-cyber-900 border border-cyber-border rounded px-3 py-2 text-slate-200 focus:outline-none focus:border-cyan-400">
                            <option value="">All Coins</option>
                            @foreach ($availableCoins as $c)
                                <option value="{{ $c }}" {{ $selectedSymbol === $c ? 'selected' : '' }}>{{ $c }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Outcome Filter -->
                    <div>
                        <label class="text-slate-400 block mb-1">Trade Outcome</label>
                        <select name="outcome" class="w-full bg-cyber-900 border border-cyber-border rounded px-3 py-2 text-slate-200 focus:outline-none focus:border-cyan-400">
                            <option value="all" {{ $selectedOutcome === 'all' ? 'selected' : '' }}>All Outcomes</option>
                            <option value="wins" {{ $selectedOutcome === 'wins' ? 'selected' : '' }}>Profitable Trades Only (Green)</option>
                            <option value="losses" {{ $selectedOutcome === 'losses' ? 'selected' : '' }}>Losing Trades Only (Red)</option>
                        </select>
                    </div>

                    <!-- Date Range Filter -->
                    <div>
                        <label class="text-slate-400 block mb-1">Date Range</label>
                        <select name="range" class="w-full bg-cyber-900 border border-cyber-border rounded px-3 py-2 text-slate-200 focus:outline-none focus:border-cyan-400">
                            <option value="all" {{ $selectedRange === 'all' ? 'selected' : '' }}>All Time</option>
                            <option value="today" {{ $selectedRange === 'today' ? 'selected' : '' }}>Today Only</option>
                            <option value="7days" {{ $selectedRange === '7days' ? 'selected' : '' }}>Past 7 Days</option>
                            <option value="30days" {{ $selectedRange === '30days' ? 'selected' : '' }}>Past 30 Days</option>
                        </select>
                    </div>

                    <!-- Filter Buttons -->
                    <div class="lg:col-span-2 flex items-end space-x-2">
                        <button type="submit" class="flex-1 py-2 px-4 rounded bg-cyan-600 hover:bg-cyan-500 text-white font-bold transition">
                            Apply Filters
                        </button>
                        <a href="{{ route('history.index', ['mode' => $mode]) }}" class="py-2 px-3 rounded bg-cyber-700 hover:bg-cyber-600 text-slate-300 transition text-center">
                            Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Complete Ledger Table -->
            <div class="glass-panel rounded-xl overflow-hidden shadow-2xl">
                <div class="px-5 py-4 border-b border-cyber-border flex items-center justify-between">
                    <h2 class="font-bold text-sm tracking-wide text-white font-mono uppercase flex items-center space-x-2">
                        <span class="text-cyan-400 font-bold">📜</span>
                        <span>Execution Ledger ({{ $trades->total() }} Records)</span>
                    </h2>
                    <span class="text-xs text-slate-400 font-mono">Mode: [{{ strtoupper($mode) }}]</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-cyber-800/80 text-slate-400 font-mono uppercase border-b border-cyber-border">
                            <tr>
                                <th class="px-5 py-3">Coin / Symbol</th>
                                <th class="px-4 py-3">Side & Margin</th>
                                <th class="px-4 py-3">Entry $\to$ Exit Price</th>
                                <th class="px-4 py-3">Profit / Loss (USD)</th>
                                <th class="px-4 py-3">ROE %</th>
                                <th class="px-4 py-3">Exit Milestone</th>
                                <th class="px-4 py-3">Duration</th>
                                <th class="px-5 py-3 text-right">Closed Timestamp</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cyber-border/50 font-mono">
                            @forelse ($trades as $trade)
                                @php
                                    $isLong = $trade->isLong();
                                    $isWin = $trade->realized_pnl >= 0;
                                    $sideBadge = $isLong ? 'text-emerald-400 bg-emerald-500/10 border-emerald-500/30' : 'text-rose-400 bg-rose-500/10 border-rose-500/30';
                                    $pnlColor = $isWin ? 'text-emerald-400 font-bold' : 'text-rose-400 font-bold';
                                    $sign = $isWin ? '+' : '';
                                    $duration = ($trade->opened_at && $trade->closed_at)
                                        ? $trade->opened_at->diffForHumans($trade->closed_at, true)
                                        : '-';
                                @endphp
                                <tr class="hover:bg-cyber-800/40 transition">
                                    <!-- Coin Name & ID -->
                                    <td class="px-5 py-3.5">
                                        <div class="flex items-center space-x-2">
                                            <span class="font-bold text-sm text-white">{{ $trade->symbol }}</span>
                                            <span class="text-[10px] px-1.5 py-0.5 rounded border {{ $sideBadge }}">{{ $trade->side }}</span>
                                        </div>
                                        <span class="text-[10px] text-slate-500">Trade #{{ $trade->id }}</span>
                                    </td>

                                    <!-- Margin & Leverage -->
                                    <td class="px-4 py-3.5 text-slate-300">
                                        <div>${{ number_format($trade->margin_used, 2) }}</div>
                                        <span class="text-[10px] text-slate-400">{{ $trade->leverage }}x ISOLATED</span>
                                    </td>

                                    <!-- Entry & Exit Prices -->
                                    <td class="px-4 py-3.5 text-slate-300">
                                        <div class="text-slate-200 font-medium">${{ $trade->entry_price }} $\to$ ${{ $trade->exit_price ?? '-' }}</div>
                                        <span class="text-[10px] text-slate-500">Qty: {{ $trade->quantity }}</span>
                                    </td>

                                    <!-- Profit or Loss Amount -->
                                    <td class="px-4 py-3.5">
                                        <span class="text-sm {{ $pnlColor }} block">
                                            {{ $isWin ? '+' : '-' }}${{ number_format(abs($trade->realized_pnl), 4) }}
                                        </span>
                                        <span class="text-[10px] text-slate-500">Fee: ${{ number_format($trade->fee_paid, 4) }}</span>
                                    </td>

                                    <!-- ROE % -->
                                    <td class="px-4 py-3.5">
                                        <span class="{{ $pnlColor }} text-xs">
                                            {{ $sign }}{{ number_format($trade->pnl_percent, 2) }}%
                                        </span>
                                    </td>

                                    <!-- Exit Milestone -->
                                    <td class="px-4 py-3.5">
                                        @php
                                            $reason = $trade->exit_reason ?? 'CLOSED';
                                            $badgeClass = match($reason) {
                                                'TP1_HIT' => 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40',
                                                'TP2_HIT' => 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40',
                                                'TRAILING_STOP' => 'bg-cyan-500/20 text-cyan-300 border-cyan-500/40',
                                                'BREAKEVEN_STOP', 'BREAKEVEN' => 'bg-amber-500/20 text-amber-300 border-amber-500/40',
                                                'STOP_LOSS' => 'bg-rose-500/20 text-rose-300 border-rose-500/40',
                                                'KILL_SWITCH' => 'bg-red-600/30 text-red-300 border-red-500',
                                                default => 'bg-cyber-700 text-slate-300 border-cyber-border'
                                            };
                                        @endphp
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold border {{ $badgeClass }}">
                                            {{ $reason }}
                                        </span>
                                    </td>

                                    <!-- Duration -->
                                    <td class="px-4 py-3.5 text-slate-400 text-xs">
                                        {{ $duration }}
                                    </td>

                                    <!-- Exact Closed Date & Time -->
                                    <td class="px-5 py-3.5 text-right">
                                        <div class="text-slate-200 font-medium">
                                            {{ $trade->closed_at ? $trade->closed_at->format('Y-m-d H:i:s') : '-' }}
                                        </div>
                                        <div class="text-[10px] text-slate-500">
                                            {{ $trade->closed_at ? $trade->closed_at->diffForHumans() : '' }}
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-5 py-12 text-center text-slate-400">
                                        No closed trades match the selected filters.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                @if ($trades->hasPages())
                    <div class="px-5 py-4 border-t border-cyber-border bg-cyber-800/40 flex items-center justify-between text-xs font-mono">
                        <div class="text-slate-400">
                            Showing {{ $trades->firstItem() }} to {{ $trades->lastItem() }} of {{ $trades->total() }} trades
                        </div>
                        <div>
                            {{ $trades->links() }}
                        </div>
                    </div>
                @endif
            </div>
        </main>

        <!-- Footer -->
        <footer class="border-t border-cyber-border bg-cyber-800/50 py-4 px-8 text-center text-xs text-slate-500 font-mono">
            AFTE Binance Futures • Comprehensive Trade History & Auditing Engine
        </footer>
    </div>
</body>
</html>
