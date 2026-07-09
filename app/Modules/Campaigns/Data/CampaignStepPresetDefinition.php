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
        public readonly ?string $channel,
        public readonly ?string $purpose,
        public readonly ?string $scope,
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
    public static function fromArray(array $data): self
    {
        $stepNumber = (int) ($data['step_number'] ?? 0);

        if ($stepNumber < 1) {
            throw new InvalidArgumentException('Campaign preset steps require a positive step_number.');
        }

        $dispatchKey = self::optionalString($data['dispatch_key'] ?? null) ?? 'campaign_step_due';
        $channel = self::optionalString($data['channel'] ?? null);
        $purpose = self::optionalString($data['purpose'] ?? null);
        $scope = self::optionalString($data['scope'] ?? null);

        $variants = is_array($data['variants'] ?? null) ? $data['variants'] : [];

        if ($variants === []) {
            throw new InvalidArgumentException('Campaign message steps must define at least one variant.');
        }

        $variantDefinitions = array_map(
            fn (array $variant): CampaignStepVariantPresetDefinition => CampaignStepVariantPresetDefinition::fromArray(
                data: $variant,
                stepNumber: $stepNumber,
                fallbackDispatchKey: $dispatchKey,
                fallbackChannel: $channel,
                fallbackPurpose: $purpose,
                fallbackScope: $scope,
                fallbackSourceVersion: self::optionalString($data['source_version'] ?? null),
            ),
            array_values(array_filter($variants, 'is_array')),
        );

        if ($variantDefinitions === []) {
            throw new InvalidArgumentException('Campaign message steps must define at least one valid variant.');
        }

        $variantKeys = [];

        foreach ($variantDefinitions as $variantDefinition) {
            if (in_array($variantDefinition->key, $variantKeys, true)) {
                throw new InvalidArgumentException(
                    'Campaign step ['.$stepNumber.'] has duplicate variant key ['.$variantDefinition->key.'].'
                );
            }

            $variantKeys[] = $variantDefinition->key;
        }

        return new self(
            stepNumber: $stepNumber,
            name: self::optionalString($data['name'] ?? null),
            dispatchKey: self::normalizeSegment($dispatchKey),
            channel: $channel !== null ? self::normalizeSegment($channel) : null,
            purpose: $purpose !== null ? self::normalizeSegment($purpose) : null,
            scope: $scope !== null ? self::normalizeSegment($scope) : null,
            variantStrategy: self::variantStrategy($data['variant_strategy'] ?? 'first_available'),
            isActive: (bool) ($data['is_active'] ?? true),
            criteria: is_array($data['criteria'] ?? null) ? $data['criteria'] : [],
            sourceVersion: self::optionalString($data['source_version'] ?? null),
            meta: is_array($data['meta'] ?? null) ? $data['meta'] : [],
            variants: $variantDefinitions,
        );
    }

    private static function variantStrategy(mixed $value): string
    {
        $strategy = is_string($value) && trim($value) !== ''
            ? self::normalizeSegment($value)
            : 'first_available';

        if (! in_array($strategy, ['first_available', 'send_all_eligible', 'dependency_aware'], true)) {
            throw new InvalidArgumentException('Unsupported Campaign variant strategy ['.$strategy.'].');
        }

        return $strategy;
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
