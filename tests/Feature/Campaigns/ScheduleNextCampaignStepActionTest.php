<?php

namespace Tests\Feature\Campaigns;

use App\Actions\Campaigns\ScheduleNextCampaignStepAction;
use App\Messaging\Payloads\EmailPayload;
use App\Models\CampaignEnrollment;
use App\Models\Contact;
use App\Models\MessageConsent;
use App\Models\ScheduledMessage;
use App\Models\WebinarRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScheduleNextCampaignStepActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_schedules_the_next_campaign_step(): void
    {
        Queue::fake();

        Carbon::setTestNow('2026-06-12 12:00:00');

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

        $contact = $this->contactWithMarketingEmailConsent();

        $registration = WebinarRegistration::factory()->create([
            'contact_id' => $contact->id,
        ]);

        $enrollment = CampaignEnrollment::create([
            'contact_id' => $contact->id,
            'source_type' => $registration->getMorphClass(),
            'source_id' => $registration->id,
            'campaign_key' => 'webinar_attended',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'status' => CampaignEnrollment::STATUS_ACTIVE,
            'current_step' => 1,
            'started_at' => Carbon::now(),
        ]);

        $scheduledMessage = app(ScheduleNextCampaignStepAction::class)->handle($enrollment);

        $this->assertInstanceOf(ScheduledMessage::class, $scheduledMessage);

        $this->assertSame('step_2', $scheduledMessage->message_type);
        $this->assertSame(Contact::class, $scheduledMessage->recipient_type);
        $this->assertSame($contact->id, $scheduledMessage->recipient_id);
        $this->assertTrue($scheduledMessage->recipient->is($contact));
        $this->assertSame('email', $scheduledMessage->channel);
        $this->assertSame('marketing', $scheduledMessage->purpose);
        $this->assertSame('webinar', $scheduledMessage->scope);
        $this->assertSame('webinar_attended', $scheduledMessage->meta['campaign_key']);
        $this->assertSame(2, $scheduledMessage->meta['campaign_step']);
        $this->assertSame($enrollment->id, $scheduledMessage->meta['campaign_enrollment_id']);
        $this->assertTrue($scheduledMessage->send_at->equalTo(Carbon::now()->addMinutes(720)));

        $enrollment->refresh();

        $this->assertSame(CampaignEnrollment::STATUS_ACTIVE, $enrollment->status);
        $this->assertSame(2, $enrollment->current_step);
        $this->assertSame($scheduledMessage->id, $enrollment->last_scheduled_message_id);
    }

    public function test_it_completes_the_enrollment_when_no_next_step_exists(): void
    {
        Queue::fake();

        Carbon::setTestNow('2026-06-12 12:00:00');

        Config::set('messaging.email.marketing.webinar', []);

        $contact = $this->contactWithMarketingEmailConsent();

        $enrollment = CampaignEnrollment::create([
            'contact_id' => $contact->id,
            'campaign_key' => 'webinar_attended',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'status' => CampaignEnrollment::STATUS_ACTIVE,
            'current_step' => 1,
            'started_at' => Carbon::now(),
        ]);

        $scheduledMessage = app(ScheduleNextCampaignStepAction::class)->handle($enrollment);

        $this->assertNull($scheduledMessage);
        $this->assertDatabaseCount('scheduled_messages', 0);

        $enrollment->refresh();

        $this->assertSame(CampaignEnrollment::STATUS_COMPLETED, $enrollment->status);
        $this->assertSame(1, $enrollment->current_step);
        $this->assertNotNull($enrollment->completed_at);
    }

    public function test_it_does_not_schedule_when_enrollment_is_not_active(): void
    {
        Queue::fake();

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

        $contact = $this->contactWithMarketingEmailConsent();

        $enrollment = CampaignEnrollment::create([
            'contact_id' => $contact->id,
            'campaign_key' => 'webinar_attended',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'status' => CampaignEnrollment::STATUS_PAUSED,
            'current_step' => 1,
            'started_at' => now(),
            'paused_at' => now(),
        ]);

        $scheduledMessage = app(ScheduleNextCampaignStepAction::class)->handle($enrollment);

        $this->assertNull($scheduledMessage);
        $this->assertDatabaseCount('scheduled_messages', 0);

        $enrollment->refresh();

        $this->assertSame(CampaignEnrollment::STATUS_PAUSED, $enrollment->status);
        $this->assertSame(1, $enrollment->current_step);
        $this->assertNull($enrollment->last_scheduled_message_id);
    }

    private function contactWithMarketingEmailConsent(): Contact
    {
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

        return $contact;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}