<?php

namespace App\Support\ProjectState;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use RuntimeException;

class ProjectStateExporter
{
    public function __construct(
        private readonly ProjectStateDocumentCodec $documentCodec,
        private readonly ProjectStateContractRegistry $contractRegistry,
        private readonly ProjectStateSchemaGuard $schemaGuard,
        private readonly ProjectStateReferenceResolver $referenceResolver,
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