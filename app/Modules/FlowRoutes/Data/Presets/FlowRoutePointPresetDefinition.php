<?php

namespace App\Modules\FlowRoutes\Data\Presets;

use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use InvalidArgumentException;

class FlowRoutePointPresetDefinition
{
    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $settings
     * @param array<int, array<string, mixed>> $cancelConditions
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly ?string $capabilityKey = null,
        public readonly int $sortOrder = 0,
        public readonly bool $isStart = false,
        public readonly bool $isActive = true,
        public readonly ?string $nextPointKey = null,
        public readonly array $definition = [],
        public readonly array $settings = [],
        public readonly array $cancelConditions = [],
        public readonly ?string $sourceVersion = null,
        public readonly array $meta = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(
        array $data,
        int $fallbackSortOrder,
        ?string $fallbackSourceVersion = null,
    ): self {
        $key = self::requiredString($data, 'key');
        $type = self::requiredString($data, 'type');

        if (! in_array($type, FlowRoutePointType::values(), true)) {
            throw new InvalidArgumentException("Unsupported FlowRoutePoint type [{$type}] for preset route point [{$key}].");
        }

        return new self(
            key: $key,
            type: $type,
            name: self::string($data, 'name') ?: self::nameFromKey($key),
            description: self::string($data, 'description'),
            capabilityKey: self::string($data, 'capability_key'),
            sortOrder: self::int($data, 'sort_order') ?? $fallbackSortOrder,
            isStart: (bool) ($data['is_start'] ?? false),
            isActive: (bool) ($data['is_active'] ?? true),
            nextPointKey: self::string($data, 'next_point_key'),
            definition: self::array($data, 'definition'),
            settings: self::array($data, 'settings'),
            cancelConditions: self::arrayList($data, 'cancel_conditions'),
            sourceVersion: self::string($data, 'source_version') ?: $fallbackSourceVersion,
            meta: self::array($data, 'meta'),
        );
    }

    /** @param array<string, mixed> $data */
    private static function requiredString(array $data, string $key): string
    {
        $value = self::string($data, $key);

        if ($value === null) {
            throw new InvalidArgumentException("Preset FlowRoutePoint is missing required [{$key}].");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $data */
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

    private static function nameFromKey(string $key): string
    {
        return str($key)->replace(['-', '_'], ' ')->headline()->toString();
    }
}
