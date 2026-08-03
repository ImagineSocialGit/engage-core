<?php

namespace Tests\Feature\ProjectState;

use App\Support\ProjectState\ProjectStateImportIdMap;
use App\Support\ProjectState\ProjectStateImporter;
use App\Support\ProjectState\ProjectStateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ProjectStateImporterExtractionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);
    }

    public function test_manager_import_delegates_to_the_extracted_importer(): void
    {
        $document = [
            'format' => 'delegation-test',
        ];
        $expectedReport = [
            'valid' => true,
            'applied' => true,
            'applied_counts' => [
                'contacts' => 1,
            ],
        ];

        $this->mock(
            ProjectStateImporter::class,
            function (MockInterface $mock) use ($document, $expectedReport): void {
                $mock
                    ->shouldReceive('import')
                    ->once()
                    ->with($document)
                    ->andReturn($expectedReport);
            },
        );

        $this->assertEquals(
            $expectedReport,
            app(ProjectStateManager::class)->import($document),
        );
    }

    public function test_extracted_importer_preserves_upsert_identity_remapping_and_reporting(): void
    {
        $now = now()->startOfSecond();

        DB::table('site_settings')->insert([
            'id' => 90,
            'key' => 'booking_url',
            'value' => 'https://source.example.test/book',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $document = app(ProjectStateManager::class)->export();

        DB::table('site_settings')->delete();
        DB::table('site_settings')->insert([
            'id' => 190,
            'key' => 'booking_url',
            'value' => 'https://target.example.test/book',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $report = app(ProjectStateImporter::class)->import($document);

        $this->assertTrue($report['applied']);
        $this->assertSame(1, $report['applied_counts']['site_settings']);
        $this->assertDatabaseHas('site_settings', [
            'id' => 190,
            'key' => 'booking_url',
            'value' => 'https://source.example.test/book',
        ]);
        $this->assertDatabaseMissing('site_settings', [
            'id' => 90,
        ]);
    }

    public function test_import_id_map_is_resettable_between_import_attempts(): void
    {
        $idMap = app(ProjectStateImportIdMap::class);

        $idMap->remember(
            table: 'contact_statuses',
            sourceId: 40,
            targetId: 140,
        );

        $this->assertSame(140, $idMap->get('contact_statuses', 40));

        $idMap->reset();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Project-state source ID [40] has not been imported for [contact_statuses].'
        );

        $idMap->get('contact_statuses', 40);
    }
}