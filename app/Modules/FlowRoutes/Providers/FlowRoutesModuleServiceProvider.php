<?php

namespace App\Modules\FlowRoutes\Providers;

use App\Modules\FlowRoutes\Listeners\HandleContactWorkflowStatusChanged;
use App\Modules\FlowRoutes\PointHandlers\NoopPointHandler;
use App\Modules\FlowRoutes\PointHandlers\WaitPointHandler;
use App\Modules\FlowRoutes\Services\PointHandlerRegistry;
use App\Modules\Workflow\Events\ContactWorkflowStatusChanged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class FlowRoutesModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([
            NoopPointHandler::class,
            WaitPointHandler::class,
        ], 'flow_routes.point_handlers');

        $this->app->singleton(PointHandlerRegistry::class, function ($app) {
            return new PointHandlerRegistry(
                handlers: $app->tagged('flow_routes.point_handlers'),
            );
        });
    }

    public function boot(): void
    {
        Event::listen(
            ContactWorkflowStatusChanged::class,
            HandleContactWorkflowStatusChanged::class,
        );
    }
}