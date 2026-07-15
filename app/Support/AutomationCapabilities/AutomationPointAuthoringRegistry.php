<?php

namespace App\Support\AutomationCapabilities;

use App\Support\AutomationCapabilities\Contracts\AutomationPointAuthoringContributor;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringDefinition;
use InvalidArgumentException;

class AutomationPointAuthoringRegistry
{
    /** @var array<string, AutomationPointAuthoringDefinition> */
    private array $definitions = [];

    /** @var array<string, AutomationPointAuthoringContributor> */
    private array $contributors = [];

    /** @param iterable<int, AutomationPointAuthoringContributor> $contributors */
    public function __construct(iterable $contributors = [])
    {
        foreach ($contributors as $contributor) {
            if (! $contributor instanceof AutomationPointAuthoringContributor) {
                throw new InvalidArgumentException(sprintf(
                    'Automation point authoring contributors must implement [%s].',
                    AutomationPointAuthoringContributor::class,
                ));
            }

            foreach ($contributor->definitions() as $definition) {
                if (! $definition instanceof AutomationPointAuthoringDefinition) {
                    throw new InvalidArgumentException(sprintf(
                        'Automation point authoring definitions must be [%s] instances.',
                        AutomationPointAuthoringDefinition::class,
                    ));
                }

                $pointType = trim($definition->pointType);

                if ($pointType === '') {
                    throw new InvalidArgumentException('Automation point authoring type cannot be empty.');
                }

                if (isset($this->definitions[$pointType])) {
                    throw new InvalidArgumentException(
                        "Automation point authoring definition already registered for type [{$pointType}]."
                    );
                }

                $this->definitions[$pointType] = $definition;
                $this->contributors[$pointType] = $contributor;
            }
        }

        ksort($this->definitions);
        ksort($this->contributors);
    }

    public function has(string $pointType): bool
    {
        return isset($this->definitions[$pointType]);
    }

    public function get(string $pointType): ?AutomationPointAuthoringDefinition
    {
        return $this->definitions[$pointType] ?? null;
    }

    /** @return array<int, string> */
    public function registeredTypes(): array
    {
        return array_keys($this->definitions);
    }

    public function available(string $pointType, AutomationPointAuthoringContext $context): bool
    {
        return $this->contributor($pointType)->available($pointType, $context);
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<int, array<string, mixed>>
     */
    public function fields(
        string $pointType,
        array $definition,
        AutomationPointAuthoringContext $context,
    ): array {
        return $this->contributor($pointType)->fields($pointType, $definition, $context);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(string $pointType, AutomationPointAuthoringContext $context): array
    {
        return $this->contributor($pointType)->rules($pointType, $context);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function buildDefinition(
        string $pointType,
        array $input,
        AutomationPointAuthoringContext $context,
    ): array {
        return $this->contributor($pointType)->buildDefinition($pointType, $input, $context);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $definition
     */
    public function pointName(
        string $pointType,
        string $fallback,
        array $input,
        array $definition,
        AutomationPointAuthoringContext $context,
    ): string {
        return $this->contributor($pointType)->pointName(
            $pointType,
            $fallback,
            $input,
            $definition,
            $context,
        );
    }

    /** @param array<string, mixed> $definition */
    public function summary(
        string $pointType,
        array $definition,
        AutomationPointAuthoringContext $context,
    ): string {
        return $this->contributor($pointType)->summary($pointType, $definition, $context);
    }

    /** @param array<string, mixed> $definition */
    public function editorSummary(
        string $pointType,
        array $definition,
        AutomationPointAuthoringContext $context,
    ): string {
        return $this->contributor($pointType)->editorSummary($pointType, $definition, $context);
    }

    private function contributor(string $pointType): AutomationPointAuthoringContributor
    {
        $contributor = $this->contributors[$pointType] ?? null;

        if (! $contributor instanceof AutomationPointAuthoringContributor) {
            throw new InvalidArgumentException(
                "No automation point authoring contributor is registered for type [{$pointType}]."
            );
        }

        return $contributor;
    }
}
