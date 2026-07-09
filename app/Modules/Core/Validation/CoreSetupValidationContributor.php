<?php

namespace App\Modules\Core\Validation;

use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;

class CoreSetupValidationContributor implements SetupValidationContributor
{
    private const SOURCE = 'presets.contact-statuses';
    private const MODULE = 'core';

    private const CLIENT_FACING_NOUNS = [
        'lead',
        'leads',
        'fan',
        'fans',
        'customer',
        'customers',
        'borrower',
        'borrowers',
        'member',
        'members',
        'owner',
        'owners',
        'client',
        'clients',
    ];

    private const FIRST_CLASS_META_FIELDS = [
        'description',
        'category',
        'color',
        'source_version',
    ];

    public function findings(): iterable
    {
        $presetKey = $this->selectedPresetKey();

        if ($presetKey === null) {
            yield $this->error(
                code: 'core.contact_status.preset_package_missing',
                message: 'No selected preset package is configured for ContactStatus validation.',
                path: 'client.preset',
            );

            return;
        }

        $package = config("presets.packages.{$presetKey}");

        if (! is_array($package)) {
            yield $this->error(
                code: 'core.contact_status.preset_package_missing',
                message: "Selected preset package [{$presetKey}] does not exist.",
                path: "presets.packages.{$presetKey}",
                context: [
                    'preset_key' => $presetKey,
                ],
            );

            return;
        }

        $groups = $package['groups']['contact_statuses'] ?? [];

        if (! is_array($groups)) {
            yield $this->error(
                code: 'core.contact_status.selected_groups_invalid',
                message: "Preset package [{$presetKey}] groups.contact_statuses must be an array.",
                path: "presets.packages.{$presetKey}.groups.contact_statuses",
                context: [
                    'preset_key' => $presetKey,
                ],
            );

            return;
        }

        foreach ($groups as $index => $groupKey) {
            if (! $this->filledString($groupKey)) {
                yield $this->error(
                    code: 'core.contact_status.selected_group_key_invalid',
                    message: 'Selected ContactStatus preset group keys must be non-empty strings.',
                    path: "presets.packages.{$presetKey}.groups.contact_statuses.{$index}",
                    context: [
                        'preset_key' => $presetKey,
                    ],
                );

                continue;
            }

            $groupKey = trim($groupKey);
            $statusKeys = config("presets.contact-statuses.groups.{$groupKey}");

            if (! is_array($statusKeys)) {
                yield $this->error(
                    code: 'core.contact_status.group_missing',
                    message: "Selected ContactStatus preset group [{$groupKey}] does not exist.",
                    path: "presets.contact-statuses.groups.{$groupKey}",
                    context: [
                        'preset_key' => $presetKey,
                        'group_key' => $groupKey,
                    ],
                );

                continue;
            }

            yield from $this->validateGroup(
                presetKey: $presetKey,
                groupKey: $groupKey,
                statusKeys: $statusKeys,
            );
        }
    }

