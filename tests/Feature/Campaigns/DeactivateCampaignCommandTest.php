<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeactivateCampaignCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_uses_the_campaign_shutdown_operation(): void
    {
        $chain = MessageChain::query()->create([
            'key' => 'campaign.command_campaign',
            'name' => 'Command Campaign chain',
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'campaign_preset_bridge',
            'is_customized' => false,
        ]);
        $version = MessageChainVersion::query()->create([
            'message_chain_id' => $chain->getKey(),
            'version' => 1,
            'exit_conditions' => [],
            'content_hash' => hash('sha256', 'command-campaign-v1'),
            'published_at' => now(),
        ]);
        $chain->forceFill(['current_version_id' => $version->getKey()])->save();

        $campaign = Campaign::factory()->create([
            'key' => 'command_campaign',
            'message_chain_id' => $chain->getKey(),
            'status' => Campaign::STATUS_ACTIVE,
        ]);
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
            'dedupe_key' => 'command-campaign-chain-enrollment',
            'started_at' => now(),
        ]);
        $enrollment = CampaignEnrollment::query()->create([
            'contact_id' => $contact->getKey(),
            'campaign_id' => $campaign->getKey(),
            'message_chain_enrollment_id' => $chainEnrollment->getKey(),
            'campaign_key' => $campaign->key,
            'started_at' => now(),
            'meta' => [],
        ]);
        $message = ScheduledMessage::factory()
            ->forContact($contact)
            ->create([
                'message_chain_enrollment_id' => $chainEnrollment->getKey(),
            ]);

        $this->artisan('campaigns:deactivate', ['campaign' => $campaign->key])
            ->expectsOutput("Campaign [{$campaign->key}] is inactive.")
            ->assertSuccessful();

        $this->assertSame(Campaign::STATUS_INACTIVE, $campaign->refresh()->status);
        $this->assertSame(MessageChainEnrollment::STATUS_CANCELLED, $enrollment->refresh()->runtimeStatus());
        $this->assertSame(ScheduledMessage::STATUS_SKIPPED, $message->refresh()->status);
    }

    public function test_command_fails_for_an_unknown_campaign_key(): void
    {
        $this->artisan('campaigns:deactivate', ['campaign' => 'missing_campaign'])
            ->expectsOutput('Campaign [missing_campaign] was not found.')
            ->assertFailed();
    }
}