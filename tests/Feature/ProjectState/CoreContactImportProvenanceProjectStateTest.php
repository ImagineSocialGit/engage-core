<?php

namespace Tests\Feature\ProjectState;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Support\ProjectState\ProjectStateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoreContactImportProvenanceProjectStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);
    }

    public function test_contact_import_occurrences_round_trip_with_core_project_state(): void
    {
        $batch = ContactImportBatch::factory()->create([
            'original_filename' => 'past-clients.csv',
        ]);

        $contact = Contact::factory()->create([
            'email' => 'borrower@example.test',
            'contact_import_batch_id' => $batch->id,
        ]);

        $occurrence = ContactImportOccurrence::query()->create([
            'contact_import_batch_id' => $batch->id,
            'contact_id' => $contact->id,
            'row_number' => 7,
            'outcome' => ContactImportOccurrence::OUTCOME_CREATED,
            'identity_type' => 'email',
            'identity_value' => 'borrower@example.test',
            'original_source' => 'Realtor.com',
            'original_subsource' => 'Space Coast',
            'original_status' => 'Old Lead',
            'row_fingerprint' => hash('sha256', 'project-state-import-row'),
            'meta' => [
                'status_mapping' => [
                    'state' => 'unmapped',
                ],
            ],
        ]);

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();

        $this->assertSame(16, $document['version']);
        $this->assertSame(2, $document['sections']['core']['version']);
        $this->assertCount(1, $document['sections']['core']['tables']['contact_import_occurrences']);
        $this->assertSame(
            'borrower@example.test',
            $document['sections']['core']['tables']['contact_import_occurrences'][0]['identity_value'],
        );

        DB::table('contact_import_occurrences')->delete();
        DB::table('contacts')->delete();
        DB::table('contact_import_batches')->delete();

        $report = $projectState->validate($document);

        $this->assertTrue($report['valid'], implode(PHP_EOL, $report['errors']));

        $projectState->import($document);

        $this->assertDatabaseHas('contact_import_occurrences', [
            'id' => $occurrence->id,
            'contact_import_batch_id' => $batch->id,
            'contact_id' => $contact->id,
            'row_number' => 7,
            'identity_type' => 'email',
            'identity_value' => 'borrower@example.test',
            'original_source' => 'Realtor.com',
        ]);
    }
}