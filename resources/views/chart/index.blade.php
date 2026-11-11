@extends('layouts.app')

@section('title', 'SignalAlgo PRO Chart')

@section('content')
<div id="sap-root" class="px-4 lg:px-8 py-6 space-y-5 max-w-full" data-url="{{ route('api.chart.near', [], false) }}" data-symbol="{{ $symbol }}">
    {{-- Header --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
                <h1 class="text-lg sm:text-xl font-extrabold text-white tracking-wide">SignalAlgo <span class="text-emerald-400">PRO</span></h1>
                <span class="px-2 py-0.5 rounded-lg text-xs font-mono font-bold bg-cyber-700 border border-cyber-border text-cyan-300">{{ $symbol }} · 1H</span>
                <span id="sap-bot" class="px-2 py-0.5 rounded-lg text-[11px] font-mono font-bold border bg-slate-800 text-slate-400 border-slate-700">BOT …</span>
                <span id="sap-stream" class="px-2 py-0.5 rounded-lg text-[11px] font-mono border bg-slate-800 text-slate-400 border-slate-700">live: connecting</span>
            </div>
            <p class="text-xs text-slate-400 mt-1">NEAR 1H Volatility-Gated Breakout: the same calculation as the auto-trader and the Telegram alerts.</p>
        </div>
        <div class="text-right">
            <div id="sap-price" class="text-2xl font-mono font-bold text-white">—</div>
            <div id="sap-candle-change" class="text-xs font-mono text-slate-400">this 1h candle</div>
        </div>
    </div>

    {{-- State cards --}}
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 text-xs font-mono">
        <div class="glass-panel rounded-xl p-3">
            <div class="text-slate-400">Next close must beat</div>
            <div id="sap-level" class="text-white text-base font-bold mt-1">—</div>
            <div id="sap-level-note" class="text-slate-500 mt-0.5">12-candle closing high</div>
        </div>
        <div class="glass-panel rounded-xl p-3">
            <div class="text-slate-400">EMA50 (trend)</div>
            <div id="sap-ema" class="text-white text-base font-bold mt-1">—</div>
            <div id="sap-ema-note" class="text-slate-500 mt-0.5">close must be above</div>
        </div>
        <div class="glass-panel rounded-xl p-3">
            <div class="text-slate-400">Volatility rank (next)</div>
            <div id="sap-rank" class="text-white text-base font-bold mt-1">—</div>
            <div id="sap-size" class="text-slate-500 mt-0.5">size —</div>
        </div>
        <div class="glass-panel rounded-xl p-3">
            <div class="text-slate-400">ATR (last candle)</div>
            <div id="sap-atr" class="text-white text-base font-bold mt-1">—</div>
            <div class="text-slate-500 mt-0.5">needs ≥ 0.30%</div>
        </div>
        <div class="glass-panel rounded-xl p-3">
            <div class="text-slate-400">Signal position</div>
            <div id="sap-position" class="text-white text-base font-bold mt-1">FLAT</div>
            <div id="sap-position-note" class="text-slate-500 mt-0.5">waiting for a BUY</div>
        </div>
        <div class="glass-panel rounded-xl p-3">
            <div class="text-slate-400">Last candle (engine)</div>
            <div id="sap-engine" class="text-white text-sm font-bold mt-1 truncate" title="">—</div>
            <div id="sap-engine-note" class="text-slate-500 mt-0.5 truncate">—</div>
        </div>
    </div>

    {{-- Chart --}}
    <div class="glass-panel rounded-xl overflow-hidden">
        <div class="px-4 py-3 border-b border-cyber-border flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-3 text-[11px] font-mono">
                <label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" data-toggle="signals" checked class="accent-emerald-500"> Script signals</label>
                <label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" data-toggle="trades" checked class="accent-purple-500"> Bot trades</label>
                <label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" data-toggle="level" checked class="accent-amber-500"> Breakout level</label>
                <label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" data-toggle="trail" checked class="accent-cyan-500"> Trail</label>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-[11px] font-mono text-slate-400">
                <span><span class="inline-block w-3 h-0.5 bg-fuchsia-400 align-middle"></span> EMA50</span>
                <span><span class="inline-block w-3 h-0.5 bg-amber-400 align-middle"></span> breakout level</span>
                <span><span class="inline-block w-3 h-0.5 bg-cyan-400 align-middle"></span> trail</span>
                <span class="text-emerald-400">▲ BUY</span>
                <span class="text-cyan-300">▼ EXIT</span>
                <span class="text-purple-400">● bot</span>
            </div>
        </div>
        <div class="relative">
            <div id="sap-chart" class="w-full h-[440px] md:h-[580px]"></div>
            <div id="sap-loading" class="absolute inset-0 flex items-center justify-center text-sm text-slate-400 font-mono bg-cyber-900/70">Loading NEAR 1h candles…</div>
        </div>
        <div class="px-4 py-3 border-t border-cyber-border grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs font-mono">
            <div><div class="text-slate-400">Signals in view</div><div id="sap-stat-trades" class="text-white font-bold">—</div></div>
            <div><div class="text-slate-400">Win rate</div><div id="sap-stat-win" class="text-white font-bold">—</div></div>
            <div><div class="text-slate-400">Profit factor</div><div id="sap-stat-pf" class="text-white font-bold">—</div></div>
            <div><div class="text-slate-400">Return (size-weighted, after fees)</div><div id="sap-stat-ret" class="text-white font-bold">—</div></div>
        </div>
        <div class="px-4 pb-3 text-[11px] text-amber-300/90 font-mono">
            Exits are replayed on <span id="sap-trail-source">5m</span> candles, the way the live bot meets prices. TradingView replays the 0.10 ATR trail on the 1h candle and shows far more winners
            (12-month test: PF 3.68 there, PF 0.92 on the real 5m path). Not proven.
        </div>
    </div>

    {{-- Tables --}}
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">
        <div class="glass-panel rounded-xl overflow-hidden">
            <div class="px-4 py-3 border-b border-cyber-border font-mono text-sm font-bold text-white uppercase tracking-wide">Script signals (= Telegram alerts)</div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs font-mono">
                    <thead class="bg-cyber-800/80 text-slate-400 uppercase"><tr><th class="px-3 py-2">Time (local)</th><th class="px-3 py-2">Signal</th><th class="px-3 py-2">Price</th><th class="px-3 py-2">Why / result</th></tr></thead>
                    <tbody id="sap-signals" class="divide-y divide-cyber-border/40"><tr><td colspan="4" class="px-3 py-6 text-center text-slate-500">Loading…</td></tr></tbody>
                </table>
            </div>
        </div>
        <div class="glass-panel rounded-xl overflow-hidden">
            <div class="px-4 py-3 border-b border-cyber-border font-mono text-sm font-bold text-white uppercase tracking-wide">Bot trades on this chart</div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs font-mono">
                    <thead class="bg-cyber-800/80 text-slate-400 uppercase"><tr><th class="px-3 py-2">Opened</th><th class="px-3 py-2">Mode</th><th class="px-3 py-2">Entry → exit</th><th class="px-3 py-2">Result</th></tr></thead>
                    <tbody id="sap-trades" class="divide-y divide-cyber-border/40"><tr><td colspan="4" class="px-3 py-6 text-center text-slate-500">Loading…</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
    <script src="{{ asset('js/lightweight-charts.standalone.production.js') }}"></script>
    <script src="{{ asset('js/signalalgo-near.js') }}?v={{ filemtime(public_path('js/signalalgo-near.js')) }}"></script>
@endpush
