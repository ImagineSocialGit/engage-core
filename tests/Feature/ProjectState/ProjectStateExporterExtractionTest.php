<?php

namespace Tests\Feature\ProjectState;

use App\Support\ProjectState\ProjectStateDocumentCodec;
use App\Support\ProjectState\ProjectStateExporter;
use App\Support\ProjectState\ProjectStateManager;
use App\Support\ProjectState\ProjectStateResumeManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ProjectStateExporterExtractionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);
    }

    public function test_manager_export_preserves_the_extracted_exporter_document(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-02 18:30:00', 'UTC'));

        try {
            $exporterDocument = app(ProjectStateExporter::class)->export();
            $managerDocument = app(ProjectStateManager::class)->export();

            $this->assertEquals($exporterDocument, $managerDocument);
            $this->assertSame(10, $managerDocument['version']);
            $this->assertSame(
                $managerDocument['checksum'],
                app(ProjectStateDocumentCodec::class)->checksum($managerDocument),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_exporter_preserves_the_pending_resume_item_guard(): void
    {
        $now = now()->startOfSecond();

        DB::table('project_state_resume_items')->insert([
            'category' => ProjectStateResumeManager::CATEGORY_SCHEDULED_MESSAGES,
            'source_table' => 'scheduled_messages',
            'source_record_id' => '150',
            'original_status' => 'pending',
            'state' => ProjectStateResumeManager::STATE_PENDING,
            'result_code' => null,
            'resumed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Project-state export is blocked while 1 imported work item(s) still require explicit resume.'
        );

        app(ProjectStateExporter::class)->export();
    }
}