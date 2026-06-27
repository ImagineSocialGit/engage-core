<?php

namespace App\Modules\FlowRoutes\Data\Points;

use App\Modules\FlowRoutes\Models\Point;
use InvalidArgumentException;

class PointPresetDefinition
{
    /**
     * @param array<string, mixed> $defaultDefinition
     * @param array<string, mixed> $defaultSettings
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly array $defaultDefinition = [],
        public readonly array $defaultSettings = [],
        public readonly bool $isActive = true,
        public readonly ?string $sourceVersion = null,
        public readonly array $meta = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, ?string $fallbackSourceVersion = null): self
    {
        $key = self::requiredString($data, 'key');
        $type = self::requiredString($data, 'type');

        if (! in_array($type, Point::TYPES, true)) {
            throw new InvalidArgumentException("Unsupported FlowRoute Point type [{$type}] for preset Point [{$key}].");
        }

        return new self(
            key: $key,
            type: $type,
            name: self::string($data, 'name') ?: self::nameFromKey($key),
            description: self::string($data, 'description'),
            defaultDefinition: self::array($data, 'default_definition'),
            defaultSettings: self::array($data, 'default_settings'),
            isActive: (bool) ($data['is_active'] ?? true),
            sourceVersion: self::string($data, 'source_version') ?: $fallbackSourceVersion,
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
            throw new InvalidArgumentException("Preset Point is missing required [{$key}].");
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
     * @return array<string, mixed>
     */
    private static function array(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    private static function nameFromKey(string $key): string
    {
        return str($key)->replace(['-', '_'], ' ')->headline()->toString();
    }
}