<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Monitor all managed coin charts continuously every minute
Schedule::command('crypto:watch-signals --once')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
