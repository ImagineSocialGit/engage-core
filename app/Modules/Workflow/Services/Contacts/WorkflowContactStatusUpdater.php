<?php

namespace App\Modules\Workflow\Services\Contacts;

use App\Modules\Core\Contracts\Contacts\UpdatesContactStatus;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Workflow\Actions\TransitionContactWorkflowStatusAction;
use Illuminate\Database\Eloquent\Model;

class WorkflowContactStatusUpdater implements UpdatesContactStatus
{
    public function __construct(
        private readonly TransitionContactWorkflowStatusAction $transitionContactWorkflowStatus,
    ) {}

    /**
     * @param array<string, mixed> $meta
     */
    public function handle(
        Contact $contact,
        ContactStatus $status,
        ?string $reason = null,
        ?string $source = null,
        ?Model $actor = null,
        array $meta = [],
        bool $force = false,
    ): Contact {
        $this->transitionContactWorkflowStatus->handle(
            contact: $contact,
            toStatus: $status,
            reason: $reason,
            source: $source,
            actor: $actor,
            meta: $meta,
            force: $force,
        );

        return $contact->refresh()->load('workflowProfile.contactStatus');
    }
}