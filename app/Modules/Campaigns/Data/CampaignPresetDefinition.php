<?php

namespace App\Modules\Campaigns\Data;

use App\Modules\Campaigns\Models\Campaign;
use InvalidArgumentException;

class CampaignPresetDefinition
{
    public const CHANNEL_MULTI = 'multi';

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
        public readonly string $dispatchKey,
        public readonly string $variantStrategy,
        public readonly ?string $sourceVersion,
        public readonly array $steps,
        public readonly array $meta = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $definitionKey): self
    {
        self::rejectRemovedFields($data, [
            'key',
            'channel',
            'dispatch_key',
        ], 'Campaign preset ['.$definitionKey.']');

        $key = self::normalizeSegment(
            self::requiredString($definitionKey, 'campaign definition key'),
        );
        $purpose = self::normalizeSegment(
            self::requiredString($data['purpose'] ?? null, 'campaign purpose'),
        );
        $scope = self::normalizeSegment(
            self::requiredString($data['scope'] ?? null, 'campaign scope'),
        );
        $dispatchKey = 'campaign_step_due';
        $variantStrategy = self::variantStrategy(
            $data['variant_strategy'] ?? 'first_available',
        );
        $sourceVersion = self::nullableString($data['source_version'] ?? null);
        $stepData = $data['steps'] ?? null;

        if (! is_array($stepData) || ! array_is_list($stepData) || $stepData === []) {
            throw new InvalidArgumentException(
                'Campaign preset ['.$key.'] steps must be a non-empty list.'
            );
        }

        $steps = [];

        foreach ($stepData as $index => $step) {
            if (! is_array($step)) {
                throw new InvalidArgumentException(
                    'Campaign preset ['.$key.'] step ['.($index + 1).'] must be an object.'
                );
            }

            $steps[] = CampaignStepPresetDefinition::fromArray(
                data: $step,
                stepNumber: $index + 1,
                fallbackDispatchKey: $dispatchKey,
                fallbackPurpose: $purpose,
                fallbackScope: $scope,
                fallbackVariantStrategy: $variantStrategy,
                fallbackSourceVersion: $sourceVersion,
            );
        }

        return new self(
            key: $key,
            name: self::requiredString($data['name'] ?? null, 'campaign name'),
            description: self::nullableString($data['description'] ?? null),
            channel: self::aggregateSegment(
                array_merge(...array_map(
                    fn (CampaignStepPresetDefinition $step): array => array_map(
                        fn (CampaignStepVariantPresetDefinition $variant): string => $variant->channel,
                        $step->variants,
                    ),
                    $steps,
                )),
            ),
            purpose: $purpose,
            scope: $scope,
            status: self::campaignStatus($data['status'] ?? null),
            dispatchKey: $dispatchKey,
            variantStrategy: $variantStrategy,
            sourceVersion: $sourceVersion,
            steps: $steps,
            meta: is_array($data['meta'] ?? null) ? $data['meta'] : [],
        );
    }

    /**
     * @param array<int, string> $segments
     */
    public static function aggregateSegment(array $segments): string
    {
        $segments = array_values(array_unique(array_filter(array_map(
            fn (mixed $segment): ?string => is_string($segment) && trim($segment) !== ''
                ? self::normalizeSegment($segment)
                : null,
            $segments,
        ))));

        if ($segments === []) {
            throw new InvalidArgumentException(
                'Campaign message identity requires at least one non-empty segment.'
            );
        }

        return count($segments) === 1
            ? $segments[0]
            : self::CHANNEL_MULTI;
    }

    public static function variantStrategy(mixed $value): string
    {
        $strategy = self::normalizeSegment(
            self::nullableString($value) ?? 'first_available',
        );

        if (! in_array($strategy, [
            'first_available',
            'send_all_eligible',
            'dependency_aware',
        ], true)) {
            throw new InvalidArgumentException(
                'Unsupported Campaign variant strategy ['.$strategy.'].'
            );
        }

        return $strategy;
    }

    private static function campaignStatus(mixed $value): string
    {
        $status = self::normalizeSegment(
            self::nullableString($value) ?? Campaign::STATUS_ACTIVE,
        );

        if (! in_array($status, [
            Campaign::STATUS_ACTIVE,
            Campaign::STATUS_INACTIVE,
            Campaign::STATUS_ARCHIVED,
        ], true)) {
            throw new InvalidArgumentException("Unsupported campaign status [{$status}].");
        }

        return $status;
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

    private static function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
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