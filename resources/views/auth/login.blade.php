<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terminal Login • AFTE Binance Futures</title>
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
            <div class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-cyan-500/10 border border-cyan-500/30 text-cyan-400 font-bold font-mono text-xl mb-1">
                ⚡
            </div>
            <h1 class="text-2xl font-bold tracking-wider text-white font-mono">AFTE TERMINAL</h1>
            <p class="text-xs text-slate-400">Binance Futures AI Engine • Authorized Personnel Access</p>
        </div>

        <!-- Card -->
        <div class="glass-panel rounded-2xl p-6 lg:p-8 shadow-2xl relative overflow-hidden">
            <div class="absolute -right-12 -top-12 w-32 h-32 bg-cyan-500/10 rounded-full blur-2xl pointer-events-none"></div>

            <!-- Status Alert -->
            @if (session('status'))
                <div class="mb-5 p-3 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs font-mono">
                    {{ session('status') }}
                </div>
            @endif

            <!-- Validation Errors -->
            @if ($errors->any())
                <div class="mb-5 p-3 rounded-lg bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs font-mono space-y-1">
                    @foreach ($errors->all() as $error)
                        <div>• {{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-4 text-xs font-mono">
                @csrf

                <!-- Email Address -->
                <div class="space-y-1">
                    <label for="email" class="text-slate-300 uppercase tracking-wider block">Trader Email</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                           placeholder="trader@autotrade.io"
                           class="w-full bg-cyber-900 border border-cyber-border rounded-lg px-3.5 py-2.5 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 transition">
                </div>

                <!-- Password -->
                <div class="space-y-1">
                    <div class="flex items-center justify-between">
                        <label for="password" class="text-slate-300 uppercase tracking-wider block">Master Password</label>
                    </div>
                    <input id="password" type="password" name="password" required autocomplete="current-password"
                           placeholder="••••••••••••"
                           class="w-full bg-cyber-900 border border-cyber-border rounded-lg px-3.5 py-2.5 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 transition">
                </div>

                <!-- Remember Me -->
                <div class="flex items-center justify-between pt-1">
                    <label class="flex items-center space-x-2 cursor-pointer">
                        <input type="checkbox" name="remember" class="rounded bg-cyber-900 border-cyber-border text-cyan-500 focus:ring-0">
                        <span class="text-slate-400 text-[11px]">Keep session active</span>
                    </label>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="w-full py-2.5 px-4 rounded-lg bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-500 hover:to-blue-500 text-white font-bold tracking-wider uppercase transition duration-200 flex items-center justify-center space-x-2 shadow-lg shadow-cyan-500/10">
                    <span>Unlock Terminal</span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                </button>
            </form>

            <div class="mt-6 pt-4 border-t border-cyber-border/60 text-center text-[11px] text-slate-400 font-mono">
                Need an account? <a href="{{ route('register') }}" class="text-cyan-400 hover:underline">Register New Trader</a>
            </div>
        </div>

        <div class="text-center text-[11px] text-slate-500 font-mono">
            Encrypted Session • HMAC-SHA256 Signed Security • Rate Limited
        </div>
    </div>
</body>
</html>
