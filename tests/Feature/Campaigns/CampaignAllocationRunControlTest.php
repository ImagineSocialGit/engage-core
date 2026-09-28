<?php

namespace Tests\Feature\Campaigns;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Campaigns\Actions\ScheduleDueCampaignAllocationRunsAction;
use App\Modules\Campaigns\Jobs\ProcessCampaignAllocationRunJob;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAllocationEnrollment;
use App\Modules\Campaigns\Models\CampaignAllocationRun;
use App\Modules\Campaigns\Services\CampaignAllocationRunPreviewService;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CampaignAllocationRunControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_run_is_idempotent_and_uses_the_latest_run_as_a_new_cadence_anchor(): void
    {
        Queue::fake();
        [$campaign, $contact] = $this->campaignWithParticipant();
        $actor = User::factory()->create();
        $scheduler = app(ScheduleDueCampaignAllocationRunsAction::class);
        $key = (string) Str::uuid();

        $first = $scheduler->scheduleNow($campaign, $actor, $key);
        $repeated = $scheduler->scheduleNow($campaign, $actor, $key);

        $this->assertSame($first->getKey(), $repeated->getKey());
        $this->assertSame('operator', data_get($first->meta, 'trigger'));
        $this->assertSame($actor->getKey(), data_get($first->meta, 'requested_by_user_id'));
        $this->assertSame(1, CampaignAllocationRun::query()->where('campaign_id', $campaign->getKey())->count());
        Queue::assertPushed(ProcessCampaignAllocationRunJob::class, 1);

        try {
            $scheduler->scheduleNow($campaign, $actor, (string) Str::uuid());
            $this->fail('A second request must not overlap a pending run.');
        } catch (ValidationException) {
            $this->assertSame(1, CampaignAllocationRun::query()->where('campaign_id', $campaign->getKey())->count());
        }

        $first->forceFill(['status' => CampaignAllocationRun::STATUS_COMPLETED, 'completed_at' => now()])->save();
        $next = $scheduler->scheduleNow($campaign, $actor, (string) Str::uuid());
        $this->assertNotSame($first->getKey(), $next->getKey());
        $this->assertSame(2, CampaignAllocationRun::query()->where('campaign_id', $campaign->getKey())->count());
    }

    public function test_preview_is_read_only_and_does_not_expose_sequence_campaign_control_routes(): void
    {
        config()->set('modules.enabled', ['campaigns', 'messaging']);
        $this->withoutMiddleware(ForceStagingAccess::class);
        [$campaign, $contact] = $this->campaignWithParticipant();
        $preview = app(CampaignAllocationRunPreviewService::class)->forCampaign($campaign);

        $this->assertTrue($preview['can_start']);
        $this->assertSame(1, $preview['messages'][0]['potential']);
        $this->assertSame(0, CampaignAllocationRun::query()->count());

        $user = User::factory()->create();
        $this->actingAs($user)
            ->get(route('crm.campaigns.runs.preview', $campaign))
            ->assertOk()
            ->assertViewIs('crm.campaigns.allocation-runs.preview');
        $this->assertSame(0, CampaignAllocationRun::query()->count());

        $sequence = Campaign::factory()->create([
            'execution_strategy' => Campaign::EXECUTION_STRATEGY_SEQUENCE,
        ]);
        $this->actingAs($user)
            ->get(route('crm.campaigns.runs.preview', $sequence))
            ->assertNotFound();
        $this->actingAs($user)
            ->post(route('crm.campaigns.runs.store', $sequence), [
                'request_key' => (string) Str::uuid(),
            ])
            ->assertNotFound();
    }

    /** @return array{0: Campaign, 1: Contact} */
    private function campaignWithParticipant(): array
    {
        $campaign = Campaign::factory()->create([
            'status' => Campaign::STATUS_ACTIVE,
            'execution_strategy' => Campaign::EXECUTION_STRATEGY_RECURRING_ALLOCATION,
            'eligibility_filter' => [],
            'allocation_settings' => [
                'run_every_days' => 14,
                'allocation_size_per_message' => 1,
                'recipient_cooldown_days' => 0,
            ],
        ]);
        $chain = MessageChain::query()->create([
            'key' => 'allocation.control.'.$campaign->getKey(),
            'name' => 'Allocation control',
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'test',
        ]);
        $version = MessageChainVersion::query()->create([
            'message_chain_id' => $chain->getKey(),
            'version' => 1,
            'exit_conditions' => [],
            'content_hash' => hash('sha256', 'allocation.control.'.$campaign->getKey()),
        ]);
        MessageChainStep::query()->create([
            'message_chain_version_id' => $version->getKey(),
            'key' => 'first',
            'name' => 'First message',
            'sort_order' => 1,
            'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
            'variant_strategy' => MessageChainStep::VARIANT_STRATEGY_FIRST_AVAILABLE,
            'advance_policy' => MessageChainStep::ADVANCE_ALL_TERMINAL,
            'conditions' => [],
            'is_active' => true,
        ]);
        $version->forceFill(['published_at' => now()])->save();
        $chain->forceFill(['current_version_id' => $version->getKey()])->save();
        $campaign->forceFill(['message_chain_id' => $chain->getKey()])->save();

        $contact = Contact::factory()->create();
        CampaignAllocationEnrollment::query()->create([
            'campaign_id' => $campaign->getKey(),
            'contact_id' => $contact->getKey(),
            'status' => CampaignAllocationEnrollment::STATUS_ACTIVE,
            'started_at' => now(),
        ]);

        return [$campaign->refresh(), $contact];
    }
}