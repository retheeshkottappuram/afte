@extends('layouts.app')

@section('title', 'User Management')

@section('content')
<div class="p-4 lg:p-8 space-y-6 max-w-7xl mx-auto">
    <!-- Header banner -->
    <div class="p-6 rounded-2xl glass-panel border border-cyber-border shadow-xl flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <div class="flex items-center space-x-3">
                <span class="px-2.5 py-1 text-xs font-bold uppercase tracking-wider bg-purple-950/80 text-purple-300 border border-purple-800 rounded-lg">
                    Admin Zone
                </span>
                <h1 class="text-2xl lg:text-3xl font-black text-white tracking-tight">
                    User Management
                </h1>
            </div>
            <p class="mt-2 text-sm text-slate-400">
                Manage system users, assign <strong class="text-purple-300 font-semibold">Admin</strong> and <strong class="text-cyan-300 font-semibold">User</strong> roles, and govern platform access.
            </p>
        </div>

        <div class="flex items-center space-x-3">
            <button type="button" onclick="document.getElementById('createUserModal').classList.remove('hidden')" class="px-4 py-2.5 text-xs font-bold text-white bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 rounded-xl shadow-lg shadow-purple-900/30 border border-purple-500/40 flex items-center space-x-2 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                <span>Add New User</span>
            </button>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="p-4 rounded-xl glass-panel border border-cyber-border">
            <div class="text-xs font-semibold uppercase tracking-wider text-slate-400">Total Users</div>
            <div class="text-2xl font-black text-white mt-1">{{ number_format($stats['total']) }}</div>
            <p class="text-[11px] text-slate-500 mt-0.5">Registered accounts</p>
        </div>
        <div class="p-4 rounded-xl glass-panel border border-purple-900/50">
            <div class="text-xs font-semibold uppercase tracking-wider text-purple-400">Administrators</div>
            <div class="text-2xl font-black text-purple-300 mt-1">{{ number_format($stats['admins']) }}</div>
            <p class="text-[11px] text-slate-500 mt-0.5">Full administrative privilege</p>
        </div>
        <div class="p-4 rounded-xl glass-panel border border-cyan-900/50">
            <div class="text-xs font-semibold uppercase tracking-wider text-cyan-400">Standard Users</div>
            <div class="text-2xl font-black text-cyan-300 mt-1">{{ number_format($stats['users']) }}</div>
            <p class="text-[11px] text-slate-500 mt-0.5">Governed by permission matrix</p>
        </div>
    </div>

    <!-- Filter & Search Bar -->
    <div class="p-4 rounded-xl glass-panel border border-cyber-border flex flex-wrap items-center justify-between gap-4">
        <form method="GET" action="{{ route('admin.users.index') }}" class="flex flex-wrap items-center gap-3 w-full sm:w-auto flex-1">
            <div class="relative flex-1 min-w-[200px] max-w-md">
                <input type="text" name="search" value="{{ $search }}" placeholder="Search by name or email..." class="w-full bg-cyber-900 border border-cyber-border rounded-xl px-3.5 py-2 text-xs text-slate-200 placeholder-slate-500 focus:outline-none focus:border-cyan-500">
            </div>

            <select name="role" class="bg-cyber-900 border border-cyber-border rounded-xl px-3 py-2 text-xs text-slate-200 focus:outline-none focus:border-cyan-500">
                <option value="all" {{ $roleFilter === 'all' || empty($roleFilter) ? 'selected' : '' }}>All Roles</option>
                <option value="admin" {{ $roleFilter === 'admin' ? 'selected' : '' }}>Admin Only</option>
                <option value="user" {{ $roleFilter === 'user' ? 'selected' : '' }}>User Only</option>
            </select>

            <button type="submit" class="px-4 py-2 text-xs font-semibold bg-cyber-700 hover:bg-cyber-600 text-slate-200 rounded-xl border border-cyber-border transition">
                Filter
            </button>

            @if (! empty($search) || ! empty($roleFilter))
                <a href="{{ route('admin.users.index') }}" class="text-xs text-slate-400 hover:text-white underline">
                    Reset
                </a>
            @endif
        </form>
    </div>

    <!-- Users Table -->
    <div class="glass-panel rounded-2xl border border-cyber-border overflow-hidden shadow-xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-cyber-900/80 border-b border-cyber-border text-slate-400 uppercase font-mono text-[11px]">
                    <tr>
                        <th class="py-3 px-4">User</th>
                        <th class="py-3 px-4">Role</th>
                        <th class="py-3 px-4">Registered At</th>
                        <th class="py-3 px-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-cyber-border/60">
                    @forelse ($users as $u)
                        <tr class="hover:bg-cyber-800/40 transition">
                            <td class="py-3 px-4">
                                <div class="flex items-center space-x-3">
                                    <div class="w-8 h-8 rounded-lg bg-cyber-700/80 border border-cyber-border flex items-center justify-center font-bold text-slate-200 uppercase text-xs">
                                        {{ substr($u->name, 0, 2) }}
                                    </div>
                                    <div>
                                        <div class="font-bold text-slate-200">{{ $u->name }}</div>
                                        <div class="text-[11px] text-slate-400 font-mono">{{ $u->email }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 px-4">
                                @if ($u->isAdmin())
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-purple-950/80 text-purple-300 border border-purple-700/80">
                                        Admin
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-cyan-950/80 text-cyan-300 border border-cyan-700/80">
                                        User
                                    </span>
                                @endif
                            </td>
                            <td class="py-3 px-4 text-slate-400 font-mono text-[11px]">
                                {{ $u->created_at?->format('M d, Y H:i') ?? 'N/A' }}
                            </td>
                            <td class="py-3 px-4 text-right space-x-2">
                                <!-- Edit Button -->
                                <button type="button" onclick="openEditModal({{ $u->id }}, '{{ addslashes($u->name) }}', '{{ addslashes($u->email) }}', '{{ $u->role }}')" class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-cyber-700 hover:bg-cyber-600 text-slate-200 border border-cyber-border transition">
                                    Edit
                                </button>

                                <!-- Delete Button -->
                                @if ($u->id !== Auth::id())
                                    <form method="POST" action="{{ route('admin.users.destroy', $u) }}" class="inline" onsubmit="return confirm('Are you sure you want to delete user {{ $u->name }}?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-rose-950/50 hover:bg-rose-900/60 text-rose-300 border border-rose-800/60 transition">
                                            Delete
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-8 text-center text-slate-500">
                                No users found matching your search criteria.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($users->hasPages())
            <div class="p-4 border-t border-cyber-border bg-cyber-900/60">
                {{ $users->links() }}
            </div>
        @endif
    </div>
</div>

<!-- Create User Modal -->
<div id="createUserModal" class="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="glass-panel bg-cyber-800 border border-cyber-border rounded-2xl w-full max-w-md p-6 shadow-2xl relative">
        <div class="flex items-center justify-between pb-4 border-b border-cyber-border">
            <h3 class="text-base font-bold text-white flex items-center space-x-2">
                <span class="w-2 h-2 rounded-full bg-purple-400"></span>
                <span>Create New User</span>
            </h3>
            <button type="button" onclick="document.getElementById('createUserModal').classList.add('hidden')" class="text-slate-400 hover:text-white">✕</button>
        </div>

        <form method="POST" action="{{ route('admin.users.store') }}" class="mt-4 space-y-4">
            @csrf
            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Full Name</label>
                <input type="text" name="name" required class="w-full bg-cyber-900 border border-cyber-border rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-purple-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Email Address</label>
                <input type="email" name="email" required class="w-full bg-cyber-900 border border-cyber-border rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-purple-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Role Assignment</label>
                <select name="role" required class="w-full bg-cyber-900 border border-cyber-border rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-purple-500">
                    <option value="user">User (Governed by Permissions)</option>
                    <option value="admin">Admin (Full Control)</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Password</label>
                <input type="password" name="password" required class="w-full bg-cyber-900 border border-cyber-border rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-purple-500">
            </div>

            <div class="pt-4 border-t border-cyber-border flex justify-end space-x-3">
                <button type="button" onclick="document.getElementById('createUserModal').classList.add('hidden')" class="px-4 py-2 text-xs font-semibold bg-cyber-700 text-slate-300 rounded-xl hover:bg-cyber-600 transition">Cancel</button>
                <button type="submit" class="px-4 py-2 text-xs font-bold text-white bg-purple-600 hover:bg-purple-500 rounded-xl shadow-lg transition">Create Account</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" class="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="glass-panel bg-cyber-800 border border-cyber-border rounded-2xl w-full max-w-md p-6 shadow-2xl relative">
        <div class="flex items-center justify-between pb-4 border-b border-cyber-border">
            <h3 class="text-base font-bold text-white flex items-center space-x-2">
                <span class="w-2 h-2 rounded-full bg-cyan-400"></span>
                <span>Edit User Details</span>
            </h3>
            <button type="button" onclick="document.getElementById('editUserModal').classList.add('hidden')" class="text-slate-400 hover:text-white">✕</button>
        </div>

        <form id="editUserForm" method="POST" action="" class="mt-4 space-y-4">
            @csrf
            @method('PUT')
            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Full Name</label>
                <input type="text" id="editName" name="name" required class="w-full bg-cyber-900 border border-cyber-border rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-cyan-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Email Address</label>
                <input type="email" id="editEmail" name="email" required class="w-full bg-cyber-900 border border-cyber-border rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-cyan-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Role Assignment</label>
                <select id="editRole" name="role" required class="w-full bg-cyber-900 border border-cyber-border rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-cyan-500">
                    <option value="user">User (Governed by Permissions)</option>
                    <option value="admin">Admin (Full Control)</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Change Password <span class="text-slate-500 font-normal">(leave blank to keep current)</span></label>
                <input type="password" name="password" placeholder="••••••••" class="w-full bg-cyber-900 border border-cyber-border rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-cyan-500">
            </div>

            <div class="pt-4 border-t border-cyber-border flex justify-end space-x-3">
                <button type="button" onclick="document.getElementById('editUserModal').classList.add('hidden')" class="px-4 py-2 text-xs font-semibold bg-cyber-700 text-slate-300 rounded-xl hover:bg-cyber-600 transition">Cancel</button>
                <button type="submit" class="px-4 py-2 text-xs font-bold text-white bg-cyan-600 hover:bg-cyan-500 rounded-xl shadow-lg transition">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openEditModal(id, name, email, role) {
        const form = document.getElementById('editUserForm');
        form.action = '{{ url('admin/users') }}/' + id;
        document.getElementById('editName').value = name;
        document.getElementById('editEmail').value = email;
        document.getElementById('editRole').value = (role === 'admin') ? 'admin' : 'user';
        document.getElementById('editUserModal').classList.remove('hidden');
    }
</script>
@endsection
