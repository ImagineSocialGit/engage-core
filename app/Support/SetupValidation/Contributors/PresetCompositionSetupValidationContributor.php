<?php

namespace App\Support\SetupValidation\Contributors;

use App\Support\Modules\ModuleManager;
use App\Support\Presets\Data\PresetContribution;
use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetContributionRegistry;
use App\Support\Presets\PresetPackageResolver;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;

class PresetCompositionSetupValidationContributor implements SetupValidationContributor
{
    private const SOURCE = 'presets.composition';
    private const MODULE = 'app';

    public function __construct(
        private readonly PresetContributionRegistry $presetContributionRegistry,
        private readonly PresetPackageResolver $presetPackageResolver,
        private readonly ModuleManager $moduleManager,
    ) {}

    public function findings(): iterable
    {
        $packages = config('presets.packages', []);

        if (! is_array($packages)) {
            yield $this->error(
                code: 'app.presets.packages_invalid',
                message: 'presets.packages must be an array.',
                path: 'presets.packages',
            );

            return;
        }

        $presetKey = $this->presetPackageResolver->resolvePresetKey();

        if ($presetKey === null) {
            yield $this->error(
                code: 'app.presets.selected_package_missing',
                message: 'No preset package is selected or available.',
                path: 'presets.default_package',
            );

            return;
        }

        $package = $packages[$presetKey] ?? null;

        if (! is_array($package)) {
            yield $this->error(
                code: 'app.presets.selected_package_missing',
                message: "Selected preset package [{$presetKey}] does not exist.",
                path: "presets.packages.{$presetKey}",
                context: ['preset_key' => $presetKey],
            );

            return;
        }

        $contributionsByDomain = $this->contributionsByDomain();

        foreach (PresetDomain::cases() as $domain) {
            $contributions = $contributionsByDomain[$domain->value] ?? [];

            yield from $this->validateContributionCollisions(
                domain: $domain,
                contributions: $contributions,
            );

            yield from $this->validateSelectedDomainComposition(
                presetKey: $presetKey,
                package: $package,
                domain: $domain,
                contributions: $contributions,
            );
        }
    }

    /**
     * @return array<string, array<int, PresetContribution>>
     */
    private function contributionsByDomain(): array
    {
        $grouped = [];

        foreach ($this->presetContributionRegistry->contributions() as $contribution) {
            $grouped[$contribution->domain->value] ??= [];
            $grouped[$contribution->domain->value][] = $contribution;
        }

        return $grouped;
    }

