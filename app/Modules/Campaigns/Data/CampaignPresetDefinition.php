<?php

namespace App\Modules\Campaigns\Data;

use InvalidArgumentException;

class CampaignPresetDefinition
{
    /**
     * @param array<int, CampaignStepPresetDefinition> $steps
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly ?string $description,
        public readonly string $channel,
        public readonly string $purpose,
        public readonly string $scope,
        public readonly string $status,
        public readonly bool $isActive,
        public readonly ?string $sourceVersion,
        public readonly array $steps,
        public readonly array $meta = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $steps = [];

        foreach (($data['steps'] ?? []) as $step) {
            if (! is_array($step)) {
                continue;
            }

            $steps[] = CampaignStepPresetDefinition::fromArray($step);
        }

        return new self(
            key: self::requiredString($data['key'] ?? null, 'campaign key'),
            name: self::requiredString($data['name'] ?? null, 'campaign name'),
            description: self::nullableString($data['description'] ?? null),
            channel: self::requiredString($data['channel'] ?? null, 'campaign channel'),
            purpose: self::requiredString($data['purpose'] ?? null, 'campaign purpose'),
            scope: self::requiredString($data['scope'] ?? null, 'campaign scope'),
            status: self::nullableString($data['status'] ?? null) ?? 'active',
            isActive: (bool) ($data['is_active'] ?? true),
            sourceVersion: self::nullableString($data['source_version'] ?? null),
            steps: $steps,
            meta: is_array($data['meta'] ?? null) ? $data['meta'] : [],
        );
    }

    private static function requiredString(mixed $value, string $field): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Missing required '.$field.'.');
        }

        return trim($value);
    }

    private static function nullableString(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
