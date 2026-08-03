<?php

namespace App\Support\ProjectState;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ProjectStateSchemaGuard
{
    public function __construct(
        private readonly ProjectStateContractRegistry $contractRegistry,
    ) {}

    /**
     * @param array<string, mixed> $definition
     */
    public function assertTargetSchema(string $table, array $definition): void
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

        $declaredColumns = $definition['columns'];
        $actualColumns = $this->connection()
            ->getSchemaBuilder()
            ->getColumnListing($table);

        sort($declaredColumns, SORT_STRING);
        sort($actualColumns, SORT_STRING);

        if ($actualColumns !== $declaredColumns) {
            $missing = array_values(array_diff($actualColumns, $declaredColumns));
            $obsolete = array_values(array_diff($declaredColumns, $actualColumns));
            $details = [];

            if ($missing !== []) {
                $details[] = 'unclassified column(s): '.implode(', ', $missing);
            }

            if ($obsolete !== []) {
                $details[] = 'missing configured column(s): '.implode(', ', $obsolete);
            }

            throw new RuntimeException(sprintf(
                'Project-state table [%s] does not match its complete column contract (%s).',
                $table,
                implode('; ', $details),
            ));
        }
    }

    /**
     * @param array<string, array<string, mixed>> $sections
     */
    public function assertSchemaCoverage(array $sections): void
    {
        $exportedTables = [];

        foreach ($sections as $section) {
            $exportedTables = [
                ...$exportedTables,
                ...array_keys($section['tables']),
            ];
        }

        $policyTables = array_keys($this->contractRegistry->tablePolicies());
        $duplicates = array_values(array_intersect($exportedTables, $policyTables));

        if ($duplicates !== []) {
            sort($duplicates, SORT_STRING);

            throw new RuntimeException(
                'Project-state table policy duplicates exported table(s): '.implode(', ', $duplicates).'.'
            );
        }

        $database = $this->connection()->getDatabaseName();

        if (! is_string($database) || trim($database) === '') {
            throw new RuntimeException(
                'Project-state export cannot resolve the active database schema.'
            );
        }

        $actualTables = array_values(array_filter(
            $this->connection()
                ->getSchemaBuilder()
                ->getTableListing($database, false),
            fn (mixed $table): bool => is_string($table) && trim($table) !== '',
        ));
        $ignoredTables = $this->contractRegistry->ignoredTables();

        $unclassified = array_values(array_diff(
            $actualTables,
            $exportedTables,
            $policyTables,
            $ignoredTables,
        ));

        if ($unclassified !== []) {
            sort($unclassified, SORT_STRING);

            throw new RuntimeException(
                'Project-state export is blocked by unclassified database table(s): '
                .implode(', ', $unclassified)
                .'. Add transfer support or an explicit table policy first.'
            );
        }
    }

    public function assertExportTablePolicies(): void
    {
        foreach ($this->contractRegistry->tablePolicies() as $table => $policy) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if ($policy['mode'] === 'must_be_empty') {
                $count = (int) $this->connection()->table($table)->count();

                if ($count > 0) {
                    throw new RuntimeException(sprintf(
                        'Project-state export is blocked because [%s] contains %d row(s). %s',
                        $table,
                        $count,
                        $policy['reason'],
                    ));
                }

                continue;
            }

            if ($policy['mode'] !== 'terminal_only') {
                continue;
            }

            $column = $policy['column'];
            $values = $policy['values'];
            $count = (int) $this->connection()
                ->table($table)
                ->where(function ($query) use ($column, $values): void {
                    $query
                        ->whereNull($column)
                        ->orWhereNotIn($column, $values);
                })
                ->count();

            if ($count > 0) {
                throw new RuntimeException(sprintf(
                    'Project-state export is blocked because [%s] contains %d nonterminal row(s) in [%s]. Allowed terminal value(s): %s. %s',
                    $table,
                    $count,
                    $column,
                    implode(', ', $values),
                    $policy['reason'],
                ));
            }
        }
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection();
    }
}