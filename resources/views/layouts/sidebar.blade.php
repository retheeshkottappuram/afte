<!-- Left Sidebar Navigation Component -->
<aside id="appSidebar" class="fixed inset-y-0 left-0 z-40 w-64 bg-cyber-800 border-r border-cyber-border flex flex-col transition-transform duration-300 transform -translate-x-full lg:translate-x-0 shadow-2xl">
    <!-- Brand / Logo Header -->
    <div class="h-16 flex items-center justify-between px-5 border-b border-cyber-border bg-cyber-900/60">
        <a href="{{ route('dashboard') }}" class="flex items-center space-x-3 group">
            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-cyan-500/20 to-blue-600/20 border border-cyan-500/40 flex items-center justify-center text-cyan-400 font-bold font-mono shadow-[0_0_15px_rgba(6,182,212,0.25)] group-hover:scale-105 transition-transform">
                ⚡
            </div>
            <div>
                <div class="flex items-center space-x-2">
                    <span class="font-extrabold text-base tracking-wider text-white">AFTE</span>
                    <span class="text-[10px] px-1.5 py-0.5 rounded font-mono font-bold bg-cyan-500/20 text-cyan-400 border border-cyan-500/40">PRO</span>
                </div>
                <div class="text-[11px] text-slate-400 font-mono flex items-center space-x-1">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span>Binance &amp; Signals</span>
                </div>
            </div>
        </a>

        <!-- Mobile Close Button -->
        <button type="button" onclick="toggleSidebar()" class="lg:hidden p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-cyber-700/60 focus:outline-none">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
        </button>
    </div>

    <!-- User Profile Strip -->
    @auth
    <div class="px-4 py-3.5 mx-3 mt-3 rounded-xl bg-cyber-900/80 border border-cyber-border/80 flex items-center justify-between">
        <div class="flex items-center space-x-2.5 min-w-0">
            <div class="w-8 h-8 rounded-lg bg-cyber-700 border border-cyber-border flex items-center justify-center text-sm font-bold text-slate-200 uppercase">
                {{ substr(Auth::user()->name, 0, 2) }}
            </div>
            <div class="min-w-0">
                <div class="text-xs font-semibold text-slate-200 truncate">{{ Auth::user()->name }}</div>
                <div class="text-[10px] text-slate-400 truncate">{{ Auth::user()->email }}</div>
            </div>
        </div>
        <div>
            @if (Auth::user()->isAdmin())
                <span class="px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider rounded-md bg-purple-950/90 text-purple-300 border border-purple-700/60 shadow-[0_0_8px_rgba(168,85,247,0.2)]">
                    Admin
                </span>
            @else
                <span class="px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider rounded-md bg-cyan-950/90 text-cyan-300 border border-cyan-700/60">
                    User
                </span>
            @endif
        </div>
    </div>
    @endauth

    <!-- Navigation Menu Items -->
    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-6 text-sm">
        <!-- GROUP 1: AFTE AUTOMATED TRADING -->
        <div>
            <div class="px-3 mb-2 text-[10px] font-bold font-mono uppercase tracking-wider text-slate-400 flex items-center justify-between">
                <span>Trading Engine</span>
                <span class="text-[9px] text-cyan-400/80">Binance USDⓈ-M</span>
            </div>
            <div class="space-y-1">
                <!-- Terminal Cockpit -->
                <a href="{{ route('dashboard') }}"
                   class="flex items-center justify-between px-3 py-2.5 rounded-xl font-medium transition {{ request()->routeIs('dashboard') ? 'bg-gradient-to-r from-cyan-500/20 to-blue-500/10 text-cyan-400 border border-cyan-500/30 shadow-[0_0_12px_rgba(6,182,212,0.15)]' : 'text-slate-300 hover:text-white hover:bg-cyber-700/50 border border-transparent' }}">
                    <div class="flex items-center space-x-3">
                        <svg class="w-4 h-4 {{ request()->routeIs('dashboard') ? 'text-cyan-400' : 'text-slate-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                        <span>Trading Terminal</span>
                    </div>
                    <span class="text-[10px] font-mono px-1.5 py-0.2 rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">Live</span>
                </a>

                <!-- Trade Ledger -->
                <a href="{{ route('history.index') }}"
                   class="flex items-center justify-between px-3 py-2.5 rounded-xl font-medium transition {{ request()->routeIs('history.*') ? 'bg-gradient-to-r from-cyan-500/20 to-blue-500/10 text-cyan-400 border border-cyan-500/30 shadow-[0_0_12px_rgba(6,182,212,0.15)]' : 'text-slate-300 hover:text-white hover:bg-cyber-700/50 border border-transparent' }}">
                    <div class="flex items-center space-x-3">
                        <svg class="w-4 h-4 {{ request()->routeIs('history.*') ? 'text-cyan-400' : 'text-slate-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                        </svg>
                        <span>Trade Ledger</span>
                    </div>
                    <span class="text-[10px] font-mono text-slate-400">History</span>
                </a>
            </div>
        </div>

        <!-- GROUP 2: CRYPTOLENS INTELLIGENCE -->
        <div>
            <div class="px-3 mb-2 text-[10px] font-bold font-mono uppercase tracking-wider text-slate-400 flex items-center justify-between">
                <span>CryptoLens SignalAlgo</span>
                <span class="text-[9px] text-emerald-400/80">PRO™</span>
            </div>
            <div class="space-y-1">
                <!-- Signals & Sentinel Cockpit -->
                <a href="{{ route('signals.dashboard') }}"
                   class="flex items-center justify-between px-3 py-2.5 rounded-xl font-medium transition {{ request()->routeIs('signals.*') ? 'bg-gradient-to-r from-emerald-500/20 to-teal-500/10 text-emerald-400 border border-emerald-500/30 shadow-[0_0_12px_rgba(16,185,129,0.15)]' : 'text-slate-300 hover:text-white hover:bg-cyber-700/50 border border-transparent' }}">
                    <div class="flex items-center space-x-3">
                        <svg class="w-4 h-4 {{ request()->routeIs('signals.*') ? 'text-emerald-400' : 'text-slate-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                        </svg>
                        <span>Signals &amp; Charts</span>
                    </div>
                    <span class="text-[10px] font-mono px-1.5 py-0.2 rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">PRO</span>
                </a>

                <!-- Telegram Alert Log -->
                <a href="{{ route('alerts.index') }}"
                   class="flex items-center justify-between px-3 py-2.5 rounded-xl font-medium transition {{ request()->routeIs('alerts.*') ? 'bg-gradient-to-r from-emerald-500/20 to-teal-500/10 text-emerald-400 border border-emerald-500/30 shadow-[0_0_12px_rgba(16,185,129,0.15)]' : 'text-slate-300 hover:text-white hover:bg-cyber-700/50 border border-transparent' }}">
                    <div class="flex items-center space-x-3">
                        <svg class="w-4 h-4 {{ request()->routeIs('alerts.*') ? 'text-emerald-400' : 'text-slate-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                        </svg>
                        <span>Telegram Alerts</span>
                    </div>
                    <span class="text-[10px] font-mono text-slate-400">Audit</span>
                </a>
            </div>
        </div>

        <!-- GROUP 3: ADMINISTRATION & SECURITY -->
        @if (Auth::user()?->isAdmin() || Auth::user()?->hasPermission('manage_users') || Auth::user()?->hasPermission('manage_roles'))
        <div>
            <div class="px-3 mb-2 text-[10px] font-bold font-mono uppercase tracking-wider text-purple-400 flex items-center justify-between">
                <span>Administration</span>
                <span class="text-[9px] text-purple-400/80">Access Control</span>
            </div>
            <div class="space-y-1">
                @if (Auth::user()?->isAdmin() || Auth::user()?->hasPermission('manage_users'))
                <a href="{{ route('admin.users.index') }}"
                   class="flex items-center justify-between px-3 py-2.5 rounded-xl font-medium transition {{ request()->routeIs('admin.users.*') ? 'bg-gradient-to-r from-purple-500/20 to-indigo-500/10 text-purple-300 border border-purple-500/30 shadow-[0_0_12px_rgba(168,85,247,0.15)]' : 'text-slate-300 hover:text-white hover:bg-cyber-700/50 border border-transparent' }}">
                    <div class="flex items-center space-x-3">
                        <svg class="w-4 h-4 {{ request()->routeIs('admin.users.*') ? 'text-purple-400' : 'text-slate-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                        </svg>
                        <span>User Management</span>
                    </div>
                    <span class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-purple-500/20 text-purple-300 border border-purple-500/30">2 Roles</span>
                </a>
                @endif

                @if (Auth::user()?->isAdmin() || Auth::user()?->hasPermission('manage_roles'))
                <a href="{{ route('admin.roles.index') }}"
                   class="flex items-center justify-between px-3 py-2.5 rounded-xl font-medium transition {{ request()->routeIs('admin.roles.*') ? 'bg-gradient-to-r from-purple-500/20 to-indigo-500/10 text-purple-300 border border-purple-500/30 shadow-[0_0_12px_rgba(168,85,247,0.15)]' : 'text-slate-300 hover:text-white hover:bg-cyber-700/50 border border-transparent' }}">
                    <div class="flex items-center space-x-3">
                        <svg class="w-4 h-4 {{ request()->routeIs('admin.roles.*') ? 'text-purple-400' : 'text-slate-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                        </svg>
                        <span>Role Permissions</span>
                    </div>
                    <span class="text-[10px] font-mono text-slate-400">Matrix</span>
                </a>
                @endif
            </div>
        </div>
        @endif
    </nav>

    <!-- Bottom Sidebar Section (Daemon status & Logout) -->
    <div class="p-3 border-t border-cyber-border bg-cyber-900/60 space-y-2">
        <!-- Sentinel Daemon Status Indicator -->
        <div id="sidebarSentinelBadge" class="p-2.5 rounded-xl bg-cyber-900 border border-cyber-border text-xs flex items-center justify-between">
            <div class="flex items-center space-x-2">
                <span id="sidebarSentinelDot" class="w-2 h-2 rounded-full bg-slate-500"></span>
                <span class="font-medium text-slate-300 text-[11px]">24/7 Sentinel</span>
            </div>
            <span id="sidebarSentinelStatus" class="font-mono text-[10px] text-slate-400 uppercase">OFFLINE</span>
        </div>

        <!-- Logout Button -->
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="w-full flex items-center justify-center space-x-2 px-3 py-2 rounded-xl text-xs font-semibold text-rose-400 hover:text-rose-300 bg-rose-950/20 hover:bg-rose-950/40 border border-rose-900/30 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
                <span>Sign Out</span>
            </button>
        </form>
    </div>
