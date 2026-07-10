<?php

namespace App\Modules\Core\Validation;

use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetCompositionResolver;
use App\Support\Presets\PresetPackageResolver;
use Throwable;

class CoreSetupValidationContributor implements SetupValidationContributor
{
    private const SOURCE = 'preset_composition.contact_statuses';
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

    public function __construct(
        private readonly PresetCompositionResolver $compositionResolver,
        private readonly PresetPackageResolver $packageResolver,
    ) {}
public function findings(): iterable
    {
        $presetKey = $this->packageResolver->resolvePresetKey();

        if ($presetKey === null) {
            yield $this->error(
                code: 'core.contact_status.preset_package_missing',
                message: 'No selected preset package is configured for ContactStatus validation.',
                path: 'client.preset',
            );

            return;
        }

        try {
            $resolved = $this->compositionResolver->resolve(
                presetKey: $presetKey,
                domain: PresetDomain::ContactStatuses,
            );
        } catch (Throwable) {
            return;
        }

        foreach ($resolved->definitions as $statusKey => $definition) {
            $groupKey = $resolved->definitionGroups[$statusKey][0] ?? 'selected';
            $source = $resolved->provenance[$statusKey]['source'] ?? self::SOURCE;
            $path = "{$source}.definitions.{$statusKey}";

            yield from $this->validateDefinition(
                presetKey: $presetKey,
                groupKey: $groupKey,
                statusKey: $statusKey,
                definition: $definition,
                path: $path,
            );
        }
    }

    /**
     * @param array<int|string, mixed> $statusKeys
     * @return iterable<int, SetupValidationFinding>
     */

    /**
     * @param array<string, mixed> $definition
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateDefinition(
        string $presetKey,
        string $groupKey,
        string $statusKey,
        array $definition,
        string $path,
    ): iterable {
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
