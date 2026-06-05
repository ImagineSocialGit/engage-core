<?php

namespace App\Providers;

use App\Services\Messaging\SmsProviderManager;
use App\Services\Messaging\SmsWebhookHandlerResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Twilio\Rest\Client;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Client::class, function () {
            return new Client(
                config('services.twilio.sid'),
                config('services.twilio.token'),
            );
        });

        $this->app->singleton(SmsWebhookHandlerResolver::class, function () {
            return SmsWebhookHandlerResolver::default();
        });

        $this->app->singleton(SmsProviderManager::class, function () {
            return SmsProviderManager::default();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('webinar-registration', function (Request $request) {
            return [
                Limit::perMinute(
                    config('webinars.registration.rate_limits.per_ip_per_minute')
                )->by($request->ip()),

                Limit::perHour(
                    config('webinars.registration.rate_limits.per_email_per_hour')
                )->by(
                    strtolower((string) $request->input('email'))
                ),
            ];
        });
    }
}
