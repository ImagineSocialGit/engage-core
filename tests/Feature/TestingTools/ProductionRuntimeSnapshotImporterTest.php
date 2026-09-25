<?php

namespace Tests\Feature\TestingTools;

use App\Support\TestingTools\ProductionRuntimeSnapshotImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class ProductionRuntimeSnapshotImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_maps_module_installations_by_string_primary_key_without_assuming_an_id_column(): void
    {
        DB::table('module_installations')->insert([
            'module_key' => 'core',
            'status' => 'installed',
            'schema_version' => 1,
            'manifest_hash' => str_repeat('a', 64),
            'installed_at' => now(),
            'last_migrated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $path = $this->writeSnapshot([
            [
                'type' => 'dump_meta',
                'generated_at' => '2026-09-21T21:00:39+00:00',
                'database' => 'production_snapshot_test',
                'mode' => 'runtime_state_with_definition_replay_v2',
            ],
            $this->schema('module_installations', [
                'module_key', 'status', 'schema_version', 'manifest_hash',
                'installed_at', 'last_migrated_at', 'created_at', 'updated_at',
            ]),
            $this->row('module_installations', 'identity_only', [
                'module_key' => 'core',
            ]),
        ]);

        try {
            $result = app(ProductionRuntimeSnapshotImporter::class)
                ->import($path, dryRun: true);

            $this->assertTrue($result['dry_run']);
            $this->assertSame(1, $result['mapped_reference_rows']);
            $this->assertSame([], $result['imported_counts']);
        } finally {
            File::delete($path);
        }
    }

    public function test_it_isolates_active_message_chain_enrollments_without_inventing_a_meta_column(): void
    {
        $chainId = DB::table('message_chains')->insertGetId([
            'key' => 'snapshot-isolation-chain',
            'name' => 'Snapshot Isolation Chain',
            'description' => null,
            'status' => 'active',
            'current_version_id' => null,
            'source' => 'config',
            'source_version' => '1',
            'is_customized' => false,
            'customized_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $versionId = DB::table('message_chain_versions')->insertGetId([
            'message_chain_id' => $chainId,
            'version' => 1,
            'exit_conditions' => null,
            'content_hash' => str_repeat('b', 64),
            'published_at' => now(),
            'created_by' => null,
            'created_at' => now(),
        ]);

        DB::table('message_chains')
            ->where('id', $chainId)
            ->update(['current_version_id' => $versionId]);

        $path = $this->writeSnapshot([
            [
                'type' => 'dump_meta',
                'generated_at' => '2026-09-21T21:00:39+00:00',
                'database' => 'production_snapshot_test',
                'mode' => 'runtime_state_with_definition_replay_v2',
            ],
            $this->schema('message_chains', [
                'id', 'key', 'name', 'status', 'current_version_id',
            ]),
            $this->row('message_chains', 'identity_only', [
                'id' => 91,
                'key' => 'snapshot-isolation-chain',
                'name' => 'Snapshot Isolation Chain',
                'status' => 'active',
                'current_version_id' => 92,
            ]),
            $this->schema('message_chain_versions', [
                'id', 'message_chain_id', 'version', 'content_hash',
            ]),
            $this->row('message_chain_versions', 'identity_only', [
                'id' => 92,
                'message_chain_id' => 91,
                'version' => 1,
                'content_hash' => str_repeat('b', 64),
            ]),
            $this->schema('contacts', [
                'id', 'first_name', 'email', 'source', 'meta', 'created_at', 'updated_at',
            ]),
            $this->row('contacts', 'full', [
                'id' => 7001,
                'first_name' => 'Snapshot',
                'email' => 'snapshot-isolation@example.test',
                'source' => 'snapshot_test',
                'meta' => '[]',
                'created_at' => '2026-09-21 18:00:00',
                'updated_at' => '2026-09-21 18:00:00',
            ]),
            $this->schema('message_chain_enrollments', [
                'id',
                'message_chain_version_id',
                'recipient_type',
                'recipient_id',
                'context_type',
                'context_id',
                'origin_type',
                'origin_id',
                'surface',
                'current_message_chain_step_id',
                'next_action_at',
                'status',
                'dedupe_key',
                'started_at',
                'paused_at',
                'resumed_at',
                'exited_at',
                'exit_reason_code',
                'completed_at',
                'cancelled_at',
                'created_at',
                'updated_at',
            ]),
            $this->row('message_chain_enrollments', 'full', [
                'id' => 8001,
                'message_chain_version_id' => 92,
                'recipient_type' => 'App\\Modules\\Core\\Models\\Contact',
                'recipient_id' => 7001,
                'context_type' => null,
                'context_id' => null,
                'origin_type' => null,
                'origin_id' => null,
                'surface' => 'campaigns',
                'current_message_chain_step_id' => null,
                'next_action_at' => '2026-09-23 13:30:22',
                'status' => 'active',
                'dedupe_key' => 'snapshot-isolation-enrollment',
                'started_at' => '2026-09-18 13:30:04',
                'paused_at' => null,
                'resumed_at' => null,
                'exited_at' => null,
                'exit_reason_code' => null,
                'completed_at' => null,
                'cancelled_at' => null,
                'created_at' => '2026-09-18 13:30:04',
                'updated_at' => '2026-09-20 13:30:22',
            ]),
        ]);

        try {
            $result = app(ProductionRuntimeSnapshotImporter::class)
                ->import($path);

            $this->assertSame(1, $result['isolated_message_chain_enrollments']);
            $this->assertSame(1, $result['imported_counts']['message_chain_enrollments']);

            $enrollment = DB::table('message_chain_enrollments')
                ->where('id', 8001)
                ->first();

            $this->assertNotNull($enrollment);
            $this->assertSame($versionId, (int) $enrollment->message_chain_version_id);
            $this->assertSame(7001, (int) $enrollment->recipient_id);
            $this->assertSame('active', $enrollment->status);
            $this->assertSame(
                'testing:production_snapshot:campaigns',
                $enrollment->surface,
            );
            $this->assertFalse(property_exists($enrollment, 'meta'));
        } finally {
            File::delete($path);
        }
    }

    public function test_it_maps_synced_definitions_and_replays_production_authored_message_definitions(): void
    {
        $statusId = DB::table('contact_statuses')->insertGetId([
            'key' => 'snapshot-test-status',
            'name' => 'Snapshot Test Status',
            'is_core' => false,
            'is_active' => true,
            'is_customized' => false,
            'sort_order' => 999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $path = $this->writeSnapshot([
            [
                'type' => 'dump_meta',
                'generated_at' => '2026-09-21T18:20:18+00:00',
                'database' => 'production_snapshot_test',
                'mode' => 'runtime_state_with_definition_replay_v2',
            ],
            $this->schema('contact_statuses', [
                'id', 'key', 'name', 'is_active',
            ]),
            $this->row('contact_statuses', 'identity_only', [
                'id' => 41,
                'key' => 'snapshot-test-status',
                'name' => 'Production label is not authoritative here',
                'is_active' => 1,
            ]),
            $this->schema('message_template_presets', [
                'id', 'key', 'name', 'description', 'channel', 'purpose', 'scope',
                'message_type', 'payload_class', 'queue', 'dispatch_keys', 'payload',
                'tokens', 'status', 'is_active', 'source', 'source_config_path',
                'source_version', 'is_customized', 'customized_at', 'last_synced_at',
                'meta', 'created_at', 'updated_at',
            ]),
            $this->row('message_template_presets', 'replay_full', [
                'id' => 144,
                'key' => 'crm_message_snapshot_birthday',
                'name' => 'Birthday',
                'description' => 'Production-authored annual touch.',
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'annual_touch',
                'message_type' => 'campaign_annual_touch',
                'payload_class' => 'App\\Modules\\Messaging\\Payloads\\EmailPayload',
                'queue' => 'marketing',
                'dispatch_keys' => json_encode(['campaign_touch_due'], JSON_THROW_ON_ERROR),
                'payload' => json_encode([
                    'subject' => 'Happy Birthday',
                    'body' => 'Happy Birthday, {first_name}!',
                ], JSON_THROW_ON_ERROR),
                'tokens' => json_encode(['first_name'], JSON_THROW_ON_ERROR),
                'status' => 'active',
                'is_active' => 1,
                'source' => 'crm_reusable',
                'source_config_path' => null,
                'source_version' => 1,
                'is_customized' => 1,
                'customized_at' => '2026-08-31 05:59:54',
                'last_synced_at' => null,
                'meta' => json_encode([], JSON_THROW_ON_ERROR),
                'created_at' => '2026-08-31 05:59:54',
                'updated_at' => '2026-08-31 05:59:54',
            ]),
            $this->schema('message_templates', [
                'id', 'key', 'name', 'description', 'channel', 'status',
                'composition_context_key', 'composition_family_key', 'current_version_id',
                'source', 'source_version', 'is_customized', 'customized_at',
                'created_at', 'updated_at',
            ]),
            $this->row('message_templates', 'replay_full', [
                'id' => 144,
                'key' => 'crm_message_snapshot_birthday',
                'name' => 'Birthday',
                'description' => 'Production-authored annual touch.',
                'channel' => 'email',
                'status' => 'active',
                'composition_context_key' => null,
                'composition_family_key' => null,
                'current_version_id' => 155,
                'source' => 'crm_reusable',
                'source_version' => '1',
                'is_customized' => 1,
                'customized_at' => '2026-08-31 05:59:54',
                'created_at' => '2026-08-31 05:59:54',
                'updated_at' => '2026-08-31 05:59:54',
            ]),
            $this->schema('message_template_versions', [
                'id', 'message_template_id', 'version', 'subject', 'content',
                'renderer_key', 'renderer_version', 'content_hash', 'created_by', 'created_at',
            ]),
            $this->row('message_template_versions', 'replay_full', [
                'id' => 155,
                'message_template_id' => 144,
                'version' => 1,
                'subject' => 'Happy Birthday',
                'content' => json_encode([
                    'body' => 'Happy Birthday, {first_name}!',
                ], JSON_THROW_ON_ERROR),
                'renderer_key' => 'email_html',
                'renderer_version' => '1',
                'content_hash' => hash('sha256', 'snapshot-birthday-v1'),
                'created_by' => null,
                'created_at' => '2026-08-31 05:59:54',
            ]),
            $this->schema('message_template_catalog_entries', [
                'id', 'message_template_preset_id', 'channel', 'purpose', 'scope',
                'module_key', 'module_label', 'surface', 'group_key', 'group_label',
                'item_key', 'item_label', 'item_order', 'usage_type', 'source',
                'source_config_path', 'context_type', 'context_id', 'is_active',
                'meta', 'created_at', 'updated_at',
            ]),
            $this->row('message_template_catalog_entries', 'replay_full', [
                'id' => 188,
                'message_template_preset_id' => 144,
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'annual_touch',
                'module_key' => 'campaigns',
                'module_label' => 'Campaigns',
                'surface' => 'campaigns',
                'group_key' => 'annual_touches:email',
                'group_label' => 'Annual touches',
                'item_key' => 'crm_message_snapshot_birthday',
                'item_label' => 'Birthday',
                'item_order' => 10,
                'usage_type' => 'campaign_annual_touch',
                'source' => 'crm_reusable',
                'source_config_path' => null,
                'context_type' => null,
                'context_id' => null,
                'is_active' => 1,
                'meta' => json_encode([], JSON_THROW_ON_ERROR),
                'created_at' => '2026-08-31 05:59:54',
                'updated_at' => '2026-08-31 05:59:54',
            ]),
            $this->schema('contacts', [
                'id', 'first_name', 'email', 'source', 'meta', 'created_at', 'updated_at',
            ]),
            $this->row('contacts', 'full', [
                'id' => 7001,
                'first_name' => 'Production',
                'email' => 'production-snapshot@example.test',
                'source' => 'snapshot_test',
                'meta' => '[]',
                'created_at' => '2026-09-21 18:00:00',
                'updated_at' => '2026-09-21 18:00:00',
            ]),
            $this->schema('contact_workflow_profiles', [
                'id', 'contact_id', 'contact_status_id', 'meta', 'created_at', 'updated_at',
            ]),
            $this->row('contact_workflow_profiles', 'full', [
                'id' => 8001,
                'contact_id' => 7001,
                'contact_status_id' => 41,
                'meta' => json_encode([
                    'last_status_change' => [
                        'from_contact_status_id' => 41,
                        'to_contact_status_id' => 41,
                    ],
                ], JSON_THROW_ON_ERROR),
                'created_at' => '2026-09-21 18:00:00',
                'updated_at' => '2026-09-21 18:00:00',
            ]),
        ]);

        try {
            $importer = app(ProductionRuntimeSnapshotImporter::class);

            $dryRun = $importer->import($path, dryRun: true);

            $this->assertTrue($dryRun['dry_run']);
            $this->assertSame(2, $dryRun['runtime_rows']);
            $this->assertSame(4, $dryRun['replay_rows']);
            $this->assertDatabaseMissing('message_templates', [
                'key' => 'crm_message_snapshot_birthday',
            ]);

            $result = $importer->import($path);

            $this->assertFalse($result['dry_run']);
            $this->assertSame(1, $result['imported_counts']['contacts']);
            $this->assertSame(1, $result['imported_counts']['contact_workflow_profiles']);

            $presetId = DB::table('message_template_presets')
                ->where('key', 'crm_message_snapshot_birthday')
                ->value('id');
            $template = DB::table('message_templates')
                ->where('key', 'crm_message_snapshot_birthday')
                ->first();

            $this->assertNotNull($presetId);
            $this->assertNotNull($template);
            $this->assertNotNull($template->current_version_id);
            $this->assertDatabaseHas('message_template_versions', [
                'id' => $template->current_version_id,
                'message_template_id' => $template->id,
                'version' => 1,
                'subject' => 'Happy Birthday',
            ]);
            $this->assertDatabaseHas('message_template_catalog_entries', [
                'message_template_preset_id' => $presetId,
                'item_key' => 'crm_message_snapshot_birthday',
            ]);
            $this->assertDatabaseHas('contacts', [
                'id' => 7001,
                'email' => 'production-snapshot@example.test',
            ]);
            $this->assertDatabaseHas('contact_workflow_profiles', [
                'id' => 8001,
                'contact_id' => 7001,
                'contact_status_id' => $statusId,
            ]);

            $meta = DB::table('contact_workflow_profiles')
                ->where('id', 8001)
                ->value('meta');
            $decoded = json_decode((string) $meta, true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(
                $statusId,
                data_get($decoded, 'last_status_change.from_contact_status_id'),
            );
            $this->assertSame(
                $statusId,
                data_get($decoded, 'last_status_change.to_contact_status_id'),
            );
        } finally {
            File::delete($path);
        }
    }

    public function test_it_rejects_the_original_identity_only_snapshot_format(): void
    {
        $path = $this->writeSnapshot([
            [
                'type' => 'dump_meta',
                'generated_at' => '2026-09-21T18:20:18+00:00',
                'database' => 'production_snapshot_test',
                'mode' => 'runtime_state_with_config_identity_maps',
            ],
            $this->schema('contacts', ['id']),
        ]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('definition-replay v2 runtime snapshot');

            app(ProductionRuntimeSnapshotImporter::class)->import($path, dryRun: true);
        } finally {
            File::delete($path);
        }
    }

    public function test_it_accepts_equivalent_hyphenated_and_underscored_client_keys(): void
    {
        config()->set('client.key', 'rob-the-mortgage-coach');

        $path = $this->writeSnapshot([
            [
                'type' => 'dump_meta',
                'generated_at' => '2026-09-25T18:33:27+00:00',
                'database' => 'crm_robthemortgagecoach',
                'mode' => 'runtime_state_with_definition_replay_v2',
            ],
            $this->schema('message_template_composition_layers', [
                'id',
                'client_key',
            ]),
            $this->row('message_template_composition_layers', 'full', [
                'id' => 7001,
                'client_key' => 'rob_the_mortgage_coach',
            ]),
            $this->schema('webhook_inbox_receipts', [
                'id',
                'client_key',
            ]),
            $this->row('webhook_inbox_receipts', 'full', [
                'id' => 8001,
                'client_key' => 'rob-the-mortgage-coach',
            ]),
        ]);

        try {
            $result = app(ProductionRuntimeSnapshotImporter::class)
                ->import($path, dryRun: true);

            $this->assertTrue($result['dry_run']);
            $this->assertSame(
                'rob-the-mortgage-coach',
                $result['source_client_key'],
            );
        } finally {
            File::delete($path);
        }
    }

    /** @param list<array<string, mixed>> $records */
    private function writeSnapshot(array $records): string
    {
        $path = storage_path('framework/testing/'.Str::uuid().'.jsonl');
        File::ensureDirectoryExists(dirname($path));

        File::put(
            $path,
            collect($records)
                ->map(fn (array $record): string => json_encode(
                    $record,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                ))
                ->implode(PHP_EOL).PHP_EOL,
        );

        return $path;
    }

    /** @param list<string> $columns @return array<string, mixed> */
    private function schema(string $table, array $columns): array
    {
        return [
            'type' => 'table_schema',
            'table' => $table,
            'columns' => array_map(
                static fn (string $column): array => ['Field' => $column],
                $columns,
            ),
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function row(string $table, string $mode, array $data): array
    {
        return [
            'type' => 'row',
            'table' => $table,
            'mode' => $mode,
            'data' => $data,
        ];
    }
}