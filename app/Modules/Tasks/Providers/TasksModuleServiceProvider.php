<?php

namespace App\Modules\Tasks\Providers;

use App\Modules\Tasks\Services\AssignedRecipients\TeamMemberTaskAssignedRecipientResolver;
use App\Modules\Tasks\Services\RelatedSubjects\ContactTaskRelatedSubjectResolver;
use App\Modules\Tasks\Services\TaskAssignedRecipientsResolver;
use App\Modules\Tasks\Services\TaskRelatedSubjectResolver;
use Illuminate\Support\ServiceProvider;

class TasksModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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
    }

    public function boot(): void
    {
        //
    }
}