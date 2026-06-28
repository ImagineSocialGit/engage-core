<?php

namespace App\Modules\FlowRoutes\Listeners;

use App\Modules\FlowRoutes\Actions\ResumeFlowRouteProgressFromEventAction;
use App\Modules\FlowRoutes\Data\Events\FlowRouteExternalEvent;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;

class ResumeFlowRoutesFromAutomationEvent
{
    public function __construct(
        private readonly ResumeFlowRouteProgressFromEventAction $resumeFlowRouteProgressFromEvent,
    ) {}

    public function handle(AutomationEventRecorded $event): void
    {
        $automationEvent = $event->event;

        if (! $automationEvent->isValid()) {
            return;
        }

        if ($automationEvent->contactId === null) {
            return;
        }

        $this->resumeFlowRouteProgressFromEvent->handle(
            FlowRouteExternalEvent::make(
                name: $automationEvent->eventKey,
                contactId: $automationEvent->contactId,
                subjectType: $automationEvent->subjectType,
                subjectId: $automationEvent->subjectId,
                occurredAt: $automationEvent->occurredAt,
                payload: [
                    ...$automationEvent->payload,
                    'automation_event' => $automationEvent->toArray(),
                    'automation_event_meta' => $automationEvent->meta,
                ],
            ),
        );
    }
}