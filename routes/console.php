<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Shared hosting: the hosting panel cron runs this every minute
|--------------------------------------------------------------------------
| * * * * * cd /path/to/afte && php artisan schedule:run >> /dev/null 2>&1
|
| No nohup / supervisor is needed. The engine reads the Paper/Live mode and the
| start/stop switch from the database every cycle.
*/

// 1. Trading engine: manages open trades every 5s for ~50s, runs the NEAR 1h breakout after each 1h close
Schedule::command('trade:engine')
    ->everyMinute()
    ->runInBackground();

// 2. Daily Telegram summary (00:05 IST = 18:35 UTC)
Schedule::command('trade:daily-summary')
    ->dailyAt('18:35');

// 3. Daily ledger reconciliation against Binance fills
Schedule::command('trade:daily-reconcile')
    ->dailyAt('00:40')
    ->withoutOverlapping(30);

// 4. Queued jobs (database queue driver on shared hosting)
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping(1)
    ->runInBackground();
