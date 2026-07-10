<?php

namespace App\Support\AutomationOpportunities\Actions;

use App\Modules\Core\Models\Contact;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use App\Support\AutomationOpportunities\Data\AutomationBehaviorData;
use App\Support\AutomationOpportunities\Models\AutomationBehaviorOccurrence;

class RecordAutomationEventCorrelationEvidenceAction extends AutomationBehaviorAction
{
    public const ACTION_KEY = 'automation_event.recorded';

    /**
     * @var array<int, string>
     */
    public const SUPPORTED_EVENT_KEYS = [
        'webinar.attended',
        'webinar.missed',
        'permission_invitation.accepted',
        'inbound_message.normal_reply',
        'task.completed',
    ];

    public function handle(
        AutomationEventRecorded $recorded,
    ): ?AutomationBehaviorOccurrence {
        $event = $recorded->event;

        if (! $event->isValid()
            || ! in_array($event->eventKey, self::SUPPORTED_EVENT_KEYS, true)
            || $event->contactId === null
        ) {
            return null;
        }

        $contact = Contact::query()->find($event->contactId);

        if (! $contact instanceof Contact) {
            return null;
        }

        return $this->recordEvidence(
            AutomationBehaviorData::make(
                actionKey: self::ACTION_KEY,
                subject: $contact,
                fingerprintParts: [
                    'event_key' => $event->eventKey,
                ],
                context: [
                    'event_key' => $event->eventKey,
                    'automation_event_subject_type' => $event->subjectType,
                    'automation_event_subject_id' => $event->subjectId,
                    'source_module' => $this->nullableString($event->meta['source_module'] ?? null),
                    'source' => $this->nullableString($event->meta['source'] ?? null),
                ],
                meta: [
                    'pattern_role' => 'automation_event_correlation_evidence',
                ],
                occurredAt: $event->occurredAt,
            ),
        );
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
