<?php

namespace App\Modules\Tasks\Providers;

use App\Modules\Tasks\Actions\RecordManualTaskCompletionAutomationBehaviorAction;
use App\Modules\Tasks\Capabilities\TasksAutomationCapabilityContributor;
use App\Modules\Tasks\ConfigContracts\TaskPresetConfigContractTargetProvider;
use App\Modules\Tasks\ConfigContracts\TaskPresetDefinitionConfigContract;
use App\Modules\Tasks\Events\TaskCompleted;
use App\Modules\Tasks\Listeners\EmitTaskCompletedAutomationEvent;
use App\Modules\Tasks\Services\AssignedRecipients\TeamMemberTaskAssignedRecipientResolver;
use App\Modules\Tasks\Services\ContactShow\ContactTasksShowDataProvider;
use App\Modules\Tasks\Services\ContactShow\ContactTaskVisibilityDataProvider;
use App\Modules\Tasks\Services\Dashboard\TodayTasksDashboardPanelProvider;
use App\Modules\Tasks\Services\RelatedSubjects\ContactTaskRelatedSubjectResolver;
use App\Modules\Tasks\Services\TaskAssignedRecipientsResolver;
use App\Modules\Tasks\Services\TaskRelatedSubjectResolver;
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
            TasksSetupValidationContributor::class,
        ], 'setup.validation_contributors');

        $this->app->tag([
            TaskPresetDefinitionConfigContract::class,
        ], 'config.contracts');

        $this->app->tag([
            TaskPresetConfigContractTargetProvider::class,
        ], 'config.contract_target_providers');

        $this->app->tag([
            TeamMemberTaskAssignedRecipientResolver::class,
        ], 'crm.tasks.assigned_recipient_resolvers');

        $this->app->when(TaskAssignedRecipientsResolver::class)
            ->needs('$resolvers')
            ->giveTagged('crm.tasks.assigned_recipient_resolvers');

        $this->app->tag([
            ContactTaskRelatedSubjectResolver::class,
        ], 'crm.task_related_subject_resolvers');

        $this->app->when(TaskRelatedSubjectResolver::class)
            ->needs('$resolvers')
            ->giveTagged('crm.task_related_subject_resolvers');

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
