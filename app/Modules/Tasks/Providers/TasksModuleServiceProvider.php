<?php

namespace App\Modules\Tasks\Providers;

use App\Modules\Tasks\Actions\RecordManualTaskCompletionAutomationBehaviorAction;
use App\Modules\Tasks\Automation\TasksAutomationPointAuthoringContributor;
use App\Modules\Tasks\Automation\CreateTaskAutomationActionHandler;
use App\Modules\Tasks\Automation\TasksAutomationPointDefinitionContributor;
use App\Modules\Tasks\Capabilities\TasksAutomationCapabilityContributor;
use App\Modules\Tasks\ConfigContracts\TaskPresetConfigContractTargetProvider;
use App\Modules\Tasks\ConfigContracts\TaskPresetDefinitionConfigContract;
use App\Modules\Tasks\Events\TaskCompleted;
use App\Modules\Tasks\Listeners\EmitTaskCompletedAutomationEvent;
use App\Modules\Tasks\Services\ContactShow\ContactTasksShowDataProvider;
use App\Modules\Tasks\Services\ContactShow\ContactTaskVisibilityDataProvider;
use App\Modules\Tasks\Services\Dashboard\TodayTasksDashboardPanelProvider;
use App\Modules\Tasks\Services\LinkPresenters\ContactTaskLinkPresenter;
use App\Modules\Tasks\Services\TaskAssignedRecipientsResolver;
use App\Modules\Tasks\Services\TaskAssigneeOptionsResolver;
use App\Modules\Tasks\Services\TaskAssignmentStrategyResolver;
use App\Modules\Tasks\Services\TaskLinkPresentationResolver;
use App\Modules\Tasks\Services\TaskNotificationScheduler;
use App\Modules\Tasks\Validation\TasksSetupValidationContributor;
use App\Support\Dashboard\DashboardPanelRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class TasksModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([
            TasksAutomationCapabilityContributor::class,
        ], 'automation.capability_contributors');

        $this->app->tag([
            TasksAutomationPointDefinitionContributor::class,
        ], 'automation.point_definition_contributors');

        $this->app->tag([
            TasksAutomationPointAuthoringContributor::class,
        ], 'automation.point_authoring_contributors');

        $this->app->tag([
            CreateTaskAutomationActionHandler::class,
        ], 'automation.action_handlers');

        $this->app->tag([
            TasksSetupValidationContributor::class,
        ], 'setup.validation_contributors');

        $this->app->tag([
            TaskPresetDefinitionConfigContract::class,
        ], 'config.contracts');

        $this->app->tag([
            TaskPresetConfigContractTargetProvider::class,
        ], 'config.contract_target_providers');

        $this->app->when(TaskAssignmentStrategyResolver::class)
            ->needs('$resolvers')
            ->giveTagged('tasks.assignment_strategy_resolvers');

        $this->app->when(TaskAssignedRecipientsResolver::class)
            ->needs('$resolvers')
            ->giveTagged('crm.tasks.assigned_recipient_resolvers');

        $this->app->when(TaskAssigneeOptionsResolver::class)
            ->needs('$providers')
            ->giveTagged('tasks.assignee_option_providers');

        $this->app->when(TaskNotificationScheduler::class)
            ->needs('$schedulers')
            ->giveTagged('tasks.notification_schedulers');

        $this->app->tag([
            ContactTaskLinkPresenter::class,
        ], 'tasks.link_presenters');

        $this->app->when(TaskLinkPresentationResolver::class)
            ->needs('$presenters')
            ->giveTagged('tasks.link_presenters');

        $this->app->tag([
            ContactTasksShowDataProvider::class,
            ContactTaskVisibilityDataProvider::class,
        ], 'core.contact_show_data_providers');

        $this->app->tag([
            TodayTasksDashboardPanelProvider::class,
        ], DashboardPanelRegistry::providerTag());
    }

    public function boot(): void
    {
        Event::listen(
            TaskCompleted::class,
            EmitTaskCompletedAutomationEvent::class,
        );

        Event::listen(
            TaskCompleted::class,
            RecordManualTaskCompletionAutomationBehaviorAction::class,
        );
    }
}
