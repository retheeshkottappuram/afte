<?php

namespace App\Providers;

use App\Services\Breakout\EbdConfig;
use App\Services\Crypto\TelegramNotifier;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TelegramNotifier::class, function () {
            return new TelegramNotifier;
        });

        // Read per resolution so config changes (tests, config:cache) are always picked up.
        $this->app->bind(EbdConfig::class, fn (): EbdConfig => EbdConfig::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
