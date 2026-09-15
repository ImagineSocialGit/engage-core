<?php

namespace App\Modules\Workflow\Services\Contacts;

use App\Modules\Core\Actions\Contacts\RecordContactFilterFactsChangedAction;
use App\Modules\Core\Contracts\Contacts\UpdatesContactStatus;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Workflow\Actions\TransitionContactWorkflowStatusAction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WorkflowContactStatusUpdater implements UpdatesContactStatus
{
    public function __construct(
        private readonly TransitionContactWorkflowStatusAction $transitionContactWorkflowStatus,
        private readonly RecordContactFilterFactsChangedAction $recordContactFilterFactsChanged,
    ) {}

    /**
     * @param array<string, mixed> $meta
     */
    public function handle(
        Contact $contact,
        ContactStatus $status,
        ?string $reason = null,
        ?string $source = null,
        ?Model $actor = null,
        array $meta = [],
        bool $force = false,
    ): Contact {
        $this->transitionContactWorkflowStatus->handle(
            contact: $contact,
            toStatus: $status,
            reason: $reason,
            source: $source,
            actor: $actor,
            meta: $meta,
            force: $force,
        );

        return $contact->refresh()->load('workflowProfile.contactStatus');
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function clear(
        Contact $contact,
        ?string $reason = null,
        ?string $source = null,
        ?Model $actor = null,
        array $meta = [],
    ): Contact {
        $change = DB::transaction(function () use (
            $contact,
            $reason,
            $source,
            $actor,
            $meta,
        ): ?array {
            $profile = $contact->workflowProfile()
                ->lockForUpdate()
                ->first();

            if (! $profile || $profile->contact_status_id === null) {
                return null;
            }

            $occurredAt = CarbonImmutable::now();
            $fromStatusId = (int) $profile->contact_status_id;
            $existingMeta = is_array($profile->meta) ? $profile->meta : [];

            $profile->forceFill([
                'contact_status_id' => null,
                'last_status_changed_at' => $occurredAt,
                'meta' => [
                    ...$existingMeta,
                    'last_status_change' => [
                        'from_contact_status_id' => $fromStatusId,
                        'to_contact_status_id' => null,
                        'reason' => $reason,
                        'source' => $source ?: 'workflow',
                        'actor_type' => $actor?->getMorphClass(),
                        'actor_id' => $actor ? (int) $actor->getKey() : null,
                        'changed_at' => $occurredAt->toISOString(),
                        'meta' => $meta,
                    ],
                ],
            ])->save();

            $contact->forceFill([
                'last_activity_at' => $occurredAt,
            ])->save();

            return [
                'from' => $fromStatusId,
                'to' => null,
            ];
        });

        if ($change !== null) {
            $this->recordContactFilterFactsChanged->handle(
                contact: $contact,
                criterionKeys: ['status'],
                source: 'workflow.contact_status_cleared',
                changes: [
                    'status' => $change,
                ],
                subject: $contact,
                meta: [
                    'reason' => $reason,
                    'source' => $source ?: 'workflow',
                ],
            );
        }

        return $contact->refresh()->load('workflowProfile.contactStatus');
    }
}