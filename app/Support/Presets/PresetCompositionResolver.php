<?php

namespace App\Support\Presets;

use App\Support\Presets\Data\ResolvedPresetDomain;
use App\Support\Presets\Enums\PresetDomain;
use InvalidArgumentException;

final class PresetCompositionResolver
{
    public function __construct(
        private readonly PresetContributionRegistry $registry,
        private readonly PresetPackageResolver $packageResolver,
    ) {}

    public function resolve(
        string $presetKey,
        PresetDomain $domain,
    ): ResolvedPresetDomain {
        $selectedGroups = $this->packageResolver->selectedGroups($presetKey, $domain);
        $availableGroups = $this->registry->groups($domain);
        $availableGroupProvenance = $this->registry->groupProvenance($domain);
        $availableDefinitions = $this->registry->definitions($domain);
        $availableProvenance = $this->registry->provenance($domain);

        $definitionKeys = [];
        $definitionGroups = [];
        $selectedContributors = [];

        foreach ($selectedGroups as $groupKey) {
            $groupDefinitionKeys = $availableGroups[$groupKey] ?? null;

            if (! is_array($groupDefinitionKeys)) {
                throw new InvalidArgumentException(sprintf(
                    'Preset package [%s] selects missing [%s] preset group [%s].',
                    $presetKey,
                    $domain->value,
                    $groupKey,
                ));
            }

            $groupContributor = $availableGroupProvenance[$groupKey]['contributor'] ?? null;

            if (is_string($groupContributor) && $groupContributor !== '') {
                $selectedContributors[] = $groupContributor;
            }

            foreach ($groupDefinitionKeys as $definitionKey) {
                if (! array_key_exists($definitionKey, $availableDefinitions)) {
                    throw new InvalidArgumentException(sprintf(
                        'Preset domain [%s] group [%s] references missing definition [%s].',
                        $domain->value,
                        $groupKey,
                        $definitionKey,
                    ));
                }

                $definitionKeys[] = $definitionKey;
                $definitionGroups[$definitionKey] ??= [];
                $definitionGroups[$definitionKey][] = $groupKey;
            }
        }

        $definitionKeys = array_values(array_unique($definitionKeys));
        $selectedContributors = array_values(array_unique($selectedContributors));
        $definitions = [];
        $provenance = [];

        foreach ($definitionKeys as $definitionKey) {
            $definitions[$definitionKey] = $availableDefinitions[$definitionKey];

            if (isset($availableProvenance[$definitionKey])) {
                $provenance[$definitionKey] = $availableProvenance[$definitionKey];
            }

            $definitionGroups[$definitionKey] = array_values(array_unique(
                $definitionGroups[$definitionKey] ?? [],
            ));
        }

        return new ResolvedPresetDomain(
            presetKey: $presetKey,
            domain: $domain,
            selectedGroups: $selectedGroups,
            selectedContributors: $selectedContributors,
            definitionKeys: $definitionKeys,
            definitions: $definitions,
            provenance: $provenance,
            definitionGroups: $definitionGroups,
        );
    }
}
