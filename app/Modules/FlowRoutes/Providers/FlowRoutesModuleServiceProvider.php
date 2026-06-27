<?php

namespace App\Modules\FlowRoutes\Providers;

use App\Modules\FlowRoutes\ConditionEvaluators\FlowRouteDataConditionEvaluator;
use App\Modules\FlowRoutes\Listeners\HandleContactWorkflowStatusChanged;
use App\Modules\FlowRoutes\Listeners\ResumeFlowRouteProgressWhenTaskCompleted;
use App\Modules\FlowRoutes\PointHandlers\BranchEvaluatePointHandler;
use App\Modules\FlowRoutes\PointHandlers\CancelCampaignPointHandler;
use App\Modules\FlowRoutes\PointHandlers\ChangeStatusPointHandler;
use App\Modules\FlowRoutes\PointHandlers\ConditionPointHandler;
use App\Modules\FlowRoutes\PointHandlers\CreateTaskPointHandler;
use App\Modules\FlowRoutes\PointHandlers\EnrollCampaignPointHandler;
use App\Modules\FlowRoutes\PointHandlers\EventWaitPointHandler;
use App\Modules\FlowRoutes\PointHandlers\NoopPointHandler;
use App\Modules\FlowRoutes\PointHandlers\SendMessagePointHandler;
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

    private const DISPATCH_MESSAGE_ACTION = 'App\\Modules\\Messaging\\Actions\\DispatchMessageAction';

    private const ENROLL_CONTACT_IN_CAMPAIGN_ACTION = 'App\\Modules\\Campaigns\\Actions\\EnrollContactInCampaignAction';

    private const CANCEL_CAMPAIGN_ENROLLMENT_ACTION = 'App\\Modules\\Campaigns\\Actions\\CancelCampaignEnrollmentAction';

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
            ChangeStatusPointHandler::class,
        ];

        if ($this->tasksAvailable()) {
            $handlers[] = CreateTaskPointHandler::class;
        }

        if ($this->messagingAvailable()) {
            $handlers[] = SendMessagePointHandler::class;
        }

        if ($this->campaignEnrollmentAvailable()) {
            $handlers[] = EnrollCampaignPointHandler::class;
        }

        if ($this->campaignCancellationAvailable()) {
            $handlers[] = CancelCampaignPointHandler::class;
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

    private function messagingAvailable(): bool
    {
        if (function_exists('module_enabled') && ! module_enabled('messaging')) {
            return false;
        }

        return class_exists(self::DISPATCH_MESSAGE_ACTION);
    }

    private function campaignsModuleEnabled(): bool
    {
        return ! function_exists('module_enabled') || module_enabled('campaigns');
    }

    private function campaignEnrollmentAvailable(): bool
    {
        if (! $this->campaignsModuleEnabled()) {
            return false;
        }

        return class_exists(self::ENROLL_CONTACT_IN_CAMPAIGN_ACTION);
    }

    private function campaignCancellationAvailable(): bool
    {
        if (! $this->campaignsModuleEnabled()) {
            return false;
        }

        return class_exists(self::CANCEL_CAMPAIGN_ENROLLMENT_ACTION);
    }
}