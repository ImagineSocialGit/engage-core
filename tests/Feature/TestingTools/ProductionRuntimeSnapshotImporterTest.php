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