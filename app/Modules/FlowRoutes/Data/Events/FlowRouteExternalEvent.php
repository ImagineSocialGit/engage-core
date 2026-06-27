<?php

namespace App\Modules\FlowRoutes\Data\Events;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class FlowRouteExternalEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $name,
        public readonly ?int $contactId = null,
        public readonly ?string $subjectType = null,
        public readonly int|string|null $subjectId = null,
        public readonly ?CarbonInterface $occurredAt = null,
        public readonly array $payload = [],
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function make(
        string $name,
        ?int $contactId = null,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
        ?CarbonInterface $occurredAt = null,
        array $payload = [],
    ): self {
        return new self(
            name: $name,
            contactId: $contactId,
            subjectType: $subjectType,
            subjectId: $subjectId,
            occurredAt: $occurredAt,
            payload: $payload,
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

        return new self(
            name: (string) ($data['name'] ?? $data['event'] ?? ''),
            contactId: is_numeric($data['contact_id'] ?? null) ? (int) $data['contact_id'] : null,
            subjectType: is_string($data['subject_type'] ?? null) ? $data['subject_type'] : null,
            subjectId: $data['subject_id'] ?? null,
            occurredAt: $occurredAt,
            payload: is_array($payload) ? $payload : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toMetaPayload(): array
    {
        return [
            'name' => $this->name,
            'contact_id' => $this->contactId,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'occurred_at' => $this->occurredAt?->toISOString(),
            'payload' => $this->payload,
        ];
    }

    public function value(string $key): mixed
    {
        return match ($key) {
            'event',
            'name' => $this->name,

            'contact_id' => $this->contactId,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,

            default => data_get($this->payload, $key),
        };
    }
}