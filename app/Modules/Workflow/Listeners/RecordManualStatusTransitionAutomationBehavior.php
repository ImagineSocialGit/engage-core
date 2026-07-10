<?php

namespace App\Modules\Workflow\Listeners;

use App\Modules\Workflow\Actions\RecordManualStatusTransitionAutomationBehaviorAction;
use App\Modules\Workflow\Events\ContactWorkflowStatusChanged;

class RecordManualStatusTransitionAutomationBehavior
{
    public function __construct(
        private readonly RecordManualStatusTransitionAutomationBehaviorAction $recordAutomationBehavior,
    ) {}

    public function handle(ContactWorkflowStatusChanged $event): void
    {
        $this->recordAutomationBehavior->handle($event->transition);
    }
}
