<?php

namespace App\Support\AutomationCapabilities\Contracts;

use App\Support\AutomationCapabilities\Data\AutomationCapabilityDefinition;

interface AutomationCapabilityContributor
{
    /**
     * @return iterable<int, AutomationCapabilityDefinition>
     */
    public function definitions(): iterable;
}
