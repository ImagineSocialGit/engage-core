<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Listeners\ScheduleNextCampaignStepAfterScheduledMessageSent;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScheduleNextCampaignStepAfterScheduledMessageSentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_schedules_next_campaign_step_when_sent_message_has_campaign_metadata(): void
    {
        Queue::fake();

        Carbon::setTestNow('2026-06-12 12:00:00');

        $campaign = $this->createCampaignWithSteps();

        $this->setMessageDefinitions();

        $contact = Contact::factory()->create([
            'email' => 'person@example.com',
        ]);

        MessageConsent::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'consented_at' => now()->subMinute(),
            'source' => 'test',
        ]);

        $enrollment = CampaignEnrollment::create([
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'campaign_key' => 'webinar_attended',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'status' => CampaignEnrollment::STATUS_ACTIVE,
            'current_step' => 1,
            'current_campaign_step_id' => $campaign->steps()->where('step_number', 1)->first()->id,
            'started_at' => now(),
        ]);

        $sentMessage = ScheduledMessage::factory()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'message_type' => 'step_1',
            'status' => 'sent',
            'sent_at' => now(),
            'meta' => [
                'campaign_enrollment_id' => $enrollment->id,
                'campaign_id' => $campaign->id,
                'campaign_key' => 'webinar_attended',
                'campaign_step_id' => $campaign->steps()->where('step_number', 1)->first()->id,
                'campaign_step' => 1,
            ],
        ]);

        app(ScheduleNextCampaignStepAfterScheduledMessageSent::class)->handle(
            new ScheduledMessageSent($sentMessage),
        );

        $enrollment->refresh();

        $this->assertSame(CampaignEnrollment::STATUS_ACTIVE, $enrollment->status);
        $this->assertSame(2, $enrollment->current_step);
        $this->assertSame($campaign->steps()->where('step_number', 2)->first()->id, $enrollment->current_campaign_step_id);
        $this->assertNotNull($enrollment->last_scheduled_message_id);
        $this->assertNotSame($sentMessage->id, $enrollment->last_scheduled_message_id);

        $nextMessage = ScheduledMessage::query()
            ->whereKey($enrollment->last_scheduled_message_id)
            ->firstOrFail();

        $this->assertSame('step_2', $nextMessage->message_type);
        $this->assertSame($enrollment->id, $nextMessage->meta['campaign_enrollment_id']);
        $this->assertSame($campaign->id, $nextMessage->meta['campaign_id']);
        $this->assertSame($campaign->steps()->where('step_number', 2)->first()->id, $nextMessage->meta['campaign_step_id']);
        $this->assertSame($sentMessage->id, $nextMessage->meta['previous_scheduled_message_id']);
    }

    public function test_it_does_nothing_when_sent_message_has_no_campaign_metadata(): void
    {
        Queue::fake();

        $contact = Contact::factory()->create([
            'email' => 'person@example.com',
        ]);

        $sentMessage = ScheduledMessage::factory()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'status' => 'sent',
            'sent_at' => now(),
            'meta' => [],
        ]);

        app(ScheduleNextCampaignStepAfterScheduledMessageSent::class)->handle(
            new ScheduledMessageSent($sentMessage),
        );

        $this->assertDatabaseCount('campaign_enrollments', 0);
        $this->assertDatabaseCount('scheduled_messages', 1);
    }

    private function createCampaignWithSteps(): Campaign
    {
        $campaign = Campaign::create([
            'key' => 'webinar_attended',
            'name' => 'Webinar Attended',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'status' => Campaign::STATUS_ACTIVE,
            'is_active' => true,
            'meta' => [],
        ]);

        CampaignStep::create([
            'campaign_id' => $campaign->id,
            'step_number' => 1,
            'name' => 'Step 1',
            'dispatch_key' => 'webinar_ended',
            'is_active' => true,
            'criteria' => [],
            'payload' => [],
            'meta' => [],
        ]);

        CampaignStep::create([
            'campaign_id' => $campaign->id,
            'step_number' => 2,
            'name' => 'Step 2',
            'dispatch_key' => 'marketing_message_sent',
            'is_active' => true,
            'criteria' => [],
            'payload' => [],
            'meta' => [],
        ]);

        return $campaign->refresh();
    }

    private function setMessageDefinitions(): void
    {
        Config::set('messaging.email.marketing.webinar', [
            'step_2' => [
                'dispatch_key' => 'marketing_message_sent',
                'campaign_key' => 'webinar_attended',
                'step' => 2,
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'delay',
                    'minutes' => 720,
                ],
                'payload_class' => EmailPayload::class,
                'queue' => 'marketing',
                'payload' => [
                    'subject' => 'Step 2',
                    'body' => 'Second message',
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}