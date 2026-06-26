<?php

namespace App\Modules\Workflow\Providers;

use App\Modules\Core\Contracts\Contacts\UpdatesContactStatus;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use App\Modules\Workflow\Services\Contacts\WorkflowContactStatusUpdater;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\ServiceProvider;

class WorkflowModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            UpdatesContactStatus::class,
            WorkflowContactStatusUpdater::class,
        );
    }

    public function boot(): void
    {
        Contact::resolveRelationUsing('workflowProfile', function (Contact $contact): HasOne {
            return $contact->hasOne(ContactWorkflowProfile::class);
        });

        ContactStatus::resolveRelationUsing('workflowProfiles', function (ContactStatus $status): HasMany {
            return $status->hasMany(ContactWorkflowProfile::class);
        });
    }
}