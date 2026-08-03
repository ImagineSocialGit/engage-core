<?php

namespace App\Support\ProjectState;

use Closure;
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
    /** @var array<string, array<string, int|string>> */
    private array $importedIds = [];

    public function __construct(
        private readonly ProjectStateDocumentCodec $documentCodec,
        private readonly ProjectStateContractRegistry $contractRegistry,
        private readonly ProjectStateSchemaGuard $schemaGuard,
        private readonly ProjectStateDocumentValidator $documentValidator,
        private readonly ProjectStateReferenceResolver $referenceResolver,
        private readonly ProjectStateImportValueMapper $importValueMapper,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function export(): array
    {
        return $this->withConsistentReadSnapshot(function (): array {
            $this->assertNoPendingResumeItemsForExport();

            $configuredSections = $this->contractRegistry->sections();

            $this->schemaGuard->assertSchemaCoverage($configuredSections);
            $this->schemaGuard->assertExportTablePolicies();

            $sections = [];

            foreach ($configuredSections as $sectionKey => $section) {
                $tables = [];

                foreach ($section['tables'] as $table => $definition) {
                    $this->schemaGuard->assertTargetSchema($table, $definition);

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

            $this->referenceResolver->assertExportReferences(
                configuredSections: $configuredSections,
                exportedSections: $sections,
            );

            $document = [
                'format' => $this->contractRegistry->format(),
                'version' => $this->contractRegistry->version(),
                'exported_at' => now('UTC')->toISOString(),
                'client_key' => (string) config('client.key', ''),
                'source' => [
                    'environment' => app()->environment(),
                    'database' => $this->connection()->getDatabaseName(),
                ],
                'sections' => $sections,
            ];

            $document['checksum'] = $this->documentCodec->checksum($document);

            return $document;
        });
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function validate(array $document): array
    {
        return $this->documentValidator->validate($document);
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
        $documentTables = $this->referenceResolver->documentTables($document);

        try {
            $this->connection()->transaction(function () use ($document, $documentTables, &$applied): void {
                $sections = $this->contractRegistry->sections();

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
                                    documentTables: $documentTables,
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
                                $this->recordResumeItems(
                                    table: $table,
                                    targetId: $targetId,
                                    sourceRow: $row,
                                    definition: $definition,
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
                                    documentTables: $documentTables,
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
                                $this->recordResumeItems(
                                    table: $table,
                                    targetId: $row['id'],
                                    sourceRow: $row,
                                    definition: $definition,
                                );
                            }
                        }
                    }
                }

                $this->applyDeferredReferences($sections, $documentTables);
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
        return $this->documentCodec->encode($document);
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $json): array
    {
        return $this->documentCodec->decode($json);
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
        array $documentTables,
    ): array {
        foreach ($definition['polymorphic_references'] as $reference) {
            $type = $row[$reference['type_column']] ?? null;
            $sourceId = $row[$reference['id_column']] ?? null;
            $referencedTable = is_string($type)
                ? ($reference['targets'][$type] ?? null)
                : null;

            if (! is_string($referencedTable)
                || $sourceId === null
                || ! $this->referenceResolver->documentTableContainsId(
                    documentTables: $documentTables,
                    table: $referencedTable,
                    sourceId: $sourceId,
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
            $sourceValue = $row[$column] ?? null;
            $targetValue = $this->importValueMapper->map(
                value: $sourceValue,
                valueMap: $valueMap,
            );
            $backup = $definition['import_value_map_backups'][$column] ?? null;

            if ($sourceValue !== $targetValue && is_array($backup)) {
                $jsonColumn = $backup['json_column'];
                $jsonValue = $row[$jsonColumn] ?? [];

                if ($jsonValue === null) {
                    $jsonValue = [];
                }

                if (! is_array($jsonValue)) {
                    throw new RuntimeException(
                        "Project-state import value-map backup [{$table}.{$column}] requires JSON object [{$jsonColumn}]."
                    );
                }

                Arr::set(
                    $jsonValue,
                    $backup['path'],
                    $sourceValue,
                );

                $row[$jsonColumn] = $jsonValue;
            }

            $row[$column] = $targetValue;
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
                    $this->importValueMapper->map(
                        value: Arr::get($value, $path),
                        valueMap: $valueMap,
                    ),
                );
            }

            $row[$column] = $value;
        }

        foreach ($definition['json_path_references'] as $column => $pathReferences) {
            $value = $row[$column] ?? null;

            if (! is_array($value)) {
                continue;
            }

            foreach ($pathReferences as $path => $reference) {
                if ($reference['deferred'] || ! Arr::has($value, $path)) {
                    continue;
                }

                $sourceId = Arr::get($value, $path);

                if ($sourceId === null) {
                    continue;
                }

                Arr::set(
                    $value,
                    $path,
                    $this->mappedReferenceId(
                        table: $table,
                        column: $column.'.'.$path,
                        referencedTable: $reference['table'],
                        sourceId: $sourceId,
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
     * @param array<string, array<string, mixed>> $sections
     * @param array<string, array<int, mixed>> $documentTables
     */
    private function applyDeferredReferences(
        array $sections,
        array $documentTables,
    ): void {
        foreach ($sections as $section) {
            foreach ($section['tables'] as $table => $definition) {
                $deferredPolymorphicReferences = array_values(array_filter(
                    $definition['polymorphic_references'],
                    fn (array $reference): bool => $reference['deferred'],
                ));
                $deferredJsonPathReferences = [];

                foreach ($definition['json_path_references'] as $column => $pathReferences) {
                    foreach ($pathReferences as $path => $reference) {
                        if ($reference['deferred']) {
                            $deferredJsonPathReferences[$column][$path] = $reference;
                        }
                    }
                }

                if ($definition['deferred_references'] === []
                    && $deferredPolymorphicReferences === []
                    && $deferredJsonPathReferences === []
                ) {
                    continue;
                }

                $rows = $this->referenceResolver->documentTableRows(
                    documentTables: $documentTables,
                    table: $table,
                ) ?? [];

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
                            || ! $this->referenceResolver->documentTableContainsId(
                                documentTables: $documentTables,
                                table: $referencedTable,
                                sourceId: $sourceReferenceId,
                            )
                        ) {
                            continue;
                        }

                        $updates[$reference['id_column']] = $this->importedId(
                            $referencedTable,
                            $sourceReferenceId,
                        );
                    }

                    foreach ($deferredJsonPathReferences as $column => $pathReferences) {
                        $storedValue = $this->connection()
                            ->table($table)
                            ->where('id', $targetId)
                            ->value($column);

                        if ($storedValue === null) {
                            continue;
                        }

                        try {
                            $value = is_array($storedValue)
                                ? $storedValue
                                : json_decode(
                                    (string) $storedValue,
                                    associative: true,
                                    flags: JSON_THROW_ON_ERROR,
                                );
                        } catch (JsonException $exception) {
                            throw new RuntimeException(
                                "Imported JSON in [{$table}.{$column}] could not apply deferred references.",
                                previous: $exception,
                            );
                        }

                        if (! is_array($value)) {
                            continue;
                        }

                        $changed = false;

                        foreach ($pathReferences as $path => $reference) {
                            if (! Arr::has($value, $path)) {
                                continue;
                            }

                            $sourceReferenceId = Arr::get($value, $path);

                            if ($sourceReferenceId === null) {
                                continue;
                            }

                            Arr::set(
                                $value,
                                $path,
                                $this->importedId(
                                    $reference['table'],
                                    $sourceReferenceId,
                                ),
                            );
                            $changed = true;
                        }

                        if ($changed) {
                            $updates[$column] = json_encode(
                                $value,
                                JSON_UNESCAPED_SLASHES
                                    | JSON_UNESCAPED_UNICODE
                                    | JSON_INVALID_UTF8_SUBSTITUTE
                                    | JSON_THROW_ON_ERROR,
                            );
                        }
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

    /**
     * @param array<string, mixed> $sourceRow
     * @param array<string, mixed> $definition
     */
    private function recordResumeItems(
        string $table,
        mixed $targetId,
        array $sourceRow,
        array $definition,
    ): void {
        if ($definition['resume_items'] === []) {
            return;
        }

        if (! Schema::hasTable('project_state_resume_items')) {
            throw new RuntimeException(
                'Project-state resume tracking is unavailable until its migration has been applied.'
            );
        }

        foreach ($definition['resume_items'] as $resumeItem) {
            $sourceStatus = $this->resumeItemSourceStatus(
                sourceRow: $sourceRow,
                resumeItem: $resumeItem,
            );

            if ($sourceStatus === null
                || ! in_array($sourceStatus, $resumeItem['statuses'], true)
            ) {
                continue;
            }

            $now = now();

            $this->connection()
                ->table('project_state_resume_items')
                ->updateOrInsert(
                    [
                        'source_table' => $table,
                        'source_record_id' => (string) $targetId,
                    ],
                    [
                        'category' => $resumeItem['category'],
                        'original_status' => $sourceStatus,
                        'state' => ProjectStateResumeManager::STATE_PENDING,
                        'result_code' => null,
                        'resumed_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
        }
    }

    /**
     * @param array<string, mixed> $sourceRow
     * @param array<string, mixed> $resumeItem
     */
    private function resumeItemSourceStatus(
        array $sourceRow,
        array $resumeItem,
    ): ?string {
        $value = $resumeItem['column'] !== null
            ? ($sourceRow[$resumeItem['column']] ?? null)
            : (is_array($sourceRow[$resumeItem['json_column']] ?? null)
                ? Arr::get(
                    $sourceRow[$resumeItem['json_column']],
                    $resumeItem['path'],
                )
                : null);

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
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
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    private function withConsistentReadSnapshot(Closure $callback): mixed
    {
        $connection = $this->connection();

        if ($connection->getDriverName() === 'mysql'
            && $connection->transactionLevel() === 0
        ) {
            $connection->statement(
                'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'
            );
        }

        return $connection->transaction($callback, attempts: 1);
    }

    private function assertNoPendingResumeItemsForExport(): void
    {
        if (! Schema::hasTable('project_state_resume_items')) {
            return;
        }

        $pending = (int) $this->connection()
            ->table('project_state_resume_items')
            ->where('state', ProjectStateResumeManager::STATE_PENDING)
            ->count();

        if ($pending > 0) {
            throw new RuntimeException(sprintf(
                'Project-state export is blocked while %d imported work item(s) still require explicit resume.',
                $pending,
            ));
        }
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection();
    }

}