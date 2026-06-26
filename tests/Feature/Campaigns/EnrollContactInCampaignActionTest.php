<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\EnrollContactInCampaignAction;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EnrollContactInCampaignActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_enrolls_contact_and_schedules_first_campaign_step(): void
    {
        Queue::fake();

        Carbon::setTestNow('2026-06-12 12:00:00');

        Config::set('messaging.email.marketing.webinar', [
            'step_1' => [
                'dispatch_key' => 'webinar_ended',
                'campaign_key' => 'webinar_attended',
                'step' => 1,
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'delay',
                    'minutes' => 720,
                ],
                'payload_class' => EmailPayload::class,
                'queue' => 'marketing',
                'payload' => [
                    'subject' => 'Step 1',
                    'body' => 'First message',
                ],
            ],
        ]);

        $contact = $this->contactWithMarketingEmailConsent();

        $registration = WebinarRegistration::factory()->create([
            'contact_id' => $contact->id,
        ]);

        $enrollment = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: 'webinar_attended',
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar',
            dispatchKey: 'webinar_ended',
            source: $registration,
        );

        $this->assertSame($contact->id, $enrollment->contact_id);
        $this->assertSame($registration->getMorphClass(), $enrollment->source_type);
        $this->assertSame($registration->id, $enrollment->source_id);
        $this->assertSame('webinar_attended', $enrollment->campaign_key);
        $this->assertSame('email', $enrollment->channel);
        $this->assertSame('marketing', $enrollment->purpose);
        $this->assertSame('webinar', $enrollment->scope);
        $this->assertSame(CampaignEnrollment::STATUS_ACTIVE, $enrollment->status);
        $this->assertSame(1, $enrollment->current_step);
        $this->assertNotNull($enrollment->started_at);

        $scheduledMessage = ScheduledMessage::first();

        $this->assertNotNull($scheduledMessage);
        $this->assertSame(Contact::class, $scheduledMessage->recipient_type);
        $this->assertSame($contact->id, $scheduledMessage->recipient_id);
        $this->assertTrue($scheduledMessage->recipient->is($contact));
        $this->assertSame($scheduledMessage->id, $enrollment->last_scheduled_message_id);
        $this->assertSame('step_1', $scheduledMessage->message_type);
        $this->assertSame('email', $scheduledMessage->channel);
        $this->assertSame('marketing', $scheduledMessage->purpose);
        $this->assertSame('webinar', $scheduledMessage->scope);
        $this->assertSame('webinar_attended', $scheduledMessage->meta['campaign_key']);
        $this->assertSame(1, $scheduledMessage->meta['campaign_step']);
        $this->assertSame($enrollment->id, $scheduledMessage->meta['campaign_enrollment_id']);
        $this->assertTrue($scheduledMessage->send_at->equalTo(Carbon::now()->addMinutes(720)));
    }

    public function test_it_returns_existing_active_enrollment_without_scheduling_duplicate_message(): void
    {
        Queue::fake();

        Config::set('messaging.email.marketing.webinar', [
            'step_1' => [
                'dispatch_key' => 'webinar_ended',
                'campaign_key' => 'webinar_attended',
                'step' => 1,
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'delay',
                    'minutes' => 720,
                ],
                'payload_class' => EmailPayload::class,
                'queue' => 'marketing',
                'payload' => [
                    'subject' => 'Step 1',
                    'body' => 'First message',
                ],
            ],
        ]);

        $contact = $this->contactWithMarketingEmailConsent();

        $existingEnrollment = CampaignEnrollment::create([
            'contact_id' => $contact->id,
            'campaign_key' => 'webinar_attended',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'status' => CampaignEnrollment::STATUS_ACTIVE,
            'current_step' => 1,
            'started_at' => now(),
        ]);

        $enrollment = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: 'webinar_attended',
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar',
            dispatchKey: 'webinar_ended',
        );

        $this->assertTrue($existingEnrollment->is($enrollment));
        $this->assertDatabaseCount('campaign_enrollments', 1);
        $this->assertDatabaseCount('scheduled_messages', 0);
    }

    public function test_it_returns_existing_paused_enrollment_without_restarting_campaign(): void
    {
        Queue::fake();

        $contact = $this->contactWithMarketingEmailConsent();

        $existingEnrollment = CampaignEnrollment::create([
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

        $enrollment = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: 'webinar_attended',
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar',
            dispatchKey: 'webinar_ended',
        );

        $this->assertTrue($existingEnrollment->is($enrollment));
        $this->assertDatabaseCount('campaign_enrollments', 1);
        $this->assertDatabaseCount('scheduled_messages', 0);
    }

    public function test_it_completes_enrollment_when_first_step_does_not_exist(): void
    {
        Queue::fake();

        Carbon::setTestNow('2026-06-12 12:00:00');

        Config::set('messaging.email.marketing.webinar', []);

        $contact = $this->contactWithMarketingEmailConsent();

        $enrollment = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: 'webinar_attended',
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar',
            dispatchKey: 'webinar_ended',
        );

        $this->assertSame(CampaignEnrollment::STATUS_COMPLETED, $enrollment->status);
        $this->assertSame(0, $enrollment->current_step);
        $this->assertNotNull($enrollment->completed_at);
        $this->assertNull($enrollment->last_scheduled_message_id);
        $this->assertDatabaseCount('scheduled_messages', 0);
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