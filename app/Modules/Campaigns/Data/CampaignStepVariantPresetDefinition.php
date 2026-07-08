<?php

namespace App\Modules\Campaigns\Data;

use InvalidArgumentException;

class CampaignStepVariantPresetDefinition
{
    /**
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $dependencyRules
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $key,
        public readonly ?string $name,
        public readonly int $sortOrder,
        public readonly string $dispatchKey,
        public readonly string $channel,
        public readonly string $purpose,
        public readonly string $scope,
        public readonly bool $isActive = true,
        public readonly array $criteria = [],
        public readonly array $dependencyRules = [],
        public readonly ?string $sourceConfigPath = null,
        public readonly ?string $sourceVersion = null,
        public readonly array $meta = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(
        array $data,
        ?int $stepNumber = null,
        ?string $fallbackDispatchKey = null,
        ?string $fallbackChannel = null,
        ?string $fallbackPurpose = null,
        ?string $fallbackScope = null,
        ?string $fallbackSourceVersion = null,
    ): self {
        return new self(
            key: self::normalizeSegment(self::requiredString($data['key'] ?? null, 'campaign step variant key')),
            name: self::nullableString($data['name'] ?? null),
            sortOrder: (int) ($data['sort_order'] ?? $data['order'] ?? 0),
            dispatchKey: self::normalizeSegment(self::requiredString($data['dispatch_key'] ?? $fallbackDispatchKey, 'campaign step variant dispatch_key')),
            channel: self::normalizeSegment(self::requiredString($data['channel'] ?? $fallbackChannel, 'campaign step variant channel')),
            purpose: self::normalizeSegment(self::requiredString($data['purpose'] ?? $fallbackPurpose, 'campaign step variant purpose')),
            scope: self::normalizeSegment(self::requiredString($data['scope'] ?? $fallbackScope, 'campaign step variant scope')),
            isActive: (bool) ($data['is_active'] ?? true),
            criteria: self::criteria($data),
            dependencyRules: self::dependencyRules($data['dependency_rules'] ?? []),
            sourceConfigPath: self::nullableString($data['source_config_path'] ?? null),
            sourceVersion: self::nullableString($data['source_version'] ?? $fallbackSourceVersion),
            meta: array_replace_recursive(
                is_array($data['meta'] ?? null) ? $data['meta'] : [],
                $stepNumber !== null ? ['campaign_step' => $stepNumber] : [],
            ),
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
     * @return array<string, mixed>
     */
    private static function dependencyRules(mixed $rules): array
    {
        if (! is_array($rules)) {
            return [];
        }

        $normalized = [];

        $legacyRequiredScheduled = self::stringList($rules['requires_scheduled_variant_keys'] ?? []);

        if ($legacyRequiredScheduled !== []) {
            $normalized['requires_scheduled_variant_keys'] = $legacyRequiredScheduled;
        }

        $variantStates = $rules['requires_variant_states'] ?? [];

        if (is_array($variantStates)) {
            foreach ($variantStates as $variantKey => $states) {
                if (is_int($variantKey)) {
                    continue;
                }

                $normalizedVariantKey = self::nullableNormalizedSegment($variantKey);

                if ($normalizedVariantKey === null) {
                    continue;
                }

                $normalized['requires_variant_states'][$normalizedVariantKey] = self::dependencyStates($states);
            }
        }

        $requires = $rules['requires'] ?? [];

        if (is_array($requires)) {
            foreach ($requires as $requirement) {
                if (! is_array($requirement)) {
                    continue;
                }

                $variantKey = self::nullableNormalizedSegment(
                    $requirement['variant_key']
                        ?? $requirement['variant']
                        ?? $requirement['key']
                        ?? null,
                );

                if ($variantKey === null) {
                    continue;
                }

                $normalized['requires'][] = [
                    'variant_key' => $variantKey,
                    'states' => self::dependencyStates(
                        $requirement['states']
                            ?? $requirement['state']
                            ?? $requirement['status']
                            ?? 'scheduled',
                    ),
                ];
            }
        }

        foreach ($rules as $key => $value) {
            if (! is_string($key) || array_key_exists($key, $normalized)) {
                continue;
            }

            if (in_array($key, ['requires_scheduled_variant_keys', 'requires_variant_states', 'requires'], true)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    private static function dependencyStates(mixed $states): array
    {
        $states = self::stringList($states);
        $states = array_values(array_intersect($states, [
            'scheduled',
            'pending',
            'sent',
            'skipped',
            'failed',
            'terminal',
        ]));

        return $states !== [] ? $states : ['scheduled'];
    }

    /**
     * @return array<int, string>
     */
    private static function stringList(mixed $values): array
    {
        if (is_string($values)) {
            $values = [$values];
        }

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => self::nullableNormalizedSegment($value),
            $values,
        ))));
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

    private static function nullableNormalizedSegment(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return self::normalizeSegment($value);
    }

    private static function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}