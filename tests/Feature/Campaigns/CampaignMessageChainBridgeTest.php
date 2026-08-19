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
        [$campaign, $chain, $version] = $this->campaignWithChain();
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
            'current_message_chain_step_id' => null,
            'next_action_at' => null,
            'status' => MessageChainEnrollment::STATUS_COMPLETED,
            'dedupe_key' => 'campaign-message-chain-bridge-test',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $campaignEnrollment = CampaignEnrollment::query()->create([
            'contact_id' => $contact->getKey(),
            'campaign_id' => $campaign->getKey(),
            'message_chain_enrollment_id' => $chainEnrollment->getKey(),
            'campaign_key' => $campaign->key,
            'started_at' => now(),
        ]);

        $this->assertTrue(Schema::hasColumn('campaigns', 'message_chain_id'));
        $this->assertTrue(Schema::hasColumn('campaign_enrollments', 'message_chain_enrollment_id'));
        $this->assertSame((int) $chain->getKey(), (int) $campaign->fresh()->messageChain?->getKey());
        $this->assertSame((int) $chainEnrollment->getKey(), (int) $campaignEnrollment->fresh()->messageChainEnrollment?->getKey());
        $this->assertSame(MessageChainEnrollment::STATUS_COMPLETED, $campaignEnrollment->fresh()->runtimeStatus());
    }

    public function test_campaign_enrollment_schema_keeps_only_business_bridge_state(): void
    {
        foreach ([
            'status',
            'current_step',
            'current_campaign_step_id',
            'exit_conditions',
            'exited_at',
            'exit_reason',
            'last_scheduled_message_id',
            'paused_at',
            'resumed_at',
            'cancelled_at',
            'completed_at',
        ] as $column) {
            $this->assertFalse(Schema::hasColumn('campaign_enrollments', $column), $column);
        }

        foreach ([
            'contact_id',
            'campaign_id',
            'message_chain_enrollment_id',
            'source_type',
            'source_id',
            'campaign_key',
            'start_context',
            'dedupe_key',
            'started_at',
            'meta',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('campaign_enrollments', $column), $column);
        }
    }

    public function test_message_chain_enrollment_id_remains_nullable_only_for_atomic_start_bridging(): void
    {
        $campaign = Campaign::factory()->create(['message_chain_id' => null]);
        $contact = Contact::factory()->create();

        $enrollment = CampaignEnrollment::query()->create([
            'contact_id' => $contact->getKey(),
            'campaign_id' => $campaign->getKey(),
            'message_chain_enrollment_id' => null,
            'campaign_key' => $campaign->key,
            'started_at' => now(),
        ]);

        $this->assertNull($enrollment->message_chain_enrollment_id);
        $this->assertNull($enrollment->runtimeStatus());
        $this->assertFalse($enrollment->isOpen());
    }

    /** @return array{0: Campaign, 1: MessageChain, 2: MessageChainVersion} */
    private function campaignWithChain(): array
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

        $chain->forceFill(['current_version_id' => $version->getKey()])->save();
        $campaign = Campaign::factory()->create(['message_chain_id' => $chain->getKey()]);

        return [$campaign, $chain, $version];
    }
}