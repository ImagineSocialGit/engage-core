<?php

namespace Tests\Feature\ProjectState;

use App\Modules\Core\Models\Contact;
use App\Modules\Mortgage\Enums\HasRealtorState;
use App\Modules\Mortgage\Enums\MortgageLoanParticipantRole;
use App\Modules\Mortgage\Enums\MortgageLoanRealtorRole;
use App\Modules\Mortgage\Models\ContactMortgageProfile;
use App\Modules\Mortgage\Models\MortgageLoan;
use App\Modules\Mortgage\Models\MortgageLoanParticipant;
use App\Modules\Mortgage\Models\MortgageLoanRealtor;
use App\Modules\Mortgage\Models\MortgageRealtorMarket;
use App\Modules\Mortgage\Models\MortgageRealtorProductionSnapshot;
use App\Modules\Mortgage\Models\MortgageRealtorProfile;
use App\Modules\Mortgage\Models\MortgageStage;
use App\Modules\Relationships\Models\ContactRelationship;
use App\Support\ProjectState\ProjectStateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MortgageProjectStateRoundTripTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, string>
     */
    protected function additionalTestMigrationModuleKeys(): array
    {
        return ['mortgage'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);
    }

    public function test_installed_mortgage_schema_round_trips_as_an_optional_project_state_section(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'borrower@example.test',
        ]);
        $agent = Contact::factory()->create([
            'email' => 'agent@example.test',
        ]);

        $stage = MortgageStage::query()->create([
            'key' => 'closed',
            'name' => 'Closed',
            'category' => 'terminal',
            'sort_order' => 900,
        ]);

        $consumerProfile = ContactMortgageProfile::query()->create([
            'contact_id' => $contact->id,
            'has_realtor' => HasRealtorState::Yes,
            'market_key' => 'fl_space_coast',
            'original_lead_at' => '2024-06-01 12:00:00',
            'meta' => ['source' => 'past_client_import'],
        ]);

        $loan = MortgageLoan::query()->create([
            'mortgage_stage_id' => $stage->id,
            'source_system' => 'pnt',
            'source_fingerprint' => hash('sha256', 'mortgage-loan-source-row'),
            'loan_originator' => 'Loan Officer',
            'loan_purpose' => 'Purchase',
            'loan_program' => 'VA',
            'loan_amount' => 473023,
            'note_rate' => 6.125,
            'subject_property_city' => 'Melbourne',
            'subject_property_state' => 'FL',
            'subject_property_zip' => '32940',
            'closed_on' => '2024-06-01',
            'meta' => ['source_file' => 'pnt.csv'],
        ]);

        $participant = MortgageLoanParticipant::query()->create([
            'mortgage_loan_id' => $loan->id,
            'contact_id' => $contact->id,
            'role' => MortgageLoanParticipantRole::PrimaryBorrower,
            'position' => 1,
            'first_name' => 'Borrower',
            'last_name' => 'Example',
            'email' => 'borrower@example.test',
            'date_of_birth' => '1986-11-28',
        ]);

        $loanRealtor = MortgageLoanRealtor::query()->create([
            'mortgage_loan_id' => $loan->id,
            'contact_id' => $agent->id,
            'role' => MortgageLoanRealtorRole::BuyerAgent,
            'position' => 1,
            'name' => 'Agent Example',
            'email' => 'agent@example.test',
        ]);

        $realtorRelationship = ContactRelationship::query()->create([
            'contact_id' => $agent->id,
            'relationship_key' => 'realtor',
            'stage_key' => 'strategic_partner',
            'source' => 'agent_database',
            'is_active' => true,
        ]);

        $realtorProfile = MortgageRealtorProfile::query()->create([
            'contact_relationship_id' => $realtorRelationship->id,
            'meta' => ['specialty' => ['va_producer']],
        ]);

        $market = MortgageRealtorMarket::query()->create([
            'mortgage_realtor_profile_id' => $realtorProfile->id,
            'market_key' => 'fl_space_coast',
            'is_primary' => true,
        ]);

        $snapshot = MortgageRealtorProductionSnapshot::query()->create([
            'mortgage_realtor_profile_id' => $realtorProfile->id,
            'period_ending_on' => '2026-08-19',
            'period_months' => 12,
            'loan_count' => 100,
            'conventional_count' => 37,
            'va_count' => 50,
            'source' => 'fl_va_agents',
            'source_fingerprint' => hash('sha256', 'agent-production-row'),
        ]);

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();

        $this->assertSame(16, $document['version']);
        $this->assertSame(1, $document['sections']['relationships']['version']);
        $this->assertSame(2, $document['sections']['mortgage']['version']);
        $this->assertCount(1, $document['sections']['mortgage']['tables']['contact_mortgage_profiles']);
        $this->assertCount(1, $document['sections']['mortgage']['tables']['mortgage_loans']);
        $this->assertCount(1, $document['sections']['mortgage']['tables']['mortgage_loan_participants']);
        $this->assertCount(1, $document['sections']['mortgage']['tables']['mortgage_loan_realtors']);
        $this->assertCount(1, $document['sections']['mortgage']['tables']['mortgage_realtor_profiles']);
        $this->assertCount(1, $document['sections']['mortgage']['tables']['mortgage_realtor_markets']);
        $this->assertCount(1, $document['sections']['mortgage']['tables']['mortgage_realtor_production_snapshots']);

        DB::table('mortgage_realtor_production_snapshots')->delete();
        DB::table('mortgage_realtor_markets')->delete();
        DB::table('mortgage_realtor_profiles')->delete();
        DB::table('contact_relationships')->delete();
        DB::table('mortgage_loan_realtors')->delete();
        DB::table('mortgage_loan_participants')->delete();
        DB::table('mortgage_loans')->delete();
        DB::table('contact_mortgage_profiles')->delete();
        DB::table('mortgage_stages')->delete();
        DB::table('contacts')->delete();

        $report = $projectState->validate($document);

        $this->assertTrue($report['valid'], implode(PHP_EOL, $report['errors']));

        $projectState->import($document);

        $this->assertDatabaseHas('contact_mortgage_profiles', [
            'id' => $consumerProfile->id,
            'contact_id' => $contact->id,
            'has_realtor' => HasRealtorState::Yes->value,
            'market_key' => 'fl_space_coast',
        ]);
        $this->assertDatabaseHas('mortgage_loan_participants', [
            'id' => $participant->id,
            'mortgage_loan_id' => $loan->id,
            'contact_id' => $contact->id,
            'role' => MortgageLoanParticipantRole::PrimaryBorrower->value,
        ]);
        $this->assertDatabaseHas('mortgage_loan_realtors', [
            'id' => $loanRealtor->id,
            'mortgage_loan_id' => $loan->id,
            'contact_id' => $agent->id,
            'role' => MortgageLoanRealtorRole::BuyerAgent->value,
        ]);
        $this->assertDatabaseHas('contact_relationships', [
            'id' => $realtorRelationship->id,
            'contact_id' => $agent->id,
            'relationship_key' => 'realtor',
            'stage_key' => 'strategic_partner',
        ]);
        $this->assertDatabaseHas('mortgage_realtor_profiles', [
            'id' => $realtorProfile->id,
            'contact_relationship_id' => $realtorRelationship->id,
        ]);
        $this->assertDatabaseHas('mortgage_realtor_markets', [
            'id' => $market->id,
            'mortgage_realtor_profile_id' => $realtorProfile->id,
            'market_key' => 'fl_space_coast',
        ]);
        $this->assertDatabaseHas('mortgage_realtor_production_snapshots', [
            'id' => $snapshot->id,
            'mortgage_realtor_profile_id' => $realtorProfile->id,
            'va_count' => 50,
        ]);

        $importedLoan = MortgageLoan::query()->findOrFail($loan->id);
        $this->assertSame(
            'closed',
            $importedLoan->stage?->key,
        );
    }
}