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
            criteria: self::criteria($data),
            payload: self::payload($data),
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
    private static function payload(array $data): array
    {
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];

        if (array_key_exists('payload', $payload) && is_array($payload['payload'])) {
            return $payload['payload'];
        }

        return $payload;
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

        if (array_key_exists('message', $data) && is_array($data['message'])) {
            $meta['message'] = $data['message'];
        }

        if (array_key_exists('message', $data['payload'] ?? []) && is_array($data['payload']['message'] ?? null)) {
            $meta['message'] = $data['payload']['message'];
        }

        if (array_key_exists('eligibility_failure', $data) && is_array($data['eligibility_failure'])) {
            $meta['eligibility_failure'] = $data['eligibility_failure'];
        }

        return $meta;
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