<?php

namespace App\Modules\FlowRoutes\Providers;

use App\Modules\FlowRoutes\Capabilities\FlowRoutesAutomationCapabilityContributor;
use App\Modules\FlowRoutes\ConfigContracts\FlowRoutePresetConfigContractTargetProvider;
use App\Modules\FlowRoutes\ConfigContracts\FlowRoutePresetDefinitionConfigContract;
use App\Modules\FlowRoutes\ConditionEvaluators\FlowRouteDataConditionEvaluator;
use App\Modules\FlowRoutes\Console\Commands\SyncFlowRoutePresetsCommand;
use App\Modules\FlowRoutes\Listeners\HandleContactWorkflowStatusChanged;
use App\Modules\FlowRoutes\Listeners\ResumeFlowRoutesFromAutomationEvent;
use App\Modules\FlowRoutes\PointDefinitions\FlowRoutesAutomationPointDefinitionContributor;
use App\Modules\FlowRoutes\PointHandlers\AutomationActionPointHandler;
use App\Modules\FlowRoutes\PointHandlers\BranchEvaluatePointHandler;
use App\Modules\FlowRoutes\PointHandlers\ChangeStatusPointHandler;
use App\Modules\FlowRoutes\PointHandlers\ConditionPointHandler;
use App\Modules\FlowRoutes\PointHandlers\EventWaitPointHandler;
use App\Modules\FlowRoutes\PointHandlers\NoopPointHandler;
use App\Modules\FlowRoutes\PointHandlers\WaitPointHandler;
use App\Modules\FlowRoutes\Services\ContactShow\ContactRoutesVisibilityDataProvider;
use App\Modules\FlowRoutes\Services\FlowRouteConditionEvaluatorRegistry;
use App\Modules\FlowRoutes\Services\PointHandlerRegistry;
use App\Modules\FlowRoutes\Validation\FlowRoutesSetupValidationContributor;
use App\Modules\Workflow\Events\ContactWorkflowStatusChanged;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class FlowRoutesModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag(
            FlowRoutePresetDefinitionConfigContract::class,
            'config.contracts',
        );

        $this->app->tag(
            FlowRoutePresetConfigContractTargetProvider::class,
            'config.contract_target_providers',
        );

        $this->app->tag([
            FlowRoutesAutomationCapabilityContributor::class,
        ], 'automation.capability_contributors');

        $this->app->tag([
            FlowRoutesAutomationPointDefinitionContributor::class,
        ], 'automation.point_definition_contributors');

        $this->app->tag([
            FlowRoutesSetupValidationContributor::class,
        ], 'setup.validation_contributors');

        $this->app->tag([
            NoopPointHandler::class,
            WaitPointHandler::class,
            EventWaitPointHandler::class,
            ConditionPointHandler::class,
            BranchEvaluatePointHandler::class,
            ChangeStatusPointHandler::class,
        ], 'flow_routes.point_handlers');

        $this->app->singleton(PointHandlerRegistry::class, function ($app): PointHandlerRegistry {
            return new PointHandlerRegistry(
                handlers: $app->tagged('flow_routes.point_handlers'),
                automationActions: $app->make(AutomationActionPointHandler::class),
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
}
