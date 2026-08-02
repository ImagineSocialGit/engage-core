<?php

namespace App\Support\ProjectState;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

class ProjectStateManager
{
    /** @var array<string, array<int, mixed>> */
    private array $validationDocumentTables = [];

    /** @var array<string, array<string, int|string>> */
    private array $importedIds = [];

    /**
     * @return array<string, mixed>
     */
    public function export(): array
    {
        $sections = [];

        foreach ($this->sections() as $sectionKey => $section) {
            $tables = [];

            foreach ($section['tables'] as $table => $definition) {
                $this->assertTargetSchema($table, $definition);

                $query = $this->connection()
                    ->table($table)
                    ->select($definition['columns']);

                foreach ($definition['order_by'] as $column) {
                    $query->orderBy($column);
                }

                $tables[$table] = $query
                    ->get()
                    ->map(fn (object $row): array => $this->exportRow(
                        (array) $row,
                        $definition,
                    ))
                    ->values()
                    ->all();
            }

            $sections[$sectionKey] = [
                'version' => $section['version'],
                'tables' => $tables,
            ];
        }

        $document = [
            'format' => $this->format(),
            'version' => $this->version(),
            'exported_at' => now('UTC')->toISOString(),
            'client_key' => (string) config('client.key', ''),
            'source' => [
                'environment' => app()->environment(),
                'database' => $this->connection()->getDatabaseName(),
            ],
            'sections' => $sections,
        ];

        $document['checksum'] = $this->checksum($document);

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function validate(array $document): array
    {
        $errors = [];
        $warnings = [];
        $counts = [];

        $this->validateEnvelope($document, $errors, $warnings);

        $configuredSections = $this->sections();
        $documentSections = $document['sections'] ?? null;

        if (! is_array($documentSections)) {
            $errors[] = 'The project-state document must contain a sections object.';
            $documentSections = [];
        }

        foreach ($configuredSections as $sectionKey => $section) {
            $documentSection = $documentSections[$sectionKey] ?? null;

            if (! is_array($documentSection)) {
                $errors[] = "Required project-state section [{$sectionKey}] is missing.";
                continue;
            }

            if (($documentSection['version'] ?? null) !== $section['version']) {
                $errors[] = sprintf(
                    'Project-state section [%s] requires version [%d].',
                    $sectionKey,
                    $section['version'],
                );
            }

            $documentTables = $documentSection['tables'] ?? null;

            if (! is_array($documentTables)) {
                $errors[] = "Project-state section [{$sectionKey}] must contain a tables object.";
                continue;
            }

            foreach ($section['tables'] as $table => $definition) {
                try {
                    $this->assertTargetSchema($table, $definition);
                } catch (Throwable $exception) {
                    $errors[] = $exception->getMessage();
                    continue;
                }

                $rows = $documentTables[$table] ?? null;

                if (! is_array($rows) || ! array_is_list($rows)) {
                    $errors[] = "Project-state table [{$table}] must be a JSON array.";
                    continue;
                }

                $counts[$table] = count($rows);
                $this->validateRows($table, $definition, $rows, $errors);
                $this->appendImportValueMapWarnings(
                    table: $table,
                    definition: $definition,
                    rows: $rows,
                    warnings: $warnings,
                );
                $this->appendJsonPathValueMapWarnings(
                    table: $table,
                    definition: $definition,
                    rows: $rows,
                    warnings: $warnings,
                );
                $this->appendPolymorphicReferenceWarnings(
                    table: $table,
                    definition: $definition,
                    rows: $rows,
                    warnings: $warnings,
                );

                if ($definition['mode'] === 'insert_empty'
                    && $this->connection()->table($table)->exists()
                ) {
                    $errors[] = "Target table [{$table}] must be empty before import.";
                }
            }

            foreach (array_keys($documentTables) as $table) {
                if (! array_key_exists($table, $section['tables'])) {
                    $warnings[] = "Unknown table [{$sectionKey}.{$table}] will not be imported.";
                }
            }
        }

        foreach (array_keys($documentSections) as $sectionKey) {
            if (! array_key_exists($sectionKey, $configuredSections)) {
                $warnings[] = "Unknown section [{$sectionKey}] will not be imported.";
            }
        }

        return [
            'valid' => $errors === [],
            'format' => $document['format'] ?? null,
            'version' => $document['version'] ?? null,
            'client_key' => $document['client_key'] ?? null,
            'counts' => $counts,
            'errors' => $errors,
            'warnings' => $warnings,
            'applied' => false,
        ];
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function import(array $document): array
    {
        $report = $this->validate($document);

        if (! $report['valid']) {
            throw new InvalidArgumentException(
                'Project-state import validation failed: '.implode(' ', $report['errors'])
            );
        }

        $applied = [];
        $this->importedIds = [];

        try {
            $this->connection()->transaction(function () use ($document, &$applied): void {
                $sections = $this->sections();

                foreach ($sections as $sectionKey => $section) {
                    $tables = $document['sections'][$sectionKey]['tables'];

                    foreach ($section['tables'] as $table => $definition) {
                        $rows = $tables[$table];
                        $applied[$table] = 0;

                        if ($definition['mode'] === 'upsert') {
                            foreach ($rows as $row) {
                                $importRow = $this->importRow(
                                    table: $table,
                                    row: $row,
                                    definition: $definition,
                                );
                                $identity = Arr::only(
                                    $importRow,
                                    $definition['identity'],
                                );

                                $this->connection()
                                    ->table($table)
                                    ->updateOrInsert($identity, $importRow);

                                $targetId = $this->targetIdForIdentity(
                                    table: $table,
                                    identity: $identity,
                                );

                                $this->rememberImportedId(
                                    table: $table,
                                    sourceId: $row['id'],
                                    targetId: $targetId,
                                );

                                $applied[$table]++;
                            }

                            continue;
                        }

                        foreach (array_chunk($rows, 500) as $chunk) {
                            $importRows = array_map(
                                fn (array $row): array => $this->importRow(
                                    table: $table,
                                    row: $row,
                                    definition: $definition,
                                ),
                                $chunk,
                            );

                            if ($importRows === []) {
                                continue;
                            }

                            $this->connection()->table($table)->insert($importRows);
                            $applied[$table] += count($importRows);

                            foreach ($chunk as $row) {
                                $this->rememberImportedId(
                                    table: $table,
                                    sourceId: $row['id'],
                                    targetId: $row['id'],
                                );
                            }
                        }
                    }
                }

                $this->applyDeferredReferences($sections);
            }, attempts: 1);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                'Project-state import failed and was rolled back: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        $report['applied'] = true;
        $report['applied_counts'] = $applied;

        return $report;
    }

    /**
     * @param array<string, mixed> $document
     */
    public function encode(array $document): string
    {
        return json_encode(
            $document,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_THROW_ON_ERROR,
        ).PHP_EOL;
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $json): array
    {
        try {
            $document = json_decode(
                $json,
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'The uploaded project-state file is not valid JSON: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        if (! is_array($document)) {
            throw new InvalidArgumentException(
                'The uploaded project-state file must contain a JSON object.'
            );
        }

        return $document;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function sections(): array
    {
        $sections = config('project_state.sections', []);

        if (! is_array($sections) || $sections === []) {
            throw new RuntimeException('No project-state sections are configured.');
        }

        $normalized = [];

        foreach ($sections as $sectionKey => $section) {
            if (! is_string($sectionKey)
                || trim($sectionKey) === ''
                || ! is_array($section)
                || ! is_int($section['version'] ?? null)
                || ! is_array($section['tables'] ?? null)
            ) {
                throw new RuntimeException('Project-state section configuration is invalid.');
            }

            $tables = [];

            foreach ($section['tables'] as $table => $definition) {
                if (! is_string($table) || trim($table) === '') {
                    throw new RuntimeException('Project-state table configuration is invalid.');
                }

                $tables[$table] = $this->normalizeDefinition($table, $definition);
            }

            $normalized[$sectionKey] = [
                'version' => $section['version'],
                'tables' => $tables,
            ];
        }

        return $normalized;
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
        $jsonPathValueMaps = $definition['json_path_value_maps'] ?? [];
        $polymorphicReferences = $definition['polymorphic_references'] ?? [];
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
            || ! $this->isJsonPathValueMap($jsonPathValueMaps)
            || ! $this->isPolymorphicReferenceList($polymorphicReferences)
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
            ...array_keys($jsonPathValueMaps),
            ...$this->polymorphicReferenceColumns($polymorphicReferences),
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

        $polymorphicReferences = array_map(
            fn (array $reference): array => [
                'type_column' => $reference['type_column'],
                'id_column' => $reference['id_column'],
                'targets' => $reference['targets'],
                'deferred' => (bool) ($reference['deferred'] ?? false),
            ],
            $polymorphicReferences,
        );

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
            'json_path_value_maps' => $jsonPathValueMaps,
            'polymorphic_references' => $polymorphicReferences,
        ];
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function assertTargetSchema(string $table, array $definition): void
    {
        if (! Schema::hasTable($table)) {
            throw new RuntimeException("Required project-state table [{$table}] does not exist.");
        }

        foreach ($definition['columns'] as $column) {
            if (! Schema::hasColumn($table, $column)) {
                throw new RuntimeException(
                    "Required project-state column [{$table}.{$column}] does not exist."
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function exportRow(array $row, array $definition): array
    {
        foreach ($definition['json_columns'] as $column) {
            $value = $row[$column] ?? null;

            if ($value === null || is_array($value)) {
                continue;
            }

            try {
                $row[$column] = json_decode(
                    (string) $value,
                    associative: true,
                    flags: JSON_THROW_ON_ERROR,
                );
            } catch (JsonException $exception) {
                throw new RuntimeException(
                    "Stored JSON in [{$column}] could not be exported.",
                    previous: $exception,
                );
            }
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function importRow(
        string $table,
        array $row,
        array $definition,
    ): array {
        foreach ($definition['polymorphic_references'] as $reference) {
            $type = $row[$reference['type_column']] ?? null;
            $sourceId = $row[$reference['id_column']] ?? null;
            $referencedTable = is_string($type)
                ? ($reference['targets'][$type] ?? null)
                : null;

            if (! is_string($referencedTable)
                || $sourceId === null
                || ! $this->documentTableContainsId(
                    $referencedTable,
                    $sourceId,
                )
            ) {
                continue;
            }

            $row[$reference['id_column']] = $reference['deferred']
                ? null
                : $this->mappedReferenceId(
                    table: $table,
                    column: $reference['id_column'],
                    referencedTable: $referencedTable,
                    sourceId: $sourceId,
                );
        }

        foreach ($definition['references'] as $column => $referencedTable) {
            $row[$column] = $this->mappedReferenceId(
                table: $table,
                column: $column,
                referencedTable: $referencedTable,
                sourceId: $row[$column] ?? null,
            );
        }

        foreach ($definition['deferred_references'] as $column => $referencedTable) {
            $row[$column] = null;
        }

        foreach ($definition['null_on_import'] as $column) {
            $row[$column] = null;
        }

        foreach ($definition['import_value_maps'] as $column => $valueMap) {
            $row[$column] = $this->mappedImportValue(
                value: $row[$column] ?? null,
                valueMap: $valueMap,
            );
        }

        foreach ($definition['json_path_value_maps'] as $column => $pathMaps) {
            $value = $row[$column] ?? null;

            if (! is_array($value)) {
                continue;
            }

            foreach ($pathMaps as $path => $valueMap) {
                if (! Arr::has($value, $path)) {
                    continue;
                }

                Arr::set(
                    $value,
                    $path,
                    $this->mappedImportValue(
                        value: Arr::get($value, $path),
                        valueMap: $valueMap,
                    ),
                );
            }

            $row[$column] = $value;
        }

        if (! $definition['preserve_id']) {
            unset($row['id']);
        }

        foreach ($definition['json_columns'] as $column) {
            if (! array_key_exists($column, $row) || $row[$column] === null) {
                continue;
            }

            $row[$column] = json_encode(
                $row[$column],
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_INVALID_UTF8_SUBSTITUTE
                    | JSON_THROW_ON_ERROR,
            );
        }

        return $row;
    }

    /**
     * @param array<int, mixed> $rows
     * @param array<int, string> $errors
     * @param array<string, mixed> $definition
     */
    private function validateRows(
        string $table,
        array $definition,
        array $rows,
        array &$errors,
    ): void {
        $expectedColumns = $definition['columns'];
        sort($expectedColumns);
        $seenIdentities = [];
        $seenSourceIds = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $errors[] = "Project-state row [{$table}.{$index}] must be an object.";
                continue;
            }

            $actualColumns = array_keys($row);
            sort($actualColumns);

            if ($actualColumns !== $expectedColumns) {
                $errors[] = "Project-state row [{$table}.{$index}] does not match the current column contract.";
                continue;
            }

            $sourceId = $row['id'] ?? null;

            if ($sourceId === null || $sourceId === '') {
                $errors[] = "Project-state row [{$table}.{$index}] requires [id].";
                continue;
            }

            $sourceIdKey = (string) $sourceId;

            if (isset($seenSourceIds[$sourceIdKey])) {
                $errors[] = "Project-state table [{$table}] contains duplicate source ID [{$sourceIdKey}].";
            }

            $seenSourceIds[$sourceIdKey] = true;

            foreach ($definition['json_columns'] as $column) {
                if ($row[$column] !== null && ! is_array($row[$column])) {
                    $errors[] = "Project-state value [{$table}.{$index}.{$column}] must be an object, array, or null.";
                }
            }

            $identityColumns = $definition['mode'] === 'upsert'
                ? $definition['identity']
                : ['id'];

            $identityValues = [];

            foreach ($identityColumns as $column) {
                $value = $row[$column] ?? null;
                $nullable = in_array(
                    $column,
                    $definition['nullable_identity'],
                    true,
                );

                if (($value === null || $value === '') && ! $nullable) {
                    $errors[] = "Project-state row [{$table}.{$index}] requires [{$column}].";
                    continue 2;
                }

                $identityValues[] = $value === null
                    ? '<null>'
                    : get_debug_type($value).':'.(string) $value;
            }

            $identityKey = implode('|', $identityValues);

            if (isset($seenIdentities[$identityKey])) {
                $errors[] = "Project-state table [{$table}] contains duplicate identity [{$identityKey}].";
            }

            $seenIdentities[$identityKey] = true;
        }

        $this->validateReferences($table, $definition, $rows, $errors);
    }

    /**
     * @param array<int, mixed> $rows
     * @param array<int, string> $errors
     * @param array<string, mixed> $definition
     */
    private function validateReferences(
        string $table,
        array $definition,
        array $rows,
        array &$errors,
    ): void {
        $references = array_merge(
            $definition['references'],
            $definition['deferred_references'],
        );

        foreach ($references as $column => $referencedTable) {
            $referencedRows = $this->documentTableRows($referencedTable);

            if ($referencedRows === null) {
                $errors[] = "Project-state reference target [{$referencedTable}] is not configured in the document.";
                continue;
            }

            $referencedIds = [];

            foreach ($referencedRows as $referencedRow) {
                if (is_array($referencedRow) && isset($referencedRow['id'])) {
                    $referencedIds[(string) $referencedRow['id']] = true;
                }
            }

            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $value = $row[$column] ?? null;

                if ($value !== null && ! isset($referencedIds[(string) $value])) {
                    $errors[] = "Project-state reference [{$table}.{$index}.{$column}] does not exist in [{$referencedTable}].";
                }
            }
        }

        foreach ($definition['polymorphic_references'] as $reference) {
            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $type = $row[$reference['type_column']] ?? null;
                $value = $row[$reference['id_column']] ?? null;

                if (($type === null) !== ($value === null)) {
                    $errors[] = sprintf(
                        'Project-state polymorphic reference [%s.%d.%s/%s] must provide both type and ID or neither.',
                        $table,
                        $index,
                        $reference['type_column'],
                        $reference['id_column'],
                    );
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<int, mixed> $rows
     * @param array<int, string> $warnings
     */
    private function appendImportValueMapWarnings(
        string $table,
        array $definition,
        array $rows,
        array &$warnings,
    ): void {
        foreach ($definition['import_value_maps'] as $column => $valueMap) {
            $counts = [];

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $sourceValue = $row[$column] ?? null;
                $targetValue = $this->mappedImportValue($sourceValue, $valueMap);

                if ($sourceValue === $targetValue) {
                    continue;
                }

                $key = $this->displayValue($sourceValue).' → '.$this->displayValue($targetValue);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }

            foreach ($counts as $mapping => $count) {
                $warnings[] = sprintf(
                    'Import will map [%s.%s] %s for %d row(s).',
                    $table,
                    $column,
                    $mapping,
                    $count,
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<int, mixed> $rows
     * @param array<int, string> $warnings
     */
    private function appendJsonPathValueMapWarnings(
        string $table,
        array $definition,
        array $rows,
        array &$warnings,
    ): void {
        foreach ($definition['json_path_value_maps'] as $column => $pathMaps) {
            foreach ($pathMaps as $path => $valueMap) {
                $counts = [];

                foreach ($rows as $row) {
                    if (! is_array($row)
                        || ! is_array($row[$column] ?? null)
                        || ! Arr::has($row[$column], $path)
                    ) {
                        continue;
                    }

                    $sourceValue = Arr::get($row[$column], $path);
                    $targetValue = $this->mappedImportValue(
                        $sourceValue,
                        $valueMap,
                    );

                    if ($sourceValue === $targetValue) {
                        continue;
                    }

                    $key = $this->displayValue($sourceValue)
                        .' → '
                        .$this->displayValue($targetValue);
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }

                foreach ($counts as $mapping => $count) {
                    $warnings[] = sprintf(
                        'Import will map [%s.%s.%s] %s for %d row(s).',
                        $table,
                        $column,
                        $path,
                        $mapping,
                        $count,
                    );
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<int, mixed> $rows
     * @param array<int, string> $warnings
     */
    private function appendPolymorphicReferenceWarnings(
        string $table,
        array $definition,
        array $rows,
        array &$warnings,
    ): void {
        foreach ($definition['polymorphic_references'] as $reference) {
            $counts = [];

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $type = $row[$reference['type_column']] ?? null;
                $sourceId = $row[$reference['id_column']] ?? null;
                $referencedTable = is_string($type)
                    ? ($reference['targets'][$type] ?? null)
                    : null;

                if (! is_string($referencedTable)
                    || $sourceId === null
                    || $this->documentTableContainsId(
                        $referencedTable,
                        $sourceId,
                    )
                ) {
                    continue;
                }

                $key = $type.' #'.(string) $sourceId;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }

            foreach ($counts as $referenceLabel => $count) {
                $warnings[] = sprintf(
                    'Historical polymorphic reference [%s.%s] to [%s] is not present in the current document and will be preserved without remapping for %d row(s).',
                    $table,
                    $reference['id_column'],
                    $referenceLabel,
                    $count,
                );
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $sections
     */
    private function applyDeferredReferences(array $sections): void
    {
        foreach ($sections as $section) {
            foreach ($section['tables'] as $table => $definition) {
                $deferredPolymorphicReferences = array_values(array_filter(
                    $definition['polymorphic_references'],
                    fn (array $reference): bool => $reference['deferred'],
                ));

                if ($definition['deferred_references'] === []
                    && $deferredPolymorphicReferences === []
                ) {
                    continue;
                }

                $rows = $this->documentTableRows($table) ?? [];

                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $sourceId = $row['id'] ?? null;
                    $targetId = $this->importedId($table, $sourceId);
                    $updates = [];

                    foreach ($definition['deferred_references'] as $column => $referencedTable) {
                        $sourceReferenceId = $row[$column] ?? null;
                        $updates[$column] = $sourceReferenceId === null
                            ? null
                            : $this->importedId(
                                $referencedTable,
                                $sourceReferenceId,
                            );
                    }

                    foreach ($deferredPolymorphicReferences as $reference) {
                        $type = $row[$reference['type_column']] ?? null;
                        $sourceReferenceId = $row[$reference['id_column']] ?? null;
                        $referencedTable = is_string($type)
                            ? ($reference['targets'][$type] ?? null)
                            : null;

                        if (! is_string($referencedTable)
                            || $sourceReferenceId === null
                            || ! $this->documentTableContainsId(
                                $referencedTable,
                                $sourceReferenceId,
                            )
                        ) {
                            continue;
                        }

                        $updates[$reference['id_column']] = $this->importedId(
                            $referencedTable,
                            $sourceReferenceId,
                        );
                    }

                    if ($updates !== []) {
                        $this->connection()
                            ->table($table)
                            ->where('id', $targetId)
                            ->update($updates);
                    }
                }
            }
        }
    }

    private function mappedReferenceId(
        string $table,
        string $column,
        string $referencedTable,
        mixed $sourceId,
    ): mixed {
        if ($sourceId === null) {
            return null;
        }

        try {
            return $this->importedId($referencedTable, $sourceId);
        } catch (RuntimeException $exception) {
            throw new RuntimeException(
                "Project-state reference [{$table}.{$column}] could not resolve source ID [{$sourceId}] in [{$referencedTable}].",
                previous: $exception,
            );
        }
    }

    private function rememberImportedId(
        string $table,
        mixed $sourceId,
        mixed $targetId,
    ): void {
        if (($sourceId === null || $sourceId === '')
            || ($targetId === null || $targetId === '')
        ) {
            throw new RuntimeException(
                "Project-state table [{$table}] could not establish an ID mapping."
            );
        }

        $this->importedIds[$table][(string) $sourceId] = $targetId;
    }

    private function importedId(string $table, mixed $sourceId): int|string
    {
        $mapped = $this->importedIds[$table][(string) $sourceId] ?? null;

        if (! is_int($mapped) && ! is_string($mapped)) {
            throw new RuntimeException(
                "Project-state source ID [{$sourceId}] has not been imported for [{$table}]."
            );
        }

        return $mapped;
    }

    /**
     * @param array<string, mixed> $identity
     */
    private function targetIdForIdentity(string $table, array $identity): int|string
    {
        $query = $this->connection()->table($table);

        foreach ($identity as $column => $value) {
            $value === null
                ? $query->whereNull($column)
                : $query->where($column, $value);
        }

        $targetId = $query->value('id');

        if (! is_int($targetId) && ! is_string($targetId)) {
            throw new RuntimeException(
                "Project-state upsert identity for [{$table}] did not resolve a target ID."
            );
        }

        return $targetId;
    }

    /**
     * @param array<int|string, mixed> $valueMap
     */
    private function mappedImportValue(mixed $value, array $valueMap): mixed
    {
        if ($value === null) {
            return null;
        }

        $key = is_bool($value)
            ? ($value ? '1' : '0')
            : (string) $value;

        return array_key_exists($key, $valueMap)
            ? $valueMap[$key]
            : $value;
    }

    private function displayValue(mixed $value): string
    {
        if ($value === null) {
            return '[null]';
        }

        if (is_bool($value)) {
            return $value ? '[true]' : '[false]';
        }

        return '['.(string) $value.']';
    }

    /**
     * @return array<int, mixed>|null
     */
    private function documentTableRows(string $table): ?array
    {
        return $this->validationDocumentTables[$table] ?? null;
    }

    private function documentTableContainsId(
        string $table,
        mixed $sourceId,
    ): bool {
        $rows = $this->documentTableRows($table);

        if ($rows === null) {
            return false;
        }

        foreach ($rows as $row) {
            if (is_array($row)
                && array_key_exists('id', $row)
                && (string) $row['id'] === (string) $sourceId
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<int, string> $errors
     * @param array<int, string> $warnings
     */
    private function validateEnvelope(array $document, array &$errors, array &$warnings): void
    {
        $this->validationDocumentTables = [];
        $documentSections = $document['sections'] ?? [];

        if (! is_array($documentSections)) {
            $documentSections = [];
        }

        foreach ($documentSections as $section) {
            if (! is_array($section) || ! is_array($section['tables'] ?? null)) {
                continue;
            }

            foreach ($section['tables'] as $table => $rows) {
                if (is_string($table) && is_array($rows)) {
                    $this->validationDocumentTables[$table] = $rows;
                }
            }
        }

        if (($document['format'] ?? null) !== $this->format()) {
            $errors[] = 'The project-state format is not supported by this application.';
        }

        if (($document['version'] ?? null) !== $this->version()) {
            $errors[] = sprintf(
                'The project-state document must use version [%d].',
                $this->version(),
            );
        }

        $clientKey = (string) ($document['client_key'] ?? '');
        $expectedClientKey = (string) config('client.key', '');

        if ((bool) config('project_state.enforce_client_key', true)
            && $clientKey !== $expectedClientKey
        ) {
            $errors[] = "The project-state client key [{$clientKey}] does not match [{$expectedClientKey}].";
        }

        $checksum = $document['checksum'] ?? null;

        if ($checksum === null) {
            $warnings[] = 'The project-state file does not contain an integrity checksum.';
        } elseif (! is_string($checksum) || ! hash_equals($this->checksum($document), $checksum)) {
            $errors[] = 'The project-state checksum is invalid.';
        }
    }

    /**
     * @param array<string, mixed> $document
     */
    private function checksum(array $document): string
    {
        unset($document['checksum']);

        return 'sha256:'.hash(
            'sha256',
            json_encode(
                $document,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_INVALID_UTF8_SUBSTITUTE
                    | JSON_PRESERVE_ZERO_FRACTION
                    | JSON_THROW_ON_ERROR,
            ),
        );
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

    private function connection(): ConnectionInterface
    {
        return DB::connection();
    }

    private function format(): string
    {
        return (string) config('project_state.format', 'engage-core-project-state');
    }

    private function version(): int
    {
        return (int) config('project_state.version', 1);
    }
}