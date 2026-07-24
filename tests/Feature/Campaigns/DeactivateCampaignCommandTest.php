<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeactivateCampaignCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_uses_the_campaign_shutdown_operation(): void
    {
        $campaign = Campaign::factory()->create([
            'key' => 'command_campaign',
            'status' => Campaign::STATUS_ACTIVE,
        ]);
        $contact = Contact::factory()->create();
        $enrollment = CampaignEnrollment::query()->create([
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'campaign_key' => $campaign->key,
            'status' => CampaignEnrollment::STATUS_ACTIVE,
            'current_step' => 1,
            'started_at' => now(),
            'meta' => [],
        ]);
        $message = ScheduledMessage::factory()
            ->forContact($contact)
            ->create([
                'meta' => [
                    'campaign_id' => $campaign->id,
                    'campaign_key' => $campaign->key,
                    'campaign_enrollment_id' => $enrollment->id,
                ],
            ]);

        $this->artisan('campaigns:deactivate', [
            'campaign' => $campaign->key,
        ])
            ->expectsOutput("Campaign [{$campaign->key}] is inactive.")
            ->assertSuccessful();

        $this->assertSame(Campaign::STATUS_INACTIVE, $campaign->refresh()->status);
        $this->assertSame(CampaignEnrollment::STATUS_CANCELLED, $enrollment->refresh()->status);
        $this->assertSame(ScheduledMessage::STATUS_SKIPPED, $message->refresh()->status);
    }

    public function test_command_fails_for_an_unknown_campaign_key(): void
    {
        $this->artisan('campaigns:deactivate', [
            'campaign' => 'missing_campaign',
        ])
            ->expectsOutput('Campaign [missing_campaign] was not found.')
            ->assertFailed();
    }
}