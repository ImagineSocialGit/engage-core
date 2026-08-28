<?php

namespace App\Modules\Scheduling\Providers;

use App\Modules\Core\Support\Contacts\ContactPanelRegistry;
use App\Modules\Scheduling\Console\Commands\SyncAppointmentCommunicationsCatalogCommand;
use App\Modules\Scheduling\EventDefinitions\SchedulingPublicBookingEventDefinitionContributor;
use App\Modules\Scheduling\Jobs\ExpireBookingHoldsJob;
use App\Modules\Scheduling\ReadModels\SchedulingBookingFunnelFactContributor;
use App\Modules\Scheduling\Services\ContactShow\SchedulingContactPanelProvider;
use App\Modules\Scheduling\Services\Dashboard\TodayAppointmentsDashboardPanelProvider;
use App\Modules\Scheduling\Services\Dashboard\TomorrowAppointmentsDashboardPanelProvider;
use App\Modules\Scheduling\Validation\SchedulingSetupValidationContributor;
use App\Support\Dashboard\DashboardPanelRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SchedulingModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag(
            SchedulingSetupValidationContributor::class,
            'setup.validation_contributors',
        );

        $this->app->tag(
            SchedulingPublicBookingEventDefinitionContributor::class,
            'reporting.event_definition_contributors',
        );

        $this->app->tag(
            SchedulingBookingFunnelFactContributor::class,
            'reporting.projection_fact_contributors',
        );

        $this->app->tag([
            TodayAppointmentsDashboardPanelProvider::class,
            TomorrowAppointmentsDashboardPanelProvider::class,
        ], DashboardPanelRegistry::providerTag());
    }

    public function boot(): void
    {
        $this->registerContactPanel();
        $this->registerBookingHoldExpiration();
        $this->registerPublicRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncAppointmentCommunicationsCatalogCommand::class,
            ]);
        }
    }

    private function registerContactPanel(): void
    {
        $this->app->make(ContactPanelRegistry::class)
            ->register(SchedulingContactPanelProvider::class, 'scheduling');
    }

    private function registerBookingHoldExpiration(): void
    {
        $this->callAfterResolving(
            Schedule::class,
            function (Schedule $schedule): void {
                $schedule
                    ->job(new ExpireBookingHoldsJob())
                    ->everyMinute()
                    ->withoutOverlapping();
            },
        );
    }

    private function registerPublicRoutes(): void
    {
        if ($this->app->routesAreCached()
            || ! (bool) config('scheduling.public.enabled', false)
        ) {
            return;
        }

        $host = config('scheduling.public.host');

        if (! is_string($host) || trim($host) === '') {
            return;
        }

        Route::middleware(['web', 'module:scheduling'])
            ->domain(strtolower(trim($host)))
            ->group(base_path('routes/scheduling.php'));
    }
}