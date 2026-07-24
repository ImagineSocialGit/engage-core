<?php

namespace App\Modules\Campaigns\Data;

use InvalidArgumentException;

class CampaignStepPresetDefinition
{
    /**
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $meta
     * @param array<int, CampaignStepVariantPresetDefinition> $variants
     */
    public function __construct(
        public readonly int $stepNumber,
        public readonly ?string $name,
        public readonly string $dispatchKey,
        public readonly string $channel,
        public readonly string $purpose,
        public readonly string $scope,
        public readonly string $variantStrategy,
        public readonly bool $isActive,
        public readonly array $criteria,
        public readonly ?string $sourceVersion,
        public readonly array $meta,
        public readonly array $variants,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(
        array $data,
        int $stepNumber,
        string $fallbackDispatchKey,
        string $fallbackPurpose,
        string $fallbackScope,
        string $fallbackVariantStrategy,
        ?string $fallbackSourceVersion = null,
    ): self {
        if ($stepNumber < 1) {
            throw new InvalidArgumentException(
                'Campaign preset steps require a positive derived position.'
            );
        }

        self::rejectRemovedFields($data, [
            'step_number',
            'dispatch_key',
            'channel',
            'purpose',
            'scope',
        ], 'Campaign step ['.$stepNumber.']');

        $variants = $data['variants'] ?? null;

        if (! is_array($variants) || array_is_list($variants) || $variants === []) {
            throw new InvalidArgumentException(
                'Campaign step ['.$stepNumber.'] variants must be a non-empty map keyed by variant key.'
            );
        }

        $variantDefinitions = [];
        $normalizedKeys = [];

        foreach ($variants as $variantKey => $variantData) {
            if (! is_string($variantKey) || trim($variantKey) === '') {
                throw new InvalidArgumentException(
                    'Campaign step ['.$stepNumber.'] variant map keys must be non-empty strings.'
                );
            }

            if (! is_array($variantData)) {
                throw new InvalidArgumentException(
                    'Campaign step ['.$stepNumber.'] variant ['.$variantKey.'] must be an object.'
                );
            }

            $variantDefinition = CampaignStepVariantPresetDefinition::fromArray(
                data: $variantData,
                variantKey: $variantKey,
                sortOrder: (count($variantDefinitions) + 1) * 10,
                stepNumber: $stepNumber,
                fallbackDispatchKey: $fallbackDispatchKey,
                fallbackPurpose: $fallbackPurpose,
                fallbackScope: $fallbackScope,
                fallbackSourceVersion: self::optionalString(
                    $data['source_version'] ?? $fallbackSourceVersion,
                ),
            );

            if (isset($normalizedKeys[$variantDefinition->key])) {
                throw new InvalidArgumentException(
                    'Campaign step ['.$stepNumber.'] has duplicate normalized variant key ['.$variantDefinition->key.'].'
                );
            }

            $normalizedKeys[$variantDefinition->key] = true;
            $variantDefinitions[] = $variantDefinition;
        }

        return new self(
            stepNumber: $stepNumber,
            name: self::optionalString($data['name'] ?? null),
            dispatchKey: self::normalizeSegment($fallbackDispatchKey),
            channel: CampaignPresetDefinition::aggregateSegment(array_map(
                fn (CampaignStepVariantPresetDefinition $variant): string => $variant->channel,
                $variantDefinitions,
            )),
            purpose: self::normalizeSegment($fallbackPurpose),
            scope: self::normalizeSegment($fallbackScope),
            variantStrategy: CampaignPresetDefinition::variantStrategy(
                $data['variant_strategy'] ?? $fallbackVariantStrategy,
            ),
            isActive: (bool) ($data['is_active'] ?? true),
            criteria: is_array($data['criteria'] ?? null) ? $data['criteria'] : [],
            sourceVersion: self::optionalString(
                $data['source_version'] ?? $fallbackSourceVersion,
            ),
            meta: is_array($data['meta'] ?? null) ? $data['meta'] : [],
            variants: $variantDefinitions,
        );
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

    private static function optionalString(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private static function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}