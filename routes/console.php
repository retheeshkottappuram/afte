<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 1. 24/7 Autonomous Trading Engine: Continuously executes trades, manages positions & scans markets
$tradingMode = (string) config('trading.mode', 'live');
Schedule::command("trade:daemon --mode={$tradingMode} --start --once")
    ->everyMinute()
    ->withoutOverlapping(1)
    ->runInBackground();

// 2. Crypto Sentinel Signal Watcher: Candle close monitor & Telegram alerts
Schedule::command('crypto:watch-signals --once')
    ->everyMinute()
    ->withoutOverlapping(1)
    ->runInBackground();

// 3. Process queued jobs (database queue driver on shared hosting)
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping(1)
    ->runInBackground();