    /**
     * @param array<int, PresetContribution> $contributions
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateContributionCollisions(
        PresetDomain $domain,
        array $contributions,
    ): iterable {
        $groupOwners = [];
        $definitionOwners = [];

        foreach ($contributions as $contribution) {
            foreach ($contribution->groups as $groupKey => $definitionKeys) {
                if (! is_string($groupKey) || trim($groupKey) === '') {
                    continue;
                }

                $groupKey = trim($groupKey);

                if (isset($groupOwners[$groupKey])) {
                    $existing = $groupOwners[$groupKey];

                    yield $this->error(
                        code: 'app.presets.duplicate_group_key',
                        message: sprintf(
                            'Preset domain [%s] group [%s] is defined by multiple contributors [%s] and [%s].',
                            $domain->value,
                            $groupKey,
                            $existing['contributor'],
                            $contribution->contributor,
                        ),
                        path: "presets.{$domain->value}.groups.{$groupKey}",
                        context: [
                            'domain' => $domain->value,
                            'group_key' => $groupKey,
                            'first_contributor' => $existing['contributor'],
                            'first_source' => $existing['source'],
                            'second_contributor' => $contribution->contributor,
                            'second_source' => $contribution->source,
                        ],
                    );

                    continue;
                }

                $groupOwners[$groupKey] = [
                    'contributor' => $contribution->contributor,
                    'source' => $contribution->source,
                ];
            }

            foreach ($contribution->definitions as $definitionKey => $definition) {
                if (! is_string($definitionKey) || trim($definitionKey) === '') {
                    continue;
                }

                $definitionKey = trim($definitionKey);

                if (isset($definitionOwners[$definitionKey])) {
                    $existing = $definitionOwners[$definitionKey];

                    yield $this->error(
                        code: 'app.presets.duplicate_definition_key',
                        message: sprintf(
                            'Preset domain [%s] definition [%s] is defined by multiple contributors [%s] and [%s].',
                            $domain->value,
                            $definitionKey,
                            $existing['contributor'],
                            $contribution->contributor,
                        ),
                        path: "presets.{$domain->value}.definitions.{$definitionKey}",
                        context: [
                            'domain' => $domain->value,
                            'definition_key' => $definitionKey,
                            'first_contributor' => $existing['contributor'],
                            'first_source' => $existing['source'],
                            'second_contributor' => $contribution->contributor,
                            'second_source' => $contribution->source,
                        ],
                    );

                    continue;
                }

                $definitionOwners[$definitionKey] = [
                    'contributor' => $contribution->contributor,
                    'source' => $contribution->source,
                ];
            }
        }
    }

    /**
     * @param array<string, mixed> $package
     * @param array<int, PresetContribution> $contributions
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateSelectedDomainComposition(
        string $presetKey,
        array $package,
        PresetDomain $domain,
        array $contributions,
    ): iterable {
        $selectedGroups = data_get($package, "groups.{$domain->value}", []);

        if (! is_array($selectedGroups)) {
            yield $this->error(
                code: 'app.presets.selected_groups_invalid',
                message: sprintf(
                    'Preset package [%s] groups.%s must be an array.',
                    $presetKey,
                    $domain->value,
                ),
                path: "presets.packages.{$presetKey}.groups.{$domain->value}",
                context: [
                    'preset_key' => $presetKey,
                    'domain' => $domain->value,
                ],
            );

            return;
        }

        $groups = [];
        $definitions = [];

        foreach ($contributions as $contribution) {
            foreach ($contribution->groups as $groupKey => $definitionKeys) {
                if (
                    ! is_string($groupKey)
                    || trim($groupKey) === ''
                    || array_key_exists(trim($groupKey), $groups)
                ) {
                    continue;
                }

                $groups[trim($groupKey)] = [
                    'definition_keys' => is_array($definitionKeys) ? $definitionKeys : [],
                    'contributor' => $contribution->contributor,
                    'source' => $contribution->source,
                ];
            }

            foreach ($contribution->definitions as $definitionKey => $definition) {
                if (
                    ! is_string($definitionKey)
                    || trim($definitionKey) === ''
                    || array_key_exists(trim($definitionKey), $definitions)
                ) {
                    continue;
                }

                $definitions[trim($definitionKey)] = true;
            }
        }

        $effectiveModules = $this->presetPackageResolver->effectiveModules($presetKey);
        $reportedDisabledContributors = [];

        foreach ($selectedGroups as $groupIndex => $groupKey) {
            if (! is_string($groupKey) || trim($groupKey) === '') {
                yield $this->error(
                    code: 'app.presets.selected_group_key_invalid',
                    message: sprintf(
                        'Preset package [%s] contains an invalid [%s] group key.',
                        $presetKey,
                        $domain->value,
                    ),
                    path: "presets.packages.{$presetKey}.groups.{$domain->value}.{$groupIndex}",
                    context: [
                        'preset_key' => $presetKey,
                        'domain' => $domain->value,
                    ],
                );

                continue;
            }

            $groupKey = trim($groupKey);
            $group = $groups[$groupKey] ?? null;

            if (! is_array($group)) {
                yield $this->error(
                    code: 'app.presets.selected_group_missing',
                    message: sprintf(
                        'Preset package [%s] selects missing [%s] preset group [%s].',
                        $presetKey,
                        $domain->value,
                        $groupKey,
                    ),
                    path: "presets.packages.{$presetKey}.groups.{$domain->value}.{$groupIndex}",
                    context: [
                        'preset_key' => $presetKey,
                        'domain' => $domain->value,
                        'group_key' => $groupKey,
                    ],
                );

                continue;
            }

            $contributor = $group['contributor'];

            if (
                $this->moduleManager->known($contributor)
                && ! in_array($contributor, $effectiveModules, true)
                && ! isset($reportedDisabledContributors[$contributor])
            ) {
                $reportedDisabledContributors[$contributor] = true;

                yield $this->warning(
                    code: 'app.presets.selected_contributor_disabled',
                    message: sprintf(
                        'Preset package [%s] selects [%s] definitions contributed by disabled module [%s].',
                        $presetKey,
                        $domain->value,
                        $contributor,
                    ),
                    path: "presets.packages.{$presetKey}.groups.{$domain->value}",
                    context: [
                        'preset_key' => $presetKey,
                        'domain' => $domain->value,
                        'contributor' => $contributor,
                        'source' => $group['source'],
                    ],
                );
            }

            foreach ($group['definition_keys'] as $definitionIndex => $definitionKey) {
                if (! is_string($definitionKey) || trim($definitionKey) === '') {
                    yield $this->error(
                        code: 'app.presets.group_definition_key_invalid',
                        message: sprintf(
                            'Preset domain [%s] group [%s] contains an invalid definition key.',
                            $domain->value,
                            $groupKey,
                        ),
                        path: "{$group['source']}.groups.{$groupKey}.{$definitionIndex}",
                        context: [
                            'preset_key' => $presetKey,
                            'domain' => $domain->value,
                            'group_key' => $groupKey,
                            'contributor' => $contributor,
                        ],
                    );

                    continue;
                }

                $definitionKey = trim($definitionKey);

                if (isset($definitions[$definitionKey])) {
                    continue;
                }

                yield $this->error(
                    code: 'app.presets.group_definition_missing',
                    message: sprintf(
                        'Preset domain [%s] group [%s] references missing definition [%s].',
                        $domain->value,
                        $groupKey,
                        $definitionKey,
                    ),
                    path: "{$group['source']}.groups.{$groupKey}.{$definitionIndex}",
                    context: [
                        'preset_key' => $presetKey,
                        'domain' => $domain->value,
                        'group_key' => $groupKey,
                        'definition_key' => $definitionKey,
                        'contributor' => $contributor,
                    ],
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function error(
        string $code,
        string $message,
        string $path,
        array $context = [],
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_ERROR,
            code: $code,
            message: $message,
            source: self::SOURCE,
            path: $path,
            module: self::MODULE,
            context: $context,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function warning(
        string $code,
        string $message,
        string $path,
        array $context = [],
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_WARNING,
            code: $code,
            message: $message,
            source: self::SOURCE,
            path: $path,
            module: self::MODULE,
            context: $context,
        );
    }
}
