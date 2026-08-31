<?php

namespace App\Support\AutomationTriggers\Data;

final readonly class AutomationTriggerAuthoringDefinition
{
    public function __construct(
        public string $key,
        public string $moduleKey,
        public string $name,
        public string $description,
        public int $sortOrder = 100,
    ) {}
}