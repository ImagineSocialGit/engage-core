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

        $this->connection()->transaction(function () use ($document, &$applied): void {
            foreach ($this->sections() as $sectionKey => $section) {
                $tables = $document['sections'][$sectionKey]['tables'];

                foreach ($section['tables'] as $table => $definition) {
                    $rows = $tables[$table];
                    $applied[$table] = 0;

                    if ($definition['mode'] === 'upsert') {
                        foreach ($rows as $row) {
                            $importRow = $this->importRow($row, $definition);
                            $identity = Arr::only($importRow, $definition['identity']);

                            $this->connection()
                                ->table($table)
                                ->updateOrInsert($identity, $importRow);

                            $applied[$table]++;
                        }

                        continue;
                    }

                    foreach (array_chunk($rows, 500) as $chunk) {
                        $importRows = array_map(
                            fn (array $row): array => $this->importRow($row, $definition),
                            $chunk,
                        );

                        if ($importRows !== []) {
                            $this->connection()->table($table)->insert($importRows);
                            $applied[$table] += count($importRows);
                        }
                    }
                }
            }
        }, attempts: 1);

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
        $jsonColumns = $definition['json_columns'] ?? [];
        $references = $definition['references'] ?? [];

        if (! in_array($mode, ['insert_empty', 'upsert'], true)
            || ! is_array($columns)
            || $columns === []
            || ! is_array($orderBy)
            || $orderBy === []
            || ! is_array($identity)
            || ! is_array($jsonColumns)
            || ! is_array($references)
        ) {
            throw new RuntimeException("Project-state table [{$table}] configuration is invalid.");
        }

        if ($mode === 'upsert' && $identity === []) {
            throw new RuntimeException("Project-state table [{$table}] requires an upsert identity.");
        }

        return [
            'mode' => $mode,
            'identity' => array_values($identity),
            'preserve_id' => (bool) ($definition['preserve_id'] ?? true),
            'order_by' => array_values($orderBy),
            'columns' => array_values($columns),
            'json_columns' => array_values($jsonColumns),
            'references' => $references,
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
    private function importRow(array $row, array $definition): array
    {
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

                if ($value === null || $value === '') {
                    $errors[] = "Project-state row [{$table}.{$index}] requires [{$column}].";
                    continue 2;
                }

                $identityValues[] = (string) $value;
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
        foreach ($definition['references'] as $column => $referencedTable) {
            $referencedRows = $this->documentTableRows($referencedTable);

            if ($referencedRows === null) {
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
    }

    /**
     * This method is replaced during validate() with the current document table
     * cache. It exists only to keep reference validation isolated.
     *
     * @return array<int, mixed>|null
     */
    private function documentTableRows(string $table): ?array
    {
        return $this->validationDocumentTables[$table] ?? null;
    }

    /** @var array<string, array<int, mixed>> */
    private array $validationDocumentTables = [];

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