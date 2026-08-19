<?php

namespace Tests\Feature\Campaigns;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use App\Modules\Campaigns\Services\CampaignWorkspacePresenter;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class CampaignWorkspaceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_exposes_campaigns_and_lifecycle_counts(): void
    {
        $this->enableCampaigns();

        $user = User::factory()->create();
        $active = Campaign::factory()->create([
            'status' => Campaign::STATUS_ACTIVE,
        ]);
        Campaign::factory()->create([
            'status' => Campaign::STATUS_INACTIVE,
        ]);

        CampaignStep::factory()->forCampaign($active)->create();

        [$chain, $version] = $this->attachPublishedChain($active);
        $contact = Contact::factory()->create();
        $chainEnrollment = MessageChainEnrollment::query()->create([
            'message_chain_version_id' => $version->getKey(),
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->getKey(),
            'context_type' => null,
            'context_id' => null,
            'origin_type' => $active->getMorphClass(),
            'origin_id' => $active->getKey(),
            'surface' => 'campaigns',
            'status' => MessageChainEnrollment::STATUS_ACTIVE,
            'dedupe_key' => 'workspace-open-enrollment',
            'started_at' => now(),
        ]);
        CampaignEnrollment::query()->create([
            'contact_id' => $contact->getKey(),
            'campaign_id' => $active->getKey(),
            'message_chain_enrollment_id' => $chainEnrollment->getKey(),
            'campaign_key' => $active->key,
            'started_at' => now(),
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $response = $this->actingAs($user)
            ->get(route('crm.campaigns.index'))
            ->assertOk()
            ->assertViewIs('crm.campaigns.index');

        $campaigns = $response->viewData('campaigns');
        $statusCounts = $response->viewData('statusCounts');

        $this->assertInstanceOf(Collection::class, $campaigns);
        $this->assertSame(2, $campaigns->count());
        $this->assertSame(1, $statusCounts[Campaign::STATUS_ACTIVE]);
        $this->assertSame(1, $statusCounts[Campaign::STATUS_INACTIVE]);
        $this->assertSame(0, $statusCounts[Campaign::STATUS_ARCHIVED]);

        $loadedActive = $campaigns->firstWhere('id', $active->getKey());

        $this->assertInstanceOf(Campaign::class, $loadedActive);
        $this->assertSame(1, (int) $loadedActive->message_steps_count);
        $this->assertSame(1, (int) $loadedActive->open_enrollments_count);
    }

    public function test_show_and_edit_share_the_campaign_builder_stage_contract(): void
    {
        $this->enableCampaigns();

        $user = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'status' => Campaign::STATUS_INACTIVE,
        ]);
        $step = CampaignStep::factory()->forCampaign($campaign)->create();
        CampaignStepVariant::factory()->create([
            'campaign_step_id' => $step->getKey(),
            'key' => 'email',
            'channel' => 'email',
            'purpose' => $campaign->purpose,
            'scope' => $campaign->scope,
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $show = $this->actingAs($user)
            ->get(route('crm.campaigns.show', $campaign))
            ->assertOk()
            ->assertViewIs('crm.campaigns.show');

        $edit = $this->actingAs($user)
            ->get(route('crm.campaigns.edit', $campaign))
            ->assertOk()
            ->assertViewIs('crm.campaigns.edit');

        $showWorkspace = $show->viewData('workspace');
        $editWorkspace = $edit->viewData('workspace');

        $showStageKeys = array_column($showWorkspace['builder_stages'], 'key');
        $editStageKeys = array_column($editWorkspace['builder_stages'], 'key');

        $this->assertEquals(CampaignWorkspacePresenter::BUILDER_STAGE_KEYS, $showStageKeys);
        $this->assertEquals(CampaignWorkspacePresenter::BUILDER_STAGE_KEYS, $editStageKeys);
        $this->assertSame(1, $showWorkspace['message_step_count']);
        $this->assertSame(1, $showWorkspace['message_count']);
        $this->assertEquals(['email'], $showWorkspace['channels']);
    }

    public function test_campaign_workspace_does_not_require_messaging_to_be_explicitly_visible(): void
    {
        config()->set('modules.enabled', [
            'campaigns',
        ]);

        $user = User::factory()->create();
        Campaign::factory()->create();

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get(route('crm.campaigns.index'))
            ->assertOk()
            ->assertViewIs('crm.campaigns.index');
    }

    public function test_workspace_lifecycle_routes_use_campaign_owned_activation_actions(): void
    {
        $this->enableCampaigns();

        $user = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'status' => Campaign::STATUS_INACTIVE,
        ]);
        $this->attachPublishedChain($campaign, MessageChain::STATUS_INACTIVE);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->patch(route('crm.campaigns.activate', $campaign))
            ->assertRedirect(route('crm.campaigns.show', $campaign));

        $this->assertSame(Campaign::STATUS_ACTIVE, $campaign->refresh()->status);

        $this->actingAs($user)
            ->patch(route('crm.campaigns.deactivate', $campaign))
            ->assertRedirect(route('crm.campaigns.show', $campaign));

        $this->assertSame(Campaign::STATUS_INACTIVE, $campaign->refresh()->status);
    }

    /** @return array{0: MessageChain, 1: MessageChainVersion} */
    private function attachPublishedChain(
        Campaign $campaign,
        string $status = MessageChain::STATUS_ACTIVE,
    ): array {
        $chain = MessageChain::query()->create([
            'key' => 'campaign.workspace.'.$campaign->getKey(),
            'name' => 'Workspace chain '.$campaign->getKey(),
            'status' => $status,
            'source' => 'campaign_preset_bridge',
            'is_customized' => false,
        ]);
        $version = MessageChainVersion::query()->create([
            'message_chain_id' => $chain->getKey(),
            'version' => 1,
            'exit_conditions' => [],
            'content_hash' => hash('sha256', 'workspace-'.$campaign->getKey()),
            'published_at' => now(),
        ]);
        $chain->forceFill(['current_version_id' => $version->getKey()])->save();
        $campaign->forceFill(['message_chain_id' => $chain->getKey()])->save();

        return [$chain, $version];
    }

    private function enableCampaigns(): void
    {
        config()->set('modules.enabled', [
            'campaigns',
            'messaging',
        ]);
    }
}