</aside>

<!-- Sidebar Overlay for Mobile -->
<div id="sidebarOverlay" onclick="toggleSidebar()" class="fixed inset-0 z-30 bg-black/60 backdrop-blur-sm hidden lg:hidden"></div>

<script>
    function toggleSidebar() {
        const sidebar = document.getElementById('appSidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (sidebar && overlay) {
            const isClosed = sidebar.classList.contains('-translate-x-full');
            if (isClosed) {
                sidebar.classList.remove('-translate-x-full');
                overlay.classList.remove('hidden');
            } else {
                sidebar.classList.add('-translate-x-full');
                overlay.classList.add('hidden');
            }
        }
    }

    // Poller for Sentinel Daemon status on sidebar
    (function() {
        function checkSidebarSentinel() {
            fetch('{{ route('daemon.status') }}', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => res.json())
            .then(data => {
                const dot = document.getElementById('sidebarSentinelDot');
                const status = document.getElementById('sidebarSentinelStatus');
                if (!dot || !status) return;

                if (data.is_running) {
                    dot.className = 'w-2 h-2 rounded-full bg-emerald-400 animate-pulse';
                    status.className = 'font-mono text-[10px] text-emerald-400 font-bold';
                    status.innerText = 'ONLINE';
                } else if (data.sentinel_enabled) {
                    dot.className = 'w-2 h-2 rounded-full bg-amber-400 animate-ping';
                    status.className = 'font-mono text-[10px] text-amber-400 font-bold';
                    status.innerText = 'STARTING';
                } else {
                    dot.className = 'w-2 h-2 rounded-full bg-slate-500';
                    status.className = 'font-mono text-[10px] text-slate-400';
                    status.innerText = 'STOPPED';
                }
            })
            .catch(() => {});
        }

        checkSidebarSentinel();
        setInterval(checkSidebarSentinel, 20000);
    })();
</script>
