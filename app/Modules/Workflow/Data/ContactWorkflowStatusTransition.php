<?php

namespace App\Modules\Workflow\Data;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class ContactWorkflowStatusTransition
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly int $contactId,
        public readonly int $contactWorkflowProfileId,
        public readonly ?int $fromContactStatusId,
        public readonly int $toContactStatusId,
        public readonly ?string $reason,
        public readonly string $source,
        public readonly ?string $actorType,
        public readonly ?int $actorId,
        public readonly CarbonImmutable $occurredAt,
        public readonly array $meta = [],
    ) {}

    /**
     * @param array<string, mixed> $meta
     */
    public static function fromModels(
        Contact $contact,
        ContactWorkflowProfile $profile,
        ?ContactStatus $fromStatus,
        ContactStatus $toStatus,
        ?string $reason,
        ?string $source,
        ?Model $actor,
        CarbonImmutable $occurredAt,
        array $meta = [],
    ): self {
        return new self(
            contactId: (int) $contact->getKey(),
            contactWorkflowProfileId: (int) $profile->getKey(),
            fromContactStatusId: $fromStatus?->getKey(),
            toContactStatusId: (int) $toStatus->getKey(),
            reason: $reason,
            source: $source ?: 'workflow',
            actorType: $actor?->getMorphClass(),
            actorId: $actor ? (int) $actor->getKey() : null,
            occurredAt: $occurredAt,
            meta: $meta,
        );
    }

    public function changed(): bool
    {
        return $this->fromContactStatusId !== $this->toContactStatusId;
    }

    /**
     * @return array<string, mixed>
     */
    public function toMetaPayload(): array
    {
        return [
            'from_contact_status_id' => $this->fromContactStatusId,
            'to_contact_status_id' => $this->toContactStatusId,
            'reason' => $this->reason,
            'source' => $this->source,
            'actor_type' => $this->actorType,
            'actor_id' => $this->actorId,
            'changed_at' => $this->occurredAt->toISOString(),
            'meta' => $this->meta,
        ];
    }
}