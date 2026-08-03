<?php

namespace App\Support\ProjectState;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ProjectStateImporter
{
    public function __construct(
        private readonly ProjectStateContractRegistry $contractRegistry,
        private readonly ProjectStateDocumentValidator $documentValidator,
        private readonly ProjectStateReferenceResolver $referenceResolver,
        private readonly ProjectStateImportIdMap $importIdMap,
        private readonly ProjectStateImportRowTransformer $rowTransformer,
        private readonly ProjectStateDeferredReferenceApplier $deferredReferenceApplier,
        private readonly ProjectStateResumeItemRecorder $resumeItemRecorder,
    ) {}

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function import(array $document): array
    {
        $report = $this->documentValidator->validate($document);

        if (! $report['valid']) {
            throw new InvalidArgumentException(
                'Project-state import validation failed: '.implode(' ', $report['errors'])
            );
        }

        $applied = [];
        $this->importIdMap->reset();
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
                            $this->importUpsertRows(
                                table: $table,
                                definition: $definition,
                                rows: $rows,
                                documentTables: $documentTables,
                                applied: $applied,
                            );

                            continue;
                        }

                        $this->importInsertEmptyRows(
                            table: $table,
                            definition: $definition,
                            rows: $rows,
                            documentTables: $documentTables,
                            applied: $applied,
                        );
                    }
                }

                $this->deferredReferenceApplier->apply(
                    sections: $sections,
                    documentTables: $documentTables,
                    importIdMap: $this->importIdMap,
                );
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
     * @param array<string, mixed> $definition
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, array<int, mixed>> $documentTables
     * @param array<string, int> $applied
     */
    private function importUpsertRows(
        string $table,
        array $definition,
        array $rows,
        array $documentTables,
        array &$applied,
    ): void {
        foreach ($rows as $row) {
            $importRow = $this->rowTransformer->transform(
                table: $table,
                row: $row,
                definition: $definition,
                documentTables: $documentTables,
                importIdMap: $this->importIdMap,
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

            $this->importIdMap->remember(
                table: $table,
                sourceId: $row['id'],
                targetId: $targetId,
            );
            $this->resumeItemRecorder->record(
                table: $table,
                targetId: $targetId,
                sourceRow: $row,
                definition: $definition,
            );

            $applied[$table]++;
        }
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, array<int, mixed>> $documentTables
     * @param array<string, int> $applied
     */
    private function importInsertEmptyRows(
        string $table,
        array $definition,
        array $rows,
        array $documentTables,
        array &$applied,
    ): void {
        foreach (array_chunk($rows, 500) as $chunk) {
            $importRows = array_map(
                fn (array $row): array => $this->rowTransformer->transform(
                    table: $table,
                    row: $row,
                    definition: $definition,
                    documentTables: $documentTables,
                    importIdMap: $this->importIdMap,
                ),
                $chunk,
            );

            if ($importRows === []) {
                continue;
            }

            $this->connection()->table($table)->insert($importRows);
            $applied[$table] += count($importRows);

            foreach ($chunk as $row) {
                $this->importIdMap->remember(
                    table: $table,
                    sourceId: $row['id'],
                    targetId: $row['id'],
                );
                $this->resumeItemRecorder->record(
                    table: $table,
                    targetId: $row['id'],
                    sourceRow: $row,
                    definition: $definition,
                );
            }
        }
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

    private function connection(): ConnectionInterface
    {
        return DB::connection();
    }
}