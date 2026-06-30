<?php

namespace App\Modules\FlowRoutes\Data\Presets;

use InvalidArgumentException;

class FlowRoutePointPresetDefinition
{
    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $settings
     * @param array<int, array<string, mixed>> $conditions
     * @param array<int, array<string, mixed>> $cancelConditions
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $key,
        public readonly string $pointKey,
        public readonly int $sortOrder,
        public readonly bool $isStart = false,
        public readonly bool $isActive = true,
        public readonly ?string $nextPointKey = null,
        public readonly array $definition = [],
        public readonly array $settings = [],
        public readonly array $conditions = [],
        public readonly array $cancelConditions = [],
        public readonly ?string $sourceVersion = null,
        public readonly array $meta = [],
    ) {}

    /**
     * @param array<string, mixed> $pointData
     */
    public static function fromEmbeddedPointArray(
        array $pointData,
        int $fallbackSortOrder,
        ?string $fallbackSourceVersion = null,
    ): self {
        $pointKey = self::requiredString($pointData, 'key');

        $definition = $pointData['default_definition'] ?? [];

        if (! is_array($definition)) {
            $definition = [];
        }

        $settings = $pointData['default_settings'] ?? [];

        if (! is_array($settings)) {
            $settings = [];
        }

        return new self(
            key: $pointKey,
            pointKey: $pointKey,
            sortOrder: self::int($pointData, 'sort_order') ?? $fallbackSortOrder,
            isStart: (bool) ($pointData['is_start'] ?? false),
            isActive: (bool) ($pointData['is_active'] ?? true),
            nextPointKey: self::string($pointData, 'next_point_key'),
            definition: $definition,
            settings: $settings,
            conditions: self::arrayList($pointData, 'conditions'),
            cancelConditions: self::arrayList($pointData, 'cancel_conditions'),
            sourceVersion: self::string($pointData, 'source_version') ?: $fallbackSourceVersion,
            meta: self::array($pointData, 'meta'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requiredString(array $data, string $key): string
    {
        $value = self::string($data, $key);

        if ($value === null) {
            throw new InvalidArgumentException("Preset FlowRoutePoint is missing required [{$key}].");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function array(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private static function arrayList(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            fn (mixed $item): bool => is_array($item),
        ));
    }
}