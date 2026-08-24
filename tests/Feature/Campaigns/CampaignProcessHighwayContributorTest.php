<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\ProcessHighway\CampaignsProcessHighwayContributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CampaignProcessHighwayContributorTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_contributor_exposes_eligibility_policy_and_journey_state_without_mutating_campaign(): void
    {
        config()->set('modules.enabled', array_values(array_unique([
            ...config('modules.enabled', []),
            'campaigns',
        ])));

        DB::table('contact_statuses')->insert([
            'key' => 'past_contact',
            'name' => 'Past Client',
            'is_core' => false,
            'is_active' => true,
            'is_customized' => false,
            'sort_order' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $campaign = Campaign::factory()->create([
            'key' => 'past_client_nurture_test',
            'name' => 'Fixture Campaign',
            'status' => Campaign::STATUS_ACTIVE,
            'eligibility_filter' => [
                'status' => ['past_contact'],
                'tag' => ['VIP'],
            ],
            'enrollment_mode' => Campaign::ENROLLMENT_MODE_AUTOMATIC,
            'reentry_policy' => Campaign::REENTRY_WHEN_ELIGIBLE_AGAIN,
            'ineligible_behavior' => Campaign::INELIGIBLE_CANCEL,
        ]);

        $processes = collect(
            app(CampaignsProcessHighwayContributor::class)->processes(),
        );

        $process = $processes->firstWhere('key', 'past_client_nurture_test');

        $this->assertNotNull($process);
        $this->assertSame('campaigns', $process['source_key']);
        $this->assertSame('active', $process['state']);

        $attributes = $process['attributes'];

        $this->assertSame(
            Campaign::ENROLLMENT_MODE_AUTOMATIC,
            $attributes['enrollment_mode'],
        );
        $this->assertSame(
            Campaign::REENTRY_WHEN_ELIGIBLE_AGAIN,
            $attributes['reentry_policy'],
        );
        $this->assertSame(
            Campaign::INELIGIBLE_CANCEL,
            $attributes['ineligible_behavior'],
        );
        $this->assertEquals([
            'status' => ['past_contact'],
            'tag' => ['VIP'],
        ], $attributes['eligibility_filter']);

        $conditions = collect($attributes['eligibility_conditions'])
            ->keyBy('key');

        $this->assertEquals(
            ['past_contact'],
            $conditions['status']['values'],
        );
        $this->assertEquals(
            ['VIP'],
            $conditions['tag']['values'],
        );
        $this->assertSame(0, $attributes['active_enrollment_count']);
        $this->assertSame(0, $attributes['message_step_count']);
        $this->assertSame(0, $attributes['message_count']);

        $campaign->refresh();

        $this->assertSame(Campaign::STATUS_ACTIVE, $campaign->status);
        $this->assertEquals([
            'status' => ['past_contact'],
            'tag' => ['VIP'],
        ], $campaign->eligibility_filter);
    }

    public function test_manual_campaign_keeps_explicit_entry_even_when_target_criteria_are_saved(): void
    {
        config()->set('modules.enabled', array_values(array_unique([
            ...config('modules.enabled', []),
            'campaigns',
        ])));

        Campaign::factory()->create([
            'key' => 'manual_targeted_campaign',
            'status' => Campaign::STATUS_INACTIVE,
            'eligibility_filter' => [
                'tag' => ['VIP'],
            ],
            'enrollment_mode' => Campaign::ENROLLMENT_MODE_MANUAL,
        ]);

        $process = collect(
            app(CampaignsProcessHighwayContributor::class)->processes(),
        )->firstWhere('key', 'manual_targeted_campaign');

        $this->assertNotNull($process);
        $this->assertSame('off', $process['state']);
        $this->assertSame(
            Campaign::ENROLLMENT_MODE_MANUAL,
            $process['attributes']['enrollment_mode'],
        );
        $this->assertEquals(
            ['tag' => ['VIP']],
            $process['attributes']['eligibility_filter'],
        );
        $this->assertCount(1, $process['outcomes']);
    }
}