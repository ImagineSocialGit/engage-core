<?php

namespace App\Modules\FlowRoutes\Providers;

use App\Modules\FlowRoutes\Listeners\HandleContactWorkflowStatusChanged;
use App\Modules\Workflow\Events\ContactWorkflowStatusChanged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class FlowRoutesModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen(
            ContactWorkflowStatusChanged::class,
            HandleContactWorkflowStatusChanged::class,
        );
    }
}