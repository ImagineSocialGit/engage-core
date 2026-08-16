<?php

namespace App\Modules\Reporting\Providers;

use App\Modules\Reporting\Actions\RecordReportingObservationAction;
use App\Modules\Reporting\Controllers\Public\ReportingObservationController;
use App\Modules\Reporting\EventDefinitions\ConfigReportingEventDefinitionContributor;
use App\Modules\Reporting\Validation\ReportingSetupValidationContributor;
use App\Support\Reporting\Contracts\ReportingObservationRecorder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ReportingModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            ReportingObservationRecorder::class,
            RecordReportingObservationAction::class,
        );

        $this->app->tag(
            ConfigReportingEventDefinitionContributor::class,
            'reporting.event_definition_contributors',
        );

        $this->app->tag(
            ReportingSetupValidationContributor::class,
            'setup.validation_contributors',
        );
    }

    public function boot(): void
    {
        $this->registerPublicRateLimiter();

        if (! $this->app->routesAreCached()) {
            Route::post('/_reporting/observations', ReportingObservationController::class)
                ->middleware([
                    'module:reporting',
                    'throttle:reporting-observations',
                ])
                ->name('reporting.observations.store');
        }
    }

    private function registerPublicRateLimiter(): void
    {
        RateLimiter::for('reporting-observations', function (Request $request): array {
            $host = rtrim(strtolower(trim($request->getHost())), '.');
            $ipHash = hash('sha256', (string) $request->ip());
            $limits = [
                Limit::perMinute($this->rateLimit('rate_limit_per_ip_per_minute', 120))
                    ->by("reporting:ip:{$host}:{$ipHash}"),
            ];

            $sessionToken = $request->input('session_token');

            if (is_string($sessionToken) && trim($sessionToken) !== '') {
                $sessionHash = hash('sha256', trim($sessionToken));

                $limits[] = Limit::perMinute($this->rateLimit('rate_limit_per_session_per_minute', 90))
                    ->by("reporting:session:{$host}:{$sessionHash}");
            }

            return $limits;
        });
    }

    private function rateLimit(string $key, int $default): int
    {
        return min(
            $default,
            max(1, (int) config("reporting.ingestion.{$key}", $default)),
        );
    }
}