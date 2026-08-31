<?php

namespace App\Support\AutomationTriggers;

use App\Support\AutomationTriggers\Contracts\AutomationTriggerAuthoringContributor;
use App\Support\AutomationTriggers\Data\AutomationTriggerAuthoringDefinition;
use App\Support\AutomationTriggers\Data\AutomationTriggerSelection;
use InvalidArgumentException;

final class AutomationTriggerAuthoringRegistry
{
    /** @var array<string, AutomationTriggerAuthoringDefinition> */
    private array $definitions = [];

    /** @var array<string, AutomationTriggerAuthoringContributor> */
    private array $contributors = [];

    /** @param iterable<int, AutomationTriggerAuthoringContributor> $contributors */
    public function __construct(iterable $contributors = [])
    {
        foreach ($contributors as $contributor) {
            if (! $contributor instanceof AutomationTriggerAuthoringContributor) {
                throw new InvalidArgumentException(sprintf(
                    'Automation trigger authoring contributors must implement [%s].',
                    AutomationTriggerAuthoringContributor::class,
                ));
            }

            foreach ($contributor->definitions() as $definition) {
                if (! $definition instanceof AutomationTriggerAuthoringDefinition) {
                    throw new InvalidArgumentException('Automation trigger definitions must use the shared definition object.');
                }

                $key = trim($definition->key);

                if ($key === '' || isset($this->definitions[$key])) {
                    throw new InvalidArgumentException("Automation trigger authoring key [{$key}] is empty or duplicated.");
                }

                $this->definitions[$key] = $definition;
                $this->contributors[$key] = $contributor;
            }
        }
    }

    /** @return array<int, AutomationTriggerAuthoringDefinition> */
    public function availableDefinitions(): array
    {
        $definitions = array_filter(
            $this->definitions,
            fn (AutomationTriggerAuthoringDefinition $definition): bool => $this->available($definition->key),
        );

        usort($definitions, fn ($left, $right): int => [
            $left->sortOrder,
            $left->moduleKey,
            $left->name,
        ] <=> [
            $right->sortOrder,
            $right->moduleKey,
            $right->name,
        ]);

        return array_values($definitions);
    }

    /** @return array<int, string> */
    public function availableKeys(): array
    {
        return array_map(
            fn (AutomationTriggerAuthoringDefinition $definition): string => $definition->key,
            $this->availableDefinitions(),
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function presentation(): array
    {
        return array_map(fn (AutomationTriggerAuthoringDefinition $definition): array => [
            'key' => $definition->key,
            'module_key' => $definition->moduleKey,
            'module_label' => (string) config(
                "modules.modules.{$definition->moduleKey}.name",
                str($definition->moduleKey)->headline(),
            ),
            'name' => $definition->name,
            'description' => $definition->description,
            'fields' => $this->fields($definition->key),
        ], $this->availableDefinitions());
    }

    public function available(string $authoringKey): bool
    {
        return isset($this->definitions[$authoringKey])
            && $this->contributor($authoringKey)->available($authoringKey);
    }

    /** @return array<int, array<string, mixed>> */
    public function fields(string $authoringKey): array
    {
        return $this->contributor($authoringKey)->fields($authoringKey);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(string $authoringKey): array
    {
        return $this->contributor($authoringKey)->rules($authoringKey);
    }

    /** @param array<string, mixed> $input */
    public function selection(string $authoringKey, array $input): AutomationTriggerSelection
    {
        if (! $this->available($authoringKey)) {
            throw new InvalidArgumentException("Automation trigger [{$authoringKey}] is not available.");
        }

        return $this->contributor($authoringKey)->selection($authoringKey, $input);
    }

    private function contributor(string $authoringKey): AutomationTriggerAuthoringContributor
    {
        return $this->contributors[$authoringKey]
            ?? throw new InvalidArgumentException("Automation trigger [{$authoringKey}] is not registered.");
    }
}