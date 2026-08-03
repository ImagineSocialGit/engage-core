<?php

namespace App\Support\ProjectState;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

class ProjectStateDeferredReferenceApplier
{
    public function __construct(
        private readonly ProjectStateReferenceResolver $referenceResolver,
    ) {}

    /**
     * @param array<string, array<string, mixed>> $sections
     * @param array<string, array<int, mixed>> $documentTables
     */
    public function apply(
        array $sections,
        array $documentTables,
        ProjectStateImportIdMap $importIdMap,
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
                    $targetId = $importIdMap->get($table, $sourceId);
                    $updates = [];

                    foreach ($definition['deferred_references'] as $column => $referencedTable) {
                        $sourceReferenceId = $row[$column] ?? null;
                        $updates[$column] = $sourceReferenceId === null
                            ? null
                            : $importIdMap->get(
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

                        $updates[$reference['id_column']] = $importIdMap->get(
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
                                $importIdMap->get(
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

    private function connection(): ConnectionInterface
    {
        return DB::connection();
    }
}