<?php

namespace App\Modules\Campaigns\Data;

use InvalidArgumentException;

class CampaignStepPresetDefinition
{
    /**
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly int $stepNumber,
        public readonly ?string $name,
        public readonly string $dispatchKey,
        public readonly ?string $channel = null,
        public readonly ?string $purpose = null,
        public readonly ?string $scope = null,
        public readonly bool $isActive = true,
        public readonly array $criteria = [],
        public readonly ?string $sourceVersion = null,
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
            throw new InvalidArgumentException('Campaign step step_number must be greater than zero.');
        }

        return new self(
            stepNumber: $stepNumber,
            name: self::nullableString($data['name'] ?? null),
            dispatchKey: $dispatchKey,
            channel: self::nullableString($data['channel'] ?? null),
            purpose: self::nullableString($data['purpose'] ?? null),
            scope: self::nullableString($data['scope'] ?? null),
            isActive: (bool) ($data['is_active'] ?? true),
            criteria: self::criteria($data),
            sourceVersion: self::nullableString($data['source_version'] ?? null),
            meta: self::meta($data),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function criteria(array $data): array
    {
        $criteria = is_array($data['criteria'] ?? null) ? $data['criteria'] : [];

        foreach (['timing', 'schedule', 'conditions'] as $key) {
            if (array_key_exists($key, $data) && is_array($data[$key])) {
                $criteria[$key] = $data[$key];
            }
        }

        return $criteria;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function meta(array $data): array
    {
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

        $type = self::nullableString($data['type'] ?? null)
            ?? self::nullableString($meta['type'] ?? null)
            ?? 'message';

        $meta['type'] = $type;

        if (array_key_exists('eligibility_failure', $data) && is_array($data['eligibility_failure'])) {
            $meta['eligibility_failure'] = $data['eligibility_failure'];
        }

        return $meta;
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
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}