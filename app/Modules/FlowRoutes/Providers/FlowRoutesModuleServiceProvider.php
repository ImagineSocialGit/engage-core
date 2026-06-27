<?php

namespace App\Modules\FlowRoutes\Providers;

use App\Modules\FlowRoutes\ConditionEvaluators\FlowRouteDataConditionEvaluator;
use App\Modules\FlowRoutes\Listeners\HandleContactWorkflowStatusChanged;
use App\Modules\FlowRoutes\PointHandlers\BranchEvaluatePointHandler;
use App\Modules\FlowRoutes\PointHandlers\ConditionPointHandler;
use App\Modules\FlowRoutes\PointHandlers\EventWaitPointHandler;
use App\Modules\FlowRoutes\PointHandlers\NoopPointHandler;
use App\Modules\FlowRoutes\PointHandlers\WaitPointHandler;
use App\Modules\FlowRoutes\Services\FlowRouteConditionEvaluatorRegistry;
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
            EventWaitPointHandler::class,
            ConditionPointHandler::class,
            BranchEvaluatePointHandler::class,
        ], 'flow_routes.point_handlers');

        $this->app->singleton(PointHandlerRegistry::class, function ($app) {
            return new PointHandlerRegistry(
                handlers: $app->tagged('flow_routes.point_handlers'),
            );
        });

        $this->app->tag([
            FlowRouteDataConditionEvaluator::class,
        ], 'flow_routes.condition_evaluators');

        $this->app->singleton(FlowRouteConditionEvaluatorRegistry::class, function ($app) {
            return new FlowRouteConditionEvaluatorRegistry(
                evaluators: $app->tagged('flow_routes.condition_evaluators'),
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