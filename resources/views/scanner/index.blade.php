@extends('layouts.app')

@section('title', 'Market Scanner')

@push('styles')
<style>
    .scan-filter-btn { padding: 0.3rem 0.65rem; border-radius: 0.5rem; font-weight: 700; white-space: nowrap; color: rgb(148 163 184); transition: all .15s; }
    .scan-filter-btn:hover { color: rgb(226 232 240); }
    .scan-filter-btn.is-active { background: rgb(30 41 59); color: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.4); }
</style>
@endpush

@section('content')
<div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 py-5 sm:py-10 overflow-x-hidden">
    <div class="mb-5 sm:mb-6 flex flex-col sm:flex-row sm:items-end justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-xl sm:text-3xl font-extrabold text-white tracking-tight">Whole-Market Scanner</h1>
            <p class="mt-1.5 text-slate-400 text-xs sm:text-sm">Every Binance USDT perpetual, the breakout watch and the setups still in play. "Chart" opens the coin on the Signals &amp; Charts page.</p>
        </div>
        <a href="{{ route('signals.dashboard') }}" class="self-start sm:self-auto px-4 py-2 text-xs font-semibold bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-xl border border-slate-700 transition">📈 Signals &amp; Charts</a>
    </div>

    <div id="toastNotification" class="hidden mb-4 p-3 rounded-xl text-xs font-semibold flex items-center justify-between transition-all"></div>

    <!-- On-Demand Whole-Market Scanner (queued, runs via cron: crypto:scan --manual) -->
    <div class="p-4 sm:p-6 rounded-2xl bg-gradient-to-r from-slate-900 via-slate-900 to-indigo-950/40 border border-slate-800 hover:border-indigo-500/30 shadow-2xl mb-6 sm:mb-8 relative overflow-hidden transition">
        <div class="absolute -right-16 -top-16 w-64 h-64 bg-cyan-500/10 rounded-full blur-3xl pointer-events-none"></div>

        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4">
            <div class="w-full lg:w-auto">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center px-3 py-1 rounded-lg text-xs font-black tracking-wider bg-cyan-500/20 text-cyan-300 border border-cyan-500/40 uppercase shadow-sm">
                        ⚡ Whole-Market Scanner
                    </span>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                        Same engine as the auto-trader
                    </span>
                    <span id="marketScanStatusBadge" class="inline-flex items-center space-x-1.5 px-3 py-1 rounded-full text-xs font-bold bg-slate-800 text-slate-400 border border-slate-700">
                        <span id="marketScanStatusDot" class="w-2.5 h-2.5 rounded-full bg-slate-500"></span>
                        <span id="marketScanStatusText">IDLE</span>
                    </span>
                </div>
                <h3 class="text-base sm:text-lg font-bold text-white mt-2">
                    On-Demand Setup Scanner
                </h3>
                <p class="text-xs text-slate-400 max-w-2xl mt-1">
                    Scans every crypto USDT perpetual on Binance (24h volume &ge; $5M) and lists every setup from the last 6 hours that is still in play. Each card shows whether it passes the auto-trader's filters (4h trend, BTC direction, volatility, stop width, liquidity) and the setup's measured track record. The scan runs in the background and takes about a minute.
                </p>

                <!-- Scan Metrics Bar -->
                <div class="grid grid-cols-2 sm:flex sm:flex-wrap items-center gap-2 sm:gap-3 mt-3 text-[11px] sm:text-xs font-mono text-slate-400">
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
                        Results from: <strong id="scanStartedAt" class="text-indigo-300">--</strong>
                    </span>
                </div>
            </div>

            <!-- Controls -->
            <div class="flex flex-wrap items-center gap-2 w-full lg:w-auto justify-start sm:justify-end">
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

        <!-- Breakout watch: coins coiled in a squeeze on the current candle (updated every 15s) -->
        <div id="breakoutWatchPanel" class="mt-5 rounded-xl border border-amber-500/30 bg-amber-500/[0.04] p-3.5">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-2.5">
                <div>
                    <h4 class="text-sm font-black text-amber-200">🔭 Breakout watch <span id="breakoutWatchCount" class="ml-1 px-2 py-0.5 rounded-full text-[10px] bg-amber-500/15 text-amber-300 border border-amber-500/30">0</span></h4>
                    <p class="text-[11px] text-slate-400 mt-0.5">Coins squeezed tight near their 20-candle high/low with every filter passing. ⚡ = trading through its trigger now. Telegram alerts both.</p>
                </div>
                <div id="earlyBreakoutStatus" class="text-[11px] font-mono text-slate-400"></div>
            </div>
            <div id="breakoutWatchList" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-2 text-xs">
                <div class="col-span-full text-slate-500 text-[11px]">Loading...</div>
            </div>
        </div>

        <!-- Detected Signals Container -->
        <div class="mt-5">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 mb-4 pb-3 border-b border-slate-800/80">
                <div>
                    <div class="flex items-center space-x-2.5">
                        <h4 class="text-sm sm:text-base font-black text-slate-100 flex items-center space-x-2">
                            <span>🎯 Setups In Play</span>
                            <span id="signalsBadgeCount" class="text-xs px-2.5 py-0.5 rounded-full font-bold bg-cyan-500/20 text-cyan-300 border border-cyan-500/40">0 Found</span>
                        </h4>
                    </div>
                    <p class="text-[11px] text-slate-400 mt-0.5">
                        Ranked by Opportunity Score (measured edge, filters, confluence, entry still valid, freshness). A ranking, not a profit guarantee.
                    </p>
                </div>

                <!-- Controls: filter, sort and search -->
                <div class="flex flex-col sm:flex-row sm:flex-wrap items-stretch sm:items-center gap-2 w-full md:w-auto">
                    <div class="flex overflow-x-auto rounded-xl bg-slate-950 p-1 border border-slate-800 text-xs" role="group" aria-label="Filter setups">
                        <button type="button" onclick="setScanFilter('ALL')" id="filter-btn-ALL" class="scan-filter-btn is-active">All</button>
                        <button type="button" onclick="setScanFilter('BUY')" id="filter-btn-BUY" class="scan-filter-btn">🟢 Longs</button>
                        <button type="button" onclick="setScanFilter('SELL')" id="filter-btn-SELL" class="scan-filter-btn">🔴 Shorts</button>
                        <button type="button" onclick="setScanFilter('TRADABLE')" id="filter-btn-TRADABLE" class="scan-filter-btn">✓ Tradable</button>
                        <button type="button" onclick="setScanFilter('ENTER_NOW')" id="filter-btn-ENTER_NOW" class="scan-filter-btn">⚡ Enter now</button>
                    </div>
                    <div class="flex gap-2">
                        <label class="flex items-center gap-1.5 bg-slate-950 px-2.5 py-1.5 rounded-xl border border-slate-800 text-xs flex-1 sm:flex-none">
                            <span class="text-slate-500 font-semibold text-[11px]">Sort</span>
                            <select id="scanSortSelect" onchange="changeScanSort(this.value)" class="bg-transparent text-slate-200 font-bold focus:outline-none cursor-pointer text-xs flex-1">
                                <option value="score_desc" selected>Best score</option>
                                <option value="newest">Newest</option>
                                <option value="record_desc">Best track record</option>
                                <option value="stop_asc">Tightest stop</option>
                            </select>
                        </label>
                        <input type="search" id="scanSearchInput" oninput="setScanSearch(this.value)" placeholder="Search coin"
                            class="w-28 sm:w-32 px-2.5 py-1.5 rounded-xl bg-slate-950 border border-slate-800 text-xs text-white uppercase placeholder-slate-500 focus:outline-none focus:border-cyan-500">
                    </div>
                </div>
            </div>

            <div id="scanSignalsGrid" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3.5">
                <!-- Empty state shown by default -->
                <div id="scanSignalsEmptyState" class="col-span-full py-8 text-center rounded-xl bg-slate-950/40 border border-dashed border-slate-800 text-slate-500 text-xs">
                    <div class="text-2xl mb-1.5">🔭</div>
                    No scan yet. Click <strong class="text-slate-400">"Run Whole-Market Scan"</strong> to check every crypto USDT perpetual.
                </div>
            </div>
        </div>

        <!-- Collapsible Raw Terminal Output Console -->
        <div id="terminalConsolePanel" class="hidden mt-5 pt-4 border-t border-slate-800/80">
            <div class="flex justify-between items-center mb-2">
                <div class="flex items-center space-x-2 font-mono text-xs text-slate-300">
                    <span class="w-2.5 h-2.5 rounded-full bg-slate-600"></span>
                    <span class="font-semibold">Scan summary</span>
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
</div>

