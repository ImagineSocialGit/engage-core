<?php

namespace App\Modules\FlowRoutes\Providers;

use App\Modules\FlowRoutes\Capabilities\FlowRoutesAutomationCapabilityContributor;
use App\Modules\FlowRoutes\ConditionEvaluators\FlowRouteDataConditionEvaluator;
use App\Modules\FlowRoutes\Console\Commands\SyncFlowRoutePresetsCommand;
use App\Modules\FlowRoutes\Listeners\HandleContactWorkflowStatusChanged;
use App\Modules\FlowRoutes\Listeners\ResumeFlowRoutesFromAutomationEvent;
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
use App\Modules\FlowRoutes\Services\ContactShow\ContactRoutesVisibilityDataProvider;
use App\Modules\FlowRoutes\Services\FlowRouteConditionEvaluatorRegistry;
use App\Modules\FlowRoutes\Services\PointHandlerRegistry;
use App\Modules\Workflow\Events\ContactWorkflowStatusChanged;
use App\Support\AutomationCapabilities\AutomationCapabilityRegistry;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class FlowRoutesModuleServiceProvider extends ServiceProvider
{
    private const CREATE_TASK_ACTION = 'App\\Modules\\Tasks\\Actions\\CreateTaskAction';

    private const DISPATCH_MESSAGE_ACTION = 'App\\Modules\\Messaging\\Actions\\DispatchMessageAction';

    private const ENROLL_CONTACT_IN_CAMPAIGN_ACTION = 'App\\Modules\\Campaigns\\Actions\\EnrollContactInCampaignAction';

    private const CANCEL_CAMPAIGN_ENROLLMENT_ACTION = 'App\\Modules\\Campaigns\\Actions\\CancelCampaignEnrollmentAction';

    public function register(): void
    {
        $this->app->tag([
            FlowRoutesAutomationCapabilityContributor::class,
        ], 'automation.capability_contributors');

        $this->app->singleton(AutomationCapabilityRegistry::class, function ($app) {
            return new AutomationCapabilityRegistry(
                contributors: $app->tagged('automation.capability_contributors'),
            );
        });

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

        $this->app->tag([
            ContactRoutesVisibilityDataProvider::class,
        ], 'core.contact_show_data_providers');
    }

    public function boot(): void
    {
        Event::listen(
            ContactWorkflowStatusChanged::class,
            HandleContactWorkflowStatusChanged::class,
        );

        Event::listen(
            AutomationEventRecorded::class,
            ResumeFlowRoutesFromAutomationEvent::class,
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncFlowRoutePresetsCommand::class,
            ]);
        }
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
