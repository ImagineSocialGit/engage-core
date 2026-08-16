<?php

namespace App\Modules\Reporting\EventDefinitions;

use App\Support\Reporting\Contracts\ReportingEventDefinitionContributor;
use App\Support\Reporting\Data\ReportingEventDefinition;
use InvalidArgumentException;

final class ConfigReportingEventDefinitionContributor implements ReportingEventDefinitionContributor
{
    private const DEFINITION_KEYS = [
        'surfaces',
        'browser_hosts',
        'session_mode',
        'properties',
        'funnel_eligible',
    ];

    public function definitions(): iterable
    {
        $events = config('reporting.events', []);

        if (! is_array($events)) {
            throw new InvalidArgumentException('Reporting [events] config must be an array.');
        }

        foreach ($events as $eventKey => $versions) {
            if (! is_string($eventKey) || trim($eventKey) === '') {
                throw new InvalidArgumentException('Reporting event config keys must be non-empty strings.');
            }

            if (! is_array($versions) || $versions === []) {
                throw new InvalidArgumentException(
                    "Reporting event [{$eventKey}] must contain a non-empty version map.",
                );
            }

            foreach ($versions as $version => $definition) {
                if (! is_int($version)
                    && (! is_string($version) || ! ctype_digit($version))
                ) {
                    throw new InvalidArgumentException(
                        "Reporting event [{$eventKey}] version keys must be positive integers.",
                    );
                }

                if (! is_array($definition)) {
                    throw new InvalidArgumentException(
                        "Reporting event [{$eventKey}:{$version}] definition must be an array.",
                    );
                }

                $unknownKeys = array_values(array_diff(
                    array_keys($definition),
                    self::DEFINITION_KEYS,
                ));

                if ($unknownKeys !== []) {
                    throw new InvalidArgumentException(sprintf(
                        'Reporting event [%s:%s] contains unsupported definition key(s): %s.',
                        $eventKey,
                        (string) $version,
                        implode(', ', $unknownKeys),
                    ));
                }

                if (array_key_exists('session_mode', $definition)
                    && ! is_string($definition['session_mode'])
                ) {
                    throw new InvalidArgumentException(
                        "Reporting event [{$eventKey}:{$version}] session_mode must be a string.",
                    );
                }

                if (array_key_exists('properties', $definition)
                    && ! is_array($definition['properties'])
                ) {
                    throw new InvalidArgumentException(
                        "Reporting event [{$eventKey}:{$version}] properties must be an array.",
                    );
                }

                if (array_key_exists('funnel_eligible', $definition)
                    && ! is_bool($definition['funnel_eligible'])
                ) {
                    throw new InvalidArgumentException(
                        "Reporting event [{$eventKey}:{$version}] funnel_eligible must be boolean.",
                    );
                }

                yield new ReportingEventDefinition(
                    key: strtolower(trim($eventKey)),
                    version: (int) $version,
                    surfaces: $this->stringList(
                        $definition['surfaces'] ?? [],
                        "Reporting event [{$eventKey}:{$version}] surfaces",
                        normalizeSurface: true,
                    ),
                    sessionMode: array_key_exists('session_mode', $definition)
                        ? strtolower(trim($definition['session_mode']))
                        : ReportingEventDefinition::SESSION_OPTIONAL,
                    properties: $definition['properties'] ?? [],
                    funnelEligible: $definition['funnel_eligible'] ?? false,
                    browserHosts: $this->stringList(
                        $definition['browser_hosts'] ?? [],
                        "Reporting event [{$eventKey}:{$version}] browser_hosts",
                        normalizeSurface: false,
                    ),
                );
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function stringList(
        mixed $values,
        string $label,
        bool $normalizeSurface,
    ): array
    {
        if (! is_array($values) || ! array_is_list($values)) {
            throw new InvalidArgumentException("{$label} must be a list.");
        }

        $normalized = [];

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException("{$label} must contain only non-empty strings.");
            }

            $value = strtolower(trim($value));
            $normalized[] = $normalizeSurface
                ? str_replace('-', '_', $value)
                : $value;
        }

        return array_values(array_unique($normalized));
    }
}