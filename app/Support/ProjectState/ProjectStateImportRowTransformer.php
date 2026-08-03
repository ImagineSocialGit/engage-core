<?php

namespace App\Support\ProjectState;

use Illuminate\Support\Arr;
use RuntimeException;

class ProjectStateImportRowTransformer
{
    public function __construct(
        private readonly ProjectStateReferenceResolver $referenceResolver,
        private readonly ProjectStateImportValueMapper $importValueMapper,
    ) {}

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $definition
     * @param array<string, array<int, mixed>> $documentTables
     * @return array<string, mixed>
     */
    public function transform(
        string $table,
        array $row,
        array $definition,
        array $documentTables,
        ProjectStateImportIdMap $importIdMap,
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
                    importIdMap: $importIdMap,
                    table: $table,
                    column: $reference['id_column'],
                    referencedTable: $referencedTable,
                    sourceId: $sourceId,
                );
        }

        foreach ($definition['references'] as $column => $referencedTable) {
            $row[$column] = $this->mappedReferenceId(
                importIdMap: $importIdMap,
                table: $table,
                column: $column,
                referencedTable: $referencedTable,
                sourceId: $row[$column] ?? null,
            );
        }

        foreach (array_keys($definition['deferred_references']) as $column) {
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
                        importIdMap: $importIdMap,
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

    private function mappedReferenceId(
        ProjectStateImportIdMap $importIdMap,
        string $table,
        string $column,
        string $referencedTable,
        mixed $sourceId,
    ): mixed {
        if ($sourceId === null) {
            return null;
        }

        try {
            return $importIdMap->get($referencedTable, $sourceId);
        } catch (RuntimeException $exception) {
            throw new RuntimeException(
                "Project-state reference [{$table}.{$column}] could not resolve source ID [{$sourceId}] in [{$referencedTable}].",
                previous: $exception,
            );
        }
    }
}