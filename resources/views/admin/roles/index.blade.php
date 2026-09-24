@extends('layouts.app')

@section('title', 'Role & Permission Matrix')

@section('content')
<div class="p-4 lg:p-8 space-y-6 max-w-7xl mx-auto">
    <!-- Header banner -->
    <div class="p-6 rounded-2xl glass-panel border border-cyber-border shadow-xl flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <div class="flex items-center space-x-3">
                <span class="px-2.5 py-1 text-xs font-bold uppercase tracking-wider bg-purple-950/80 text-purple-300 border border-purple-800 rounded-lg">
                    Security Governance
                </span>
                <h1 class="text-2xl lg:text-3xl font-black text-white tracking-tight">
                    Role &amp; Permission Matrix
                </h1>
            </div>
            <p class="mt-2 text-sm text-slate-400">
                Configure granular platform capabilities for <strong class="text-cyan-300 font-semibold">Standard Users</strong> while administrators retain root access.
            </p>
        </div>

        <div class="flex items-center space-x-2">
            <span class="px-3 py-1.5 rounded-xl bg-purple-950/60 border border-purple-800/80 text-purple-300 text-xs font-mono font-semibold flex items-center space-x-2">
                <span class="w-2 h-2 rounded-full bg-purple-400 animate-pulse"></span>
                <span>Active Roles: Admin &bull; User</span>
            </span>
        </div>
    </div>

    <!-- Role Explanation Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="p-5 rounded-2xl glass-panel border border-purple-800/60">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-2.5">
                    <span class="w-8 h-8 rounded-lg bg-purple-500/20 border border-purple-500/40 flex items-center justify-center text-purple-300 font-bold">🛡️</span>
                    <div>
                        <h2 class="text-sm font-bold text-white">Administrator (Admin)</h2>
                        <p class="text-[11px] text-purple-300 font-mono">Full Superuser Access</p>
                    </div>
                </div>
                <span class="px-2.5 py-0.5 rounded text-[10px] font-bold bg-purple-950 text-purple-300 border border-purple-700">Root Locked</span>
            </div>
            <p class="mt-3 text-xs text-slate-400 leading-relaxed">
                Admins bypass all permission gates by default. They can manage all users, edit permission assignments, execute trades, start/stop background daemons, and trigger whole-market sweeps.
            </p>
        </div>

        <div class="p-5 rounded-2xl glass-panel border border-cyan-800/60">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-2.5">
                    <span class="w-8 h-8 rounded-lg bg-cyan-500/20 border border-cyan-500/40 flex items-center justify-center text-cyan-300 font-bold">👤</span>
                    <div>
                        <h2 class="text-sm font-bold text-white">Standard User (User)</h2>
                        <p class="text-[11px] text-cyan-300 font-mono">Granular RBAC Governed</p>
                    </div>
                </div>
                <span class="px-2.5 py-0.5 rounded text-[10px] font-bold bg-cyan-950 text-cyan-300 border border-cyan-700">Customizable</span>
            </div>
            <p class="mt-3 text-xs text-slate-400 leading-relaxed">
                Users only have access to modules enabled in the matrix below. Check or uncheck permissions to grant or revoke specific feature capabilities instantly.
            </p>
        </div>
    </div>

    <!-- Permission Matrix Form -->
    <form method="POST" action="{{ route('admin.roles.update') }}">
        @csrf
        <div class="glass-panel rounded-2xl border border-cyber-border overflow-hidden shadow-2xl">
            <div class="p-5 border-b border-cyber-border bg-cyber-900/80 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-bold text-white">Capabilities &amp; Feature Privileges</h3>
                    <p class="text-xs text-slate-400 mt-0.5">Toggle privileges granted to the <span class="text-cyan-300 font-mono font-bold">User</span> role.</p>
                </div>
                <div class="flex items-center space-x-2">
                    <button type="button" onclick="toggleAllCheckboxes(true)" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-cyber-700 text-slate-300 hover:text-white transition">Select All</button>
                    <button type="button" onclick="toggleAllCheckboxes(false)" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-cyber-700 text-slate-300 hover:text-white transition">Deselect All</button>
                    <button type="submit" class="px-5 py-2 rounded-xl text-xs font-bold text-white bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-500 hover:to-blue-500 shadow-lg shadow-cyan-900/30 border border-cyan-500/40 transition">
                        Save Permissions
                    </button>
                </div>
            </div>

            <div class="divide-y divide-cyber-border">
                @foreach ($permissions as $category => $perms)
                    <div class="p-6 bg-cyber-800/30">
                        <div class="flex items-center space-x-2 mb-4">
                            <span class="w-2 h-2 rounded-full bg-cyan-400"></span>
                            <h4 class="text-xs font-mono font-bold uppercase tracking-wider text-cyan-300">{{ $category }}</h4>
                            <span class="text-[10px] text-slate-500">({{ count($perms) }} privileges)</span>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            @foreach ($perms as $perm)
                                @php
                                    $isUserChecked = in_array($perm->slug, $userPermissionSlugs, true);
                                @endphp
                                <label class="flex items-start p-4 rounded-xl bg-cyber-900/70 border border-cyber-border/80 hover:border-cyan-500/40 cursor-pointer transition select-none group">
                                    <div class="flex items-center h-5 mr-3">
                                        <input type="checkbox" name="permissions[]" value="{{ $perm->slug }}" {{ $isUserChecked ? 'checked' : '' }} class="perm-checkbox w-4 h-4 rounded bg-cyber-800 border-cyber-border text-cyan-500 focus:ring-cyan-500 focus:ring-offset-cyber-900">
                                    </div>
                                    <div class="min-w-0">
                                        <div class="text-xs font-bold text-slate-200 group-hover:text-cyan-300 transition">{{ $perm->name }}</div>
                                        <div class="text-[11px] text-slate-400 mt-1 leading-snug">{{ $perm->description }}</div>
                                        <div class="mt-2 text-[9px] font-mono text-slate-500">slug: {{ $perm->slug }}</div>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="p-5 border-t border-cyber-border bg-cyber-900/80 flex items-center justify-end">
                <button type="submit" class="px-6 py-2.5 rounded-xl text-xs font-bold text-white bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-500 hover:to-blue-500 shadow-lg shadow-cyan-900/30 border border-cyan-500/40 transition">
                    Save Permission Matrix
                </button>
            </div>
        </div>
    </form>
</div>

<script>
    function toggleAllCheckboxes(state) {
        document.querySelectorAll('.perm-checkbox').forEach(cb => {
            cb.checked = state;
        });
    }
</script>
@endsection
