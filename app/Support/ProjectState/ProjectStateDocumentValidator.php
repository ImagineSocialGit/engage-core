<?php

namespace App\Support\ProjectState;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProjectStateDocumentValidator
{
    public function __construct(
        private readonly ProjectStateDocumentCodec $documentCodec,
        private readonly ProjectStateContractRegistry $contractRegistry,
        private readonly ProjectStateSchemaGuard $schemaGuard,
        private readonly ProjectStateReferenceResolver $referenceResolver,
        private readonly ProjectStateImportValueMapper $importValueMapper,
    ) {}

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function validate(array $document): array
    {
        $errors = [];
        $warnings = [];
        $counts = [];
        $documentTables = $this->referenceResolver->documentTables($document);

        $this->validateEnvelope($document, $errors, $warnings);

        $configuredSections = $this->contractRegistry->sections();
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

            $documentSectionTables = $documentSection['tables'] ?? null;

            if (! is_array($documentSectionTables)) {
                $errors[] = "Project-state section [{$sectionKey}] must contain a tables object.";
                continue;
            }

            foreach ($section['tables'] as $table => $definition) {
                try {
                    $this->schemaGuard->assertTargetSchema($table, $definition);
                } catch (Throwable $exception) {
                    $errors[] = $exception->getMessage();
                    continue;
                }

                $rows = $documentSectionTables[$table] ?? null;

                if (! is_array($rows) || ! array_is_list($rows)) {
                    $errors[] = "Project-state table [{$table}] must be a JSON array.";
                    continue;
                }

                $counts[$table] = count($rows);
                $this->validateRows(
                    table: $table,
                    definition: $definition,
                    rows: $rows,
                    documentTables: $documentTables,
                    errors: $errors,
                );
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
                $this->appendNullOnImportWarnings(
                    table: $table,
                    definition: $definition,
                    rows: $rows,
                    warnings: $warnings,
                );
                $this->appendPolymorphicReferenceWarnings(
                    table: $table,
                    definition: $definition,
                    rows: $rows,
                    documentTables: $documentTables,
                    warnings: $warnings,
                );

                if ($definition['mode'] === 'insert_empty'
                    && $this->connection()->table($table)->exists()
                ) {
                    $errors[] = "Target table [{$table}] must be empty before import.";
                }
            }

            foreach (array_keys($documentSectionTables) as $table) {
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
     * @param array<int, mixed> $rows
     * @param array<string, array<int, mixed>> $documentTables
     * @param array<int, string> $errors
     * @param array<string, mixed> $definition
     */
    private function validateRows(
        string $table,
        array $definition,
        array $rows,
        array $documentTables,
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

        $this->referenceResolver->validateReferences(
            table: $table,
            definition: $definition,
            rows: $rows,
            documentTables: $documentTables,
            errors: $errors,
        );
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
                $targetValue = $this->importValueMapper->map(
                    $sourceValue,
                    $valueMap,
                );

                if ($sourceValue === $targetValue) {
                    continue;
                }

                $key = $this->importValueMapper->display($sourceValue)
                    .' → '
                    .$this->importValueMapper->display($targetValue);
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
                    $targetValue = $this->importValueMapper->map(
                        $sourceValue,
                        $valueMap,
                    );

                    if ($sourceValue === $targetValue) {
                        continue;
                    }

                    $key = $this->importValueMapper->display($sourceValue)
                        .' → '
                        .$this->importValueMapper->display($targetValue);
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
    private function appendNullOnImportWarnings(
        string $table,
        array $definition,
        array $rows,
        array &$warnings,
    ): void {
        foreach ($definition['null_on_import'] as $column) {
            $count = 0;

            foreach ($rows as $row) {
                if (is_array($row)
                    && array_key_exists($column, $row)
                    && $row[$column] !== null
                ) {
                    $count++;
                }
            }

            if ($count > 0) {
                $warnings[] = sprintf(
                    'Import will clear [%s.%s] for %d row(s).',
                    $table,
                    $column,
                    $count,
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<int, mixed> $rows
     * @param array<string, array<int, mixed>> $documentTables
     * @param array<int, string> $warnings
     */
    private function appendPolymorphicReferenceWarnings(
        string $table,
        array $definition,
        array $rows,
        array $documentTables,
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
                    || $this->referenceResolver->documentTableRows(
                        $documentTables,
                        $referencedTable,
                    ) === null
                    || $this->referenceResolver->documentTableContainsId(
                        documentTables: $documentTables,
                        table: $referencedTable,
                        sourceId: $sourceId,
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
     * @param array<string, mixed> $document
     * @param array<int, string> $errors
     * @param array<int, string> $warnings
     */
    private function validateEnvelope(
        array $document,
        array &$errors,
        array &$warnings,
    ): void {
        if (($document['format'] ?? null) !== $this->contractRegistry->format()) {
            $errors[] = 'The project-state format is not supported by this application.';
        }

        if (($document['version'] ?? null) !== $this->contractRegistry->version()) {
            $errors[] = sprintf(
                'The project-state document must use version [%d].',
                $this->contractRegistry->version(),
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
        } elseif (! is_string($checksum)
            || ! hash_equals($this->documentCodec->checksum($document), $checksum)
        ) {
            $errors[] = 'The project-state checksum is invalid.';
        }
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection();
    }
}