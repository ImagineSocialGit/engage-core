<?php

namespace App\Modules\FlowRoutes\Providers;

use App\Modules\FlowRoutes\ConditionEvaluators\FlowRouteDataConditionEvaluator;
use App\Modules\FlowRoutes\Listeners\HandleContactWorkflowStatusChanged;
use App\Modules\FlowRoutes\Listeners\ResumeFlowRouteProgressWhenTaskCompleted;
use App\Modules\FlowRoutes\PointHandlers\BranchEvaluatePointHandler;
use App\Modules\FlowRoutes\PointHandlers\ConditionPointHandler;
use App\Modules\FlowRoutes\PointHandlers\CreateTaskPointHandler;
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
    private const TASK_COMPLETED_EVENT = 'App\\Modules\\Tasks\\Events\\TaskCompleted';

    private const CREATE_TASK_ACTION = 'App\\Modules\\Tasks\\Actions\\CreateTaskAction';

    public function register(): void
    {
        $this->app->tag($this->pointHandlers(), 'flow_routes.point_handlers');

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

        $this->registerOptionalTaskListeners();
    }

    /**
     * @return array<int, class-string>
     */
    private function pointHandlers(): array
    {
        $handlers = [
            NoopPointHandler::class,
            WaitPointHandler::class,
            EventWaitPointHandler::class,
            ConditionPointHandler::class,
            BranchEvaluatePointHandler::class,
        ];

        if ($this->tasksAvailable()) {
            $handlers[] = CreateTaskPointHandler::class;
        }

        return $handlers;
    }

    private function registerOptionalTaskListeners(): void
    {
        if (! $this->tasksAvailable()) {
            return;
        }

        if (! class_exists(self::TASK_COMPLETED_EVENT)) {
            return;
        }

        Event::listen(
            self::TASK_COMPLETED_EVENT,
            ResumeFlowRouteProgressWhenTaskCompleted::class,
        );
    }

    private function tasksAvailable(): bool
    {
        if (function_exists('module_enabled') && ! module_enabled('tasks')) {
            return false;
        }

        return class_exists(self::CREATE_TASK_ACTION);
    }
}