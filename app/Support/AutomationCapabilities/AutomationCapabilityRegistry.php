<?php

namespace App\Support\AutomationCapabilities;

use App\Support\AutomationCapabilities\Contracts\AutomationCapabilityContributor;
use App\Support\AutomationCapabilities\Data\AutomationCapabilityDefinition;
use InvalidArgumentException;

class AutomationCapabilityRegistry
{
    /**
     * @param iterable<int, AutomationCapabilityContributor> $contributors
     */
    public function __construct(
        private readonly iterable $contributors,
    ) {}

    /**
     * @return array<string, AutomationCapabilityDefinition>
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->contributors as $contributor) {
            foreach ($contributor->definitions() as $definition) {
                if (! $definition instanceof AutomationCapabilityDefinition) {
                    throw new InvalidArgumentException(sprintf(
                        'Automation capability contributor [%s] returned an invalid definition.',
                        $contributor::class,
                    ));
                }

                if (array_key_exists($definition->key, $definitions)) {
                    throw new InvalidArgumentException("Duplicate automation capability key [{$definition->key}].");
                }

                $definitions[$definition->key] = $definition;
            }
        }

        ksort($definitions);

        return $definitions;
    }
}
