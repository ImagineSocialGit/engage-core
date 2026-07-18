<?php

namespace App\Modules\Workflow\Events;

use App\Modules\Workflow\Data\ContactWorkflowStatusTransition;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContactWorkflowStatusChanged
{
    use Dispatchable;
    use SerializesModels;

    public const AUTOMATION_EVENT_KEY = 'workflow.contact_status_changed';

    public function __construct(
        public readonly ContactWorkflowStatusTransition $transition,
    ) {}
}