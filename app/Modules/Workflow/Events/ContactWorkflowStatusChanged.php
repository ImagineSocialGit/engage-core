<?php

namespace App\Modules\Workflow\Events;

use App\Modules\Workflow\Data\ContactWorkflowStatusTransition;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContactWorkflowStatusChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly ContactWorkflowStatusTransition $transition,
    ) {}
}