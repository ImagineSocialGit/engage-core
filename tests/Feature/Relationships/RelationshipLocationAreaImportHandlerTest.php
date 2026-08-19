<?php

namespace Tests\Feature\Relationships;

use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Modules\Location\Models\LocationArea;
use App\Modules\Location\Models\LocationAreaAssignment;
use App\Support\ModuleIntegrations\RelationshipLocationAreaImportHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelationshipLocationAreaImportHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_location_import_assigns_existing_area_to_relationship_idempotently(): void
    {
        config()->set('modules.enabled', ['relationships', 'location']);
        config()->set('relationships.types', [
            'realtor' => [
                'singular' => 'Realtor',
                'plural' => 'Realtors',
                'stages' => [],
            ],
        ]);

        $contact = Contact::factory()->create(['email' => 'agent@example.test']);
        $batch = ContactImportBatch::factory()->create(['source' => 'crm_csv']);
        $area = LocationArea::factory()->create([
            'key' => 'fl_space_coast',
            'name' => 'Space Coast',
            'type' => LocationArea::TYPE_MARKET,
        ]);
        $row = [
            'Relationship' => 'realtor',
            'Area' => 'fl_space_coast',
        ];
        $mapping = [
            'relationship_key' => 'Relationship',
            'relationship_location_area_key' => 'Area',
        ];

        $handler = app(RelationshipLocationAreaImportHandler::class);
        $handler->handle($this->context($contact, $batch, 2, $row, $mapping));
        $handler->handle($this->context($contact, $batch, 3, $row, $mapping));

        $assignment = LocationAreaAssignment::query()->firstOrFail();

        $this->assertDatabaseCount('contact_relationships', 1);
        $this->assertDatabaseCount('location_area_assignments', 1);
        $this->assertSame($area->id, $assignment->location_area_id);
        $this->assertSame($contact->id, $assignment->contact_id);
        $this->assertTrue($assignment->is_primary);
        $this->assertSame('agent_database', $assignment->source);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $mapping
     */
    private function context(
        Contact $contact,
        ContactImportBatch $batch,
        int $rowNumber,
        array $row,
        array $mapping,
    ): ContactImportContext {
        $occurrence = ContactImportOccurrence::query()->create([
            'contact_import_batch_id' => $batch->id,
            'contact_id' => $contact->id,
            'row_number' => $rowNumber,
            'outcome' => ContactImportOccurrence::OUTCOME_UPDATED,
            'identity_type' => 'email',
            'identity_value' => $contact->email,
            'original_source' => 'agent_database',
            'row_fingerprint' => hash('sha256', serialize($row).$rowNumber),
        ]);

        return new ContactImportContext(
            contact: $contact,
            batch: $batch,
            occurrence: $occurrence,
            row: $row,
            mapping: $mapping,
        );
    }
}