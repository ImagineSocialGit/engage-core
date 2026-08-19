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
use App\Modules\Mortgage\Models\MortgageRealtorProductionSnapshot;
use App\Modules\Mortgage\Models\MortgageRealtorProfile;
use App\Modules\Relationships\Models\ContactRelationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MortgageDomainFoundationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, string>
     */
    protected function additionalTestMigrationModuleKeys(): array
    {
        return ['mortgage'];
    }

    public function test_current_consumer_facts_are_separate_from_repeatable_loan_history(): void
    {
        $contact = Contact::factory()->create();

        $profile = ContactMortgageProfile::query()->create([
            'contact_id' => $contact->id,
            'has_realtor' => HasRealtorState::No,
            'original_lead_at' => '2024-02-03 10:00:00',
        ]);

        $firstLoan = MortgageLoan::query()->create([
            'source_system' => 'pnt',
            'source_record_id' => 'loan-1',
            'loan_amount' => 300000,
            'subject_property_city' => 'Tampa',
            'closed_on' => '2023-01-15',
        ]);
        $secondLoan = MortgageLoan::query()->create([
            'source_system' => 'pnt',
            'source_record_id' => 'loan-2',
            'loan_amount' => 425000,
            'subject_property_city' => 'Melbourne',
            'closed_on' => '2025-05-01',
        ]);

        foreach ([$firstLoan, $secondLoan] as $position => $loan) {
            MortgageLoanParticipant::query()->create([
                'mortgage_loan_id' => $loan->id,
                'contact_id' => $contact->id,
                'role' => MortgageLoanParticipantRole::PrimaryBorrower,
                'position' => 1,
                'first_name' => 'Borrower',
                'email' => $contact->email,
            ]);
        }

        $this->assertSame(HasRealtorState::No, $profile->has_realtor);
        $this->assertSame(
            2,
            MortgageLoanParticipant::query()
                ->where('contact_id', $contact->id)
                ->count(),
        );
        $this->assertFalse(in_array('market_key', $profile->getFillable(), true));
    }

    public function test_unresolved_coborrowers_and_realtors_keep_source_snapshots_without_forcing_contacts(): void
    {
        $loan = MortgageLoan::query()->create([
            'source_system' => 'encompass',
            'source_record_id' => 'shared-email-loan',
        ]);

        $participant = MortgageLoanParticipant::query()->create([
            'mortgage_loan_id' => $loan->id,
            'contact_id' => null,
            'role' => MortgageLoanParticipantRole::CoBorrower,
            'position' => 2,
            'first_name' => 'Co',
            'last_name' => 'Borrower',
            'email' => 'shared@example.test',
        ]);

        $realtor = MortgageLoanRealtor::query()->create([
            'mortgage_loan_id' => $loan->id,
            'contact_id' => null,
            'role' => MortgageLoanRealtorRole::BuyerAgent,
            'position' => 1,
            'name' => 'Agent Snapshot',
            'email' => 'agent@example.test',
        ]);

        $this->assertNull($participant->contact_id);
        $this->assertSame('shared@example.test', $participant->email);
        $this->assertNull($realtor->contact_id);
        $this->assertSame('Agent Snapshot', $realtor->name);
    }

    public function test_realtor_specialization_uses_relationship_state_while_production_stays_mortgage_owned(): void
    {
        $contact = Contact::factory()->create();
        $relationship = ContactRelationship::query()->create([
            'contact_id' => $contact->id,
            'relationship_key' => 'realtor',
            'stage_key' => 'target_agent',
            'is_active' => true,
        ]);

        $profile = MortgageRealtorProfile::query()->create([
            'contact_relationship_id' => $relationship->id,
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

        $this->assertSame('target_agent', $profile->contactRelationship->stage_key);
        $this->assertCount(1, $profile->fresh()->productionSnapshots);
        $this->assertSame(56, $profile->fresh()->productionSnapshots->first()->va_count);
        $this->assertFalse(method_exists($profile, 'markets'));
    }
}