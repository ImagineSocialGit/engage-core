<?php

namespace App\Modules\Scheduling\Providers;

use App\Modules\Scheduling\Jobs\ExpireBookingHoldsJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class SchedulingModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
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
}