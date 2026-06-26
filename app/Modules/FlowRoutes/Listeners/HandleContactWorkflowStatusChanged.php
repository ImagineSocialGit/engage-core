<?php

namespace App\Modules\FlowRoutes\Listeners;

use App\Modules\FlowRoutes\Actions\HandleContactWorkflowStatusChangedAction;
use App\Modules\Workflow\Events\ContactWorkflowStatusChanged;

class HandleContactWorkflowStatusChanged
{
    public function __construct(
        private readonly HandleContactWorkflowStatusChangedAction $handleContactWorkflowStatusChanged,
    ) {}

    public function handle(ContactWorkflowStatusChanged $event): void
    {
        $this->handleContactWorkflowStatusChanged->handle($event->transition);
    }
}