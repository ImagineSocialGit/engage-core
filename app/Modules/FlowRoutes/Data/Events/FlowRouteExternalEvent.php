<?php

namespace App\Modules\FlowRoutes\Data\Events;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use JsonException;

class FlowRouteExternalEvent
{
    public const MAX_PERSISTENCE_REFERENCE_BYTES = 1024;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $name,
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
        string $name,
        ?int $contactId = null,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
        ?CarbonInterface $occurredAt = null,
        array $payload = [],
        array $meta = [],
        ?string $eventId = null,
    ): self {
        return new self(
            name: $name,
            contactId: $contactId,
            subjectType: $subjectType,
            subjectId: $subjectId,
            occurredAt: $occurredAt,
            payload: $payload,
            meta: $meta,
            eventId: $eventId,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $occurredAt = $data['occurred_at'] ?? null;

        if (is_string($occurredAt) && trim($occurredAt) !== '') {
            $occurredAt = CarbonImmutable::parse($occurredAt);
        }

        if (! $occurredAt instanceof CarbonInterface) {
            $occurredAt = null;
        }

        $payload = $data['payload'] ?? [];
        $meta = $data['meta'] ?? [];
        $eventId = $data['event_id'] ?? null;

        return new self(
            name: (string) ($data['name'] ?? $data['event'] ?? ''),
            contactId: is_numeric($data['contact_id'] ?? null) ? (int) $data['contact_id'] : null,
            subjectType: is_string($data['subject_type'] ?? null) ? $data['subject_type'] : null,
            subjectId: $data['subject_id'] ?? null,
            occurredAt: $occurredAt,
            payload: is_array($payload) ? $payload : [],
            meta: is_array($meta) ? $meta : [],
            eventId: is_string($eventId) && trim($eventId) !== ''
                ? trim($eventId)
                : null,
        );
    }

    /**
     * @return array<string, int|string>
     *
     * @throws JsonException
     */
    public function persistenceReference(): array
    {
        $reference = [
            'name' => $this->name,
        ];

        if (is_string($this->eventId) && trim($this->eventId) !== '') {
            $reference['event_id'] = trim($this->eventId);
        }

        if ($this->contactId !== null) {
            $reference['contact_id'] = $this->contactId;
        }

        if (is_string($this->subjectType) && trim($this->subjectType) !== '') {
            $reference['subject_type'] = $this->subjectType;
        }

        if ($this->subjectId !== null) {
            $reference['subject_id'] = $this->subjectId;
        }

        if ($this->occurredAt instanceof CarbonInterface) {
            $reference['occurred_at'] = $this->occurredAt->toISOString();
        }

        if (strlen(json_encode($reference, JSON_THROW_ON_ERROR)) > self::MAX_PERSISTENCE_REFERENCE_BYTES) {
            throw new InvalidArgumentException(
                'The FlowRoutes automation-event persistence reference exceeds its encoded-size limit.',
            );
        }

        return $reference;
    }

    public function value(string $key): mixed
    {
        return match ($key) {
            'event',
            'name' => $this->name,

            'event_id' => $this->eventId,
            'contact_id' => $this->contactId,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'occurred_at' => $this->occurredAt,

            default => data_get($this->payload, $key),
        };
    }
}