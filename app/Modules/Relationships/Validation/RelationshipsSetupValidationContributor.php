<?php

namespace App\Modules\Relationships\Validation;

use App\Modules\Core\Services\SiteSettings\SiteSettingResolver;
use App\Modules\Relationships\Services\RelationshipDefinitionRegistry;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use Throwable;

class RelationshipsSetupValidationContributor implements SetupValidationContributor
{
    private const MODULE = 'relationships';
    private const SOURCE = 'relationships';

    public function __construct(
        private readonly RelationshipDefinitionRegistry $definitions,
        private readonly SiteSettingResolver $siteSettings,
    ) {}

    public function findings(): iterable
    {
        $root = config('relationships', []);

        if (! is_array($root)) {
            yield $this->error(
                code: 'relationships.root_invalid',
                message: 'Relationships configuration must be an array.',
                path: 'relationships',
            );

            return;
        }

        $unknownRootFields = array_values(array_diff(
            array_keys($root),
            ['types', 'default_relationship', 'default_relationship_setting_key'],
        ));

        if ($unknownRootFields !== []) {
            sort($unknownRootFields);

            yield $this->error(
                code: 'relationships.root_unknown_fields',
                message: 'Relationships configuration contains unknown field(s): '.implode(', ', $unknownRootFields).'.',
                path: 'relationships',
            );
        }

        try {
            $definitions = $this->definitions->all();
        } catch (Throwable $exception) {
            yield $this->error(
                code: 'relationships.config_invalid',
                message: $exception->getMessage(),
                path: 'relationships.types',
            );

            return;
        }

        $configuredDefault = $this->nullableString(
            config('relationships.default_relationship'),
        );

        if ($configuredDefault !== null) {
            yield from $this->validateDefault(
                key: $configuredDefault,
                definitions: $definitions,
                path: 'relationships.default_relationship',
                codePrefix: 'relationships.default_relationship',
            );
        }

        $settingKey = trim((string) config(
            'relationships.default_relationship_setting_key',
            'crm.contacts.default_relationship',
        ));

        if ($settingKey === '') {
            yield $this->error(
                code: 'relationships.default_setting_key_invalid',
                message: 'Relationship default-setting key must be a non-empty string.',
                path: 'relationships.default_relationship_setting_key',
            );

            return;
        }

        $storedDefault = $this->nullableString(
            $this->siteSettings->get($settingKey),
        );

        if ($storedDefault !== null) {
            yield from $this->validateDefault(
                key: $storedDefault,
                definitions: $definitions,
                path: $settingKey,
                codePrefix: 'relationships.stored_default_relationship',
            );
        }
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateDefault(
        string $key,
        array $definitions,
        string $path,
        string $codePrefix,
    ): iterable {
        if (! isset($definitions[$key])) {
            yield $this->error(
                code: $codePrefix.'_unknown',
                message: "Default Contact relationship [{$key}] is not configured.",
                path: $path,
            );

            return;
        }

        if (! $definitions[$key]['visible']) {
            yield $this->error(
                code: $codePrefix.'_hidden',
                message: "Default Contact relationship [{$key}] is configured as hidden.",
                path: $path,
            );
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function error(
        string $code,
        string $message,
        string $path,
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_ERROR,
            code: $code,
            message: $message,
            source: self::SOURCE,
            path: $path,
            module: self::MODULE,
        );
    }
}