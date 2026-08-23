<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\EvaluateCampaignEligibilityAction;
use App\Modules\Campaigns\Data\CampaignEligibilityEvaluationResult;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEligibilityState;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignEligibilityFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_semantic_status_key_and_tag_are_anded_across_criterion_types(): void
    {
        $status = ContactStatus::query()->create([
            'key' => 'prospect_nurture',
            'name' => 'Prospect – Nurture',
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $eligibleContact = Contact::factory()->create();
        $missingTagContact = Contact::factory()->create();

        foreach ([$eligibleContact, $missingTagContact] as $contact) {
            ContactWorkflowProfile::query()->create([
                'contact_id' => $contact->getKey(),
                'contact_status_id' => $status->getKey(),
            ]);
        }

        ContactTag::query()->create([
            'contact_id' => $eligibleContact->getKey(),
            'tag' => 'VA',
        ]);

        $campaign = Campaign::factory()->create([
            'eligibility_filter' => [
                'status' => ['prospect_nurture'],
                'tag' => ['VA'],
            ],
            'enrollment_mode' => Campaign::ENROLLMENT_MODE_AUTOMATIC,
            'reentry_policy' => Campaign::REENTRY_NEVER,
            'ineligible_behavior' => Campaign::INELIGIBLE_CANCEL,
        ]);

        $eligible = app(EvaluateCampaignEligibilityAction::class)->handle(
            campaign: $campaign,
            contact: $eligibleContact,
            at: '2026-08-23 20:00:00 UTC',
        );
        $ineligible = app(EvaluateCampaignEligibilityAction::class)->handle(
            campaign: $campaign,
            contact: $missingTagContact,
            at: '2026-08-23 20:00:00 UTC',
        );

        $this->assertTrue($eligible->currentEligible);
        $this->assertSame(
            CampaignEligibilityEvaluationResult::BECAME_ELIGIBLE,
            $eligible->transition,
        );
        $this->assertSame(1, $eligible->eligibilityCycle);

        $this->assertFalse($ineligible->currentEligible);
        $this->assertSame(
            CampaignEligibilityEvaluationResult::UNCHANGED_INELIGIBLE,
            $ineligible->transition,
        );
        $this->assertSame(0, $ineligible->eligibilityCycle);
    }

    public function test_state_tracks_false_true_false_true_cycles_without_enrolling_campaign(): void
    {
        $contact = Contact::factory()->create();
        $campaign = Campaign::factory()->create([
            'eligibility_filter' => [
                'tag' => ['VIP'],
            ],
            'enrollment_mode' => Campaign::ENROLLMENT_MODE_AUTOMATIC,
        ]);

        $first = app(EvaluateCampaignEligibilityAction::class)->handle(
            $campaign,
            $contact,
            '2026-08-23 20:00:00 UTC',
        );

        ContactTag::query()->create([
            'contact_id' => $contact->getKey(),
            'tag' => 'VIP',
        ]);

        $second = app(EvaluateCampaignEligibilityAction::class)->handle(
            $campaign,
            $contact,
            '2026-08-23 20:01:00 UTC',
        );

        ContactTag::query()
            ->where('contact_id', $contact->getKey())
            ->where('tag', 'VIP')
            ->delete();

        $third = app(EvaluateCampaignEligibilityAction::class)->handle(
            $campaign,
            $contact,
            '2026-08-23 20:02:00 UTC',
        );

        ContactTag::query()->create([
            'contact_id' => $contact->getKey(),
            'tag' => 'VIP',
        ]);

        $fourth = app(EvaluateCampaignEligibilityAction::class)->handle(
            $campaign,
            $contact,
            '2026-08-23 20:03:00 UTC',
        );

        $this->assertSame(
            CampaignEligibilityEvaluationResult::UNCHANGED_INELIGIBLE,
            $first->transition,
        );
        $this->assertSame(
            CampaignEligibilityEvaluationResult::BECAME_ELIGIBLE,
            $second->transition,
        );
        $this->assertSame(
            CampaignEligibilityEvaluationResult::BECAME_INELIGIBLE,
            $third->transition,
        );
        $this->assertSame(
            CampaignEligibilityEvaluationResult::BECAME_ELIGIBLE,
            $fourth->transition,
        );
        $this->assertSame(2, $fourth->eligibilityCycle);
        $this->assertDatabaseCount('campaign_eligibility_states', 1);
        $this->assertDatabaseCount('campaign_enrollments', 0);

        $state = CampaignEligibilityState::query()->firstOrFail();
        $this->assertTrue($state->is_eligible);
        $this->assertSame(2, $state->eligibility_cycle);
    }

    public function test_missing_stable_status_key_fails_closed(): void
    {
        $campaign = Campaign::factory()->create([
            'eligibility_filter' => [
                'status' => ['does_not_exist'],
            ],
            'enrollment_mode' => Campaign::ENROLLMENT_MODE_AUTOMATIC,
        ]);

        $result = app(EvaluateCampaignEligibilityAction::class)->handle(
            $campaign,
            Contact::factory()->create(),
        );

        $this->assertFalse($result->currentEligible);
        $this->assertSame(
            CampaignEligibilityEvaluationResult::UNCHANGED_INELIGIBLE,
            $result->transition,
        );
    }

    public function test_unknown_optional_module_criterion_fails_closed(): void
    {
        $campaign = Campaign::factory()->create([
            'eligibility_filter' => [
                'not_currently_contributed' => ['anything'],
            ],
            'enrollment_mode' => Campaign::ENROLLMENT_MODE_AUTOMATIC,
        ]);

        $result = app(EvaluateCampaignEligibilityAction::class)->handle(
            $campaign,
            Contact::factory()->create(),
        );

        $this->assertFalse($result->currentEligible);
    }
}