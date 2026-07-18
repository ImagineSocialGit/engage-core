<?php

namespace App\Modules\Workflow\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Workflow\Data\ContactWorkflowStatusTransition;
use App\Modules\Workflow\Events\ContactWorkflowStatusChanged;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Services\AutomationEventOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class TransitionContactWorkflowStatusAction
{
    public function __construct(
        private readonly AutomationEventOutbox $automationEventOutbox,
    ) {}

    /**
     * @param array<string, mixed> $meta
     */
    public function handle(
        Contact $contact,
        ContactStatus $toStatus,
        ?string $reason = null,
        ?string $source = null,
        ?Model $actor = null,
        array $meta = [],
        bool $force = false,
    ): ContactWorkflowProfile {
        return DB::transaction(function () use (
            $contact,
            $toStatus,
            $reason,
            $source,
            $actor,
            $meta,
            $force,
        ): ContactWorkflowProfile {
            $occurredAt = CarbonImmutable::now();

            $profile = ContactWorkflowProfile::query()
                ->where('contact_id', $contact->getKey())
                ->lockForUpdate()
                ->first();

            if (! $profile) {
                $profile = new ContactWorkflowProfile([
                    'contact_id' => $contact->getKey(),
                ]);
            }

            $fromStatusId = $profile->contact_status_id !== null
                ? (int) $profile->contact_status_id
                : null;

            $toStatusId = (int) $toStatus->getKey();

            if (! $force && $fromStatusId === $toStatusId) {
                return $profile->exists
                    ? $profile->refresh()->load('contact', 'contactStatus')
                    : $profile;
            }

            $fromStatus = $fromStatusId
                ? ContactStatus::query()->find($fromStatusId)
                : null;

            $profile->fill([
                'contact_status_id' => $toStatusId,
                'last_status_changed_at' => $occurredAt,
                'meta' => $this->profileMeta(
                    existingMeta: $profile->meta ?? [],
                    transition: ContactWorkflowStatusTransition::fromModels(
                        contact: $contact,
                        profile: $profile->exists ? $profile : new ContactWorkflowProfile([
                            'id' => 0,
                            'contact_id' => $contact->getKey(),
                        ]),
                        fromStatus: $fromStatus,
                        toStatus: $toStatus,
                        reason: $reason,
                        source: $source,
                        actor: $actor,
                        occurredAt: $occurredAt,
                        meta: $meta,
                    ),
                ),
            ]);

            $profile->save();

            $transition = ContactWorkflowStatusTransition::fromModels(
                contact: $contact,
                profile: $profile,
                fromStatus: $fromStatus,
                toStatus: $toStatus,
                reason: $reason,
                source: $source,
                actor: $actor,
                occurredAt: $occurredAt,
                meta: $meta,
            );

            $profile->forceFill([
                'meta' => $this->profileMeta(
                    existingMeta: $profile->meta ?? [],
                    transition: $transition,
                ),
            ])->save();

            $contact->forceFill([
                'last_activity_at' => $occurredAt,
            ])->save();

            $this->recordStatusChange($profile, $transition);

            return $profile->refresh()->load('contact', 'contactStatus');
        });
    }

    private function recordStatusChange(
        ContactWorkflowProfile $profile,
        ContactWorkflowStatusTransition $transition,
    ): void {
        $this->automationEventOutbox->record(
            AutomationEventData::forSubject(
                eventKey: ContactWorkflowStatusChanged::AUTOMATION_EVENT_KEY,
                subject: $profile,
                contactId: $transition->contactId,
                occurredAt: $transition->occurredAt,
                payload: [
                    'workflow_transition' => [
                        'contact_id' => $transition->contactId,
                        'contact_workflow_profile_id' => $transition->contactWorkflowProfileId,
                        'from_contact_status_id' => $transition->fromContactStatusId,
                        'to_contact_status_id' => $transition->toContactStatusId,
                        'reason' => $transition->reason,
                        'source' => $transition->source,
                        'actor_type' => $transition->actorType,
                        'actor_id' => $transition->actorId,
                        'occurred_at' => $transition->occurredAt->toISOString(),
                        'meta' => $transition->meta,
                    ],
                ],
                meta: [
                    'source_module' => 'workflow',
                    'source' => 'contact_workflow_status_changed',
                ],
            ),
        );
    }

    /**
     * @param array<string, mixed> $existingMeta
     * @return array<string, mixed>
     */
    private function profileMeta(
        array $existingMeta,
        ContactWorkflowStatusTransition $transition,
    ): array {
        return [
            ...$existingMeta,
            'last_status_change' => $transition->toMetaPayload(),
        ];
    }
}