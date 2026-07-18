<?php

namespace App\Modules\FlowRoutes\Listeners;

use App\Modules\FlowRoutes\Actions\ResumeFlowRouteProgressFromEventAction;
use App\Modules\FlowRoutes\Actions\StartFlowRoutesFromAutomationEventAction;
use App\Modules\FlowRoutes\Data\Events\FlowRouteExternalEvent;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use App\Support\AutomationEvents\Services\AutomationEventConsumer;

class ResumeFlowRoutesFromAutomationEvent
{
    public const CONSUMER = 'flow_routes.automation_event';

    public function __construct(
        private readonly StartFlowRoutesFromAutomationEventAction $startFlowRoutesFromAutomationEvent,
        private readonly ResumeFlowRouteProgressFromEventAction $resumeFlowRouteProgressFromEvent,
        private readonly AutomationEventConsumer $automationEventConsumer,
    ) {}

    public function handle(AutomationEventRecorded $event): void
    {
        $automationEvent = $event->event;

        if (! $automationEvent->isValid()) {
            return;
        }

        if (! $automationEvent->hasDurableIdentity()) {
            $this->consume($automationEvent);

            return;
        }

        $this->automationEventConsumer->consume(
            eventId: $automationEvent->eventId,
            consumer: self::CONSUMER,
            effect: fn () => $this->consume($automationEvent),
        );
    }

    private function consume(AutomationEventData $automationEvent): void
    {
        $externalEvent = FlowRouteExternalEvent::make(
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
        );

        if ($automationEvent->contactId !== null) {
            $this->startFlowRoutesFromAutomationEvent->handle($externalEvent);
        }

        $this->resumeFlowRouteProgressFromEvent->handle($externalEvent);
    }
}