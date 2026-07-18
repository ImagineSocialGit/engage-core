<?php

namespace App\Modules\Workflow\Listeners;

use App\Modules\Workflow\Data\ContactWorkflowStatusTransition;
use App\Modules\Workflow\Events\ContactWorkflowStatusChanged;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use App\Support\AutomationEvents\Services\AutomationEventConsumer;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class DispatchContactWorkflowStatusChangedFromAutomationEvent
{
    public const CONSUMER = 'workflow.contact_status_changed';

    public function __construct(
        private readonly AutomationEventConsumer $automationEventConsumer,
    ) {}

    public function handle(AutomationEventRecorded $recorded): void
    {
        $event = $recorded->event;

        if ($event->eventKey !== ContactWorkflowStatusChanged::AUTOMATION_EVENT_KEY) {
            return;
        }

        if (! $event->hasDurableIdentity()) {
            $this->dispatchStatusChange($event);

            return;
        }

        $this->automationEventConsumer->consume(
            eventId: $event->eventId,
            consumer: self::CONSUMER,
            effect: fn () => $this->dispatchStatusChange($event),
        );
    }

    private function dispatchStatusChange(AutomationEventData $event): void
    {
        ContactWorkflowStatusChanged::dispatch(
            $this->transition($event),
        );
    }

    private function transition(AutomationEventData $event): ContactWorkflowStatusTransition
    {
        $payload = data_get($event->payload, 'workflow_transition');

        if (! is_array($payload)) {
            throw new InvalidArgumentException(
                'Workflow status-change automation event is missing its transition payload.',
            );
        }

        $contactId = $this->requiredInt(
            $payload['contact_id'] ?? $event->contactId,
            'contact_id',
        );
        $profileId = $this->requiredInt(
            $payload['contact_workflow_profile_id'] ?? $event->subjectId,
            'contact_workflow_profile_id',
        );
        $toStatusId = $this->requiredInt(
            $payload['to_contact_status_id'] ?? null,
            'to_contact_status_id',
        );
        $occurredAt = $payload['occurred_at'] ?? $event->occurredAt?->toISOString();

        if (! is_string($occurredAt) || trim($occurredAt) === '') {
            throw new InvalidArgumentException(
                'Workflow status-change automation event is missing occurred_at.',
            );
        }

        return new ContactWorkflowStatusTransition(
            contactId: $contactId,
            contactWorkflowProfileId: $profileId,
            fromContactStatusId: $this->nullableInt(
                $payload['from_contact_status_id'] ?? null,
            ),
            toContactStatusId: $toStatusId,
            reason: $this->nullableString($payload['reason'] ?? null),
            source: $this->nullableString($payload['source'] ?? null) ?? 'workflow',
            actorType: $this->nullableString($payload['actor_type'] ?? null),
            actorId: $this->nullableInt($payload['actor_id'] ?? null),
            occurredAt: CarbonImmutable::parse($occurredAt),
            meta: is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
        );
    }

    private function requiredInt(mixed $value, string $field): int
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException(
                "Workflow status-change automation event has invalid [{$field}].",
            );
        }

        return (int) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}