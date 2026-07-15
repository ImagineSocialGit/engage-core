<?php

namespace App\Support\AutomationCapabilities\Data;

use Illuminate\Database\Eloquent\Model;

final readonly class AutomationPointAuthoringContext
{
    /**
     * @param array<int, string> $existingPointTypes
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public array $existingPointTypes = [],
        public ?Model $container = null,
        public ?Model $point = null,
        public ?Model $capability = null,
        public array $meta = [],
    ) {}

    public function hasPointType(string $pointType): bool
    {
        return in_array($pointType, $this->existingPointTypes, true);
    }
}
