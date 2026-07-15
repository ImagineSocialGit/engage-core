<?php

namespace App\Support\AutomationCapabilities\Data;

use App\Support\ConfigContracts\Data\ConfigSchema;
use InvalidArgumentException;

class AutomationPointDefinition
{
    public function __construct(
        public readonly string $pointType,
        public readonly ConfigSchema $schema,
    ) {
        if (trim($pointType) === '') {
            throw new InvalidArgumentException('Automation point definition type must be a non-empty string.');
        }
    }
}
