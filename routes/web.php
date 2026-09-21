<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TradeHistoryController;
use Illuminate\Support\Facades\Route;

// Guest Authentication Routes
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);
});

// Authenticated Terminal & Trading Routes
Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // Web Trading Terminal Cockpit
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Dedicated Trade History Ledger
    Route::get('/history', [TradeHistoryController::class, 'index'])->name('history.index');
    Route::get('/history/export', [TradeHistoryController::class, 'exportCsv'])->name('history.export');

    // Dashboard JSON APIs
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
});
