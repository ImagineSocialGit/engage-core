<?php

namespace App\Support\AutomationTriggers\Contracts;

use App\Support\AutomationTriggers\Data\AutomationTriggerAuthoringDefinition;
use App\Support\AutomationTriggers\Data\AutomationTriggerSelection;

interface AutomationTriggerAuthoringContributor
{
    /** @return iterable<int, AutomationTriggerAuthoringDefinition> */
    public function definitions(): iterable;

    public function available(string $authoringKey): bool;

    /** @return array<int, array<string, mixed>> */
    public function fields(string $authoringKey): array;

    /** @return array<string, array<int, mixed>> */
    public function rules(string $authoringKey): array;

    /** @param array<string, mixed> $input */
    public function selection(string $authoringKey, array $input): AutomationTriggerSelection;
}