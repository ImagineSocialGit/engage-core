<?php

namespace App\Modules\Forms\Actions;

use App\Modules\Forms\Data\FormPresetSyncResult;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormVersion;
use App\Support\Presets\Data\ResolvedPresetDomain;
use App\Support\Presets\Enums\PresetDomain;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SyncFormPresetsAction
{
    private const SOURCE_PRESET = 'preset';

    private const FORM_KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    private const FIELD_TYPES = [
        'text',
        'email',
        'tel',
        'url',
        'number',
        'textarea',
        'select',
        'radio',
        'checkbox',
        'checkboxes',
        'boolean',
        'date',
        'datetime',
        'hidden',
    ];

    private const CATEGORIES = [
        FormDefinition::CATEGORY_INTAKE,
        FormDefinition::CATEGORY_QUESTIONNAIRE,
        FormDefinition::CATEGORY_REVIEW,
        FormDefinition::CATEGORY_REQUEST,
        FormDefinition::CATEGORY_FEEDBACK,
    ];

    public function handle(ResolvedPresetDomain $resolved): FormPresetSyncResult
    {
        if ($resolved->domain !== PresetDomain::Forms) {
            throw new InvalidArgumentException(sprintf(
                'Form preset sync requires domain [%s]; received [%s].',
                PresetDomain::Forms->value,
                $resolved->domain->value,
            ));
        }

        $definitions = [];

        foreach ($resolved->definitions as $definitionKey => $definition) {
            $definitions[] = $this->normalizeDefinition(
                definitionKey: $definitionKey,
                definition: $definition,
                resolved: $resolved,
            );
        }

        return DB::transaction(function () use ($definitions): FormPresetSyncResult {
            $result = new FormPresetSyncResult();

            foreach ($definitions as $definition) {
                $this->syncDefinition($definition, $result);
            }

            return $result;
        });
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function syncDefinition(
        array $definition,
        FormPresetSyncResult $result,
    ): void {
        $form = FormDefinition::query()
            ->withTrashed()
            ->where('key', $definition['key'])
            ->lockForUpdate()
            ->first();

        $created = false;

        if ($form instanceof FormDefinition && $form->trashed()) {
            throw new InvalidArgumentException(sprintf(
                'Form preset [%s] collides with a soft-deleted FormDefinition. Restore or permanently remove the existing record before syncing.',
                $definition['key'],
            ));
        }

        if (! $form instanceof FormDefinition) {
            $form = FormDefinition::query()->create([
                'key' => $definition['key'],
                'name' => $definition['name'],
                'description' => $definition['description'],
                'status' => FormDefinition::STATUS_ACTIVE,
                'category' => $definition['category'],
                'is_public' => $definition['is_public'],
                'current_form_version_id' => null,
                'source' => self::SOURCE_PRESET,
                'provider' => null,
                'external_id' => null,
                'meta' => $definition['definition_meta'],
            ]);

            $created = true;
        } elseif ($form->source !== self::SOURCE_PRESET) {
            throw new InvalidArgumentException(sprintf(
                'Form preset [%s] collides with existing non-preset FormDefinition source [%s]. Preset sync will not overwrite manual or provider-owned forms.',
                $definition['key'],
                (string) $form->source,
            ));
        }

        $currentVersion = $form->currentVersion()->first();

        if (
            $currentVersion instanceof FormVersion
            && $currentVersion->source !== self::SOURCE_PRESET
        ) {
            throw new InvalidArgumentException(sprintf(
                'Preset-owned FormDefinition [%s] points at non-preset FormVersion [%s]. Resolve the ownership mismatch before syncing.',
                $definition['key'],
                $currentVersion->getKey(),
            ));
        }

        if (
            $currentVersion instanceof FormVersion
            && $currentVersion->status === FormVersion::STATUS_PUBLISHED
            && $currentVersion->published_at !== null
            && $this->versionMatches($currentVersion, $definition['version_snapshot'])
        ) {
            $version = $currentVersion;
            $result->versionsReused++;
        } else {
            $nextVersion = ((int) $form->versions()
                ->withTrashed()
                ->max('version')) + 1;

            $version = $form->versions()->create([
                'version' => $nextVersion,
                'status' => FormVersion::STATUS_PUBLISHED,
                ...$definition['version_snapshot'],
                'published_at' => now(),
                'archived_at' => null,
                'source' => self::SOURCE_PRESET,
                'provider' => null,
                'external_id' => null,
                'meta' => $definition['version_meta'],
            ]);

            $result->versionsPublished++;
        }

        $definitionChanged = $this->definitionChanged(
            form: $form,
            definition: $definition,
            version: $version,
        );

        if ($definitionChanged) {
            $form->forceFill([
                'name' => $definition['name'],
                'description' => $definition['description'],
                'status' => FormDefinition::STATUS_ACTIVE,
                'category' => $definition['category'],
                'is_public' => $definition['is_public'],
                'current_form_version_id' => $version->getKey(),
                'source' => self::SOURCE_PRESET,
                'provider' => null,
                'external_id' => null,
                'meta' => $definition['definition_meta'],
            ])->save();
        }

        if ($created) {
            $result->definitionsCreated++;

            return;
        }

        if (! $definitionChanged) {
            $result->definitionsUnchanged++;

            return;
        }

        $result->definitionsUpdated++;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function definitionChanged(
        FormDefinition $form,
        array $definition,
        FormVersion $version,
    ): bool {
        return $form->name !== $definition['name']
            || $form->description !== $definition['description']
            || $form->status !== FormDefinition::STATUS_ACTIVE
            || $form->category !== $definition['category']
            || (bool) $form->is_public !== $definition['is_public']
            || (int) $form->current_form_version_id !== (int) $version->getKey()
            || $form->source !== self::SOURCE_PRESET
            || $form->provider !== null
            || $form->external_id !== null
            || $this->canonicalize($form->meta ?? [])
                !== $this->canonicalize($definition['definition_meta']);
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function normalizeDefinition(
        string $definitionKey,
        array $definition,
        ResolvedPresetDomain $resolved,
    ): array {
        $key = $this->normalizeKey(
            $definition['key'] ?? $definitionKey,
            "Form definition [{$definitionKey}] key",
        );

        if ($key !== $definitionKey) {
            throw new InvalidArgumentException(sprintf(
                'Form definition [%s] key [%s] must match its definition-map key.',
                $definitionKey,
                $key,
            ));
        }

        $name = $this->requiredString(
            $definition['name'] ?? null,
            "Form definition [{$definitionKey}] name",
            255,
        );
        $description = $this->nullableString(
            $definition['description'] ?? null,
            "Form definition [{$definitionKey}] description",
        );
        $category = strtolower($this->nullableString(
            $definition['category'] ?? null,
            "Form definition [{$definitionKey}] category",
        ) ?? FormDefinition::CATEGORY_INTAKE);

        if (! in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Form definition [%s] category [%s] is unsupported. Allowed categories: %s.',
                $definitionKey,
                $category,
                implode(', ', self::CATEGORIES),
            ));
        }

        $isPublic = $definition['is_public'] ?? false;

        if (! is_bool($isPublic)) {
            throw new InvalidArgumentException(
                "Form definition [{$definitionKey}] is_public must be a boolean.",
            );
        }

        $schema = $this->normalizeSchema(
            value: $definition['schema'] ?? null,
            definitionKey: $definitionKey,
        );
        $rules = $this->arrayValue($definition['rules'] ?? [], "Form definition [{$definitionKey}] rules");
        $layout = $this->arrayValue($definition['layout'] ?? [], "Form definition [{$definitionKey}] layout");
        $settings = $this->arrayValue($definition['settings'] ?? [], "Form definition [{$definitionKey}] settings");
        $meta = $this->arrayValue($definition['meta'] ?? [], "Form definition [{$definitionKey}] meta");

        $provenance = [
            'preset_key' => $resolved->presetKey,
            'contributor' => $resolved->provenance[$definitionKey]['contributor'] ?? null,
            'source' => $resolved->provenance[$definitionKey]['source'] ?? null,
            'groups' => $resolved->definitionGroups[$definitionKey] ?? [],
        ];

        return [
            'key' => $key,
            'name' => $name,
            'description' => $description,
            'category' => $category,
            'is_public' => $isPublic,
            'definition_meta' => [
                ...$meta,
                'preset' => $provenance,
            ],
            'version_meta' => [
                'preset' => $provenance,
            ],
            'version_snapshot' => [
                'name' => $name,
                'description' => $description,
                'schema' => $schema,
                'rules' => $rules,
                'layout' => $layout,
                'settings' => $settings,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeSchema(mixed $value, string $definitionKey): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException(
                "Form definition [{$definitionKey}] schema must be an array.",
            );
        }

        $sections = $value['sections'] ?? null;

        if (! is_array($sections) || ! array_is_list($sections) || $sections === []) {
            throw new InvalidArgumentException(
                "Form definition [{$definitionKey}] schema.sections must be a non-empty list.",
            );
        }

        $sectionKeys = [];
        $fieldKeys = [];

        foreach ($sections as $sectionIndex => $section) {
            if (! is_array($section)) {
                throw new InvalidArgumentException(
                    "Form definition [{$definitionKey}] schema.sections.{$sectionIndex} must be an array.",
                );
            }

            $sectionKey = $this->normalizeKey(
                $section['key'] ?? null,
                "Form definition [{$definitionKey}] section {$sectionIndex} key",
            );

            if (isset($sectionKeys[$sectionKey])) {
                throw new InvalidArgumentException(
                    "Form definition [{$definitionKey}] contains duplicate section key [{$sectionKey}].",
                );
            }

            $sectionKeys[$sectionKey] = true;
            $value['sections'][$sectionIndex]['key'] = $sectionKey;
            $fields = $section['fields'] ?? null;

            if (! is_array($fields) || ! array_is_list($fields) || $fields === []) {
                throw new InvalidArgumentException(
                    "Form definition [{$definitionKey}] section [{$sectionKey}] fields must be a non-empty list.",
                );
            }

            foreach ($fields as $fieldIndex => $field) {
                if (! is_array($field)) {
                    throw new InvalidArgumentException(
                        "Form definition [{$definitionKey}] section [{$sectionKey}] field {$fieldIndex} must be an array.",
                    );
                }

                $fieldKey = $this->normalizeKey(
                    $field['key'] ?? null,
                    "Form definition [{$definitionKey}] field {$fieldIndex} key",
                );

                if (isset($fieldKeys[$fieldKey])) {
                    throw new InvalidArgumentException(
                        "Form definition [{$definitionKey}] contains duplicate field key [{$fieldKey}].",
                    );
                }

                $fieldKeys[$fieldKey] = true;
                $value['sections'][$sectionIndex]['fields'][$fieldIndex]['key'] = $fieldKey;
                $fieldType = strtolower($this->requiredString(
                    $field['type'] ?? null,
                    "Form definition [{$definitionKey}] field [{$fieldKey}] type",
                    50,
                ));

                $value['sections'][$sectionIndex]['fields'][$fieldIndex]['type'] = $fieldType;

                if (! in_array($fieldType, self::FIELD_TYPES, true)) {
                    throw new InvalidArgumentException(sprintf(
                        'Form definition [%s] field [%s] type [%s] is unsupported. Allowed field types: %s.',
                        $definitionKey,
                        $fieldKey,
                        $fieldType,
                        implode(', ', self::FIELD_TYPES),
                    ));
                }

                if (array_key_exists('required', $field) && ! is_bool($field['required'])) {
                    throw new InvalidArgumentException(
                        "Form definition [{$definitionKey}] field [{$fieldKey}] required must be a boolean.",
                    );
                }
            }
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function versionMatches(FormVersion $version, array $snapshot): bool
    {
        $current = [
            'name' => $version->name,
            'description' => $version->description,
            'schema' => $version->schema ?? [],
            'rules' => $version->rules ?? [],
            'layout' => $version->layout ?? [],
            'settings' => $version->settings ?? [],
        ];

        return $this->canonicalize($current) === $this->canonicalize($snapshot);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->canonicalize($item),
                $value,
            );
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value, string $label): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException("{$label} must be an array.");
        }

        return $value;
    }

    private function normalizeKey(mixed $value, string $label): string
    {
        $value = $this->requiredString($value, $label, 150);

        if (preg_match(self::FORM_KEY_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                "{$label} [{$value}] must use lowercase snake_case and begin with a letter.",
            );
        }

        return $value;
    }

    private function requiredString(
        mixed $value,
        string $label,
        int $maximumLength,
    ): string {
        if (! is_string($value)) {
            throw new InvalidArgumentException("{$label} must be a string.");
        }

        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException("{$label} cannot be empty.");
        }

        if (mb_strlen($value) > $maximumLength) {
            throw new InvalidArgumentException(
                "{$label} cannot exceed {$maximumLength} characters.",
            );
        }

        return $value;
    }

    private function nullableString(mixed $value, string $label): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("{$label} must be a string or null.");
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}