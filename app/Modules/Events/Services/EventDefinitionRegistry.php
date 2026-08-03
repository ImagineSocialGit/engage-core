<?php

namespace App\Modules\Events\Services;

use App\Modules\Events\Contracts\EventDefinitionContributor;
use App\Modules\Events\Data\EventDefinitionContribution;
use InvalidArgumentException;

class EventDefinitionRegistry
{
    /**
     * @var array<string, array<string, EventDefinitionContribution>>|null
     */
    private ?array $resolved = null;

    /**
     * @param array<string, mixed> $baseDefinitions
     * @param iterable<int, EventDefinitionContributor> $contributors
     */
    public function __construct(
        private readonly array $baseDefinitions = [],
        private readonly iterable $contributors = [],
    ) {}

    /**
     * @return array<string, array<string, EventDefinitionContribution>>
     */
    public function all(): array
    {
        return $this->resolve();
    }

    /**
     * @return array<string, EventDefinitionContribution>
     */
    public function definitions(string $category, bool $activeOnly = true): array
    {
        $this->assertCategory($category);

        $definitions = $this->resolve()[$category];

        if ($activeOnly) {
            $definitions = array_filter(
                $definitions,
                static fn (EventDefinitionContribution $definition): bool => $definition->isActive,
            );
        }

        return $definitions;
    }

    public function get(
        string $category,
        string $key,
        bool $activeOnly = true,
    ): ?EventDefinitionContribution {
        $definitions = $this->definitions($category, $activeOnly);

        return $definitions[$key] ?? null;
    }

    public function has(
        string $category,
        string $key,
        bool $activeOnly = true,
    ): bool {
        return $this->get($category, $key, $activeOnly) instanceof EventDefinitionContribution;
    }

    /**
     * @return array<int, string>
     */
    public function keys(string $category, bool $activeOnly = true): array
    {
        return array_keys($this->definitions($category, $activeOnly));
    }

    /**
     * @return array<string, array<string, EventDefinitionContribution>>
     */
    private function resolve(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $resolved = array_fill_keys(
            EventDefinitionContribution::CATEGORIES,
            [],
        );

        foreach ($this->baseDefinitions as $category => $definitions) {
            if (! is_string($category)) {
                throw new InvalidArgumentException(
                    'Event definition configuration categories must be strings.'
                );
            }

            $this->assertCategory($category);

            if (! is_array($definitions) || array_is_list($definitions)) {
                throw new InvalidArgumentException(
                    "Event definition configuration category [{$category}] must be a keyed map."
                );
            }

            foreach ($definitions as $key => $definition) {
                if (! is_string($key) || ! is_array($definition)) {
                    throw new InvalidArgumentException(
                        "Event definition configuration category [{$category}] contains an invalid definition."
                    );
                }

                $this->add(
                    $resolved,
                    EventDefinitionContribution::fromConfig(
                        category: $category,
                        key: $key,
                        definition: $definition,
                    ),
                    'config/events.php',
                );
            }
        }

        foreach ($this->contributors as $contributor) {
            if (! $contributor instanceof EventDefinitionContributor) {
                throw new InvalidArgumentException(sprintf(
                    'Event definition contributors must implement [%s].',
                    EventDefinitionContributor::class,
                ));
            }

            foreach ($contributor->definitions() as $definition) {
                if (! $definition instanceof EventDefinitionContribution) {
                    throw new InvalidArgumentException(sprintf(
                        'Event definition contributor [%s] returned an invalid definition.',
                        $contributor::class,
                    ));
                }

                $this->add($resolved, $definition, $contributor::class);
            }
        }

        foreach ($resolved as &$definitions) {
            uasort(
                $definitions,
                static fn (
                    EventDefinitionContribution $left,
                    EventDefinitionContribution $right,
                ): int => [
                    $left->sortOrder,
                    strtolower($left->label),
                    $left->key,
                ] <=> [
                    $right->sortOrder,
                    strtolower($right->label),
                    $right->key,
                ],
            );
        }
        unset($definitions);

        return $this->resolved = $resolved;
    }

    /**
     * @param array<string, array<string, EventDefinitionContribution>> $resolved
     */
    private function add(
        array &$resolved,
        EventDefinitionContribution $definition,
        string $source,
    ): void {
        $existing = $resolved[$definition->category][$definition->key] ?? null;

        if ($existing instanceof EventDefinitionContribution) {
            throw new InvalidArgumentException(
                "Duplicate Event definition [{$definition->category}:{$definition->key}] from [{$source}]."
            );
        }

        $resolved[$definition->category][$definition->key] = $definition;
    }

    private function assertCategory(string $category): void
    {
        if (! in_array($category, EventDefinitionContribution::CATEGORIES, true)) {
            throw new InvalidArgumentException(
                "Unsupported Event definition category [{$category}]."
            );
        }
    }
}