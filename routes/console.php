<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 1. 24/7 Autonomous Trading Engine: Continuously executes trades, manages positions & scans markets
Schedule::command('trade:daemon --mode=live --once')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground();

// 2. Crypto Sentinel Signal Watcher: Candle close monitor & Telegram alerts
Schedule::command('crypto:watch-signals --once')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground();
