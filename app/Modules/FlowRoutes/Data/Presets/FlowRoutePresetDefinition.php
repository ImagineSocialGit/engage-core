<?php

namespace App\Modules\FlowRoutes\Data\Presets;

use App\Modules\FlowRoutes\Data\Points\PointPresetDefinition;
use App\Modules\FlowRoutes\Models\FlowRoute;
use InvalidArgumentException;

class FlowRoutePresetDefinition
{
    /**
     * @param array<int, PointPresetDefinition> $points
     * @param array<int, FlowRoutePointPresetDefinition> $flowRoutePoints
     * @param array<string, mixed> $trigger
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $presetKey,
        public readonly string $key,
        public readonly ?string $contactStatusKey,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly int $version = 1,
        public readonly bool $isActive = true,
        public readonly ?string $sourceVersion = null,
        public readonly ?string $ownerType = null,
        public readonly ?int $ownerId = null,
        public readonly ?string $ownerGroup = null,
        public readonly array $trigger = [],
        public readonly array $points = [],
        public readonly array $flowRoutePoints = [],
        public readonly array $meta = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $presetKey, array $data): self
    {
        $sourceVersion = self::string($data, 'source_version');
        $contactStatusKey = self::string($data, 'contact_status_key');
        $trigger = self::array($data, 'trigger');

        if ($contactStatusKey === null && $trigger === []) {
            throw new InvalidArgumentException('Preset FlowRoute must define either [contact_status_key] or [trigger].');
        }

        $points = [];
        $flowRoutePoints = [];

        foreach (self::arrayList($data, 'points') as $index => $pointData) {
            if (! is_array($pointData)) {
                continue;
            }

            $points[] = PointPresetDefinition::fromArray($pointData, $sourceVersion);

            $flowRoutePoints[] = FlowRoutePointPresetDefinition::fromEmbeddedPointArray(
                pointData: $pointData,
                fallbackSortOrder: (($index + 1) * 10),
                fallbackSourceVersion: $sourceVersion,
            );
        }

        return new self(
            presetKey: $presetKey,
            key: self::requiredString($data, 'key'),
            contactStatusKey: $contactStatusKey,
            name: self::requiredString($data, 'name'),
            description: self::string($data, 'description'),
            version: self::int($data, 'version') ?? 1,
            isActive: (bool) ($data['is_active'] ?? true),
            sourceVersion: $sourceVersion,
            ownerType: self::string($data, 'owner_type'),
            ownerId: self::int($data, 'owner_id'),
            ownerGroup: self::string($data, 'owner_group'),
            trigger: $trigger,
            points: $points,
            flowRoutePoints: $flowRoutePoints,
            meta: self::array($data, 'meta'),
        );
    }

    public function triggerType(): string
    {
        $triggerType = $this->trigger['type'] ?? null;

        if (is_string($triggerType) && trim($triggerType) !== '') {
            return trim($triggerType);
        }

        if ($this->contactStatusKey !== null) {
            return FlowRoute::TRIGGER_CONTACT_STATUS;
        }

        return FlowRoute::TRIGGER_MANUAL;
    }

    public function triggerKey(): ?string
    {
        if ($this->triggerType() === FlowRoute::TRIGGER_CONTACT_STATUS) {
            return $this->contactStatusKey;
        }

        $triggerKey = $this->trigger['event_key'] ?? null;

        if (! is_string($triggerKey)) {
            return null;
        }

        $triggerKey = trim($triggerKey);

        return $triggerKey !== '' ? $triggerKey : null;
    }

    public function shouldCreateDefaultBinding(): bool
    {
        return $this->isActive
            && in_array($this->triggerType(), [
                FlowRoute::TRIGGER_CONTACT_STATUS,
                FlowRoute::TRIGGER_AUTOMATION_EVENT,
            ], true)
            && $this->triggerKey() !== null;
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
