<?php

use App\Http\Controllers\Admin\RolePermissionController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\AlertHistoryController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\CryptoSignalController;
use App\Http\Controllers\DaemonController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MarketScanController;
use App\Http\Controllers\TradeHistoryController;
use Illuminate\Support\Facades\Route;

// Guest Authentication Routes
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:10,1');
});

// Authenticated Terminal & Trading Routes
Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // 1. AFTE Trading Terminal Cockpit
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // 2. Dedicated Trade History Ledger
    Route::get('/history', [TradeHistoryController::class, 'index'])->name('history.index');
    Route::get('/history/export', [TradeHistoryController::class, 'exportCsv'])->name('history.export');

    // 3. AFTE Dashboard JSON APIs
    Route::prefix('api')->group(function (): void {
        Route::get('/stats', [DashboardController::class, 'stats'])->name('api.stats');
        Route::get('/positions', [DashboardController::class, 'positions'])->name('api.positions');
        Route::get('/signals', [DashboardController::class, 'signals'])->name('api.signals');
        Route::get('/history', [DashboardController::class, 'history'])->name('api.history');
        Route::get('/equity-curve', [DashboardController::class, 'equityCurve'])->name('api.equity_curve');
        Route::get('/scan', [DashboardController::class, 'scanMarket'])->name('api.scan');
        Route::post('/lock-breakeven', [DashboardController::class, 'lockBreakeven'])->name('api.lock_breakeven');
        Route::post('/close-position', [DashboardController::class, 'closePosition'])->name('api.close_position');
        Route::post('/kill-switch', [DashboardController::class, 'toggleKillSwitch'])->name('api.kill_switch');
        Route::post('/toggle-auto-trading', [DashboardController::class, 'toggleAutoTrading'])->name('api.toggle_auto_trading');
        Route::post('/auto-tick', [DashboardController::class, 'autoTick'])->name('api.auto_tick');
        Route::post('/backtest', [DashboardController::class, 'runBacktest'])->name('api.backtest');
    });

    // 4. CryptoLens Signals & Live Sentinel
    Route::get('/signals', [CryptoSignalController::class, 'index'])
        ->middleware('permission:view_signals')
        ->name('signals.dashboard');
    Route::get('/signals/analyze', [CryptoSignalController::class, 'analyze'])
        ->middleware('permission:view_signals')
        ->name('signals.analyze');
    Route::get('/dashboard/analyze', [CryptoSignalController::class, 'analyze'])
        ->middleware('permission:view_signals')
        ->name('dashboard.analyze');
    Route::post('/signals/send-alert', [CryptoSignalController::class, 'sendAlert'])
        ->middleware('permission:send_alerts')
        ->name('signals.send_alert');
    Route::post('/dashboard/send-alert', [CryptoSignalController::class, 'sendAlert'])
        ->middleware('permission:send_alerts')
        ->name('dashboard.send-alert');

    // 5. Telegram Alert History
    Route::get('/alerts', [AlertHistoryController::class, 'index'])
        ->middleware('permission:view_alerts')
        ->name('alerts.index');

    // 6. 24/7 Sentinel Watcher Daemon API
    Route::prefix('daemon')->group(function (): void {
        Route::get('/status', [DaemonController::class, 'status'])->name('daemon.status');
        Route::post('/start', [DaemonController::class, 'start'])
            ->middleware('permission:manage_sentinel')
            ->name('daemon.start');
        Route::post('/stop', [DaemonController::class, 'stop'])
            ->middleware('permission:manage_sentinel')
            ->name('daemon.stop');
        Route::get('/monitored-coins', [DaemonController::class, 'getMonitoredCoins'])->name('daemon.monitored-coins');
        Route::post('/monitored-coins/add', [DaemonController::class, 'addMonitoredCoin'])
            ->middleware('permission:manage_sentinel')
            ->name('daemon.monitored-coins.add');
        Route::post('/monitored-coins/remove', [DaemonController::class, 'removeMonitoredCoin'])
            ->middleware('permission:manage_sentinel')
            ->name('daemon.monitored-coins.remove');
        Route::post('/monitored-coins/reset', [DaemonController::class, 'resetMonitoredCoins'])
            ->middleware('permission:manage_sentinel')
            ->name('daemon.monitored-coins.reset');
        Route::post('/monitored-coins/mode', [DaemonController::class, 'setScanMode'])
            ->middleware('permission:manage_sentinel')
            ->name('daemon.monitored-coins.mode');
    });

    // 7. Whole-Market Scan API
    Route::prefix('market-scan')->group(function (): void {
        Route::get('/status', [MarketScanController::class, 'status'])->name('market-scan.status');
        Route::post('/start', [MarketScanController::class, 'start'])
            ->middleware('permission:trigger_scans')
            ->name('market-scan.start');
        Route::post('/stop', [MarketScanController::class, 'stop'])
            ->middleware('permission:trigger_scans')
            ->name('market-scan.stop');
        Route::post('/clear', [MarketScanController::class, 'clear'])
            ->middleware('permission:trigger_scans')
            ->name('market-scan.clear');
    });

    // 8. Administration (Admin Role Required)
    Route::prefix('admin')->middleware('admin')->name('admin.')->group(function (): void {
        // User Management
        Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
        Route::post('/users', [UserManagementController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserManagementController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserManagementController::class, 'destroy'])->name('users.destroy');

        // Role & Permission Matrix
        Route::get('/roles', [RolePermissionController::class, 'index'])->name('roles.index');
        Route::post('/roles', [RolePermissionController::class, 'update'])->name('roles.update');
    });
});
