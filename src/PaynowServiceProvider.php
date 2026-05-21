<?php

namespace Bpotmalnik\LunarPaynow;

use Bpotmalnik\LunarPaynow\Http\Controllers\PaynowNotificationController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Lunar\Facades\Payments;

class PaynowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/lunar/paynow.php',
            'lunar.paynow'
        );

        $this->app->singleton(PaynowClient::class, function () {
            return new PaynowClient(
                apiKey: config('lunar.paynow.api_key', ''),
                signatureKey: config('lunar.paynow.signature_key', ''),
                sandbox: (bool) config('lunar.paynow.sandbox', false),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/lunar/paynow.php' => config_path('lunar/paynow.php'),
            ], 'lunar-paynow-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'lunar-paynow-migrations');

            $this->publishes([
                __DIR__.'/../resources/lang' => lang_path('vendor/lunar-paynow'),
            ], 'lunar-paynow-lang');
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'lunar-paynow');

        $this->registerRoutes();

        Payments::extend('paynow', function ($app) {
            return $app->make(PaynowPaymentDriver::class);
        });
    }

    private function registerRoutes(): void
    {
        Route::post(
            config('lunar.paynow.notification_path', 'paynow/notification'),
            PaynowNotificationController::class
        )->name('paynow.notification');
    }
}
