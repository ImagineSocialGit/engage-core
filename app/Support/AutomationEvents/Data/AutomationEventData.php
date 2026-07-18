<?php

namespace App\Support\AutomationEvents\Data;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class AutomationEventData
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $eventKey,
        public readonly ?int $contactId = null,
        public readonly ?string $subjectType = null,
        public readonly int|string|null $subjectId = null,
        public readonly ?CarbonInterface $occurredAt = null,
        public readonly array $payload = [],
        public readonly array $meta = [],
        public readonly ?string $eventId = null,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     */
    public static function make(
        string $eventKey,
        ?int $contactId = null,
        ?Model $subject = null,
        ?CarbonInterface $occurredAt = null,
        array $payload = [],
        array $meta = [],
        ?string $eventId = null,
    ): self {
        return new self(
            eventKey: trim($eventKey),
            contactId: $contactId,
            subjectType: $subject?->getMorphClass(),
            subjectId: $subject?->getKey(),
            occurredAt: $occurredAt ?? CarbonImmutable::now('UTC'),
            payload: $payload,
            meta: $meta,
            eventId: $eventId,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     */
    public static function forSubject(
        string $eventKey,
        Model $subject,
        ?int $contactId = null,
        ?CarbonInterface $occurredAt = null,
        array $payload = [],
        array $meta = [],
        ?string $eventId = null,
    ): self {
        return self::make(
            eventKey: $eventKey,
            contactId: $contactId,
            subject: $subject,
            occurredAt: $occurredAt,
            payload: $payload,
            meta: $meta,
            eventId: $eventId,
        );
    }

    public function isValid(): bool
    {
        return trim($this->eventKey) !== '';
    }

    public function hasDurableIdentity(): bool
    {
        return is_string($this->eventId) && trim($this->eventId) !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_key' => $this->eventKey,
            'contact_id' => $this->contactId,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'occurred_at' => $this->occurredAt?->toISOString(),
            'payload' => $this->payload,
            'meta' => $this->meta,
        ];
    }
}