<?php

namespace App\Support\AutomationCapabilities\Data;

final readonly class AutomationPointAuthoringDefinition
{
    /**
     * @param array<int, string> $useCases
     * @param array<int, string> $genericLabels
     * @param array<int, string> $generatedPrefixes
     */
    public function __construct(
        public string $pointType,
        public string $moduleKey,
        public string $name,
        public string $description,
        public string $tip = '',
        public array $useCases = [],
        public ?string $typeLabel = null,
        public array $genericLabels = [],
        public array $generatedPrefixes = [],
    ) {}
}
