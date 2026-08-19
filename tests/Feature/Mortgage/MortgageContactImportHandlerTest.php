<?php

namespace Tests\Feature\Mortgage;

use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Modules\Mortgage\Enums\HasRealtorState;
use App\Modules\Mortgage\Import\MortgageContactImportHandler;
use App\Modules\Mortgage\Models\ContactMortgageProfile;
use App\Modules\Mortgage\Models\MortgageLoan;
use App\Modules\Mortgage\Models\MortgageLoanParticipant;
use App\Modules\Mortgage\Models\MortgageLoanRealtor;
use App\Modules\Mortgage\Models\MortgageRealtorProductionSnapshot;
use App\Modules\Mortgage\Models\MortgageRealtorProfile;
use App\Modules\Relationships\Models\ContactRelationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MortgageContactImportHandlerTest extends TestCase
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
                ],
            ],
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected function additionalTestMigrationModuleKeys(): array
    {
        return ['mortgage'];
    }

    public function test_consumer_loan_history_and_shared_email_coborrower_import_replay_without_duplicates(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Russell',
            'last_name' => 'Shirey',
            'email' => 'russell@example.test',
            'phone' => '2487196618',
        ]);
        $batch = ContactImportBatch::factory()->create([
            'source' => 'crm_csv',
            'imported_at' => '2026-08-19 12:00:00',
        ]);

        $row = [
            'Has Realtor' => 'No',
            'Original Lead Date' => '2024-02-03',
            'Purpose' => 'Purchase',
            'Amount' => '$473,023',
            'Property' => '2355 REELING CIR',
            'City' => 'Melbourne',
            'State' => 'FL',
            'ZIP' => '32940',
            'Primary DOB' => '1986-11-28',
            'Primary Mailing' => '5270 Ellicott Drive, Centreville, VA 20120',
            'Co First' => 'Andrea',
            'Co Last' => 'Shirey',
            'Co Email' => 'RUSSELL@example.test',
            'Co Phone' => '2484083329',
            'Co DOB' => '1990-07-10',
            'Buyer Agent' => 'Agent Snapshot',
            'Buyer Agent Email' => 'agent@example.test',
        ];
        $mapping = [
            'mortgage_has_realtor' => 'Has Realtor',
            'mortgage_original_lead_at' => 'Original Lead Date',
            'mortgage_loan_purpose' => 'Purpose',
            'mortgage_loan_amount' => 'Amount',
            'mortgage_subject_property_street' => 'Property',
            'mortgage_subject_property_city' => 'City',
            'mortgage_subject_property_state' => 'State',
            'mortgage_subject_property_zip' => 'ZIP',
            'mortgage_primary_date_of_birth' => 'Primary DOB',
            'mortgage_primary_mailing_address' => 'Primary Mailing',
            'mortgage_coborrower_first_name' => 'Co First',
            'mortgage_coborrower_last_name' => 'Co Last',
            'mortgage_coborrower_email' => 'Co Email',
            'mortgage_coborrower_phone' => 'Co Phone',
            'mortgage_coborrower_date_of_birth' => 'Co DOB',
            'mortgage_buyer_agent_name' => 'Buyer Agent',
            'mortgage_buyer_agent_email' => 'Buyer Agent Email',
        ];

        $handler = app(MortgageContactImportHandler::class);
        $handler->handle($this->context($contact, $batch, 2, $row, $mapping));
        $handler->handle($this->context($contact, $batch, 3, $row, $mapping));

        $profile = ContactMortgageProfile::query()->where('contact_id', $contact->id)->firstOrFail();
        $loan = MortgageLoan::query()->firstOrFail();
        $participants = MortgageLoanParticipant::query()->orderBy('position')->get();

        $this->assertSame(HasRealtorState::No, $profile->has_realtor);
        $this->assertSame('2024-02-03', $profile->original_lead_at?->toDateString());
        $this->assertSame('473023.00', $loan->loan_amount);
        $this->assertSame('Melbourne', $loan->subject_property_city);
        $this->assertDatabaseCount('mortgage_loans', 1);
        $this->assertCount(2, $participants);
        $this->assertSame($contact->id, $participants[0]->contact_id);
        $this->assertNull($participants[1]->contact_id);
        $this->assertSame('russell@example.test', $participants[1]->email);
        $this->assertDatabaseCount('mortgage_loan_realtors', 1);
        $this->assertSame('Agent Snapshot', MortgageLoanRealtor::query()->value('name'));
    }

    public function test_realtor_production_import_creates_relationship_specialization_and_observation_snapshot_idempotently(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Karen',
            'last_name' => 'Nelson',
            'email' => 'karen@example.test',
        ]);
        $batch = ContactImportBatch::factory()->create([
            'source' => 'crm_csv',
            'imported_at' => '2026-08-19 12:00:00',
        ]);
        $row = [
            'Relationship' => 'realtor',
            'Stage' => 'target_agent',
            'Brokerage' => 'Example Realty',
            'Loan Count' => '427',
            'Conv Count' => '271',
            'VA Count' => '56',
        ];
        $mapping = [
            'relationship_key' => 'Relationship',
            'relationship_stage' => 'Stage',
            'mortgage_realtor_brokerage' => 'Brokerage',
            'mortgage_realtor_production_loan_count' => 'Loan Count',
            'mortgage_realtor_production_conventional_count' => 'Conv Count',
            'mortgage_realtor_production_va_count' => 'VA Count',
        ];

        $handler = app(MortgageContactImportHandler::class);
        $handler->handle($this->context($contact, $batch, 2, $row, $mapping, 'agent_database'));
        $handler->handle($this->context($contact, $batch, 3, $row, $mapping, 'agent_database'));

        $relationship = ContactRelationship::query()->firstOrFail();
        $profile = MortgageRealtorProfile::query()->firstOrFail();
        $snapshot = MortgageRealtorProductionSnapshot::query()->firstOrFail();

        $this->assertSame('realtor', $relationship->relationship_key);
        $this->assertSame('target_agent', $relationship->stage_key);
        $this->assertSame('agent_database', $relationship->source);
        $this->assertSame($relationship->id, $profile->contact_relationship_id);
        $this->assertSame('Example Realty', $profile->brokerage_name);
        $this->assertSame('2026-08-19', $snapshot->period_ending_on?->toDateString());
        $this->assertSame(12, $snapshot->period_months);
        $this->assertSame(427, $snapshot->loan_count);
        $this->assertSame(271, $snapshot->conventional_count);
        $this->assertSame(56, $snapshot->va_count);
        $this->assertDatabaseCount('contact_relationships', 1);
        $this->assertDatabaseCount('mortgage_realtor_profiles', 1);
        $this->assertDatabaseCount('mortgage_realtor_production_snapshots', 1);
    }

    public function test_realtor_production_metadata_defaults_do_not_create_empty_mortgage_profile_or_snapshot(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Taylor',
            'last_name' => 'Agent',
            'email' => 'taylor@example.test',
        ]);
        $batch = ContactImportBatch::factory()->create([
            'source' => 'crm_csv',
            'imported_at' => '2026-08-19 12:00:00',
        ]);

        $handler = app(MortgageContactImportHandler::class);
        $handler->handle($this->context(
            contact: $contact,
            batch: $batch,
            rowNumber: 2,
            row: [],
            mapping: [],
            defaults: [
                'relationship_key' => 'realtor',
                'relationship_stage' => 'target_agent',
                'mortgage_realtor_production_period_months' => '12',
                'mortgage_realtor_production_source' => 'agent_export',
            ],
        ));

        $this->assertDatabaseCount('mortgage_realtor_profiles', 0);
        $this->assertDatabaseCount('mortgage_realtor_production_snapshots', 0);
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
        ?string $source = 'loan_crm',
        array $defaults = [],
    ): ContactImportContext {
        $occurrence = ContactImportOccurrence::query()->create([
            'contact_import_batch_id' => $batch->id,
            'contact_id' => $contact->id,
            'row_number' => $rowNumber,
            'outcome' => ContactImportOccurrence::OUTCOME_UPDATED,
            'identity_type' => 'email',
            'identity_value' => $contact->email,
            'original_source' => $source,
            'row_fingerprint' => hash('sha256', serialize($row).$rowNumber),
        ]);

        return new ContactImportContext(
            contact: $contact,
            batch: $batch,
            occurrence: $occurrence,
            row: $row,
            mapping: $mapping,
            defaults: $defaults,
        );
    }
}