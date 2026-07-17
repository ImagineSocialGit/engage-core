<?php

namespace App\Modules\Webinars\Providers;

use App\Modules\Core\Support\Contacts\ContactPanelRegistry;
use App\Modules\Webinars\ConfigContracts\WebinarMessageAreaConfigContract;
use App\Modules\Webinars\ConfigContracts\WebinarPostEventConfigContract;
use App\Modules\Webinars\ConfigContracts\WebinarScheduleProfileConfigContract;
use App\Modules\Webinars\ConfigContracts\WebinarsConfigContractTargetProvider;
use App\Modules\Webinars\Console\Commands\ImportWebinarRegistrationsCommand;
use App\Modules\Webinars\Console\Commands\SyncWebinarScheduleProfilesCommand;
use App\Modules\Webinars\Services\ContactPanels\WebinarContactPanelProvider;
use App\Modules\Webinars\Services\Dashboard\WebinarActivityDashboardPanelProvider;
use App\Modules\Webinars\TokenContracts\WebinarTokenContextProvider;
use App\Modules\Webinars\TokenContracts\WebinarTokenSourceProvider;
use App\Modules\Webinars\Validation\WebinarsSetupValidationContributor;
use App\Support\Dashboard\DashboardPanelRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class WebinarsModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([
            WebinarMessageAreaConfigContract::class,
            WebinarPostEventConfigContract::class,
            WebinarScheduleProfileConfigContract::class,
        ], 'config.contracts');

        $this->app->tag(
            WebinarsConfigContractTargetProvider::class,
            'config.contract_target_providers',
        );
        $this->app->tag(WebinarTokenSourceProvider::class, 'token.source_providers');
        $this->app->tag(WebinarTokenContextProvider::class, 'token.context_providers');

        $this->app->tag([
            WebinarActivityDashboardPanelProvider::class,
        ], DashboardPanelRegistry::providerTag());

        $this->app->tag([
            WebinarsSetupValidationContributor::class,
        ], 'setup.validation_contributors');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ImportWebinarRegistrationsCommand::class,
                SyncWebinarScheduleProfilesCommand::class,
            ]);
        }

        $this->app->make(ContactPanelRegistry::class)
            ->register(WebinarContactPanelProvider::class, 'webinars');

        $this->registerPublicLimiter('webinar-registration', 'registration');
        $this->registerPublicLimiter('webinar-waitlist', 'waitlist');
    }

    private function registerPublicLimiter(string $limiterName, string $flow): void
    {
        RateLimiter::for($limiterName, function (Request $request) use ($flow) {
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

            $seriesSlug = $this->seriesSlug($request);
            $limits = [
                Limit::perMinute($perIpPerMinute)
                    ->by('webinar-public:ip:'.$this->hashedRateLimitIdentity(
                        'webinar-public:ip:'.(string) $request->ip(),
                    ))
                    ->response($this->webinarRegistrationThrottleResponse(
                        'email',
                        'Too many attempts. Please wait a moment and try again.'
                    )),
            ];

            $email = strtolower(trim((string) $request->input('email')));

            if ($email !== '') {
                $limits[] = Limit::perHour($perEmailPerHour)
                    ->by('webinar-public:email:'.$this->hashedRateLimitIdentity(sprintf(
                        'webinar:%s:%s:email:%s',
                        $flow,
                        $seriesSlug,
                        $email,
                    )))
                    ->response($this->webinarRegistrationThrottleResponse(
                        'email',
                        'Too many attempts for this email. Please wait a moment and try again.'
                    ));
            }

            $phone = preg_replace('/\D+/', '', (string) $request->input('phone'));

            if (is_string($phone) && $phone !== '') {
                $limits[] = Limit::perHour($perPhonePerHour)
                    ->by('webinar-public:phone:'.$this->hashedRateLimitIdentity(sprintf(
                        'webinar:%s:%s:phone:%s',
                        $flow,
                        $seriesSlug,
                        $phone,
                    )))
                    ->response($this->webinarRegistrationThrottleResponse(
                        'phone',
                        'Too many attempts for this phone number. Please wait a moment and try again.'
                    ));
            }

            return $limits;
        });
    }

    private function seriesSlug(Request $request): string
    {
        $seriesSlug = $request->route('seriesSlug');

        return is_string($seriesSlug) && trim($seriesSlug) !== ''
            ? strtolower(trim($seriesSlug))
            : 'unknown';
    }

    private function hashedRateLimitIdentity(string $material): string
    {
        $key = config('app.key');

        if (! is_string($key) || trim($key) === '') {
            $key = 'engage-core-webinar-rate-limiter';
        }

        return hash_hmac('sha256', $material, $key);
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
