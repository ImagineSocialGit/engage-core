<?php

namespace App\Modules\Campaigns\Data;

class CampaignStepPresetDefinition
{
    /**
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly int $stepNumber,
        public readonly ?string $name,
        public readonly string $dispatchKey,
        public readonly bool $isActive = true,
        public readonly array $criteria = [],
        public readonly array $payload = [],
        public readonly array $meta = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $stepNumber = (int) ($data['step_number'] ?? 0);
        $dispatchKey = self::requiredString($data['dispatch_key'] ?? null, 'campaign step dispatch_key');

        if ($stepNumber < 1) {
            throw new \InvalidArgumentException('Campaign step step_number must be greater than zero.');
        }

        return new self(
            stepNumber: $stepNumber,
            name: self::nullableString($data['name'] ?? null),
            dispatchKey: $dispatchKey,
            isActive: (bool) ($data['is_active'] ?? true),
            criteria: is_array($data['criteria'] ?? null) ? $data['criteria'] : [],
            payload: is_array($data['payload'] ?? null) ? $data['payload'] : [],
            meta: is_array($data['meta'] ?? null) ? $data['meta'] : [],
        );
    }

    private static function requiredString(mixed $value, string $field): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException('Missing required '.$field.'.');
        }

        return trim($value);
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}