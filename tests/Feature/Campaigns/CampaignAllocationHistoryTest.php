<?php

namespace Tests\Feature\Campaigns;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAllocationAssignment;
use App\Modules\Campaigns\Models\CampaignAllocationEnrollment;
use App\Modules\Campaigns\Models\CampaignAllocationRun;
use App\Modules\Campaigns\Services\CampaignAllocationHistoryService;
use App\Modules\Core\Access\Models\UserAccessProfile;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignAllocationHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_history_is_scoped_to_the_campaign_and_rejects_sequence_campaigns(): void
    {
        config()->set('modules.enabled', ['campaigns', 'messaging']);
        $this->withoutMiddleware(ForceStagingAccess::class);
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'execution_strategy' => Campaign::EXECUTION_STRATEGY_RECURRING_ALLOCATION,
        ]);
        $other = Campaign::factory()->create([
            'execution_strategy' => Campaign::EXECUTION_STRATEGY_RECURRING_ALLOCATION,
        ]);
        $sequence = Campaign::factory()->create([
            'execution_strategy' => Campaign::EXECUTION_STRATEGY_SEQUENCE,
        ]);
        $run = $this->runAllocation($campaign);

        $this->actingAs($user)
            ->get(route('crm.campaigns.runs.index', $campaign))
            ->assertOk()
            ->assertViewIs('crm.campaigns.allocation-runs.index');
        $this->actingAs($user)
            ->get(route('crm.campaigns.runs.show', ['campaign' => $campaign, 'run' => $run]))
            ->assertOk()
            ->assertViewIs('crm.campaigns.allocation-runs.show');
        $this->actingAs($user)
            ->get(route('crm.campaigns.runs.show', ['campaign' => $other, 'run' => $run]))
            ->assertNotFound();
        $this->actingAs($user)
            ->get(route('crm.campaigns.runs.index', $sequence))
            ->assertNotFound();
    }

    public function test_run_counts_include_assignments_while_lead_rows_obey_contact_visibility(): void
    {
        config()->set('modules.enabled', ['campaigns', 'messaging']);
        $this->withoutMiddleware(ForceStagingAccess::class);
        $user = User::factory()->create();
        UserAccessProfile::query()->create([
            'user_id' => $user->getKey(),
            'role_key' => 'member',
            'is_active' => true,
        ]);
        $campaign = Campaign::factory()->create([
            'execution_strategy' => Campaign::EXECUTION_STRATEGY_RECURRING_ALLOCATION,
        ]);
        $run = $this->runAllocation($campaign);
        $visible = Contact::factory()->create(['assigned_user_id' => $user->getKey()]);
        $hidden = Contact::factory()->create();
        $this->assignment($campaign, $run, $visible, 'first', true);
        $this->assignment($campaign, $run, $hidden, 'second', false);
        $history = app(CampaignAllocationHistoryService::class);

        $this->assertSame(2, (int) $history->run($campaign, (int) $run->getKey())->assignments_count);
        $this->assertSame(1, $history->assignments($run, $user)->total());
        $this->assertSame($visible->getKey(), $history->assignments($run, $user)->items()[0]->contact_id);
        $this->assertSame(1, $history->assignments($run, $user, 'first')->total());
        $this->assertSame(0, $history->assignments($run, $user, 'second')->total());
        $this->assertSame(2, $history->messages($run)->sum('assigned'));
        $this->assertSame(1, $history->messages($run)->sum('sent'));
    }

    private function runAllocation(Campaign $campaign): CampaignAllocationRun
    {
        $chain = MessageChain::query()->create([
            'key' => 'allocation.history.'.$campaign->getKey(),
            'name' => 'Allocation history',
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'test',
        ]);
        $version = MessageChainVersion::query()->create([
            'message_chain_id' => $chain->getKey(),
            'version' => 1,
            'exit_conditions' => [],
            'content_hash' => hash('sha256', 'allocation.history.'.$campaign->getKey()),
            'published_at' => now(),
        ]);

        return CampaignAllocationRun::query()->create([
            'campaign_id' => $campaign->getKey(),
            'run_key' => 'test:'.$campaign->getKey(),
            'status' => CampaignAllocationRun::STATUS_COMPLETED,
            'scheduled_for' => now(),
            'started_at' => now(),
            'completed_at' => now(),
            'meta' => ['message_chain_version_id' => $version->getKey()],
        ]);
    }

    private function assignment(
        Campaign $campaign,
        CampaignAllocationRun $run,
        Contact $contact,
        string $step,
        bool $sent,
    ): void {
        $enrollment = CampaignAllocationEnrollment::query()->create([
            'campaign_id' => $campaign->getKey(),
            'contact_id' => $contact->getKey(),
            'status' => CampaignAllocationEnrollment::STATUS_ACTIVE,
            'started_at' => now(),
        ]);

        CampaignAllocationAssignment::query()->create([
            'campaign_id' => $campaign->getKey(),
            'campaign_allocation_run_id' => $run->getKey(),
            'campaign_allocation_enrollment_id' => $enrollment->getKey(),
            'contact_id' => $contact->getKey(),
            'message_chain_version_id' => data_get($run->meta, 'message_chain_version_id'),
            'message_step_key' => $step,
            'assigned_at' => now(),
            'sent_at' => $sent ? now() : null,
        ]);
    }
}