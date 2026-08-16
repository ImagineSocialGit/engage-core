<?php

namespace App\Support\Reporting;

use App\Support\Reporting\Contracts\ReportingEventDefinitionContributor;
use App\Support\Reporting\Data\ReportingEventDefinition;
use InvalidArgumentException;

final class ReportingEventDefinitionRegistry
{
    /** @var array<string, ReportingEventDefinition>|null */
    private ?array $resolved = null;

    /**
     * @param iterable<int, ReportingEventDefinitionContributor> $contributors
     */
    public function __construct(
        private readonly iterable $contributors,
    ) {}

    /**
     * @return array<string, ReportingEventDefinition>
     */
    public function definitions(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $definitions = [];

        foreach ($this->contributors as $contributor) {
            if (! $contributor instanceof ReportingEventDefinitionContributor) {
                throw new InvalidArgumentException(sprintf(
                    'Reporting event-definition registry received invalid contributor [%s].',
                    get_debug_type($contributor),
                ));
            }

            foreach ($contributor->definitions() as $definition) {
                if (! $definition instanceof ReportingEventDefinition) {
                    throw new InvalidArgumentException(sprintf(
                        'Reporting event-definition contributor [%s] returned invalid definition [%s].',
                        $contributor::class,
                        get_debug_type($definition),
                    ));
                }

                $identity = $this->identity($definition->key, $definition->version);

                if (isset($definitions[$identity])) {
                    throw new InvalidArgumentException(
                        "Duplicate Reporting event definition [{$identity}].",
                    );
                }

                $definitions[$identity] = $definition;
            }
        }

        ksort($definitions);

        return $this->resolved = $definitions;
    }

    public function get(string $key, int $version): ?ReportingEventDefinition
    {
        return $this->definitions()[$this->identity($key, $version)] ?? null;
    }

    public function require(string $key, int $version): ReportingEventDefinition
    {
        $definition = $this->get($key, $version);

        if (! $definition instanceof ReportingEventDefinition) {
            throw new InvalidArgumentException(
                "Unknown Reporting event definition [{$key}:{$version}].",
            );
        }

        return $definition;
    }

    private function identity(string $key, int $version): string
    {
        return trim($key).':'.$version;
    }
}