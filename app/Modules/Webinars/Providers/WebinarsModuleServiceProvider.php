<?php

namespace App\Modules\Webinars\Providers;

use App\Modules\Core\Support\Contacts\ContactPanelRegistry;
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

        RateLimiter::for('webinar-registration', fn (Request $request): array =>
            $this->publicFormLimits($request, 'registration')
        );

        RateLimiter::for('webinar-waitlist', fn (Request $request): array =>
            $this->publicFormLimits($request, 'waitlist')
        );

    }
    /** @return array<int, Limit> */
    private function publicFormLimits(Request $request, string $flow): array
    {
        $seriesSlug = strtolower(trim((string) $request->route('seriesSlug')));
        $seriesKey = $seriesSlug !== '' ? $seriesSlug : 'unknown';
        $limits = [];

        if ($request->ip()) {
            $limits[] = Limit::perMinute((int) config(
                'webinars.registration.rate_limits.per_ip_per_minute',
                20,
            ))
                ->by('webinar-public:ip:'.$this->rateLimitHash($request->ip()))
                ->response(fn (Request $request, array $headers) =>
                    back()->withInput()->withErrors([
                        'rate_limit' => 'Too many webinar requests were submitted. Please wait a moment and try again.',
                    ])->withHeaders($headers)
                );
        }

        $email = strtolower(trim((string) $request->input('email')));

        if ($email !== '') {
            $limits[] = Limit::perHour((int) config(
                'webinars.registration.rate_limits.per_email_per_hour',
                3,
            ))
                ->by(sprintf(
                    'webinar:%s:%s:email:%s',
                    $flow,
                    $seriesKey,
                    $this->rateLimitHash($email),
                ))
                ->response(fn (Request $request, array $headers) =>
                    back()->withInput()->withErrors([
                        'email' => 'Too many attempts were submitted for this email. Please wait and try again.',
                    ])->withHeaders($headers)
                );
        }

        $phone = preg_replace('/\D+/', '', (string) $request->input('phone'));

        if (filled($phone)) {
            $limits[] = Limit::perHour((int) config(
                'webinars.registration.rate_limits.per_phone_per_hour',
                3,
            ))
                ->by(sprintf(
                    'webinar:%s:%s:phone:%s',
                    $flow,
                    $seriesKey,
                    $this->rateLimitHash($phone),
                ))
                ->response(fn (Request $request, array $headers) =>
                    back()->withInput()->withErrors([
                        'phone' => 'Too many attempts were submitted for this phone number. Please wait and try again.',
                    ])->withHeaders($headers)
                );
        }

        return $limits;
    }

    private function rateLimitHash(string $value): string
    {
        return hash_hmac(
            'sha256',
            trim($value),
            (string) config('app.key'),
        );
    }

}
