<?php

namespace App\Modules\FlowRoutes\Data\Presets;

use App\Modules\FlowRoutes\Models\FlowRoute;
use InvalidArgumentException;

class FlowRoutePresetDefinition
{
    /**
     * @param array<int, FlowRoutePointPresetDefinition> $points
     * @param array<string, mixed> $trigger
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $presetKey,
        public readonly string $key,
        public readonly ?string $contactStatusKey,
        public readonly string $name,
        public readonly ?string $description,
        public readonly int $version,
        public readonly bool $isActive,
        public readonly ?string $sourceVersion,
        public readonly ?string $ownerType,
        public readonly ?int $ownerId,
        public readonly ?string $ownerGroup,
        public readonly array $trigger,
        public readonly array $points,
        public readonly array $meta,
    ) {
        $this->validateRoutePointContract();
    }

    public function triggerType(): string
    {
        if ($this->contactStatusKey !== null) {
            return FlowRoute::TRIGGER_CONTACT_STATUS;
        }

        $triggerType = $this->trigger['type'] ?? null;

        return is_string($triggerType) && trim($triggerType) !== ''
            ? trim($triggerType)
            : FlowRoute::TRIGGER_MANUAL;
    }

    public function triggerKey(): ?string
    {
        if ($this->triggerType() === FlowRoute::TRIGGER_CONTACT_STATUS) {
            return $this->contactStatusKey;
        }

        if ($this->triggerType() !== FlowRoute::TRIGGER_AUTOMATION_EVENT) {
            return null;
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

    private function validateRoutePointContract(): void
    {
        $keys = [];
        $activeStartCount = 0;
        $activePointCount = 0;

        foreach ($this->points as $point) {
            if (isset($keys[$point->key])) {
                throw new InvalidArgumentException(
                    "Preset FlowRoute contains duplicate point key [{$point->key}]."
                );
            }

            $keys[$point->key] = true;

            if (! $point->isActive) {
                continue;
            }

            $activePointCount++;

            if ($point->isStart) {
                $activeStartCount++;
            }
        }

        if ($this->isActive && $activePointCount === 0) {
            throw new InvalidArgumentException(
                'Active preset FlowRoute must contain at least one active point.'
            );
        }

        if ($this->isActive && $activeStartCount !== 1) {
            throw new InvalidArgumentException(
                "Active preset FlowRoute must contain exactly one active start point; found [{$activeStartCount}]."
            );
        }

        foreach ($this->points as $point) {
            if ($point->nextPointKey === null) {
                continue;
            }

            if (! isset($keys[$point->nextPointKey])) {
                throw new InvalidArgumentException(
                    "Preset FlowRoutePoint [{$point->key}] references missing next point [{$point->nextPointKey}]."
                );
            }
        }
    }
}