<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Services\CampaignWorkspacePresenter;
use App\Modules\Campaigns\Services\ContactShow\ContactCampaignsVisibilityDataProvider;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignMessageChainRuntimeOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_reads_use_linked_message_chain_runtime_state(): void
    {
        [$campaign, $version] = $this->campaignWithChain();
        $contact = Contact::factory()->create();
        $chainEnrollment = MessageChainEnrollment::query()->create([
            'message_chain_version_id' => $version->getKey(),
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->getKey(),
            'context_type' => null,
            'context_id' => null,
            'origin_type' => $campaign->getMorphClass(),
            'origin_id' => $campaign->getKey(),
            'surface' => 'campaigns',
            'status' => MessageChainEnrollment::STATUS_ACTIVE,
            'dedupe_key' => 'runtime-ownership-enrollment',
            'started_at' => now(),
        ]);
        $wrapper = CampaignEnrollment::query()->create([
            'contact_id' => $contact->getKey(),
            'campaign_id' => $campaign->getKey(),
            'message_chain_enrollment_id' => $chainEnrollment->getKey(),
            'campaign_key' => $campaign->key,
            'started_at' => now(),
        ]);

        $linked = ScheduledMessage::factory()
            ->forContact($contact)
            ->create([
                'message_chain_enrollment_id' => $chainEnrollment->getKey(),
                'status' => ScheduledMessage::STATUS_PENDING,
            ]);

        ScheduledMessage::factory()
            ->forContact($contact)
            ->create([
                'message_chain_enrollment_id' => null,
                'status' => ScheduledMessage::STATUS_PENDING,
                'meta' => [
                    'campaign_id' => $campaign->getKey(),
                    'campaign_key' => $campaign->key,
                ],
            ]);

        $workspace = app(CampaignWorkspacePresenter::class)->forCampaign($campaign);

        $this->assertSame(1, $workspace['active_enrollment_count']);
        $this->assertSame(1, $workspace['pending_message_count']);
        $this->assertSame(MessageChainEnrollment::STATUS_ACTIVE, $wrapper->runtimeStatus());

        $section = app(ContactCampaignsVisibilityDataProvider::class)
            ->dataFor($contact)['contactVisibilitySections']['campaigns'];

        $this->assertCount(1, $section['items']);
        $this->assertSame($campaign->name, $section['items'][0]['title']);
        $this->assertSame((int) $linked->getKey(), (int) $chainEnrollment->fresh()->latestScheduledMessage?->getKey());

        $chainEnrollment->forceFill([
            'status' => MessageChainEnrollment::STATUS_COMPLETED,
            'completed_at' => now(),
        ])->save();

        $this->assertSame(
            0,
            app(CampaignWorkspacePresenter::class)->forCampaign($campaign)['active_enrollment_count'],
        );
        $this->assertSame(MessageChainEnrollment::STATUS_COMPLETED, $wrapper->fresh()->runtimeStatus());
    }

    /** @return array{0: Campaign, 1: MessageChainVersion} */
    private function campaignWithChain(): array
    {
        $chain = MessageChain::query()->create([
            'key' => 'campaign.runtime_ownership',
            'name' => 'Runtime ownership chain',
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'test',
            'is_customized' => false,
        ]);
        $version = MessageChainVersion::query()->create([
            'message_chain_id' => $chain->getKey(),
            'version' => 1,
            'exit_conditions' => [],
            'content_hash' => hash('sha256', 'runtime-ownership-v1'),
            'published_at' => now(),
        ]);
        $chain->forceFill(['current_version_id' => $version->getKey()])->save();

        $campaign = Campaign::factory()->create([
            'key' => 'runtime_ownership',
            'message_chain_id' => $chain->getKey(),
            'status' => Campaign::STATUS_ACTIVE,
        ]);

        return [$campaign, $version];
    }
}