    /**
     * @param array<int|string, mixed> $statusKeys
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateGroup(
        string $presetKey,
        string $groupKey,
        array $statusKeys,
    ): iterable {
        foreach ($statusKeys as $index => $statusKey) {
            if (! $this->filledString($statusKey)) {
                yield $this->error(
                    code: 'core.contact_status.group_reference_invalid',
                    message: "ContactStatus preset group [{$groupKey}] contains an invalid definition reference.",
                    path: "presets.contact-statuses.groups.{$groupKey}.{$index}",
                    context: [
                        'preset_key' => $presetKey,
                        'group_key' => $groupKey,
                    ],
                );

                continue;
            }

            $statusKey = trim($statusKey);
            $definitionPath = "presets.contact-statuses.definitions.{$statusKey}";
            $definition = config($definitionPath);

            if (! is_array($definition)) {
                yield $this->error(
                    code: 'core.contact_status.definition_missing',
                    message: "ContactStatus preset definition [{$statusKey}] does not exist.",
                    path: $definitionPath,
                    context: [
                        'preset_key' => $presetKey,
                        'group_key' => $groupKey,
                        'status_key' => $statusKey,
                    ],
                );

                continue;
            }

            yield from $this->validateDefinition(
                presetKey: $presetKey,
                groupKey: $groupKey,
                statusKey: $statusKey,
                definition: $definition,
            );
        }
    }

    /**
     * @param array<string, mixed> $definition
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateDefinition(
        string $presetKey,
        string $groupKey,
        string $statusKey,
        array $definition,
    ): iterable {
        $path = "presets.contact-statuses.definitions.{$statusKey}";
        $context = [
            'preset_key' => $presetKey,
            'group_key' => $groupKey,
            'status_key' => $statusKey,
        ];

        $definitionKey = $definition['key'] ?? null;

        if (! $this->filledString($definitionKey)) {
            yield $this->error(
                code: 'core.contact_status.definition_key_missing',
                message: "ContactStatus preset definition [{$statusKey}] is missing a non-empty [key].",
                path: "{$path}.key",
                context: $context,
            );
        } else {
            $definitionKey = trim($definitionKey);

            if ($definitionKey !== $statusKey) {
                yield $this->error(
                    code: 'core.contact_status.definition_key_mismatch',
                    message: "ContactStatus preset definition [{$statusKey}] key must match its definition key [{$definitionKey}].",
                    path: "{$path}.key",
                    context: $context + [
                        'definition_key' => $definitionKey,
                    ],
                );
            }

            if ($noun = $this->clientFacingNounInKey($definitionKey)) {
                yield $this->error(
                    code: 'core.contact_status.noncanonical_identifier',
                    message: "ContactStatus internal key [{$definitionKey}] uses client-facing noun [{$noun}] instead of canonical contact terminology.",
                    path: "{$path}.key",
                    context: $context + [
                        'definition_key' => $definitionKey,
                        'client_facing_noun' => $noun,
                    ],
                );
            }
        }

        if ($noun = $this->clientFacingNounInKey($statusKey)) {
            yield $this->error(
                code: 'core.contact_status.noncanonical_identifier',
                message: "ContactStatus definition identity [{$statusKey}] uses client-facing noun [{$noun}] instead of canonical contact terminology.",
                path: $path,
                context: $context + [
                    'client_facing_noun' => $noun,
                ],
            );
        }

        if (! $this->filledString($definition['name'] ?? null)) {
            yield $this->error(
                code: 'core.contact_status.definition_name_missing',
                message: "ContactStatus preset definition [{$statusKey}] is missing a non-empty [name].",
                path: "{$path}.name",
                context: $context,
            );
        }

        $meta = $definition['meta'] ?? [];

        if (! is_array($meta)) {
            yield $this->error(
                code: 'core.contact_status.meta_invalid',
                message: "ContactStatus preset definition [{$statusKey}] [meta] must be an array.",
                path: "{$path}.meta",
                context: $context,
            );

            return;
        }

        foreach (self::FIRST_CLASS_META_FIELDS as $field) {
            if (! array_key_exists($field, $meta)) {
                continue;
            }

            yield $this->warning(
                code: 'core.contact_status.first_class_field_duplicated_in_meta',
                message: "ContactStatus preset definition [{$statusKey}] duplicates first-class field [{$field}] inside [meta].",
                path: "{$path}.meta.{$field}",
                context: $context + [
                    'field' => $field,
                ],
            );
        }
    }

    private function selectedPresetKey(): ?string
    {
        foreach ([
            config('client.preset'),
            config('presets.default_package'),
        ] as $presetKey) {
            if ($this->filledString($presetKey)) {
                return trim($presetKey);
            }
        }

        return null;
    }

    private function clientFacingNounInKey(string $key): ?string
    {
        $segments = preg_split('/[^a-z0-9]+/', strtolower(trim($key))) ?: [];

        foreach ($segments as $segment) {
            if (in_array($segment, self::CLIENT_FACING_NOUNS, true)) {
                return $segment;
            }
        }

        return null;
    }

    private function filledString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
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
