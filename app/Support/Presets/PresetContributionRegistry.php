<?php

namespace App\Support\Presets;

use App\Support\Presets\Contracts\PresetContributor;
use App\Support\Presets\Data\PresetContribution;
use App\Support\Presets\Enums\PresetDomain;
use InvalidArgumentException;

final class PresetContributionRegistry
{
    /**
     * @var array<int, PresetContributor>
     */
    private array $contributors;

    /**
     * @param iterable<int, PresetContributor> $contributors
     */
    public function __construct(iterable $contributors)
    {
        $this->contributors = [];

        foreach ($contributors as $contributor) {
            if (! $contributor instanceof PresetContributor) {
                throw new InvalidArgumentException(sprintf(
                    'Preset contribution registry received invalid contributor [%s].',
                    get_debug_type($contributor),
                ));
            }

            $this->contributors[] = $contributor;
        }
    }

    /**
     * @return array<int, PresetContribution>
     */
    public function contributions(): array
    {
        $contributions = [];

        foreach ($this->contributors as $contributor) {
            foreach ($contributor->contributions() as $contribution) {
                if (! $contribution instanceof PresetContribution) {
                    throw new InvalidArgumentException(sprintf(
                        'Preset contributor [%s] returned invalid contribution [%s].',
                        $contributor::class,
                        get_debug_type($contribution),
                    ));
                }

                $contributions[] = $contribution;
            }
        }

        return $contributions;
    }

    /**
     * @return array<int, PresetContribution>
     */
    public function forDomain(PresetDomain $domain): array
    {
        return array_values(array_filter(
            $this->contributions(),
            fn (PresetContribution $contribution): bool => $contribution->domain === $domain,
        ));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function groups(PresetDomain $domain): array
    {
        $groups = [];
        $owners = [];

        foreach ($this->forDomain($domain) as $contribution) {
            foreach ($contribution->groups as $groupKey => $definitionKeys) {
                $groupKey = $this->requiredKey($groupKey, 'group');

                if (isset($owners[$groupKey])) {
                    throw new InvalidArgumentException(sprintf(
                        'Preset domain [%s] group [%s] is defined by multiple contributors [%s] and [%s].',
                        $domain->value,
                        $groupKey,
                        $owners[$groupKey],
                        $contribution->contributor,
                    ));
                }

                if (! is_array($definitionKeys)) {
                    throw new InvalidArgumentException(sprintf(
                        'Preset domain [%s] group [%s] from contributor [%s] must be an array.',
                        $domain->value,
                        $groupKey,
                        $contribution->contributor,
                    ));
                }

                $groups[$groupKey] = $this->normalizeStringList($definitionKeys);
                $owners[$groupKey] = $contribution->contributor;
            }
        }

        return $groups;
    }

    /**
     * @return array<string, array{contributor: string, source: string}>
     */
    public function groupProvenance(PresetDomain $domain): array
    {
        $provenance = [];

        foreach ($this->forDomain($domain) as $contribution) {
            foreach ($contribution->groups as $groupKey => $definitionKeys) {
                if (! is_string($groupKey) || trim($groupKey) === '') {
                    continue;
                }

                $groupKey = trim($groupKey);

                if (isset($provenance[$groupKey])) {
                    continue;
                }

                $provenance[$groupKey] = [
                    'contributor' => $contribution->contributor,
                    'source' => $contribution->source,
                ];
            }
        }

        return $provenance;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(PresetDomain $domain): array
    {
        $definitions = [];
        $owners = [];

        foreach ($this->forDomain($domain) as $contribution) {
            foreach ($contribution->definitions as $definitionKey => $definition) {
                $definitionKey = $this->requiredKey($definitionKey, 'definition');

                if (isset($owners[$definitionKey])) {
                    throw new InvalidArgumentException(sprintf(
                        'Preset domain [%s] definition [%s] is defined by multiple contributors [%s] and [%s].',
                        $domain->value,
                        $definitionKey,
                        $owners[$definitionKey],
                        $contribution->contributor,
                    ));
                }

                if (! is_array($definition)) {
                    throw new InvalidArgumentException(sprintf(
                        'Preset domain [%s] definition [%s] from contributor [%s] must be an array.',
                        $domain->value,
                        $definitionKey,
                        $contribution->contributor,
                    ));
                }

                $definitions[$definitionKey] = $definition;
                $owners[$definitionKey] = $contribution->contributor;
            }
        }

        return $definitions;
    }

    /**
     * @return array<string, array{contributor: string, source: string}>
     */
    public function provenance(PresetDomain $domain): array
    {
        $provenance = [];

        foreach ($this->forDomain($domain) as $contribution) {
            foreach ($contribution->definitions as $definitionKey => $definition) {
                if (! is_string($definitionKey) || trim($definitionKey) === '') {
                    continue;
                }

                $definitionKey = trim($definitionKey);

                if (isset($provenance[$definitionKey])) {
                    continue;
                }

                $provenance[$definitionKey] = [
                    'contributor' => $contribution->contributor,
                    'source' => $contribution->source,
                ];
            }
        }

        return $provenance;
    }

    private function requiredKey(mixed $value, string $type): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Preset {$type} keys must be non-empty strings.");
        }

        return trim($value);
    }

    /**
     * @param mixed $values
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $values): array
    {
        if (is_string($values)) {
            $values = [$values];
        }

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
                ? trim($value)
                : null,
            $values,
        ))));
    }
}
