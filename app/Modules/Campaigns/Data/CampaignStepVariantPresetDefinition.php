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
        public readonly ?string $sourceVersion = null,
        public readonly array $meta = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(
        array $data,
        string $variantKey,
        int $sortOrder,
        int $stepNumber,
        string $fallbackDispatchKey,
        string $fallbackPurpose,
        string $fallbackScope,
        ?string $fallbackSourceVersion = null,
    ): self {
        self::rejectRemovedFields($data, [
            'key',
            'sort_order',
            'order',
            'dispatch_key',
            'purpose',
            'scope',
            'source_config_path',
        ], 'Campaign step ['.$stepNumber.'] variant ['.$variantKey.']');

        if ($sortOrder < 1) {
            throw new InvalidArgumentException(
                'Campaign step variant sort order must be derived from a positive position.'
            );
        }

        return new self(
            key: self::normalizeSegment(
                self::requiredString($variantKey, 'campaign step variant map key'),
            ),
            name: self::nullableString($data['name'] ?? null),
            sortOrder: $sortOrder,
            dispatchKey: self::normalizeSegment($fallbackDispatchKey),
            channel: self::normalizeSegment(
                self::requiredString($data['channel'] ?? null, 'campaign step variant channel'),
            ),
            purpose: self::normalizeSegment($fallbackPurpose),
            scope: self::normalizeSegment($fallbackScope),
            isActive: (bool) ($data['is_active'] ?? true),
            criteria: self::criteria($data),
            dependencyRules: self::dependencyRules($data['dependency_rules'] ?? []),
            sourceVersion: self::nullableString($data['source_version'] ?? $fallbackSourceVersion),
            meta: array_replace_recursive(
                is_array($data['meta'] ?? null) ? $data['meta'] : [],
                ['campaign_step' => $stepNumber],
            ),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function criteria(array $data): array
    {
        return is_array($data['criteria'] ?? null)
            ? $data['criteria']
            : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function dependencyRules(mixed $rules): array
    {
        if ($rules === null || $rules === []) {
            return [];
        }

        if (! is_array($rules)) {
            throw new InvalidArgumentException(
                'Campaign step variant dependency_rules must be an object.'
            );
        }

        $unknown = array_values(array_diff(
            array_keys($rules),
            ['requires_variant_states'],
        ));

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Campaign step variant dependency_rules contains unsupported field(s): ['.
                implode(', ', $unknown).'].'
            );
        }

        $variantStates = $rules['requires_variant_states'] ?? [];

        if (! is_array($variantStates) || array_is_list($variantStates)) {
            throw new InvalidArgumentException(
                'Campaign step variant requires_variant_states must be a map keyed by sibling variant.'
            );
        }

        $normalized = [];

        foreach ($variantStates as $variantKey => $states) {
            $normalizedVariantKey = self::nullableNormalizedSegment($variantKey);

            if ($normalizedVariantKey === null) {
                throw new InvalidArgumentException(
                    'Campaign step variant dependency keys must be non-empty strings.'
                );
            }

            $normalized['requires_variant_states'][$normalizedVariantKey] = self::dependencyStates($states);
        }

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    private static function dependencyStates(mixed $states): array
    {
        $states = self::stringList($states);
        $allowedStates = [
            'scheduled',
            'pending',
            'sent',
            'skipped',
            'failed',
            'terminal',
            'unavailable',
        ];

        if ($states === []) {
            throw new InvalidArgumentException(
                'Campaign step variant dependency states must include at least one supported state.'
            );
        }

        $unsupportedStates = array_values(array_diff($states, $allowedStates));

        if ($unsupportedStates !== []) {
            throw new InvalidArgumentException(
                'Unsupported Campaign step variant dependency state(s): ['.
                implode(', ', $unsupportedStates).'].'
            );
        }

        return $states;
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

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $fields
     */
    private static function rejectRemovedFields(
        array $data,
        array $fields,
        string $context,
    ): void {
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                throw new InvalidArgumentException(
                    "{$context} must not define removed field [{$field}]."
                );
            }
        }
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