<?php

namespace App\Modules\FlowRoutes\Data\Presets;

class FlowRoutePointPresetDefinition
{
    /**
     * @param array<string, mixed> $definition
     * @param array<int, array<string, mixed>> $cancelConditions
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly string $name,
        public readonly ?string $description,
        public readonly string $capabilityKey,
        public readonly int $sortOrder,
        public readonly bool $isStart,
        public readonly bool $isActive,
        public readonly ?string $nextPointKey,
        public readonly array $definition,
        public readonly array $cancelConditions,
        public readonly ?string $sourceVersion,
    ) {}
}