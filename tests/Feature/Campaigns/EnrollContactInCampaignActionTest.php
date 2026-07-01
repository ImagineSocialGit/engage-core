<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\EnrollContactInCampaignAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EnrollContactInCampaignActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_enrolls_contact_and_schedules_first_campaign_step(): void
    {
        Queue::fake();

        Carbon::setTestNow('2026-06-12 12:00:00');

        $campaign = $this->createCampaignWithStep(
            campaignKey: 'webinar_attended',
            stepNumber: 1,
        );

        $contact = $this->contactWithMarketingEmailConsent();

        $registration = WebinarRegistration::factory()->create([
            'contact_id' => $contact->id,
        ]);

        $enrollment = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: 'webinar_attended',
            source: $registration,
        );

        $step = $campaign->steps()->where('step_number', 1)->firstOrFail();

        $this->assertSame($contact->id, $enrollment->contact_id);
        $this->assertSame($campaign->id, $enrollment->campaign_id);
        $this->assertSame($registration->getMorphClass(), $enrollment->source_type);
        $this->assertSame($registration->id, $enrollment->source_id);
        $this->assertSame('webinar_attended', $enrollment->campaign_key);
        $this->assertSame('email', $enrollment->channel);
        $this->assertSame('marketing', $enrollment->purpose);
        $this->assertSame('webinar', $enrollment->scope);
        $this->assertSame(CampaignEnrollment::STATUS_ACTIVE, $enrollment->status);
        $this->assertSame(1, $enrollment->current_step);
        $this->assertSame($step->id, $enrollment->current_campaign_step_id);
        $this->assertNotNull($enrollment->started_at);

        $scheduledMessage = ScheduledMessage::first();

        $this->assertNotNull($scheduledMessage);
        $this->assertSame(Contact::class, $scheduledMessage->recipient_type);
        $this->assertSame($contact->id, $scheduledMessage->recipient_id);
        $this->assertTrue($scheduledMessage->recipient->is($contact));
        $this->assertSame($scheduledMessage->id, $enrollment->last_scheduled_message_id);
        $this->assertSame('webinar_attended_step_1', $scheduledMessage->message_type);
        $this->assertSame('email', $scheduledMessage->channel);
        $this->assertSame('marketing', $scheduledMessage->purpose);
        $this->assertSame('webinar', $scheduledMessage->scope);
        $this->assertSame('marketing', $scheduledMessage->queue);
        $this->assertSame(['campaign_step_due'], $scheduledMessage->dispatch_keys);
        $this->assertSame(
            'messaging.email.marketing.webinar.campaigns.webinar_attended.steps.1',
            $scheduledMessage->definition_config_path,
        );
        $this->assertSame($campaign->id, $scheduledMessage->meta['campaign_id']);
        $this->assertSame('webinar_attended', $scheduledMessage->meta['campaign_key']);
        $this->assertSame(1, $scheduledMessage->meta['campaign_step']);
        $this->assertSame($step->id, $scheduledMessage->meta['campaign_step_id']);
        $this->assertSame($enrollment->id, $scheduledMessage->meta['campaign_enrollment_id']);
        $this->assertSame(
            'messaging.email.marketing.webinar.campaigns.webinar_attended.steps.1',
            $scheduledMessage->meta['definition_config_path'],
        );
        $this->assertTrue($scheduledMessage->send_at->equalTo(Carbon::now()->addMinutes(720)));
    }

    public function test_it_returns_existing_active_enrollment_without_scheduling_duplicate_message(): void
    {
        Queue::fake();

        $campaign = $this->createCampaignWithStep(
            campaignKey: 'webinar_attended',
            stepNumber: 1,
        );

        $contact = $this->contactWithMarketingEmailConsent();
        $step = $campaign->steps()->where('step_number', 1)->firstOrFail();

        $existingEnrollment = CampaignEnrollment::create([
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'campaign_key' => 'webinar_attended',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'status' => CampaignEnrollment::STATUS_ACTIVE,
            'current_step' => 1,
            'current_campaign_step_id' => $step->id,
            'started_at' => now(),
        ]);

        $enrollment = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: 'webinar_attended',
        );

        $this->assertTrue($existingEnrollment->is($enrollment));
        $this->assertDatabaseCount('campaign_enrollments', 1);
        $this->assertDatabaseCount('scheduled_messages', 0);
    }

    public function test_it_returns_existing_paused_enrollment_without_restarting_campaign(): void
    {
        Queue::fake();

        $campaign = $this->createCampaignWithStep(
            campaignKey: 'webinar_attended',
            stepNumber: 1,
        );

        $contact = $this->contactWithMarketingEmailConsent();
        $step = $campaign->steps()->where('step_number', 1)->firstOrFail();

        $existingEnrollment = CampaignEnrollment::create([
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'campaign_key' => 'webinar_attended',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'status' => CampaignEnrollment::STATUS_PAUSED,
            'current_step' => 1,
            'current_campaign_step_id' => $step->id,
            'started_at' => now(),
            'paused_at' => now(),
        ]);

        $enrollment = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: 'webinar_attended',
        );

        $this->assertTrue($existingEnrollment->is($enrollment));
        $this->assertDatabaseCount('campaign_enrollments', 1);
        $this->assertDatabaseCount('scheduled_messages', 0);
    }

    public function test_it_completes_enrollment_when_first_step_does_not_exist(): void
    {
        Queue::fake();

        Carbon::setTestNow('2026-06-12 12:00:00');

        Campaign::create([
            'key' => 'webinar_attended',
            'name' => 'Webinar Attended',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'status' => Campaign::STATUS_ACTIVE,
            'is_active' => true,
            'meta' => [],
        ]);

        $contact = $this->contactWithMarketingEmailConsent();

        $enrollment = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: 'webinar_attended',
        );

        $this->assertSame(CampaignEnrollment::STATUS_COMPLETED, $enrollment->status);
        $this->assertSame(0, $enrollment->current_step);
        $this->assertNull($enrollment->current_campaign_step_id);
        $this->assertNotNull($enrollment->completed_at);
        $this->assertNull($enrollment->last_scheduled_message_id);
        $this->assertDatabaseCount('scheduled_messages', 0);
    }

    private function createCampaignWithStep(
        string $campaignKey,
        int $stepNumber,
    ): Campaign {
        $this->defineCampaignStepMessageTemplate(
            campaignKey: $campaignKey,
            stepNumber: $stepNumber,
        );

        $campaign = Campaign::create([
            'key' => $campaignKey,
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
            'step_number' => $stepNumber,
            'name' => 'Step '.$stepNumber,
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'is_active' => true,
            'criteria' => [
                'timing' => [
                    'type' => 'delay',
                    'minutes' => 720,
                ],
            ],
            'meta' => [
                'type' => 'message',
            ],
        ]);

        return $campaign->refresh();
    }

    public function test_it_skips_sms_campaign_step_when_sms_is_not_available_for_campaigns(): void
    {
        Queue::fake();

        config()->set('messaging.channel_availability.sms.runtime_supported', true);
        config()->set('messaging.channel_availability.sms.provider_enabled', true);
        config()->set('messaging.channel_availability.sms.surfaces.campaigns', false);
        config()->set('messaging.channel_availability.sms.purpose_scopes', [
            'marketing:webinar' => true,
        ]);

        config()->set(
            'messaging.sms.marketing.webinar.campaigns.webinar_sms.steps.1',
            [
                'dispatch_key' => 'campaign_step_due',
                'payload_class' => SmsPayload::class,
                'queue' => 'marketing',
                'payload' => [
                    'message' => 'SMS step',
                ],
            ],
        );

        $campaign = Campaign::create([
            'key' => 'webinar_sms',
            'name' => 'Webinar SMS',
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'status' => Campaign::STATUS_ACTIVE,
            'is_active' => true,
            'meta' => [],
        ]);

        CampaignStep::create([
            'campaign_id' => $campaign->id,
            'step_number' => 1,
            'name' => 'SMS Step 1',
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'is_active' => true,
            'criteria' => [
                'timing' => [
                    'type' => 'delay',
                    'minutes' => 15,
                ],
            ],
            'meta' => [
                'type' => 'message',
            ],
        ]);

        $contact = Contact::factory()->create([
            'phone' => '+15555550123',
        ]);

        MessageConsent::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'consented_at' => now()->subMinute(),
            'source' => 'test',
        ]);

        $enrollment = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: 'webinar_sms',
        );

        $this->assertSame(CampaignEnrollment::STATUS_COMPLETED, $enrollment->status);
        $this->assertSame(1, $enrollment->current_step);
        $this->assertNull($enrollment->last_scheduled_message_id);
        $this->assertDatabaseCount('scheduled_messages', 0);

        $this->assertSame(
            'campaign_channel_unavailable',
            data_get($enrollment->meta, 'last_message_schedule_attempt.reason'),
        );

        $this->assertSame(
            'message_not_scheduled',
            data_get($enrollment->meta, 'skipped_steps.0.reason'),
        );
    }

    private function defineCampaignStepMessageTemplate(
        string $campaignKey,
        int $stepNumber,
    ): void {
        config()->set(
            "messaging.email.marketing.webinar.campaigns.{$campaignKey}.steps.{$stepNumber}",
            [
                'dispatch_key' => 'campaign_step_due',
                'payload_class' => EmailPayload::class,
                'queue' => 'marketing',
                'payload' => [
                    'to' => '{email}',
                    'subject' => 'Step '.$stepNumber,
                    'body' => 'Message '.$stepNumber,
                ],
            ],
        );
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