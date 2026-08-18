<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CampaignMessageChainBridgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_and_enrollment_expose_message_chain_bridge_relationships(): void
    {
        $chain = MessageChain::query()->create([
            'key' => 'campaign_bridge_test',
            'name' => 'Campaign bridge test',
            'description' => null,
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'test',
            'source_version' => '1',
            'is_customized' => false,
            'customized_at' => null,
        ]);

        $version = MessageChainVersion::query()->create([
            'message_chain_id' => $chain->getKey(),
            'version' => 1,
            'exit_conditions' => [],
            'content_hash' => hash('sha256', 'campaign-message-chain-bridge-test'),
            'published_at' => now(),
            'created_by' => null,
        ]);

        $chain->forceFill([
            'current_version_id' => $version->getKey(),
        ])->save();

        $contact = Contact::factory()->create();

        $chainEnrollment = MessageChainEnrollment::query()->create([
            'message_chain_version_id' => $version->getKey(),
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->getKey(),
            'context_type' => null,
            'context_id' => null,
            'origin_type' => null,
            'origin_id' => null,
            'surface' => 'campaigns',
            'current_message_chain_step_id' => null,
            'next_action_at' => null,
            'status' => MessageChainEnrollment::STATUS_COMPLETED,
            'dedupe_key' => 'campaign-message-chain-bridge-test',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $campaign = Campaign::factory()->create([
            'message_chain_id' => $chain->getKey(),
        ]);

        $campaignEnrollment = CampaignEnrollment::query()->create([
            'contact_id' => $contact->getKey(),
            'campaign_id' => $campaign->getKey(),
            'message_chain_enrollment_id' => $chainEnrollment->getKey(),
            'campaign_key' => $campaign->key,
            'status' => CampaignEnrollment::STATUS_COMPLETED,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $this->assertTrue(Schema::hasColumn('campaigns', 'message_chain_id'));
        $this->assertTrue(Schema::hasColumn('campaign_enrollments', 'message_chain_enrollment_id'));
        $this->assertSame(
            (int) $chain->getKey(),
            (int) $campaign->fresh()->messageChain?->getKey(),
        );
        $this->assertSame(
            (int) $chainEnrollment->getKey(),
            (int) $campaignEnrollment->fresh()->messageChainEnrollment?->getKey(),
        );
    }

    public function test_bridge_columns_remain_nullable_while_legacy_campaign_runtime_is_active(): void
    {
        $campaign = Campaign::factory()->create([
            'message_chain_id' => null,
        ]);
        $contact = Contact::factory()->create();

        $enrollment = CampaignEnrollment::query()->create([
            'contact_id' => $contact->getKey(),
            'campaign_id' => $campaign->getKey(),
            'message_chain_enrollment_id' => null,
            'campaign_key' => $campaign->key,
            'status' => CampaignEnrollment::STATUS_ACTIVE,
            'started_at' => now(),
        ]);

        $this->assertNull($campaign->fresh()->message_chain_id);
        $this->assertNull($campaign->fresh()->messageChain);
        $this->assertNull($enrollment->fresh()->message_chain_enrollment_id);
        $this->assertNull($enrollment->fresh()->messageChainEnrollment);
    }
}