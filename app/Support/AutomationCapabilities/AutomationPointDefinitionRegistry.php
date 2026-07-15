<?php

namespace App\Support\AutomationCapabilities;

use App\Support\AutomationCapabilities\Contracts\AutomationPointDefinitionContributor;
use App\Support\AutomationCapabilities\Data\AutomationPointDefinition;
use App\Support\AutomationCapabilities\Data\AutomationPointValidationContext;
use InvalidArgumentException;

class AutomationPointDefinitionRegistry
{
    /**
     * @var array<string, array{definition: AutomationPointDefinition, contributor: AutomationPointDefinitionContributor}>|null
     */
    private ?array $resolved = null;

    /**
     * @param iterable<int, AutomationPointDefinitionContributor> $contributors
     */
    public function __construct(
        private readonly iterable $contributors,
    ) {}

    /**
     * @return array<string, AutomationPointDefinition>
     */
    public function definitions(): array
    {
        return array_map(
            static fn (array $entry): AutomationPointDefinition => $entry['definition'],
            $this->entries(),
        );
    }

    public function has(string $pointType): bool
    {
        return isset($this->entries()[trim($pointType)]);
    }

    public function get(string $pointType): ?AutomationPointDefinition
    {
        return $this->entries()[trim($pointType)]['definition'] ?? null;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $settings
     */
    public function validate(
        string $pointType,
        array $definition,
        array $settings,
        AutomationPointValidationContext $context,
    ): iterable {
        $entry = $this->entries()[trim($pointType)] ?? null;

        if ($entry === null) {
            return [];
        }

        return $entry['contributor']->validate(
            pointType: $pointType,
            definition: $definition,
            settings: $settings,
            context: $context,
        );
    }

    /**
     * @return array<string, array{definition: AutomationPointDefinition, contributor: AutomationPointDefinitionContributor}>
     */
    private function entries(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $entries = [];

        foreach ($this->contributors as $contributor) {
            if (! $contributor instanceof AutomationPointDefinitionContributor) {
                throw new InvalidArgumentException(sprintf(
                    'Automation point definition registry received invalid contributor [%s].',
                    get_debug_type($contributor),
                ));
            }

            foreach ($contributor->definitions() as $definition) {
                if (! $definition instanceof AutomationPointDefinition) {
                    throw new InvalidArgumentException(sprintf(
                        'Automation point definition contributor [%s] returned invalid definition [%s].',
                        $contributor::class,
                        get_debug_type($definition),
                    ));
                }

                $pointType = trim($definition->pointType);

                if (isset($entries[$pointType])) {
                    throw new InvalidArgumentException(
                        "Duplicate automation point definition type [{$pointType}]."
                    );
                }

                $entries[$pointType] = [
                    'definition' => $definition,
                    'contributor' => $contributor,
                ];
            }
        }

        ksort($entries);

        return $this->resolved = $entries;
    }
}
