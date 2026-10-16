<?php

namespace Tests\Feature\Campaigns;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use App\Modules\Campaigns\Services\CampaignWorkspacePresenter;
use App\Modules\Campaigns\Services\CampaignAudienceProgressService;
use App\Modules\Core\Access\Models\UserAccessProfile;
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


    public function test_audience_respects_lead_visibility_in_counts_and_lists(): void
    {
        $this->enableCampaigns();
        $user = User::factory()->create();
        UserAccessProfile::query()->create([
            'user_id' => $user->getKey(),
            'role_key' => 'member',
            'is_active' => true,
        ]);
        $campaign = Campaign::factory()->create([
            'eligibility_filter' => ['source' => ['referral']],
        ]);
        [, $version] = $this->attachPublishedChain($campaign);
        $visible = Contact::factory()->create([
            'source' => 'referral',
            'assigned_user_id' => $user->getKey(),
        ]);
        $hidden = Contact::factory()->create(['source' => 'referral']);
        $this->recordEnrollment($campaign, $version, $visible, MessageChainEnrollment::STATUS_ACTIVE);
        $this->recordEnrollment($campaign, $version, $hidden, MessageChainEnrollment::STATUS_ACTIVE);

        $service = app(CampaignAudienceProgressService::class);
        $summary = $service->summary($campaign, $user);
        $this->assertSame(1, $summary['matching']);
        $this->assertSame(1, $summary['enrolled']);
        $this->assertSame(1, $service->participants($campaign, $user)->total());
        $this->assertSame(1, $service->matches($campaign, $user)->total());
    }

    private function recordEnrollment(
        Campaign $campaign,
        MessageChainVersion $version,
        Contact $contact,
        string $status,
    ): void {
        $chainEnrollment = MessageChainEnrollment::query()->create([
            'message_chain_version_id' => $version->getKey(),
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->getKey(),
            'origin_type' => $campaign->getMorphClass(),
            'origin_id' => $campaign->getKey(),
            'surface' => 'campaigns',
            'status' => $status,
            'started_at' => now(),
        ]);
        CampaignEnrollment::query()->create([
            'contact_id' => $contact->getKey(),
            'campaign_id' => $campaign->getKey(),
            'campaign_key' => $campaign->key,
            'message_chain_enrollment_id' => $chainEnrollment->getKey(),
            'started_at' => now(),
        ]);
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