<?php

namespace App\Support\AutomationOpportunities\Data;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class AutomationBehaviorData
{
    /**
     * @param array<string, mixed> $fingerprintParts
     * @param array<string, mixed> $context
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $actionKey,
        public readonly ?Model $actor = null,
        public readonly ?Model $subject = null,
        public readonly ?string $capabilityKey = null,
        public readonly array $fingerprintParts = [],
        public readonly array $context = [],
        public readonly array $meta = [],
        public readonly ?CarbonInterface $occurredAt = null,
    ) {}

    /**
     * @param array<string, mixed> $fingerprintParts
     * @param array<string, mixed> $context
     * @param array<string, mixed> $meta
     */
    public static function make(
        string $actionKey,
        ?Model $actor = null,
        ?Model $subject = null,
        ?string $capabilityKey = null,
        array $fingerprintParts = [],
        array $context = [],
        array $meta = [],
        ?CarbonInterface $occurredAt = null,
    ): self {
        return new self(
            actionKey: trim($actionKey),
            actor: $actor,
            subject: $subject,
            capabilityKey: self::nullableTrimmedString($capabilityKey),
            fingerprintParts: $fingerprintParts,
            context: $context,
            meta: $meta,
            occurredAt: $occurredAt ?? CarbonImmutable::now('UTC'),
        );
    }

    public function isValid(): bool
    {
        return trim($this->actionKey) !== ''
            && $this->fingerprintParts !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action_key' => $this->actionKey,
            'actor_type' => $this->actor?->getMorphClass(),
            'actor_id' => $this->actor?->getKey(),
            'subject_type' => $this->subject?->getMorphClass(),
            'subject_id' => $this->subject?->getKey(),
            'capability_key' => $this->capabilityKey,
            'fingerprint_parts' => $this->fingerprintParts,
            'context' => $this->context,
            'meta' => $this->meta,
            'occurred_at' => $this->occurredAt?->toISOString(),
        ];
    }

    private static function nullableTrimmedString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
