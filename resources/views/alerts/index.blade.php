@extends('layouts.app')

@section('title', 'Telegram Alert History')

@section('content')
<div class="p-4 lg:p-8 space-y-6 max-w-7xl mx-auto">
    <!-- Header banner -->
    <div class="p-6 rounded-2xl glass-panel border border-cyber-border shadow-xl flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <div class="flex items-center space-x-3">
                <span class="px-2.5 py-1 text-xs font-bold uppercase tracking-wider bg-emerald-950/80 text-emerald-300 border border-emerald-800 rounded-lg">
                    Telegram Log
                </span>
                <h1 class="text-2xl lg:text-3xl font-black text-white tracking-tight">
                    Telegram Alert History
                </h1>
            </div>
            <p class="mt-2 text-sm text-slate-400">
                Audit log of all algorithmic trading alerts delivered to Telegram with verified timestamps, targets, and execution parameters.
            </p>
        </div>

        <div class="flex items-center space-x-3">
            <a href="{{ route('signals.dashboard') }}" class="px-4 py-2.5 text-xs font-semibold text-slate-200 bg-cyber-700 hover:bg-cyber-600 rounded-xl border border-cyber-border transition">
                ← Signals &amp; Sentinel
            </a>
            @if (Auth::user()?->isAdmin())
                <a href="{{ route('admin.users.index') }}" class="px-4 py-2.5 text-xs font-semibold text-purple-300 bg-purple-950/80 hover:bg-purple-900 rounded-xl border border-purple-800 transition">
                    Manage Users
                </a>
            @endif
        </div>
    </div>

    <!-- Summary KPI Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="p-5 rounded-xl glass-panel border border-cyber-border shadow">
            <div class="text-xs font-semibold uppercase tracking-wider text-slate-400">Total Alerts Sent</div>
            <div class="text-2xl sm:text-3xl font-black text-white mt-1.5">{{ number_format($stats['total']) }}</div>
            <p class="text-[11px] text-slate-500 mt-1">Recorded in database</p>
        </div>
        <div class="p-5 rounded-xl glass-panel border border-emerald-900/60 shadow">
            <div class="text-xs font-semibold uppercase tracking-wider text-emerald-400">Long (BUY) Alerts</div>
            <div class="text-2xl sm:text-3xl font-black text-emerald-400 mt-1.5">{{ number_format($stats['buy']) }}</div>
            <p class="text-[11px] text-slate-500 mt-1">Bullish trend &amp; bottom reversals</p>
        </div>
        <div class="p-5 rounded-xl glass-panel border border-rose-900/60 shadow">
            <div class="text-xs font-semibold uppercase tracking-wider text-rose-400">Short (SELL) Alerts</div>
            <div class="text-2xl sm:text-3xl font-black text-rose-400 mt-1.5">{{ number_format($stats['sell']) }}</div>
            <p class="text-[11px] text-slate-500 mt-1">Bearish breakdowns &amp; top reversals</p>
        </div>
        <div class="p-5 rounded-xl glass-panel border border-amber-900/60 shadow">
            <div class="text-xs font-semibold uppercase tracking-wider text-amber-400">Grade A Confluence</div>
            <div class="text-2xl sm:text-3xl font-black text-amber-400 mt-1.5">{{ number_format($stats['grade_a']) }}</div>
            <p class="text-[11px] text-slate-500 mt-1">Scores &ge; 90 / 100</p>
        </div>
    </div>

    <!-- Filters Bar -->
    <div class="p-5 rounded-xl glass-panel border border-cyber-border shadow-xl">
        <form method="GET" action="{{ route('alerts.index') }}" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-4 items-end">
            <!-- Symbol search -->
            <div>
                <label for="symbol" class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1.5">Symbol / Pair</label>
                <input type="text" id="symbol" name="symbol" value="{{ $symbol }}" placeholder="e.g. SOLUSDT, BTC"
                    class="block w-full px-3 py-2 bg-cyber-900 border border-cyber-border rounded-xl text-white placeholder-slate-500 text-xs focus:outline-none focus:border-cyan-500">
            </div>

            <!-- Side filter -->
            <div>
                <label for="side" class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1.5">Direction</label>
                <select id="side" name="side" class="block w-full px-3 py-2 bg-cyber-900 border border-cyber-border rounded-xl text-white text-xs focus:outline-none focus:border-cyan-500">
                    <option value="">All Directions</option>
                    <option value="BUY" {{ strtoupper($side) === 'BUY' ? 'selected' : '' }}>🟢 BUY (Long)</option>
                    <option value="SELL" {{ strtoupper($side) === 'SELL' ? 'selected' : '' }}>🔴 SELL (Short)</option>
                </select>
            </div>

            <!-- Timeframe filter -->
            <div>
                <label for="interval" class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1.5">Timeframe</label>
                <select id="interval" name="interval" class="block w-full px-3 py-2 bg-cyber-900 border border-cyber-border rounded-xl text-white text-xs focus:outline-none focus:border-cyan-500">
                    <option value="">All Timeframes</option>
                    @foreach (['1m', '3m', '5m', '15m', '30m', '1h', '2h', '4h', '1d'] as $tf)
                        <option value="{{ $tf }}" {{ strtolower($interval) === $tf ? 'selected' : '' }}>{{ $tf }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Setup Type filter -->
            <div>
                <label for="setup_type" class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1.5">Setup Type</label>
                <select id="setup_type" name="setup_type" class="block w-full px-3 py-2 bg-cyber-900 border border-cyber-border rounded-xl text-white text-xs focus:outline-none focus:border-cyan-500">
                    <option value="">All Setup Types</option>
                    <option value="TREND" {{ strtoupper($setupType) === 'TREND' ? 'selected' : '' }}>Trend Continuation</option>
                    <option value="REVERSAL" {{ strtoupper($setupType) === 'REVERSAL' ? 'selected' : '' }}>Exhaustion Reversal</option>
                </select>
            </div>

            <!-- Filter actions -->
            <div class="flex items-center space-x-2">
                <button type="submit" class="w-full py-2 px-4 bg-cyan-600 hover:bg-cyan-500 text-white font-bold text-xs rounded-xl transition shadow">
                    Filter
                </button>
                @if ($symbol || $side || $interval || $setupType)
                    <a href="{{ route('alerts.index') }}" class="py-2 px-3 bg-cyber-700 hover:bg-cyber-600 text-slate-300 text-xs font-semibold rounded-xl border border-cyber-border transition" title="Clear Filters">
                        ✕
                    </a>
                @endif
            </div>
        </form>
    </div>

    <!-- Alert History Table -->
    <div class="glass-panel rounded-2xl border border-cyber-border shadow-2xl overflow-hidden">
        <div class="p-5 border-b border-cyber-border flex justify-between items-center bg-cyber-900/60">
            <div>
                <h2 class="text-sm font-bold text-white uppercase tracking-wider">Alert Log Records</h2>
                <p class="text-[11px] text-slate-400 mt-0.5">Showing {{ $alerts->firstItem() ?? 0 }} to {{ $alerts->lastItem() ?? 0 }} of {{ $alerts->total() }} alerts</p>
            </div>
            <div class="flex items-center space-x-2">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-950 text-emerald-300 border border-emerald-800">
                    Auto-Synced with Telegram
                </span>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-cyber-border text-left text-xs">
                <thead class="bg-cyber-900/90 text-slate-400 font-semibold uppercase tracking-wider text-[10px]">
                    <tr>
                        <th class="py-3 px-4">Date &amp; Time</th>
                        <th class="py-3 px-4">Contract</th>
                        <th class="py-3 px-4">TF</th>
                        <th class="py-3 px-4">Direction</th>
                        <th class="py-3 px-4">Setup Type</th>
                        <th class="py-3 px-4">Grade &amp; Score</th>
                        <th class="py-3 px-4">Entry</th>
                        <th class="py-3 px-4">Stop Loss</th>
                        <th class="py-3 px-4">TP1 / TP2 / TP3</th>
                        <th class="py-3 px-4">R:R / Lev</th>
                        <th class="py-3 px-4">Source</th>
                        <th class="py-3 px-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-cyber-border/60 font-mono text-[11px]">
                    @forelse ($alerts as $a)
                        <tr class="hover:bg-cyber-800/40 transition">
                            <!-- Date & Time -->
                            <td class="py-3 px-4 text-slate-300 whitespace-nowrap">
                                <div class="font-sans font-semibold text-white">
                                    {{ $a->sent_at ? $a->sent_at->format('M d, Y H:i') : 'N/A' }}
                                </div>
                                <div class="text-[10px] text-slate-500 font-sans">
                                    {{ $a->sent_at ? $a->sent_at->diffForHumans() : '' }}
                                </div>
                            </td>

                            <!-- Contract -->
                            <td class="py-3 px-4 whitespace-nowrap">
                                <span class="font-bold text-white tracking-tight">#{{ $a->symbol }}</span>
                                <div class="text-[10px] text-slate-500 font-sans">USDⓈ-M Futures</div>
                            </td>

                            <!-- Timeframe -->
                            <td class="py-3 px-4 whitespace-nowrap">
                                <span class="px-2 py-0.5 rounded bg-cyber-700 border border-cyber-border text-slate-300 font-semibold text-[10px]">
                                    {{ $a->interval }}
                                </span>
                            </td>

                            <!-- Direction -->
                            <td class="py-3 px-4 whitespace-nowrap font-sans">
                                @if ($a->isBuy())
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-emerald-950 text-emerald-300 border border-emerald-800/80">
                                        🟢 BUY
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-rose-950 text-rose-300 border border-rose-800/80">
                                        🔴 SELL
                                    </span>
                                @endif
                            </td>

                            <!-- Setup Type -->
                            <td class="py-3 px-4 whitespace-nowrap font-sans">
                                @if ($a->isReversal())
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-amber-950 text-amber-300 border border-amber-800/80">
                                        Reversal
                                    </span>
                                @else
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-cyan-950 text-cyan-300 border border-cyan-800/80">
                                        Trend
                                    </span>
                                @endif
                            </td>

                            <!-- Grade & Score -->
                            <td class="py-3 px-4 whitespace-nowrap">
                                <div class="font-sans font-bold {{ $a->grade === 'A' ? 'text-emerald-400' : ($a->grade === 'B' ? 'text-cyan-400' : 'text-slate-300') }}">
                                    Grade {{ $a->grade }}
                                    <span class="text-[10px] font-normal text-slate-400">({{ $a->score }}/100)</span>
                                </div>
                                <div class="text-[10px] text-slate-500 font-mono">
                                    RSI: {{ $a->rsi ?? '-' }} | ADX: {{ $a->adx ?? '-' }}
                                </div>
                            </td>

                            <!-- Entry -->
                            <td class="py-3 px-4 text-white font-bold whitespace-nowrap">
                                ${{ $a->entry_price < 1 ? number_format($a->entry_price, 6) : number_format($a->entry_price, 4) }}
                            </td>

                            <!-- Stop Loss -->
                            <td class="py-3 px-4 text-rose-400 whitespace-nowrap">
                                ${{ $a->stop_loss < 1 ? number_format($a->stop_loss, 6) : number_format($a->stop_loss, 4) }}
                            </td>

                            <!-- Take Profits -->
                            <td class="py-3 px-4 text-emerald-400 whitespace-nowrap">
                                <div>TP1: ${{ $a->take_profit_1 < 1 ? number_format($a->take_profit_1, 6) : number_format($a->take_profit_1, 4) }}</div>
                                <div class="text-[10px] text-slate-400">
                                    TP2: ${{ $a->take_profit_2 < 1 ? number_format($a->take_profit_2, 6) : number_format($a->take_profit_2, 4) }}
                                </div>
                            </td>

                            <!-- R:R & Leverage -->
                            <td class="py-3 px-4 whitespace-nowrap font-sans">
                                <div class="text-white font-semibold">{{ $a->risk_reward ?? '1 : 2.5' }}</div>
                                <div class="text-[10px] text-slate-400">{{ $a->leverage ?? '5x - 10x' }}</div>
                            </td>

                            <!-- Source -->
                            <td class="py-3 px-4 whitespace-nowrap font-sans">
                                @if ($a->source === 'cron_scanner')
                                    <span class="px-2 py-0.5 rounded text-[10px] bg-cyber-700 text-slate-300 border border-cyber-border">Scanner</span>
                                @elseif ($a->source === 'manual_alert')
                                    <span class="px-2 py-0.5 rounded text-[10px] bg-indigo-950 text-indigo-300 border border-indigo-800">On-Demand</span>
                                @else
                                    <span class="px-2 py-0.5 rounded text-[10px] bg-amber-950 text-amber-300 border border-amber-800">Test</span>
                                @endif
                            </td>

                            <!-- Actions -->
                            <td class="py-3 px-4 text-right whitespace-nowrap font-sans">
                                <a href="https://www.binance.com/en/futures/{{ $a->symbol }}" target="_blank" rel="noopener noreferrer"
                                    class="inline-flex items-center px-2.5 py-1 text-xs font-semibold text-cyan-400 hover:text-cyan-300 bg-cyan-950/40 hover:bg-cyan-900/60 border border-cyan-800/60 rounded-lg transition">
                                    <span>Binance ↗</span>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="12" class="py-12 text-center text-slate-500 font-sans">
                                <div class="text-3xl mb-2">📡</div>
                                <div class="font-bold text-sm text-slate-400">No alert records found</div>
                                <div class="text-xs mt-1">Alerts sent by the automated sentinel or manual dashboard triggers will appear here.</div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($alerts->hasPages())
            <div class="p-4 border-t border-cyber-border bg-cyber-900/60">
                {{ $alerts->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
