<?php

namespace App\Support\AutomationCapabilities\Contracts;

use App\Support\AutomationCapabilities\Data\AutomationPointDefinition;
use App\Support\AutomationCapabilities\Data\AutomationPointValidationContext;
use App\Support\SetupValidation\Data\SetupValidationFinding;

interface AutomationPointDefinitionContributor
{
    /**
     * @return iterable<int, AutomationPointDefinition>
     */
    public function definitions(): iterable;

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $settings
     * @return iterable<int, SetupValidationFinding>
     */
    public function validate(
        string $pointType,
        array $definition,
        array $settings,
        AutomationPointValidationContext $context,
    ): iterable;
}
