<?php

namespace App\Providers;

use App\Services\Messaging\Email\EmailProviderManager;
use App\Services\Messaging\Email\EmailWebhookHandlerResolver;
use App\Services\Messaging\Sms\SmsProviderManager;
use App\Services\Messaging\Sms\SmsWebhookHandlerResolver;
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
        $this->mergeConfigFrom(config_path('messaging/sms.php'), 'messaging.sms');
        $this->mergeConfigFrom(config_path('messaging/email.php'), 'messaging.email');

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

        $this->app->singleton(EmailProviderManager::class);

        $this->app->singleton(EmailWebhookHandlerResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('webinar-registration', function (Request $request) {
            $perIpPerMinute = max(
                1,
                (int) config('webinars.registration.rate_limits.per_ip_per_minute', 5)
            );

            $perEmailPerHour = max(
                1,
                (int) config('webinars.registration.rate_limits.per_email_per_hour', 3)
            );

            $perPhonePerHour = max(
                1,
                (int) config('webinars.registration.rate_limits.per_phone_per_hour', $perEmailPerHour)
            );

            $limits = [
                Limit::perMinute($perIpPerMinute)
                    ->by('webinar-registration:ip:'.$request->ip())
                    ->response($this->webinarRegistrationThrottleResponse(
                        'email',
                        'Too many registration attempts. Please wait a moment and try again.'
                    )),
            ];

            $email = strtolower(trim((string) $request->input('email')));

            if ($email !== '') {
                $limits[] = Limit::perHour($perEmailPerHour)
                    ->by('webinar-registration:email:'.$email)
                    ->response($this->webinarRegistrationThrottleResponse(
                        'email',
                        'Too many registration attempts for this email. Please wait a moment and try again.'
                    ));
            }

            $phone = preg_replace('/\D+/', '', (string) $request->input('phone'));

            if ($phone !== '') {
                $limits[] = Limit::perHour($perPhonePerHour)
                    ->by('webinar-registration:phone:'.$phone)
                    ->response($this->webinarRegistrationThrottleResponse(
                        'phone',
                        'Too many registration attempts for this phone number. Please wait a moment and try again.'
                    ));
            }

            return $limits;
        });
    }

    private function webinarRegistrationThrottleResponse(string $field, string $message): callable
    {
        return function (Request $request, array $headers) use ($field, $message) {
            return redirect()
                ->back()
                ->withInput($request->except('_token'))
                ->withErrors([
                    $field => $message,
                ])
                ->withHeaders($headers);
        };
    }
}