<script type="text/javascript">
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

    // ==========================================
    // On-Demand Whole-Market Scanner (queued; the cron runs crypto:scan --manual)
    // ==========================================
    let marketScanPollInterval = null;
    let isMarketScanRunning = false;

    function startMarketScan() {
        const btn = document.getElementById('btnStartMarketScan');
        const textSpan = document.getElementById('btnStartMarketScanText');
        if (btn) btn.disabled = true;
        if (textSpan) textSpan.innerHTML = 'Launching Scanner...';

        
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
            if (startedAt) {
                const age = data.age_minutes;
                const ageText = age === null || age === undefined ? '' : (age < 1 ? ' (just now)' : age < 60 ? ` (${age} min ago)` : ` (${Math.floor(age / 60)}h ${age % 60}m ago)`);
                startedAt.textContent = data.started_at ? `${data.started_at}${ageText}` : '--';
                startedAt.className = age !== null && age !== undefined && age > 15 ? 'text-amber-300' : 'text-indigo-300';
                startedAt.title = age > 15 ? 'These results are old. Press the scan button for a fresh scan.' : '';
            }

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
            renderMarketScanSignals(data.signals || [], data.account || null);
        })
        .catch(() => {});
    }

    let rawMarketScanSignals = [];
    let scanAccount = null;
    let activeScanFilter = 'ALL';
    let activeScanSort = 'score_desc';
    let activeScanSearch = '';

    function setScanFilter(filter) {
        activeScanFilter = filter;
        document.querySelectorAll('.scan-filter-btn').forEach(btn => {
            btn.classList.toggle('is-active', btn.id === `filter-btn-${filter}`);
        });
        applyScanFilterAndSort();
    }

    function changeScanSort(sort) {
        activeScanSort = sort;
        applyScanFilterAndSort();
    }

    function setScanSearch(text) {
        activeScanSearch = String(text || '').trim().toUpperCase();
        applyScanFilterAndSort();
    }

    function renderMarketScanSignals(signals, account = null) {
        rawMarketScanSignals = Array.isArray(signals) ? signals : [];
        if (account) scanAccount = account;
        applyScanFilterAndSort();
    }

    /** Readable price for its magnitude, e.g. 275.02 / 1.2345 / 0.043341. */
    function fmtPrice(value, decimals) {
        if (value === null || value === undefined || isNaN(Number(value))) return '—';
        const n = Number(value);
        const d = Number.isInteger(decimals) ? decimals : (Math.abs(n) >= 1000 ? 2 : Math.abs(n) >= 1 ? 4 : Math.abs(n) >= 0.01 ? 5 : 7);
        return n.toLocaleString('en-US', { minimumFractionDigits: Math.min(d, 2), maximumFractionDigits: d });
    }

    function fmtAge(minutes) {
        const m = Number(minutes) || 0;
        if (m < 60) return `${m}m ago`;
        const h = Math.floor(m / 60);
        return `${h}h ${m % 60}m ago`;
    }

    function escHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function filteredScanSignals() {
        const list = rawMarketScanSignals.filter(s => {
            if (activeScanSearch && !String(s.symbol).toUpperCase().includes(activeScanSearch)) return false;
            if (activeScanFilter === 'BUY') return s.direction === 'LONG';
            if (activeScanFilter === 'SELL') return s.direction === 'SHORT';
            if (activeScanFilter === 'TRADABLE') return s.tradable === true;
            if (activeScanFilter === 'ENTER_NOW') return s.tradable === true && s.entry_status === 'Enter now';
            return true;
        });

        const recordScore = (s) => (s.stats && s.stats.expectancy !== null && s.stats.expectancy !== undefined) ? Number(s.stats.expectancy) : -99;
        list.sort((a, b) => {
            if (activeScanSort === 'newest') return (a.age_minutes || 0) - (b.age_minutes || 0);
            if (activeScanSort === 'record_desc') return recordScore(b) - recordScore(a) || (b.score || 0) - (a.score || 0);
            if (activeScanSort === 'stop_asc') return (Number(a.sl_pct) || 99) - (Number(b.sl_pct) || 99);
            return (a.rank || 999) - (b.rank || 999);
        });

        return list;
    }

    function applyScanFilterAndSort() {
        const grid = document.getElementById('scanSignalsGrid');
        if (!grid) return;
        const badgeCount = document.getElementById('signalsBadgeCount');

        if (rawMarketScanSignals.length === 0) {
            grid.innerHTML = `
                <div id="scanSignalsEmptyState" class="col-span-full py-8 text-center rounded-xl bg-slate-950/40 border border-dashed border-slate-800 text-slate-500 text-xs">
                    <div class="text-2xl mb-1.5">🔭</div>
                    No setups in play. Run a scan, or wait for the next one: most hours have no valid entry.
                </div>`;
            if (badgeCount) badgeCount.textContent = '0 Found';
            return;
        }

        const list = filteredScanSignals();
        if (badgeCount) badgeCount.textContent = `${list.length} of ${rawMarketScanSignals.length}`;

        if (list.length === 0) {
            grid.innerHTML = `
                <div class="col-span-full py-8 text-center rounded-xl bg-slate-950/40 border border-dashed border-slate-800 text-slate-400 text-xs">
                    No setups match this filter. <button type="button" class="text-cyan-400 font-bold underline" onclick="setScanFilter('ALL'); document.getElementById('scanSearchInput').value=''; setScanSearch('');">Show all</button>
                </div>`;
            return;
        }

        grid.innerHTML = list.map(renderScanCard).join('');
    }

    function renderScanCard(s) {
        const isLong = s.direction === 'LONG';
        const d = s.price_decimals;
        const score = Number(s.score) || 0;
        const scoreColor = score >= 75 ? 'bg-emerald-500' : score >= 60 ? 'bg-cyan-500' : score >= 45 ? 'bg-amber-500' : 'bg-slate-500';
        const sideCls = isLong ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/40' : 'bg-rose-500/15 text-rose-300 border-rose-500/40';
        const border = s.top_pick ? 'border-amber-400/70 ring-1 ring-amber-400/30' : (s.tradable ? (isLong ? 'border-emerald-500/40' : 'border-rose-500/40') : 'border-slate-800');

        // Status line
        let status;
        if (!s.tradable) {
            const reason = s.is_shadow ? 'Tracked setup, not traded' : (s.failed_filters[0] || 'Filter failed');
            status = `<span class="text-amber-300">⚠️ Info only · ${escHtml(reason)}</span>`;
        } else if (s.entry_status === 'Enter now') {
            status = `<span class="text-emerald-300 font-bold">✅ Tradable · Enter now</span>`;
        } else {
            status = `<span class="text-slate-300">⏱ ${escHtml(s.entry_status)}</span>`;
        }

        // Account projection: risk = balance x risk%, targets in R minus round-trip fees
        let accountHtml = '';
        if (scanAccount && scanAccount.balance > 0) {
            const riskUsd = scanAccount.balance * scanAccount.risk_pct / 100;
            const feeR = (Number(s.entry) * (scanAccount.fee_rate || 0.0005) * 2) / Math.max(1e-12, Math.abs(Number(s.entry) - Number(s.sl)));
            const tp1Usd = riskUsd * (1.5 - feeR);
            const tp2Usd = riskUsd * (3 - feeR);
            accountHtml = `
                <div class="text-[11px] text-slate-400 bg-slate-900/70 border border-slate-800 rounded-lg px-2.5 py-1.5">
                    Your ${escHtml(String(scanAccount.mode).toUpperCase())} $${Number(scanAccount.balance).toFixed(2)}:
                    risk <b class="text-rose-300">$${riskUsd.toFixed(2)}</b> ·
                    TP1 <b class="text-emerald-300">+$${tp1Usd.toFixed(2)}</b> ·
                    TP2 <b class="text-emerald-300">+$${tp2Usd.toFixed(2)}</b>
                    <span class="text-slate-500">(fees incl.)</span>
                </div>`;
        }

        const st = s.stats || {};
        const record = st.n
            ? `${escHtml(s.setup_label)}: <b class="text-white">${st.win_rate}%</b> win · <b class="${st.expectancy >= 0 ? 'text-emerald-300' : 'text-rose-300'}">${st.expectancy >= 0 ? '+' : ''}${Number(st.expectancy).toFixed(2)}R</b> avg · n=${st.n} <span class="text-slate-500">(${escHtml(st.source)})</span>`
            : `${escHtml(s.setup_label)}: no track record yet`;

        const bd = s.score_breakdown || {};
        const breakdownRows = [['Measured edge', bd.edge, 35], ['Filters', bd.filters, 20], ['Confluence', bd.confluence, 20], ['Entry valid', bd.entry, 15], ['Freshness', bd.freshness, 10], ['AI', bd.ai, 10]]
            .map(([name, val, max]) => `<div class="flex justify-between"><span>${name}</span><span class="font-mono text-slate-200">${Number(val || 0).toFixed(0)} / ${max}</span></div>`).join('');
        const filterRows = Object.entries(s.filters || {})
            .map(([name, f]) => `<li><span class="${f.pass ? 'text-emerald-400' : 'text-rose-400'} font-bold">${f.pass ? '✓' : '✗'}</span> <span class="capitalize">${escHtml(name.replace('_', ' '))}</span>: <span class="text-slate-500">${escHtml(f.detail)}</span></li>`).join('');
        const ind = s.indicators || {};
        const drift = (s.drift_r !== null && s.drift_r !== undefined) ? `${s.drift_r >= 0 ? '+' : ''}${Number(s.drift_r).toFixed(2)}R` : '';

        const level = (label, value, sub, cls) => `
            <div class="rounded-lg bg-slate-900/80 border border-slate-800 px-2 py-1.5 min-w-0">
                <div class="text-[10px] uppercase tracking-wide text-slate-500 font-semibold">${label}</div>
                <div class="font-mono font-bold text-[13px] tabular-nums truncate ${cls}" title="${value}">${value}</div>
                <div class="text-[10px] text-slate-500 tabular-nums">${sub}</div>
            </div>`;

        const canTrade = s.tradable && s.entry_status === 'Enter now';
        const tradeLabel = `Place ${isLong ? 'LONG' : 'SHORT'}${scanAccount ? ' · ' + String(scanAccount.mode).toUpperCase() : ''}`;

        return `
            <div class="p-3.5 sm:p-4 rounded-xl bg-slate-950/80 border ${border} shadow-xl flex flex-col gap-2.5 min-w-0">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="text-[11px] font-black text-slate-400">#${s.rank}</span>
                            <span class="text-base font-black text-white">${escHtml(s.symbol.replace('USDT', ''))}<span class="text-slate-500 text-xs">USDT</span></span>
                            <span class="px-2 py-0.5 rounded-md text-[11px] font-black border ${sideCls}">${isLong ? '▲ LONG' : '▼ SHORT'}</span>
                            ${s.top_pick ? '<span class="px-2 py-0.5 rounded-md text-[10px] font-black bg-amber-400/20 text-amber-300 border border-amber-400/50">⭐ TOP PICK</span>' : ''}
                        </div>
                        <div class="text-[11px] text-slate-400 mt-0.5">${escHtml(s.setup_label)}${s.is_shadow ? ' (shadow)' : ''} · Grade ${escHtml(s.grade || '-')} · ${fmtAge(s.age_minutes)}</div>
                    </div>
                    <button type="button" onclick="toggleScanAlert('${s.symbol}', ${s.watched ? 'true' : 'false'}, this)" title="${s.watched ? 'Alerts on for this coin' : 'Alert me on this coin'}"
                        class="shrink-0 w-8 h-8 rounded-lg border text-sm ${s.watched ? 'border-cyan-500/50 bg-cyan-500/10' : 'border-slate-700 bg-slate-900 hover:bg-slate-800'}">${s.watched ? '🔔' : '🔕'}</button>
                </div>

                <details class="rounded-lg bg-slate-900/60 border border-slate-800 px-2.5 py-1.5">
                    <summary class="cursor-pointer select-none list-none">
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-slate-400">Opportunity</span>
                            <span class="font-black text-white">${score}<span class="text-slate-500 font-normal">/100</span> <span class="text-[11px] ${score >= 60 ? 'text-emerald-300' : 'text-slate-400'}">${escHtml(s.score_label)}</span> <span class="text-slate-500">▾</span></span>
                        </div>
                        <div class="mt-1 h-1.5 rounded-full bg-slate-800 overflow-hidden"><div class="h-full ${scoreColor}" style="width:${Math.max(3, score)}%"></div></div>
                    </summary>
                    <div class="mt-2 space-y-0.5 text-[11px] text-slate-400">${breakdownRows}</div>
                </details>

                <div class="text-xs">${status}</div>
                ${s.auto_trade ? `<div class="text-[11px] text-indigo-300 -mt-1">🤖 ${escHtml(s.auto_trade)}</div>` : ""}

                <div class="grid grid-cols-2 sm:grid-cols-3 gap-1.5">
                    ${level('Entry', fmtPrice(s.entry, d), `${s.interval} close`, 'text-white')}
                    ${level('Now', fmtPrice(s.now_price, d), drift, 'text-cyan-300')}
                    ${level('Stop', fmtPrice(s.sl, d), `−${s.sl_pct}%`, 'text-rose-300')}
                    ${level('TP1 · 1.5R', fmtPrice(s.tp1, d), `+${s.tp1_pct}% · book 50%`, 'text-emerald-300')}
                    ${level('TP2 · 3R', fmtPrice(s.tp2, d), `+${s.tp2_pct}%`, 'text-emerald-300')}
                    ${level('Edge', s.edge_r !== null && s.edge_r !== undefined ? `${s.edge_r >= 0 ? '+' : ''}${Number(s.edge_r).toFixed(2)}R` : '—', 'avg per trade', s.edge_r > 0 ? 'text-emerald-300' : 'text-slate-300')}
                </div>

                ${accountHtml}
                <div class="text-[11px] text-slate-400">${record}</div>

                <details class="rounded-lg bg-slate-900/60 border border-slate-800 px-2.5 py-1.5 text-[11px] text-slate-400">
                    <summary class="cursor-pointer select-none font-semibold text-cyan-300">Why this signal ▾</summary>
                    <ul class="mt-1.5 space-y-0.5">${filterRows}</ul>
                    ${s.confluences && s.confluences.length ? `<div class="mt-1 text-cyan-300">Confluence: ${escHtml(s.confluences.join(', '))}</div>` : ''}
                    <div class="mt-1 font-mono">RSI ${ind.rsi ?? '—'} · ADX ${ind.adx ?? '—'} · Vol ${ind.volume_ratio ?? '—'}x · ATR ${ind.atr_pct ?? '—'}%</div>
                    ${s.auto_trade ? `<div class="mt-1 text-indigo-300">Auto-trader: ${escHtml(s.auto_trade)}</div>` : ''}
                </details>

                <div class="grid grid-cols-3 gap-2 mt-auto">
                    <button type="button" ${canTrade ? '' : 'disabled'} onclick="executeManualScanTrade('${s.symbol}', '${s.direction}', this)"
                        class="col-span-2 py-2 px-2 text-xs font-black rounded-lg transition ${canTrade ? (isLong ? 'bg-emerald-600 hover:bg-emerald-500 text-white' : 'bg-rose-600 hover:bg-rose-500 text-white') : 'bg-slate-800 text-slate-500 cursor-not-allowed'}"
                        title="${canTrade ? 'Opens the trade with the stop-loss placed on Binance' : 'Only tradable signals with a valid entry can be placed'}">
                        ${canTrade ? '⚡ ' + tradeLabel : (s.tradable ? 'Entry no longer valid' : 'Not tradable')}
                    </button>
                    <button type="button" onclick="openScanChart('${s.symbol}', ${Number(s.time) || 0}, '${s.interval}')"
                        class="py-2 px-2 text-xs font-bold rounded-lg bg-slate-800 hover:bg-slate-700 text-cyan-300 border border-slate-700 transition">📈 Chart</button>
                </div>
            </div>`;
    }

    /** Open the coin on the SignalAlgo chart with this signal's plan drawn, and scroll to it. */
    /** Open the coin on the Signals & Charts page, focused on the setup's candle. */
    function openScanChart(symbol, signalTime, interval) {
        const params = new URLSearchParams({ symbol: String(symbol).toUpperCase(), interval: interval || '1h', signal_time: String(Number(signalTime) || 0) });
        window.location.href = '{{ route('signals.dashboard') }}?' + params.toString();
    }

    /** Toggle Telegram alerts for one coin from its card. */
    async function toggleScanAlert(symbol, watched, btn) {
        const url = watched ? '{{ route('daemon.monitored-coins.remove') }}' : '{{ route('daemon.monitored-coins.add') }}';
        try {
            const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }, body: JSON.stringify({ symbol }) });
            const data = await res.json().catch(() => ({}));
            showToast(data.message || (res.ok ? 'Updated.' : 'Could not update alerts.'), res.ok);
            if (res.ok) {
                rawMarketScanSignals.forEach(s => { if (s.symbol === symbol) s.watched = !watched; });
                applyScanFilterAndSort();
            }
        } catch (e) {
            showToast('Network error.', false);
        }
    }

    async function executeManualScanTrade(symbol, direction, btnEl) {
        const mode = scanAccount ? String(scanAccount.mode).toUpperCase() : 'ACTIVE';
        const confirmed = confirm(`Place ${direction} ${symbol} in ${mode} mode?\nRisk is capped at ${scanAccount ? scanAccount.risk_pct : 2}% of the balance and the stop-loss is placed with the order.${mode === 'LIVE' ? '\n\nLIVE: this is a REAL order with REAL money.' : ''}`);
        if (!confirmed) return;

        const originalHtml = btnEl ? btnEl.innerHTML : null;
        if (btnEl) {
            btnEl.innerHTML = `<span class="inline-block animate-spin mr-1">⌛</span> Placing ${direction}...`;
            btnEl.disabled = true;
        }

        try {
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '{{ csrf_token() }}';
            const res = await fetch('/api/execute-radar-trade', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    symbol: symbol,
                    direction: direction
                })
            });

            const data = await res.json();
            if (res.ok && data.success) {
                showToast(`✅ ${data.message || `Successfully opened ${symbol} ${direction} trade!`}`, true);
                if (btnEl) {
                    btnEl.innerHTML = `<span>✅ Order Placed!</span>`;
                    btnEl.classList.remove('bg-emerald-600', 'bg-rose-600');
                    btnEl.classList.add('bg-cyan-700');
                }
            } else {
                showToast(`❌ Trade Failed: ${data.message || 'Execution rejected by exchange'}`, false);
                if (btnEl && originalHtml) {
                    btnEl.innerHTML = originalHtml;
                    btnEl.disabled = false;
                }
            }
        } catch (err) {
            showToast(`❌ Network/Execution Error: ${err.message}`, false);
            if (btnEl && originalHtml) {
                btnEl.innerHTML = originalHtml;
                btnEl.disabled = false;
            }
        }
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

    /** Breakout watch list + early-breakout auto-trader status. */
    async function pollBreakoutWatch() {
        const list = document.getElementById('breakoutWatchList');
        if (!list) return;
        try {
            const res = await fetch('{{ route('market-scan.watch') }}', { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            const coins = Array.isArray(data.coins) ? data.coins : [];
            document.getElementById('breakoutWatchCount').textContent = coins.length;

            const eb = data.early_breakout || {};
            const status = document.getElementById('earlyBreakoutStatus');
            if (status) {
                const auto = eb.auto_trade
                    ? (eb.paused
                        ? `<span class="text-rose-300">⏸ ${escHtml(eb.paused)}</span> <button type="button" onclick="resumeEarlyBreakout(this)" class="ml-1 px-2 py-0.5 rounded bg-amber-600 hover:bg-amber-500 text-white font-bold">Resume</button>`
                        : `<span class="text-emerald-300">🤖 Auto-trade ON (${escHtml(String(eb.mode).toUpperCase())}, ${eb.risk_pct}% risk, max ${eb.max_per_day}/day)</span>${eb.blocked ? ` · <span class="text-amber-300">${escHtml(eb.blocked)}</span>` : ''}`)
                    : '<span>Auto-trade OFF (alerts only)</span>';
                status.innerHTML = auto;
            }

            if (coins.length === 0) {
                list.innerHTML = '<div class="col-span-full text-slate-500 text-[11px]">No coin is coiled for a breakout on this candle. The list refreshes after each hourly scan.</div>';
                return;
            }
            list.innerHTML = coins.map(c => {
                const isLong = c.side === 'LONG';
                const away = c.distance_pct === null ? '—' : (c.triggered ? '⚡ breaking out' : `${Number(c.distance_pct).toFixed(2)}% away`);
                return `
                    <button type="button" onclick="openScanChart('${c.symbol}', 0, '1h')" class="text-left rounded-lg border ${c.triggered ? 'border-amber-400 bg-amber-500/10' : 'border-slate-800 bg-slate-950/70 hover:bg-slate-900'} px-2.5 py-2 min-w-0">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-black text-white">${escHtml(c.symbol.replace('USDT', ''))}<span class="text-slate-500 text-[10px]">USDT</span></span>
                            <span class="px-1.5 py-0.5 rounded text-[10px] font-black border ${isLong ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/40' : 'bg-rose-500/15 text-rose-300 border-rose-500/40'}">${isLong ? '▲ above' : '▼ below'} ${fmtPrice(c.trigger, c.price_decimals)}</span>
                        </div>
                        <div class="flex items-center justify-between mt-1 text-[11px] font-mono">
                            <span class="text-slate-400">now ${fmtPrice(c.price, c.price_decimals)}</span>
                            <span class="${c.triggered ? 'text-amber-300 font-bold' : 'text-slate-300'}">${away}</span>
                        </div>
                    </button>`;
            }).join('');
        } catch (e) {
            list.innerHTML = '<div class="col-span-full text-slate-500 text-[11px]">Could not load the breakout watch list.</div>';
        }
    }

    async function resumeEarlyBreakout(btn) {
        btn.disabled = true;
        try {
            const res = await fetch('{{ route('market-scan.early-resume') }}', { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken } });
            const data = await res.json().catch(() => ({}));
            showToast(data.message || (res.ok ? 'Resumed.' : 'Not allowed.'), res.ok);
        } finally {
            pollBreakoutWatch();
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        pollMarketScanStatus();
        pollBreakoutWatch();
        setInterval(function() {
            if (document.visibilityState === 'visible') pollBreakoutWatch();
        }, 15000);
        setInterval(function() {
            if (!isMarketScanRunning && document.visibilityState === 'visible') {
                pollMarketScanStatus();
            }
        }, 10000);
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'visible') {
                pollMarketScanStatus();
                pollBreakoutWatch();
            }
        });
    });
</script>
@endsection
