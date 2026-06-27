<?php

namespace App\Modules\FlowRoutes\Data\Presets;

use InvalidArgumentException;

class FlowRoutePresetDefinition
{
    /**
     * @param array<int, PointPresetDefinition> $points
     * @param array<int, FlowRoutePointPresetDefinition> $flowRoutePoints
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $presetKey,
        public readonly string $key,
        public readonly string $contactStatusKey,
        public readonly string $name,
        public readonly int $version = 1,
        public readonly bool $isActive = true,
        public readonly ?string $sourceVersion = null,
        public readonly array $points = [],
        public readonly array $flowRoutePoints = [],
        public readonly array $meta = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $presetKey, array $data): self
    {
        $sourceVersion = self::string($data, 'source_version')
            ?? self::string($data, 'version_key');

        $points = [];
        $flowRoutePoints = [];

        foreach (self::arrayList($data, 'points') as $index => $pointData) {
            if (! is_array($pointData)) {
                continue;
            }

            $points[] = PointPresetDefinition::fromArray($pointData, $sourceVersion);
            $flowRoutePoints[] = FlowRoutePointPresetDefinition::fromEmbeddedPointArray(
                pointData: $pointData,
                fallbackSortOrder: $index + 1,
                fallbackSourceVersion: $sourceVersion,
            );
        }

        foreach (self::arrayList($data, 'flow_route_points') as $index => $flowRoutePointData) {
            if (! is_array($flowRoutePointData)) {
                continue;
            }

            $flowRoutePoints[] = FlowRoutePointPresetDefinition::fromArray(
                data: $flowRoutePointData,
                fallbackSortOrder: count($flowRoutePoints) + $index + 1,
                fallbackSourceVersion: $sourceVersion,
            );
        }

        return new self(
            presetKey: $presetKey,
            key: self::requiredString($data, 'key'),
            contactStatusKey: self::requiredString($data, 'contact_status_key'),
            name: self::requiredString($data, 'name'),
            version: self::int($data, 'version') ?? 1,
            isActive: (bool) ($data['is_active'] ?? true),
            sourceVersion: $sourceVersion,
            points: $points,
            flowRoutePoints: $flowRoutePoints,
            meta: self::array($data, 'meta'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requiredString(array $data, string $key): string
    {
        $value = self::string($data, $key);

        if ($value === null) {
            throw new InvalidArgumentException("Preset FlowRoute is missing required [{$key}].");
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
     * @return array<int, mixed>
     */
    private static function arrayList(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        if (! is_array($value)) {
            return [];
        }

        return array_values($value);
    }
}