<?php

namespace Tests\Feature\Campaigns;

use App\Models\User;
use App\Modules\Campaigns\Access\CampaignsAccessCapabilityContributor;
use App\Modules\Campaigns\Jobs\EnrollContactResultCampaignChunkJob;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Core\Access\Models\UserAccessProfile;
use App\Modules\Core\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContactResultCampaignEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_enrollment_queues_only_visible_contacts_from_result_set(): void
    {
        Queue::fake();

        $manager = User::factory()->create();
        $other = User::factory()->create();
        $this->profile($manager, [
            CampaignsAccessCapabilityContributor::ENROLL_CONTACT_RESULTS => true,
        ]);

        $campaign = Campaign::factory()->create([
            'status' => Campaign::STATUS_ACTIVE,
        ]);
        $visible = Contact::factory()->create([
            'assigned_user_id' => $manager->getKey(),
            'email' => 'matching-visible@example.test',
        ]);
        Contact::factory()->create([
            'assigned_user_id' => $other->getKey(),
            'email' => 'matching-hidden@example.test',
        ]);
        Contact::factory()->create([
            'assigned_user_id' => $manager->getKey(),
            'email' => 'different@example.test',
        ]);

        $this
            ->actingAs($manager)
            ->post(route('crm.campaigns.contact-results.store'), [
                'campaign_key' => $campaign->key,
                'contact_result' => [
                    'search' => 'matching-',
                    'criteria' => [],
                ],
            ])
            ->assertRedirect(route('crm.contacts.index', [
                'search' => 'matching-',
            ]));

        Queue::assertPushed(
            EnrollContactResultCampaignChunkJob::class,
            fn (EnrollContactResultCampaignChunkJob $job): bool => $job->campaignKey === $campaign->key
                && $job->contactIds === [$visible->getKey()]
                && $job->actorUserId === $manager->getKey(),
        );
    }

    public function test_campaign_result_action_only_lists_active_campaigns(): void
    {
        $owner = User::factory()->create();
        $active = Campaign::factory()->create([
            'status' => Campaign::STATUS_ACTIVE,
        ]);
        $inactive = Campaign::factory()->create([
            'status' => Campaign::STATUS_INACTIVE,
        ]);

        $actions = app(\App\Modules\Core\Support\Contacts\ContactResultActionRegistry::class)
            ->actionsFor($owner);
        $campaignAction = collect($actions)
            ->first(fn ($action): bool => $action->key === 'campaigns.enroll');

        $this->assertNotNull($campaignAction);

        $campaignKeys = array_column($campaignAction->data['campaigns'], 'value');

        $this->assertContains($active->key, $campaignKeys);
        $this->assertNotContains($inactive->key, $campaignKeys);
    }

    public function test_user_without_campaign_result_capability_is_forbidden(): void
    {
        $member = User::factory()->create();
        UserAccessProfile::query()->create([
            'user_id' => $member->getKey(),
            'role_key' => 'member',
            'is_active' => true,
            'capability_overrides' => null,
        ]);

        $this
            ->actingAs($member)
            ->post(route('crm.campaigns.contact-results.store'), [
                'contact_result' => [
                    'search' => '',
                    'criteria' => [],
                ],
            ])
            ->assertForbidden();
    }

    /** @param array<string, bool> $overrides */
    private function profile(User $user, array $overrides): UserAccessProfile
    {
        return UserAccessProfile::query()->create([
            'user_id' => $user->getKey(),
            'role_key' => 'manager',
            'is_active' => true,
            'capability_overrides' => $overrides,
        ]);
    }
}