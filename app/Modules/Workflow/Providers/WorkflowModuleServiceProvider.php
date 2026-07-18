<?php

namespace App\Modules\Workflow\Providers;

use App\Modules\Core\Contracts\Contacts\UpdatesContactStatus;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Workflow\Events\ContactWorkflowStatusChanged;
use App\Modules\Workflow\Listeners\DispatchContactWorkflowStatusChangedFromAutomationEvent;
use App\Modules\Workflow\Listeners\RecordManualStatusTransitionAutomationBehavior;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use App\Modules\Workflow\Services\Contacts\WorkflowContactStatusUpdater;
use App\Modules\Workflow\Services\ContactShow\ContactWorkflowVisibilityDataProvider;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class WorkflowModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            UpdatesContactStatus::class,
            WorkflowContactStatusUpdater::class,
        );

        $this->app->tag([
            ContactWorkflowVisibilityDataProvider::class,
        ], 'core.contact_show_data_providers');
    }

    public function boot(): void
    {
        Contact::resolveRelationUsing('workflowProfile', function (Contact $contact): HasOne {
            return $contact->hasOne(ContactWorkflowProfile::class);
        });

        ContactStatus::resolveRelationUsing('workflowProfiles', function (ContactStatus $status): HasMany {
            return $status->hasMany(ContactWorkflowProfile::class);
        });

        Event::listen(
            AutomationEventRecorded::class,
            DispatchContactWorkflowStatusChangedFromAutomationEvent::class,
        );

        Event::listen(
            ContactWorkflowStatusChanged::class,
            RecordManualStatusTransitionAutomationBehavior::class,
        );
    }
}