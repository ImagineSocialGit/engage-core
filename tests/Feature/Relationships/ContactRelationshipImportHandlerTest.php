<?php

namespace Tests\Feature\Relationships;

use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Modules\Relationships\Import\ContactRelationshipImportHandler;
use App\Modules\Relationships\Models\ContactRelationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactRelationshipImportHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('relationships.types', [
            'consumer' => [
                'singular' => 'Lead',
                'plural' => 'Leads',
                'stages' => [],
            ],
            'realtor' => [
                'singular' => 'Realtor',
                'plural' => 'Realtors',
                'stages' => [
                    'target_agent' => [
                        'label' => 'Target Agent',
                        'sort_order' => 10,
                        'active' => true,
                    ],
                    'referral_partner' => [
                        'label' => 'Referral Partner',
                        'sort_order' => 20,
                        'active' => true,
                    ],
                ],
            ],
        ]);
    }

    public function test_relationship_import_uses_row_values_and_occurrence_source_fallback_idempotently(): void
    {
        $contact = Contact::factory()->create(['email' => 'agent@example.test']);
        $batch = ContactImportBatch::factory()->create(['source' => 'crm_csv']);

        $first = $this->context(
            contact: $contact,
            batch: $batch,
            rowNumber: 2,
            row: [
                'Relationship' => 'realtor',
                'Stage' => 'target_agent',
                'Started' => '2025-01-15',
            ],
            mapping: [
                'relationship_key' => 'Relationship',
                'relationship_stage' => 'Stage',
                'relationship_started_at' => 'Started',
            ],
            source: 'agent_database',
            subsource: 'fl_va_agents',
        );

        app(ContactRelationshipImportHandler::class)->handle($first);

        $relationship = ContactRelationship::query()->firstOrFail();

        $this->assertSame('realtor', $relationship->relationship_key);
        $this->assertSame('target_agent', $relationship->stage_key);
        $this->assertSame('agent_database', $relationship->source);
        $this->assertSame('fl_va_agents', $relationship->subsource);
        $this->assertSame('2025-01-15', $relationship->started_at?->toDateString());

        $second = $this->context(
            contact: $contact,
            batch: $batch,
            rowNumber: 3,
            row: [
                'Relationship' => 'realtor',
                'Stage' => 'referral_partner',
            ],
            mapping: [
                'relationship_key' => 'Relationship',
                'relationship_stage' => 'Stage',
            ],
            source: 'later_import',
            subsource: null,
        );

        app(ContactRelationshipImportHandler::class)->handle($second);

        $this->assertDatabaseCount('contact_relationships', 1);
        $this->assertSame('referral_partner', $relationship->fresh()->stage_key);
        $this->assertSame('agent_database', $relationship->fresh()->source);
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
        ?string $source,
        ?string $subsource,
    ): ContactImportContext {
        $occurrence = ContactImportOccurrence::query()->create([
            'contact_import_batch_id' => $batch->id,
            'contact_id' => $contact->id,
            'row_number' => $rowNumber,
            'outcome' => ContactImportOccurrence::OUTCOME_UPDATED,
            'identity_type' => 'email',
            'identity_value' => $contact->email,
            'original_source' => $source,
            'original_subsource' => $subsource,
            'row_fingerprint' => hash('sha256', serialize($row)),
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