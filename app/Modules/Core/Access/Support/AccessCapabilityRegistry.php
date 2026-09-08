<?php

namespace App\Modules\Core\Access\Support;

use App\Modules\Core\Access\Contracts\AccessCapabilityContributor;
use App\Modules\Core\Access\Data\AccessCapabilityDefinition;
use LogicException;

final class AccessCapabilityRegistry
{
    public const CONTRIBUTOR_TAG = 'core.access_capability_contributors';

    /** @var array<string, AccessCapabilityDefinition>|null */
    private ?array $definitions = null;

    /** @param iterable<int, AccessCapabilityContributor> $contributors */
    public function __construct(
        private readonly iterable $contributors,
    ) {}

    /** @return array<string, AccessCapabilityDefinition> */
    public function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $definitions = [];

        foreach ($this->contributors as $contributor) {
            foreach ($contributor->capabilities() as $definition) {
                if (! $definition instanceof AccessCapabilityDefinition) {
                    continue;
                }

                $key = trim($definition->key);

                if ($key === '') {
                    continue;
                }

                if (array_key_exists($key, $definitions)) {
                    throw new LogicException("Duplicate access capability [{$key}].");
                }

                $definitions[$key] = $definition;
            }
        }

        ksort($definitions);

        return $this->definitions = $definitions;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->definitions());
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->definitions());
    }
}