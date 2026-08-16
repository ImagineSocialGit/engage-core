<?php

namespace Tests\Feature\ProjectState;

use App\Support\ProjectState\ProjectStateContractRegistry;
use App\Support\ProjectState\ProjectStateManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ProjectStateCoverageContractTest extends TestCase
{
    use RefreshDatabase;

    private const SCHEMA_MUTATION_CONNECTION = 'project_state_schema_mutation';

    public function test_every_application_table_is_exported_or_has_an_explicit_policy(): void
    {
        $exportedTables = collect(app(ProjectStateContractRegistry::class)->sections())
            ->flatMap(fn (array $section): array => array_keys($section['tables']))
            ->values()
            ->all();
        $policyTables = array_keys(config('project_state.table_policies'));
        $ignoredTables = config('project_state.schema_ignored_tables');
        $connection = DB::connection();
        $actualTables = $connection
            ->getSchemaBuilder()
            ->getTableListing(
                $connection->getDatabaseName(),
                false,
            );

        $this->assertEquals(
            [],
            array_values(array_intersect($exportedTables, $policyTables)),
        );
        $this->assertEquals(
            [],
            array_values(array_diff(
                $actualTables,
                $exportedTables,
                $policyTables,
                $ignoredTables,
            )),
        );
    }

    public function test_export_reads_section_tables_inside_one_database_transaction(): void
    {
        $transactionLevels = [];
        $baseTransactionLevel = DB::connection()->transactionLevel();

        DB::listen(function ($query) use (&$transactionLevels): void {
            $sql = strtolower(ltrim($query->sql));

            if (str_starts_with($sql, 'select')
                && str_contains($sql, 'contacts')
            ) {
                $transactionLevels[] = DB::connection()->transactionLevel();
            }
        });

        app(ProjectStateManager::class)->export();

        $this->assertNotEmpty($transactionLevels);

        foreach ($transactionLevels as $transactionLevel) {
            $this->assertSame($baseTransactionLevel + 1, $transactionLevel);
        }
    }

    public function test_export_is_blocked_when_a_new_table_has_no_policy(): void
    {
        $schema = $this->independentSchemaBuilder();

        $schema->dropIfExists('unclassified_project_state_test');
        $schema->create('unclassified_project_state_test', function (Blueprint $table): void {
            $table->id();
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Project-state export is blocked by unclassified database table(s): unclassified_project_state_test.'
        );

        try {
            app(ProjectStateManager::class)->export();
        } finally {
            $schema->dropIfExists('unclassified_project_state_test');
            $this->releaseIndependentSchemaConnection();
        }
    }

    public function test_export_is_blocked_when_an_exported_table_gains_an_unclassified_column(): void
    {
        $schema = $this->independentSchemaBuilder();

        if ($schema->hasColumn('contacts', 'unclassified_project_state_value')) {
            $schema->table('contacts', function (Blueprint $table): void {
                $table->dropColumn('unclassified_project_state_value');
            });
        }

        $schema->table('contacts', function (Blueprint $table): void {
            $table->string('unclassified_project_state_value')->nullable();
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Project-state table [contacts] does not match its complete column contract (unclassified column(s): unclassified_project_state_value).'
        );

        try {
            app(ProjectStateManager::class)->export();
        } finally {
            if ($schema->hasColumn('contacts', 'unclassified_project_state_value')) {
                $schema->table('contacts', function (Blueprint $table): void {
                    $table->dropColumn('unclassified_project_state_value');
                });
            }

            $this->releaseIndependentSchemaConnection();
        }
    }

    public function test_export_is_blocked_when_unsupported_durable_state_exists(): void
    {
        config()->set('project_state.table_policies.cache', [
            'mode' => 'must_be_empty',
            'reason' => 'Test unsupported state must be cleared before export.',
        ]);

        DB::table('cache')->insert([
            'key' => 'project-state-unsupported-test',
            'value' => '1',
            'expiration' => now()->addHour()->timestamp,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Project-state export is blocked because [cache] contains 1 row(s).'
        );

        app(ProjectStateManager::class)->export();
    }

    public function test_export_is_blocked_when_operational_receipts_are_not_terminal(): void
    {
        $now = now()->startOfSecond();

        DB::table('inbound_message_receipts')->insert([
            'inbound_message_id' => null,
            'client_key' => 'test-client',
            'provider' => 'telnyx',
            'provider_event_id' => 'event-1',
            'provider_message_id' => null,
            'provider_event_key' => hash('sha256', 'event-1'),
            'provider_message_key' => null,
            'status' => 'processing',
            'attempts' => 1,
            'response_message' => null,
            'last_error' => null,
            'last_attempted_at' => $now,
            'completed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Project-state export is blocked because [inbound_message_receipts] contains 1 nonterminal row(s) in [status].'
        );

        app(ProjectStateManager::class)->export();
    }

    public function test_terminal_operational_receipts_do_not_block_export(): void
    {
        $now = now()->startOfSecond();

        DB::table('inbound_message_receipts')->insert([
            'inbound_message_id' => null,
            'client_key' => 'test-client',
            'provider' => 'telnyx',
            'provider_event_id' => 'event-2',
            'provider_message_id' => null,
            'provider_event_key' => hash('sha256', 'event-2'),
            'provider_message_key' => null,
            'status' => 'completed',
            'attempts' => 1,
            'response_message' => null,
            'last_error' => null,
            'last_attempted_at' => $now,
            'completed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $document = app(ProjectStateManager::class)->export();

        $this->assertSame(11, $document['version']);
        $this->assertArrayNotHasKey(
            'inbound_message_receipts',
            $document['sections']['core']['tables'],
        );
    }

    public function test_export_rejects_known_polymorphic_targets_that_are_not_exported(): void
    {
        $now = now()->startOfSecond();

        $taskId = (int) DB::table('tasks')->insertGetId([
            'assigned_to_type' => null,
            'assigned_to_id' => null,
            'responsible_party' => 'internal',
            'responsible_type' => null,
            'responsible_id' => null,
            'task_template_id' => null,
            'task_template_key' => null,
            'source' => 'manual',
            'title' => 'Unsafe appointment link',
            'description' => null,
            'status' => 'open',
            'priority' => null,
            'due_at' => null,
            'completed_at' => null,
            'canceled_at' => null,
            'canceled_reason' => null,
            'archived_at' => null,
            'meta' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('task_links')->insert([
            'task_id' => $taskId,
            'linkable_type' => 'App\\Modules\\Scheduling\\Models\\Appointment',
            'linkable_id' => 999,
            'role' => 'subject',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Project-state polymorphic reference [task_links.0.linkable_type/linkable_id] targets unexported table [appointments].'
        );

        app(ProjectStateManager::class)->export();
    }

    public function test_export_rejects_unknown_polymorphic_types_instead_of_preserving_raw_ids(): void
    {
        $now = now()->startOfSecond();

        DB::table('notes')->insert([
            'contact_id' => null,
            'related_type' => 'App\\Modules\\Scheduling\\Models\\Appointment',
            'related_id' => 999,
            'body' => 'Unsafe historical relation.',
            'meta' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Project-state polymorphic reference [notes.0.related_type] uses unsupported type [App\\Modules\\Scheduling\\Models\\Appointment].'
        );

        app(ProjectStateManager::class)->export();
    }

    private function independentSchemaBuilder(): SchemaBuilder
    {
        $defaultConnection = DB::getDefaultConnection();
        $configuration = config("database.connections.{$defaultConnection}");

        if (! is_array($configuration)) {
            throw new RuntimeException(
                "Database connection [{$defaultConnection}] has no configuration."
            );
        }

        config()->set(
            'database.connections.'.self::SCHEMA_MUTATION_CONNECTION,
            $configuration,
        );

        DB::purge(self::SCHEMA_MUTATION_CONNECTION);

        return Schema::connection(self::SCHEMA_MUTATION_CONNECTION);
    }

    private function releaseIndependentSchemaConnection(): void
    {
        DB::disconnect(self::SCHEMA_MUTATION_CONNECTION);
        DB::purge(self::SCHEMA_MUTATION_CONNECTION);
    }
}