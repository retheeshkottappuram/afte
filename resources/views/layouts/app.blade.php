<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'AFTE Trading & Signals Platform') • Binance Futures</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">

    <!-- Tailwind CSS CDN -->
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
    <style>
        body { font-family: 'Inter', sans-serif; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        .glass-panel {
            background: rgba(13, 19, 34, 0.85);
            backdrop-filter: blur(12px);
            border: 1px solid #233358;
        }
        @keyframes pulse-slow {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }
        .animate-pulse-slow { animation: pulse-slow 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
    </style>
    @stack('styles')
</head>
<body class="bg-cyber-900 text-slate-200 min-h-screen antialiased flex flex-col">
    <div class="flex-1 flex min-h-screen">
        <!-- Unified Left Sidebar -->
        @include('layouts.sidebar')

        <!-- Main Wrapper (Shifted right by 16rem / 64 tailwind units on lg screens) -->
        <div class="lg:pl-64 flex flex-col flex-1 min-w-0">
            <!-- Mobile Top Bar with Hamburger -->
            <header class="lg:hidden border-b border-cyber-border bg-cyber-800/90 sticky top-0 z-20 backdrop-blur-md px-4 py-3 flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <button type="button" onclick="toggleSidebar()" class="p-2 rounded-xl text-slate-300 hover:text-white bg-cyber-700/60 border border-cyber-border focus:outline-none">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                    </button>
                    <a href="{{ route('dashboard') }}" class="flex items-center space-x-2">
                        <span class="w-7 h-7 rounded-lg bg-cyan-500/20 border border-cyan-500/40 flex items-center justify-center text-cyan-400 font-bold text-xs">⚡</span>
                        <span class="font-extrabold text-sm text-white tracking-wider">AFTE <span class="text-cyan-400">PRO</span></span>
                    </a>
                </div>

                <div class="flex items-center space-x-2">
                    @auth
                        @if (Auth::user()->isAdmin())
                            <span class="px-2 py-0.5 text-[10px] font-bold uppercase rounded bg-purple-950 text-purple-300 border border-purple-800">Admin</span>
                        @else
                            <span class="px-2 py-0.5 text-[10px] font-bold uppercase rounded bg-cyan-950 text-cyan-300 border border-cyan-800">User</span>
                        @endif
                    @endauth
                </div>
            </header>

            <!-- Notification Messages -->
            @if (session('success') || session('status'))
                <div class="mx-4 lg:mx-8 mt-4">
                    <div class="p-4 rounded-xl bg-emerald-950/80 border border-emerald-700/60 text-emerald-300 text-sm flex items-center shadow-lg">
                        <svg class="w-5 h-5 mr-3 flex-shrink-0 text-emerald-400" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                        </svg>
                        <span>{{ session('success') ?? session('status') }}</span>
                    </div>
                </div>
            @endif

            @if (session('error'))
                <div class="mx-4 lg:mx-8 mt-4">
                    <div class="p-4 rounded-xl bg-rose-950/80 border border-rose-700/60 text-rose-300 text-sm flex items-center shadow-lg">
                        <svg class="w-5 h-5 mr-3 flex-shrink-0 text-rose-400" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                        <span>{{ session('error') }}</span>
                    </div>
                </div>
            @endif

            <!-- Main Content Outlet -->
            <main class="flex-1">
                @yield('content')
            </main>
        </div>
    </div>

    @stack('scripts')
</body>
</html>
