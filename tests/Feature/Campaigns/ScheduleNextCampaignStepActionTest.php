<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\ScheduleNextCampaignStepAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Webinars\Models\WebinarRegistration;
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

        $campaign = $this->createCampaignWithSteps();

        $this->setMessageDefinitions();

        $contact = $this->contactWithMarketingEmailConsent();

        $registration = WebinarRegistration::factory()->create([
            'contact_id' => $contact->id,
        ]);

        $enrollment = CampaignEnrollment::create([
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'source_type' => $registration->getMorphClass(),
            'source_id' => $registration->id,
            'campaign_key' => 'webinar_attended',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'status' => CampaignEnrollment::STATUS_ACTIVE,
            'current_step' => 1,
            'current_campaign_step_id' => $campaign->steps()->where('step_number', 1)->first()->id,
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
        $this->assertSame($campaign->id, $scheduledMessage->meta['campaign_id']);
        $this->assertSame('webinar_attended', $scheduledMessage->meta['campaign_key']);
        $this->assertSame(2, $scheduledMessage->meta['campaign_step']);
        $this->assertSame($enrollment->id, $scheduledMessage->meta['campaign_enrollment_id']);
        $this->assertTrue($scheduledMessage->send_at->equalTo(Carbon::now()->addMinutes(720)));

        $enrollment->refresh();

        $this->assertSame(CampaignEnrollment::STATUS_ACTIVE, $enrollment->status);
        $this->assertSame(2, $enrollment->current_step);
        $this->assertSame($campaign->steps()->where('step_number', 2)->first()->id, $enrollment->current_campaign_step_id);
        $this->assertSame($scheduledMessage->id, $enrollment->last_scheduled_message_id);
    }

    public function test_it_completes_the_enrollment_when_no_next_step_exists(): void
    {
        Queue::fake();

        Carbon::setTestNow('2026-06-12 12:00:00');

        $campaign = $this->createCampaignWithStep(
            stepNumber: 1,
            dispatchKey: 'webinar_ended',
        );

        $this->setMessageDefinitions([]);

        $contact = $this->contactWithMarketingEmailConsent();

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

        $campaign = $this->createCampaignWithSteps();

        $this->setMessageDefinitions();

        $contact = $this->contactWithMarketingEmailConsent();

        $enrollment = CampaignEnrollment::create([
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'campaign_key' => 'webinar_attended',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'status' => CampaignEnrollment::STATUS_PAUSED,
            'current_step' => 1,
            'current_campaign_step_id' => $campaign->steps()->where('step_number', 1)->first()->id,
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

    private function createCampaignWithSteps(): Campaign
    {
        $campaign = $this->createCampaignWithStep(
            stepNumber: 1,
            dispatchKey: 'webinar_ended',
        );

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

    private function createCampaignWithStep(
        int $stepNumber,
        string $dispatchKey,
    ): Campaign {
        $campaign = Campaign::query()->firstOrCreate(
            ['key' => 'webinar_attended'],
            [
                'name' => 'Webinar Attended',
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'webinar',
                'status' => Campaign::STATUS_ACTIVE,
                'is_active' => true,
                'meta' => [],
            ],
        );

        CampaignStep::create([
            'campaign_id' => $campaign->id,
            'step_number' => $stepNumber,
            'name' => 'Step '.$stepNumber,
            'dispatch_key' => $dispatchKey,
            'is_active' => true,
            'criteria' => [],
            'payload' => [],
            'meta' => [],
        ]);

        return $campaign->refresh();
    }

    /**
     * @param array<string, mixed>|null $definitions
     */
    private function setMessageDefinitions(?array $definitions = null): void
    {
        Config::set('messaging.email.marketing.webinar', $definitions ?? [
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