<?php

namespace App\Modules\Messaging\Data\Delivery;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

final readonly class MessageDeliveryIntent
{
    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $behavior
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $key,
        public Model $recipient,
        public array $definition,
        public array $payload = [],
        public ?Model $context = null,
        public Carbon|string|null $triggeredAt = null,
        public Carbon|string|null $anchor = null,
        public Carbon|string|null $sendAt = null,
        public ?Model $behaviorOwner = null,
        public array $behavior = [],
        public ?string $occurrenceKey = null,
        public array $meta = [],
    ) {}

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     */
    public static function fromDefinition(
        string $key,
        Model $recipient,
        array $definition,
        array $payload = [],
        ?Model $context = null,
        Carbon|string|null $triggeredAt = null,
        Carbon|string|null $anchor = null,
        Carbon|string|null $sendAt = null,
        ?Model $behaviorOwner = null,
        array $behavior = [],
        ?string $occurrenceKey = null,
        array $meta = [],
    ): self {
        $definitionBehaviorOwner = $definition['behavior_owner'] ?? null;
        $definitionBehavior = is_array($definition['resolved_behavior'] ?? null)
            ? $definition['resolved_behavior']
            : [];

        unset($definition['behavior_owner'], $definition['resolved_behavior']);

        return new self(
            key: $key,
            recipient: $recipient,
            definition: $definition,
            payload: $payload,
            context: $context,
            triggeredAt: $triggeredAt,
            anchor: $anchor,
            sendAt: $sendAt,
            behaviorOwner: $definitionBehaviorOwner instanceof Model
                ? $definitionBehaviorOwner
                : $behaviorOwner,
            behavior: array_replace_recursive($definitionBehavior, $behavior),
            occurrenceKey: $occurrenceKey,
            meta: $meta,
        );
    }

    public function channel(): string
    {
        return $this->normalizeSegment($this->definition['channel'] ?? null);
    }

    public function purpose(): string
    {
        return $this->normalizeSegment($this->definition['purpose'] ?? null);
    }

    public function scope(): string
    {
        return $this->normalizeSegment($this->definition['scope'] ?? null);
    }

    private function normalizeSegment(mixed $value): string
    {
        return is_string($value)
            ? str_replace('-', '_', strtolower(trim($value)))
            : '';
    }
}
