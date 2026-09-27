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
                            border: '#233358',
                            accent: '#06b6d4'
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
            background: rgba(13, 19, 34, 0.9);
            backdrop-filter: blur(12px);
            border: 1px solid #233358;
        }
    </style>
</head>
<body class="bg-cyber-900 text-slate-200 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md space-y-6">
        <!-- Logo & Header -->
        <div class="text-center space-y-2">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-cyan-500/10 border border-cyan-500/30 p-2.5 mb-1 shadow-[0_0_20px_rgba(6,182,212,0.25)]">
                <img src="{{ asset('asset/logo.png') }}" alt="AFTE Logo" class="w-full h-full object-contain">
            </div>
            <h1 class="text-2xl font-bold tracking-wider text-white font-mono">REGISTER USER</h1>
            <p class="text-xs text-slate-400">Admin Console • Provision System User</p>
        </div>

        <!-- Card -->
        <div class="glass-panel rounded-2xl p-6 lg:p-8 shadow-2xl relative overflow-hidden">
            <div class="absolute -left-12 -top-12 w-32 h-32 bg-cyan-500/10 rounded-full blur-2xl pointer-events-none"></div>

            <!-- Validation Errors -->
            @if ($errors->any())
                <div class="mb-5 p-3 rounded-lg bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs font-mono space-y-1">
                    @foreach ($errors->all() as $error)
                        <div>• {{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('register') }}" class="space-y-4 text-xs font-mono">
                @csrf

                <!-- Name -->
                <div class="space-y-1">
                    <label for="name" class="text-slate-300 uppercase tracking-wider block">Full Name</label>
                    <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name"
                           placeholder="John Doe"
                           class="w-full bg-cyber-900 border border-cyber-border rounded-lg px-3.5 py-2.5 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 transition">
                </div>

                <!-- Email Address -->
                <div class="space-y-1">
                    <label for="email" class="text-slate-300 uppercase tracking-wider block">Email Address</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username"
                           placeholder="trader@autotrade.io"
                           class="w-full bg-cyber-900 border border-cyber-border rounded-lg px-3.5 py-2.5 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 transition">
                </div>

                <!-- Role Assignment -->
                <div class="space-y-1">
                    <label for="role" class="text-slate-300 uppercase tracking-wider block">Role Assignment</label>
                    <select id="role" name="role" required
                            class="w-full bg-cyber-900 border border-cyber-border rounded-lg px-3.5 py-2.5 text-slate-100 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 transition">
                        <option value="user" {{ old('role', 'user') === 'user' ? 'selected' : '' }}>User (Governed by permissions)</option>
                        <option value="admin" {{ old('role') === 'admin' ? 'selected' : '' }}>Administrator (Full Access)</option>
                    </select>
                </div>

                <!-- Password -->
                <div class="space-y-1">
                    <label for="password" class="text-slate-300 uppercase tracking-wider block">Password</label>
                    <input id="password" type="password" name="password" required autocomplete="new-password"
                           placeholder="Minimum 8 characters"
                           class="w-full bg-cyber-900 border border-cyber-border rounded-lg px-3.5 py-2.5 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 transition">
                </div>

                <!-- Confirm Password -->
                <div class="space-y-1">
                    <label for="password_confirmation" class="text-slate-300 uppercase tracking-wider block">Confirm Password</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"
                           placeholder="Repeat password"
                           class="w-full bg-cyber-900 border border-cyber-border rounded-lg px-3.5 py-2.5 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 transition">
                </div>

                <!-- Submit Button -->
                <button type="submit" class="w-full py-2.5 px-4 rounded-lg bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-500 hover:to-blue-500 text-white font-bold tracking-wider uppercase transition duration-200 flex items-center justify-center space-x-2 shadow-lg shadow-cyan-500/10 mt-2">
                    <span>Register Account</span>
                </button>
            </form>

            <div class="mt-6 pt-4 border-t border-cyber-border/60 flex items-center justify-between text-[11px] text-slate-400 font-mono">
                <a href="{{ route('admin.users.index') }}" class="text-cyan-400 hover:underline flex items-center space-x-1">
                    <span>← User Management</span>
                </a>
                <a href="{{ route('dashboard') }}" class="text-slate-400 hover:text-slate-200">
                    Terminal Cockpit →
                </a>
            </div>
        </div>
    </div>
</body>
</html>
