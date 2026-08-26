<?php

namespace App\Support\ProjectState;

use Illuminate\Support\Facades\Schema;
use JsonException;
use RuntimeException;

class ProjectStateContractRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function sections(): array
    {
        $active = [];

        foreach ($this->configuredSections() as $sectionKey => $section) {
            if (! $this->sectionIsActive($sectionKey, $section)) {
                continue;
            }

            $active[$sectionKey] = $section;
        }

        return $active;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function configuredSections(): array
    {
        $sections = config('project_state.sections', []);

        if (! is_array($sections) || $sections === []) {
            throw new RuntimeException('No project-state sections are configured.');
        }

        $normalized = [];
        $tableOwners = [];

        foreach ($sections as $sectionKey => $section) {
            if (! is_string($sectionKey)
                || trim($sectionKey) === ''
                || ! is_array($section)
                || ! is_int($section['version'] ?? null)
                || ! is_array($section['tables'] ?? null)
                || (array_key_exists('optional', $section) && ! is_bool($section['optional']))
            ) {
                throw new RuntimeException('Project-state section configuration is invalid.');
            }

            $optional = $section['optional'] ?? false;
            $activationTables = array_key_exists('activation_tables', $section)
                ? $section['activation_tables']
                : array_keys($section['tables']);

            if ($optional
                && ! $this->isStringList($activationTables, allowEmpty: false)
            ) {
                throw new RuntimeException(
                    "Optional project-state section [{$sectionKey}] activation table configuration is invalid."
                );
            }

            if ($optional) {
                $activationTables = array_values(array_map(
                    static fn (string $table): string => trim($table),
                    $activationTables,
                ));

                if (count(array_unique($activationTables)) !== count($activationTables)) {
                    throw new RuntimeException(
                        "Optional project-state section [{$sectionKey}] activation table configuration is invalid."
                    );
                }
            }

            if (! $optional && array_key_exists('activation_tables', $section)) {
                throw new RuntimeException(
                    "Required project-state section [{$sectionKey}] cannot declare activation tables."
                );
            }

            $tables = [];

            foreach ($section['tables'] as $table => $definition) {
                if (! is_string($table) || trim($table) === '') {
                    throw new RuntimeException('Project-state table configuration is invalid.');
                }

                if (array_key_exists($table, $tableOwners)) {
                    throw new RuntimeException(sprintf(
                        'Project-state table [%s] is configured in both [%s] and [%s].',
                        $table,
                        $tableOwners[$table],
                        $sectionKey,
                    ));
                }

                $tableOwners[$table] = $sectionKey;
                $tables[$table] = $this->normalizeDefinition($table, $definition);
            }

            $normalized[$sectionKey] = [
                'version' => $section['version'],
                'optional' => $optional,
                'activation_tables' => $optional
                    ? array_values($activationTables)
                    : [],
                'tables' => $tables,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $section
     */
    private function sectionIsActive(string $sectionKey, array $section): bool
    {
        if (! $section['optional']) {
            return true;
        }

        $activationTables = $section['activation_tables'];
        $present = array_values(array_filter(
            $activationTables,
            fn (string $table): bool => Schema::hasTable($table),
        ));

        if ($present === []) {
            return false;
        }

        if (count($present) !== count($activationTables)) {
            $missing = array_values(array_diff($activationTables, $present));
            sort($missing, SORT_STRING);

            throw new RuntimeException(sprintf(
                'Optional project-state section [%s] has a partially installed activation schema. Missing table(s): %s.',
                $sectionKey,
                implode(', ', $missing),
            ));
        }

        return true;
    }

    /**
     * @param mixed $definition
     * @return array<string, mixed>
     */
    private function normalizeDefinition(string $table, mixed $definition): array
    {
        if (! is_array($definition)) {
            throw new RuntimeException("Project-state table [{$table}] configuration is invalid.");
        }

        $mode = $definition['mode'] ?? null;
        $columns = $definition['columns'] ?? null;
        $orderBy = $definition['order_by'] ?? ['id'];
        $identity = $definition['identity'] ?? [];
        $nullableIdentity = $definition['nullable_identity'] ?? [];
        $jsonColumns = $definition['json_columns'] ?? [];
        $references = $definition['references'] ?? [];
        $deferredReferences = $definition['deferred_references'] ?? [];
        $nullOnImport = $definition['null_on_import'] ?? [];
        $importValueMaps = $definition['import_value_maps'] ?? [];
        $importValueMapBackups = $definition['import_value_map_backups'] ?? [];
        $jsonPathValueMaps = $definition['json_path_value_maps'] ?? [];
        $jsonPathReferences = $definition['json_path_references'] ?? [];
        $polymorphicReferences = $definition['polymorphic_references'] ?? [];
        $resumeItems = $definition['resume_items'] ?? [];
        $preserveId = (bool) ($definition['preserve_id'] ?? true);

        if (! in_array($mode, ['insert_empty', 'upsert'], true)
            || ! $this->isStringList($columns, allowEmpty: false)
            || ! $this->isStringList($orderBy, allowEmpty: false)
            || ! $this->isStringList($identity)
            || ! $this->isStringList($nullableIdentity)
            || ! $this->isStringList($jsonColumns)
            || ! $this->isStringList($nullOnImport)
            || ! $this->isReferenceMap($references)
            || ! $this->isReferenceMap($deferredReferences)
            || ! $this->isImportValueMap($importValueMaps)
            || ! $this->isImportValueMapBackupMap($importValueMapBackups)
            || ! $this->isJsonPathValueMap($jsonPathValueMaps)
            || ! $this->isJsonPathReferenceMap($jsonPathReferences)
            || ! $this->isPolymorphicReferenceList($polymorphicReferences)
            || ! $this->isResumeItemList($resumeItems)
        ) {
            throw new RuntimeException("Project-state table [{$table}] configuration is invalid.");
        }

        if ($mode === 'upsert' && $identity === []) {
            throw new RuntimeException("Project-state table [{$table}] requires an upsert identity.");
        }

        if ($mode === 'insert_empty' && ! $preserveId) {
            throw new RuntimeException(
                "Project-state table [{$table}] must preserve IDs in insert-empty mode."
            );
        }

        $referencedColumns = [
            ...array_keys($references),
            ...array_keys($deferredReferences),
        ];

        foreach ([
            ...$orderBy,
            ...$identity,
            ...$nullableIdentity,
            ...$jsonColumns,
            ...$nullOnImport,
            ...$referencedColumns,
            ...array_keys($importValueMaps),
            ...array_keys($importValueMapBackups),
            ...array_keys($jsonPathValueMaps),
            ...array_keys($jsonPathReferences),
            ...$this->polymorphicReferenceColumns($polymorphicReferences),
            ...$this->resumeItemColumns($resumeItems),
        ] as $column) {
            if (! in_array($column, $columns, true)) {
                throw new RuntimeException(
                    "Project-state table [{$table}] references unknown column [{$column}]."
                );
            }
        }

        foreach (array_keys($jsonPathValueMaps) as $column) {
            if (! in_array($column, $jsonColumns, true)) {
                throw new RuntimeException(
                    "Project-state table [{$table}] JSON path value map [{$column}] is not a JSON column."
                );
            }
        }

        foreach ($importValueMapBackups as $column => $backup) {
            if (! array_key_exists($column, $importValueMaps)) {
                throw new RuntimeException(
                    "Project-state table [{$table}] import value-map backup [{$column}] has no import value map."
                );
            }

            if (! in_array($backup['json_column'], $jsonColumns, true)) {
                throw new RuntimeException(
                    "Project-state table [{$table}] import value-map backup [{$column}] does not target a JSON column."
                );
            }
        }

        foreach (array_keys($jsonPathReferences) as $column) {
            if (! in_array($column, $jsonColumns, true)) {
                throw new RuntimeException(
                    "Project-state table [{$table}] JSON path reference [{$column}] is not a JSON column."
                );
            }
        }

        foreach ($resumeItems as $resumeItem) {
            $jsonColumn = $resumeItem['json_column'] ?? null;

            if ($jsonColumn !== null && ! in_array($jsonColumn, $jsonColumns, true)) {
                throw new RuntimeException(
                    "Project-state table [{$table}] resume item source [{$jsonColumn}] is not a JSON column."
                );
            }
        }

        foreach ($nullableIdentity as $column) {
            if (! in_array($column, $identity, true)) {
                throw new RuntimeException(
                    "Project-state table [{$table}] nullable identity [{$column}] is not an identity column."
                );
            }
        }

        if (array_intersect(array_keys($references), array_keys($deferredReferences)) !== []) {
            throw new RuntimeException(
                "Project-state table [{$table}] repeats a reference as immediate and deferred."
            );
        }

        $normalizedJsonPathReferences = [];

        foreach ($jsonPathReferences as $column => $pathReferences) {
            foreach ($pathReferences as $path => $reference) {
                $normalizedJsonPathReferences[$column][$path] = [
                    'table' => $reference['table'],
                    'deferred' => (bool) ($reference['deferred'] ?? false),
                ];
            }
        }

        $polymorphicReferences = array_map(
            fn (array $reference): array => [
                'type_column' => $reference['type_column'],
                'id_column' => $reference['id_column'],
                'targets' => $reference['targets'],
                'deferred' => (bool) ($reference['deferred'] ?? false),
            ],
            $polymorphicReferences,
        );

        $resumeItems = array_map(
            fn (array $resumeItem): array => [
                'category' => trim($resumeItem['category']),
                'statuses' => array_values($resumeItem['statuses']),
                'column' => isset($resumeItem['column'])
                    ? trim($resumeItem['column'])
                    : null,
                'json_column' => isset($resumeItem['json_column'])
                    ? trim($resumeItem['json_column'])
                    : null,
                'path' => isset($resumeItem['path'])
                    ? trim($resumeItem['path'])
                    : null,
            ],
            $resumeItems,
        );
        if ($resumeItems !== []) {
            $supportedResumeCategories = ProjectStateResumeManager::supportedCategoryKeys();

            foreach ($resumeItems as $resumeItem) {
                if (! in_array(
                    $resumeItem['category'],
                    $supportedResumeCategories,
                    true,
                )) {
                    throw new RuntimeException(sprintf(
                        'Project-state table [%s] resume item uses unsupported category [%s].',
                        $table,
                        $resumeItem['category'],
                    ));
                }
            }
        }

        return [
            'mode' => $mode,
            'identity' => array_values($identity),
            'nullable_identity' => array_values($nullableIdentity),
            'preserve_id' => $preserveId,
            'order_by' => array_values($orderBy),
            'columns' => array_values($columns),
            'json_columns' => array_values($jsonColumns),
            'references' => $references,
            'deferred_references' => $deferredReferences,
            'null_on_import' => array_values($nullOnImport),
            'import_value_maps' => $importValueMaps,
            'import_value_map_backups' => $importValueMapBackups,
            'json_path_value_maps' => $jsonPathValueMaps,
            'json_path_references' => $normalizedJsonPathReferences,
            'polymorphic_references' => $polymorphicReferences,
            'resume_items' => $resumeItems,
        ];
    }

    /** @return array<int, string> */
    public function ignoredTables(): array
    {
        $ignoredTables = config('project_state.schema_ignored_tables', []);

        if (! $this->isStringList($ignoredTables)) {
            throw new RuntimeException('Project-state ignored schema table configuration is invalid.');
        }

        return array_values($ignoredTables);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function tablePolicies(): array
    {
        $policies = config('project_state.table_policies', []);

        if (! is_array($policies)) {
            throw new RuntimeException('Project-state table policy configuration is invalid.');
        }

        $normalized = [];

        foreach ($policies as $table => $policy) {
            if (! is_string($table)
                || trim($table) === ''
                || ! is_array($policy)
                || ! is_string($policy['mode'] ?? null)
                || ! in_array($policy['mode'], [
                    'environment_owned',
                    'resettable',
                    'must_be_empty',
                    'terminal_only',
                ], true)
                || ! is_string($policy['reason'] ?? null)
                || trim($policy['reason']) === ''
            ) {
                throw new RuntimeException('Project-state table policy configuration is invalid.');
            }

            $entry = [
                'mode' => $policy['mode'],
                'reason' => trim($policy['reason']),
            ];

            if ($policy['mode'] === 'terminal_only') {
                if (! is_string($policy['column'] ?? null)
                    || trim($policy['column']) === ''
                    || ! $this->isStringList(
                        $policy['values'] ?? null,
                        allowEmpty: false,
                    )
                ) {
                    throw new RuntimeException(
                        "Project-state terminal table policy [{$table}] is invalid."
                    );
                }

                $entry['column'] = trim($policy['column']);
                $entry['values'] = array_values($policy['values']);

                if (Schema::hasTable($table)
                    && ! Schema::hasColumn($table, $entry['column'])
                ) {
                    throw new RuntimeException(
                        "Project-state terminal table policy [{$table}] references missing column [{$entry['column']}]."
                    );
                }
            }

            $normalized[$table] = $entry;
        }

        return $normalized;
    }

    public function format(): string
    {
        return (string) config('project_state.format', 'engage-core-project-state');
    }

    public function version(): int
    {
        return array_sum($this->sectionVersions());
    }

    /**
     * Ordered configured section-version vector.
     *
     * @return array<string, int>
     */
    public function sectionVersions(): array
    {
        $versions = [];

        foreach ($this->configuredSections() as $sectionKey => $section) {
            $versions[$sectionKey] = (int) $section['version'];
        }

        return $versions;
    }

    /**
     * Exact compatibility identity for the normalized configured contract.
     *
     * The human-readable root version is intentionally only the sum of section
     * versions. The fingerprint also covers section order and every normalized
     * table/import contract detail, so distinct version vectors or an accidental
     * contract edit without a section bump cannot compare as compatible.
     */
    public function contractFingerprint(): string
    {
        try {
            $encoded = json_encode(
                [
                    'format' => $this->format(),
                    'sections' => $this->configuredSections(),
                ],
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_INVALID_UTF8_SUBSTITUTE
                    | JSON_PRESERVE_ZERO_FRACTION
                    | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Project-state contract could not be fingerprinted.',
                previous: $exception,
            );
        }

        return 'sha256:'.hash('sha256', $encoded);
    }
    private function isStringList(mixed $value, bool $allowEmpty = true): bool
    {
        if (! is_array($value)
            || (! $allowEmpty && $value === [])
            || ! array_is_list($value)
        ) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                return false;
            }
        }

        return true;
    }

    private function isReferenceMap(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $column => $table) {
            if (! is_string($column)
                || trim($column) === ''
                || ! is_string($table)
                || trim($table) === ''
            ) {
                return false;
            }
        }

        return true;
    }

    private function isImportValueMap(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $column => $valueMap) {
            if (! is_string($column)
                || trim($column) === ''
                || ! is_array($valueMap)
            ) {
                return false;
            }
        }

        return true;
    }

    private function isImportValueMapBackupMap(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $column => $backup) {
            if (! is_string($column)
                || trim($column) === ''
                || ! is_array($backup)
                || ! is_string($backup['json_column'] ?? null)
                || trim($backup['json_column']) === ''
                || ! is_string($backup['path'] ?? null)
                || trim($backup['path']) === ''
            ) {
                return false;
            }
        }

        return true;
    }

    private function isJsonPathValueMap(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $column => $pathMaps) {
            if (! is_string($column)
                || trim($column) === ''
                || ! is_array($pathMaps)
            ) {
                return false;
            }

            foreach ($pathMaps as $path => $valueMap) {
                if (! is_string($path)
                    || trim($path) === ''
                    || ! is_array($valueMap)
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    private function isJsonPathReferenceMap(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $column => $pathReferences) {
            if (! is_string($column)
                || trim($column) === ''
                || ! is_array($pathReferences)
            ) {
                return false;
            }

            foreach ($pathReferences as $path => $reference) {
                if (! is_string($path)
                    || trim($path) === ''
                    || ! is_array($reference)
                    || ! is_string($reference['table'] ?? null)
                    || trim($reference['table']) === ''
                    || (array_key_exists('deferred', $reference)
                        && ! is_bool($reference['deferred']))
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    private function isPolymorphicReferenceList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $reference) {
            if (! is_array($reference)
                || ! is_string($reference['type_column'] ?? null)
                || trim($reference['type_column']) === ''
                || ! is_string($reference['id_column'] ?? null)
                || trim($reference['id_column']) === ''
                || ! $this->isReferenceMap($reference['targets'] ?? null)
                || (array_key_exists('deferred', $reference)
                    && ! is_bool($reference['deferred']))
            ) {
                return false;
            }
        }

        return true;
    }

    private function isResumeItemList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $resumeItem) {
            if (! is_array($resumeItem)
                || ! is_string($resumeItem['category'] ?? null)
                || trim($resumeItem['category']) === ''
                || ! $this->isStringList(
                    $resumeItem['statuses'] ?? null,
                    allowEmpty: false,
                )
            ) {
                return false;
            }

            $column = $resumeItem['column'] ?? null;
            $jsonColumn = $resumeItem['json_column'] ?? null;
            $path = $resumeItem['path'] ?? null;
            $hasColumn = is_string($column) && trim($column) !== '';
            $hasJsonPath = is_string($jsonColumn)
                && trim($jsonColumn) !== ''
                && is_string($path)
                && trim($path) !== '';

            if ($hasColumn === $hasJsonPath) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $resumeItems
     * @return array<int, string>
     */
    private function resumeItemColumns(array $resumeItems): array
    {
        $columns = [];

        foreach ($resumeItems as $resumeItem) {
            $column = $resumeItem['column'] ?? $resumeItem['json_column'] ?? null;

            if (is_string($column) && trim($column) !== '') {
                $columns[] = trim($column);
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * @param array<int, array<string, mixed>> $references
     * @return array<int, string>
     */
    private function polymorphicReferenceColumns(array $references): array
    {
        $columns = [];

        foreach ($references as $reference) {
            $columns[] = $reference['type_column'];
            $columns[] = $reference['id_column'];
        }

        return array_values(array_unique($columns));
    }

}