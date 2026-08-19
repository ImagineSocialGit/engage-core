<?php

namespace Tests\Feature\Mortgage;

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
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MortgageDomainFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $app->make(Migrator::class)->path(
            $app->basePath('database/migrations/verticals/mortgage'),
        );

        return $app;
    }

    public function test_current_consumer_facts_are_separate_from_repeatable_loan_history(): void
    {
        $contact = Contact::factory()->create();

        $profile = ContactMortgageProfile::query()->create([
            'contact_id' => $contact->id,
            'has_realtor' => HasRealtorState::No,
            'market_key' => 'tampa',
            'original_lead_at' => '2024-02-03 10:00:00',
        ]);

        $stage = MortgageStage::query()->create([
            'key' => 'closed',
            'name' => 'Closed',
            'category' => 'terminal',
            'sort_order' => 900,
        ]);

        $firstLoan = MortgageLoan::query()->create([
            'mortgage_stage_id' => $stage->id,
            'source_system' => 'loan_crm',
            'loan_purpose' => 'Purchase',
            'loan_amount' => 473023,
            'subject_property_city' => 'Melbourne',
            'subject_property_state' => 'Florida',
            'closed_on' => '2024-06-01',
        ]);

        $secondLoan = MortgageLoan::query()->create([
            'mortgage_stage_id' => $stage->id,
            'source_system' => 'closed_loans',
            'loan_purpose' => 'Purchase',
            'subject_property_city' => 'Melbourne',
            'subject_property_state' => 'Florida',
            'closed_on' => '2022-05-15',
        ]);

        MortgageLoanParticipant::query()->create([
            'mortgage_loan_id' => $firstLoan->id,
            'contact_id' => $contact->id,
            'role' => MortgageLoanParticipantRole::PrimaryBorrower,
            'position' => 1,
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'email' => $contact->email,
        ]);

        MortgageLoanParticipant::query()->create([
            'mortgage_loan_id' => $secondLoan->id,
            'contact_id' => $contact->id,
            'role' => MortgageLoanParticipantRole::PrimaryBorrower,
            'position' => 1,
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'email' => $contact->email,
        ]);

        $this->assertSame(HasRealtorState::No, $profile->has_realtor);
        $this->assertSame('tampa', $profile->market_key);
        $this->assertFalse(Schema::hasColumn('mortgage_loans', 'contact_id'));
        $this->assertCount(2, MortgageLoanParticipant::query()
            ->where('contact_id', $contact->id)
            ->get());
        $this->assertCount(2, $stage->fresh()->loans);
    }

    public function test_unresolved_coborrowers_and_realtors_keep_source_snapshots_without_forcing_contacts(): void
    {
        $borrower = Contact::factory()->create([
            'email' => 'household@example.test',
        ]);
        $agent = Contact::factory()->create([
            'email' => 'agent@example.test',
        ]);

        $loan = MortgageLoan::query()->create([
            'source_system' => 'pnt',
            'loan_purpose' => 'Purch',
            'loan_amount' => 620000,
            'subject_property_state' => 'FL',
        ]);

        MortgageLoanParticipant::query()->create([
            'mortgage_loan_id' => $loan->id,
            'contact_id' => $borrower->id,
            'role' => MortgageLoanParticipantRole::PrimaryBorrower,
            'position' => 1,
            'first_name' => 'Primary',
            'last_name' => 'Borrower',
            'email' => 'household@example.test',
        ]);

        $coBorrower = MortgageLoanParticipant::query()->create([
            'mortgage_loan_id' => $loan->id,
            'contact_id' => null,
            'role' => MortgageLoanParticipantRole::CoBorrower,
            'position' => 1,
            'first_name' => 'Co',
            'last_name' => 'Borrower',
            'email' => 'household@example.test',
        ]);

        $buyerAgent = MortgageLoanRealtor::query()->create([
            'mortgage_loan_id' => $loan->id,
            'contact_id' => $agent->id,
            'role' => MortgageLoanRealtorRole::BuyerAgent,
            'position' => 1,
            'name' => 'Agent Example',
            'email' => 'agent@example.test',
        ]);

        $listingAgent = MortgageLoanRealtor::query()->create([
            'mortgage_loan_id' => $loan->id,
            'contact_id' => null,
            'role' => MortgageLoanRealtorRole::ListingAgent,
            'position' => 1,
            'name' => 'Unresolved Listing Agent',
            'email' => 'listing@example.test',
        ]);

        $this->assertNull($coBorrower->contact_id);
        $this->assertSame('household@example.test', $coBorrower->email);
        $this->assertSame($agent->id, $buyerAgent->contact_id);
        $this->assertNull($listingAgent->contact_id);
        $this->assertCount(2, $loan->fresh()->participants);
        $this->assertCount(2, $loan->fresh()->realtors);
    }

    public function test_realtor_relationships_support_multiple_markets_and_time_bounded_production_snapshots(): void
    {
        $contact = Contact::factory()->create();

        $profile = MortgageRealtorProfile::query()->create([
            'contact_id' => $contact->id,
            'relationship_stage_key' => 'target_agent',
        ]);

        MortgageRealtorMarket::query()->create([
            'mortgage_realtor_profile_id' => $profile->id,
            'market_key' => 'fl_space_coast',
            'is_primary' => true,
        ]);

        MortgageRealtorMarket::query()->create([
            'mortgage_realtor_profile_id' => $profile->id,
            'market_key' => 'fl_tampa',
            'is_primary' => false,
        ]);

        MortgageRealtorProductionSnapshot::query()->create([
            'mortgage_realtor_profile_id' => $profile->id,
            'period_ending_on' => '2026-08-19',
            'period_months' => 12,
            'loan_count' => 427,
            'conventional_count' => 271,
            'va_count' => 56,
            'source' => 'fl_va_agents',
        ]);

        $this->assertSame('target_agent', $profile->relationship_stage_key);
        $this->assertCount(2, $profile->fresh()->markets);
        $this->assertCount(1, $profile->fresh()->productionSnapshots);
        $this->assertSame(
            56,
            $profile->fresh()->productionSnapshots->first()->va_count,
        );
    }
}