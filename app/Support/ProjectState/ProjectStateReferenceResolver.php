<?php

namespace App\Support\ProjectState;

use Illuminate\Support\Arr;
use RuntimeException;

class ProjectStateReferenceResolver
{
    /**
     * @param array<string, mixed> $document
     * @return array<string, array<int, mixed>>
     */
    public function documentTables(array $document): array
    {
        $tables = [];
        $documentSections = $document['sections'] ?? [];

        if (! is_array($documentSections)) {
            return $tables;
        }

        foreach ($documentSections as $section) {
            if (! is_array($section) || ! is_array($section['tables'] ?? null)) {
                continue;
            }

            foreach ($section['tables'] as $table => $rows) {
                if (is_string($table) && is_array($rows)) {
                    $tables[$table] = $rows;
                }
            }
        }

        return $tables;
    }

    /**
     * @param array<string, array<string, mixed>> $exportedSections
     * @return array<string, array<int, mixed>>
     */
    public function exportedTables(array $exportedSections): array
    {
        $tables = [];

        foreach ($exportedSections as $section) {
            foreach ($section['tables'] as $table => $rows) {
                $tables[$table] = $rows;
            }
        }

        return $tables;
    }

    /**
     * @param array<string, array<int, mixed>> $documentTables
     * @return array<int, mixed>|null
     */
    public function documentTableRows(
        array $documentTables,
        string $table,
    ): ?array {
        return $documentTables[$table] ?? null;
    }

    /**
     * @param array<string, array<int, mixed>> $documentTables
     */
    public function documentTableContainsId(
        array $documentTables,
        string $table,
        mixed $sourceId,
    ): bool {
        $rows = $this->documentTableRows($documentTables, $table);

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
     * @param array<string, mixed> $definition
     * @param array<int, mixed> $rows
     * @param array<string, array<int, mixed>> $documentTables
     * @param array<int, string> $errors
     */
    public function validateReferences(
        string $table,
        array $definition,
        array $rows,
        array $documentTables,
        array &$errors,
    ): void {
        $references = array_merge(
            $definition['references'],
            $definition['deferred_references'],
        );

        foreach ($references as $column => $referencedTable) {
            $referencedRows = $this->documentTableRows(
                $documentTables,
                $referencedTable,
            );

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

                    continue;
                }

                if ($type === null) {
                    continue;
                }

                if (! is_string($type)
                    || ! array_key_exists($type, $reference['targets'])
                ) {
                    $errors[] = sprintf(
                        'Project-state polymorphic reference [%s.%d.%s] uses unsupported type [%s].',
                        $table,
                        $index,
                        $reference['type_column'],
                        is_scalar($type) ? (string) $type : get_debug_type($type),
                    );

                    continue;
                }

                $referencedTable = $reference['targets'][$type];
                $referencedRows = $this->documentTableRows(
                    $documentTables,
                    $referencedTable,
                );

                if ($referencedRows === null) {
                    $errors[] = sprintf(
                        'Project-state polymorphic reference [%s.%d.%s/%s] targets unexported table [%s].',
                        $table,
                        $index,
                        $reference['type_column'],
                        $reference['id_column'],
                        $referencedTable,
                    );
                }
            }
        }

        foreach ($definition['json_path_references'] as $column => $pathReferences) {
            foreach ($pathReferences as $path => $reference) {
                $referencedTable = $reference['table'];
                $referencedRows = $this->documentTableRows(
                    $documentTables,
                    $referencedTable,
                );

                if ($referencedRows === null) {
                    $errors[] = "Project-state JSON reference target [{$referencedTable}] is not configured in the document.";
                    continue;
                }

                $referencedIds = [];

                foreach ($referencedRows as $referencedRow) {
                    if (is_array($referencedRow) && isset($referencedRow['id'])) {
                        $referencedIds[(string) $referencedRow['id']] = true;
                    }
                }

                foreach ($rows as $index => $row) {
                    if (! is_array($row)
                        || ! is_array($row[$column] ?? null)
                        || ! Arr::has($row[$column], $path)
                    ) {
                        continue;
                    }

                    $value = Arr::get($row[$column], $path);

                    if ($value !== null && ! isset($referencedIds[(string) $value])) {
                        $errors[] = "Project-state JSON reference [{$table}.{$index}.{$column}.{$path}] does not exist in [{$referencedTable}].";
                    }
                }
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $configuredSections
     * @param array<string, array<string, mixed>> $exportedSections
     */
    public function assertExportReferences(
        array $configuredSections,
        array $exportedSections,
    ): void {
        $documentTables = $this->exportedTables($exportedSections);
        $errors = [];

        foreach ($configuredSections as $section) {
            foreach ($section['tables'] as $table => $definition) {
                $this->validateReferences(
                    table: $table,
                    definition: $definition,
                    rows: $documentTables[$table] ?? [],
                    documentTables: $documentTables,
                    errors: $errors,
                );
            }
        }

        if ($errors !== []) {
            throw new RuntimeException(
                'Project-state export reference validation failed: '.implode(' ', $errors)
            );
        }
    }
}