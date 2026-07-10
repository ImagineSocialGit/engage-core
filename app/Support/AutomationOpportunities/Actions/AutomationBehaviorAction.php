<?php

namespace App\Support\AutomationOpportunities\Actions;

use App\Support\AutomationOpportunities\Data\AutomationBehaviorData;
use App\Support\AutomationOpportunities\Models\AutomationBehaviorOccurrence;

abstract class AutomationBehaviorAction
{
    public function __construct(
        private readonly RecordAutomationBehaviorOccurrenceAction $recordAutomationBehaviorOccurrence,
    ) {}

    protected function record(
        AutomationBehaviorData $behavior,
    ): AutomationBehaviorOccurrence {
        return $this->recordAutomationBehaviorOccurrence->handle($behavior);
    }
}
