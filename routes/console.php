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

// 1. Trading engine: manages open trades every 5s for ~50s, scans + trades after each candle close
Schedule::command('trade:engine')
    ->everyMinute()
    ->runInBackground();

// 2. Signal outcome tracking (feeds the measured win rates and the AI model)
Schedule::command('crypto:resolve-signals')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();

// 3. Nightly AI model retraining (activated only if it beats the current model)
Schedule::command('ai:train-signal-model')
    ->dailyAt('00:20')
    ->withoutOverlapping(60);

// 4. Daily Telegram summary (00:05 IST = 18:35 UTC)
Schedule::command('trade:daily-summary')
    ->dailyAt('18:35');

// 5. Daily ledger reconciliation against Binance fills
Schedule::command('trade:daily-reconcile')
    ->dailyAt('00:40')
    ->withoutOverlapping(30);

// 6. Queued jobs (database queue driver on shared hosting)
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping(1)
    ->runInBackground();
