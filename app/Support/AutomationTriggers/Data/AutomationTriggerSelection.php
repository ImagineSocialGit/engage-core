<?php

namespace App\Support\AutomationTriggers\Data;

final readonly class AutomationTriggerSelection
{
    /** @param array<int, array<string, mixed>> $entryConditions */
    public function __construct(
        public string $triggerType,
        public ?string $triggerKey,
        public ?int $contactStatusId = null,
        public array $entryConditions = [],
    ) {}
}