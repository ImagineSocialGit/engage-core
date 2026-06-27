<?php

namespace App\Modules\FlowRoutes\Data\Presets;

use InvalidArgumentException;

class FlowRoutePointPresetDefinition
{
    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $cancelConditions
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $pointKey,
        public readonly int $sortOrder,
        public readonly bool $isActive = true,
        public readonly array $definition = [],
        public readonly array $settings = [],
        public readonly array $cancelConditions = [],
        public readonly ?string $sourceVersion = null,
        public readonly array $meta = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, int $fallbackSortOrder, ?string $fallbackSourceVersion = null): self
    {
        $pointKey = self::requiredString($data, 'point_key');

        return new self(
            pointKey: $pointKey,
            sortOrder: self::int($data, 'sort_order') ?? $fallbackSortOrder,
            isActive: (bool) ($data['is_active'] ?? true),
            definition: self::array($data, 'definition'),
            settings: self::array($data, 'settings'),
            cancelConditions: self::array($data, 'cancel_conditions'),
            sourceVersion: self::string($data, 'source_version') ?: $fallbackSourceVersion,
            meta: self::array($data, 'meta'),
        );
    }

    /**
     * @param array<string, mixed> $pointData
     */
    public static function fromEmbeddedPointArray(
        array $pointData,
        int $fallbackSortOrder,
        ?string $fallbackSourceVersion = null,
    ): self {
        $routePoint = $pointData['route_point']
            ?? $pointData['route']
            ?? [];

        if (! is_array($routePoint)) {
            $routePoint = [];
        }

        $routePoint['point_key'] = $pointData['key'] ?? null;

        return self::fromArray(
            data: $routePoint,
            fallbackSortOrder: $fallbackSortOrder,
            fallbackSourceVersion: $fallbackSourceVersion,
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
}