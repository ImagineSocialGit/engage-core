<?php

namespace App\Modules\Scheduling\Providers;

use App\Modules\Scheduling\Jobs\ExpireBookingHoldsJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SchedulingModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerBookingHoldExpiration();
        $this->registerPublicRoutes();
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