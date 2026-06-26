<?php

namespace Tests\Feature\Webinars\PostEvent;

use App\Modules\Webinars\Actions\PostEvent\DispatchPostWebinarCampaignsAction;
use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class DispatchPostWebinarCampaignsActionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_enrolls_registrations_matching_enabled_campaign_routes(): void
    {
        Queue::fake();

        Carbon::setTestNow('2026-06-12 12:00:00');

        $this->configureCampaigns();

        [$webinar, $attendedRegistration, $missedRegistration] = $this->makeWebinarWithRegistrations();

        $provider = $this->mock(WebinarProvider::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('key');
            $mock->shouldNotReceive('listAttendanceRecords');
            $mock->shouldNotReceive('getRecording');
        });

        app(DispatchPostWebinarCampaignsAction::class)->execute(
            provider: $provider,
            webinar: $webinar,
            event: 'webinar.ended',
        );

        $webinar->refresh();

        $this->assertNotNull(data_get($webinar->meta, 'normalized.post_event.campaigns_dispatched_at'));

        $this->assertCampaignEnrollmentCreated(
            registration: $attendedRegistration,
            channel: MessageChannel::Email->value,
        );

        $this->assertCampaignEnrollmentCreated(
            registration: $attendedRegistration,
            channel: MessageChannel::Sms->value,
        );

        $this->assertDatabaseMissing('campaign_enrollments', [
            'contact_id' => $missedRegistration->contact_id,
            'campaign_key' => 'webinar_attended',
        ]);

        Queue::assertPushed(SendScheduledMessageJob::class, 2);
    }

    public function test_it_safely_no_ops_when_campaigns_are_disabled(): void
    {
        Queue::fake();

        Carbon::setTestNow('2026-06-12 12:00:00');

        $this->configureCampaigns();

        Config::set('webinars.post_event.campaigns.enabled', false);

        [$webinar] = $this->makeWebinarWithRegistrations();

        $provider = $this->mock(WebinarProvider::class);

        app(DispatchPostWebinarCampaignsAction::class)->execute(
            provider: $provider,
            webinar: $webinar,
            event: 'webinar.ended',
        );

        $webinar->refresh();

        $this->assertNull(data_get($webinar->meta, 'normalized.post_event.campaigns_dispatched_at'));
        $this->assertDatabaseCount('campaign_enrollments', 0);
        $this->assertDatabaseCount('scheduled_messages', 0);

        Queue::assertNothingPushed();
    }

    public function test_it_is_idempotent_at_the_webinar_level(): void
    {
        Queue::fake();

        Carbon::setTestNow('2026-06-12 12:00:00');

        $this->configureCampaigns();

        [$webinar, $attendedRegistration] = $this->makeWebinarWithRegistrations();

        $provider = $this->mock(WebinarProvider::class);

        app(DispatchPostWebinarCampaignsAction::class)->execute(
            provider: $provider,
            webinar: $webinar,
            event: 'webinar.ended',
        );

        app(DispatchPostWebinarCampaignsAction::class)->execute(
            provider: $provider,
            webinar: $webinar->fresh(),
            event: 'webinar.ended',
        );

        $this->assertSame(2, CampaignEnrollment::query()
            ->where('contact_id', $attendedRegistration->contact_id)
            ->where('campaign_key', 'webinar_attended')
            ->count()
        );

        $this->assertSame(2, ScheduledMessage::query()
            ->where('recipient_type', Contact::class)
            ->where('recipient_id', $attendedRegistration->contact_id)
            ->where('purpose', MessagePurpose::Marketing->value)
            ->where('scope', 'webinar')
            ->count()
        );

        Queue::assertPushed(SendScheduledMessageJob::class, 2);
    }

    private function configureCampaigns(): void
    {
        Config::set('webinars.post_event.campaigns', [
            'enabled' => true,

            'routes' => [
                'attended' => [
                    'enabled' => true,
                    'campaign_key' => 'webinar_attended',
                    'dispatch_key' => 'webinar_ended',
                    'conditions' => [
                        [
                            'field' => 'registration.attended_at',
                            'operator' => 'filled',
                        ],
                    ],
                ],

                'missed' => [
                    'enabled' => false,
                    'campaign_key' => null,
                    'dispatch_key' => null,
                    'conditions' => [
                        [
                            'field' => 'registration.attended_at',
                            'operator' => 'blank',
                        ],
                    ],
                ],
            ],
        ]);

        Config::set('messaging.email.marketing.webinar', [
            'campaigns' => [
                [
                    'dispatch_key' => 'webinar_ended',
                    'campaign_key' => 'webinar_attended',
                    'step' => 1,
                    'timing' => 'scheduled',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'marketing',

                    'schedule' => [
                        'type' => 'delay',
                        'minutes' => 720,
                    ],

                    'payload' => [
                        'subject' => 'What held you back?',
                        'body' => 'First marketing email.',
                    ],
                ],
            ],
        ]);

        Config::set('messaging.sms.marketing.webinar', [
            'campaigns' => [
                [
                    'dispatch_key' => 'webinar_ended',
                    'campaign_key' => 'webinar_attended',
                    'step' => 1,
                    'timing' => 'scheduled',
                    'payload_class' => SmsPayload::class,
                    'queue' => 'marketing',

                    'schedule' => [
                        'type' => 'delay',
                        'minutes' => 720,
                    ],

                    'payload' => [
                        'message' => 'First marketing SMS.',
                    ],
                ],
            ],
        ]);
    }

    private function makeWebinarWithRegistrations(): array
    {
        $webinar = Webinar::factory()->create([
            'platform' => 'zoom',
            'external_id' => '123456789',
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
            'meta' => [],
        ]);

        $attendedContact = $this->contactWithMarketingConsent(
            email: 'attended@example.com',
            phone: '+15555550123',
        );

        $missedContact = $this->contactWithMarketingConsent(
            email: 'missed@example.com',
            phone: '+15555550124',
        );

        $attendedRegistration = WebinarRegistration::factory()
            ->for($webinar)
            ->for($attendedContact)
            ->create([
                'attended_at' => now()->subMinutes(45),
                'meta' => [],
            ]);

        $missedRegistration = WebinarRegistration::factory()
            ->for($webinar)
            ->for($missedContact)
            ->create([
                'attended_at' => null,
                'meta' => [],
            ]);

        return [$webinar, $attendedRegistration, $missedRegistration];
    }

    private function contactWithMarketingConsent(string $email, string $phone): Contact
    {
        $contact = Contact::factory()->create([
            'email' => $email,
            'phone' => $phone,
        ]);

        foreach ([MessageChannel::Email->value, MessageChannel::Sms->value] as $channel) {
            MessageConsent::query()->create([
                'contact_id' => $contact->id,
                'channel' => $channel,
                'purpose' => MessagePurpose::Marketing->value,
                'scope' => 'webinar',
                'consented_at' => now()->subMinute(),
                'source' => 'test',
            ]);
        }

        return $contact;
    }

    private function assertCampaignEnrollmentCreated(
        WebinarRegistration $registration,
        string $channel,
    ): void {
        $enrollment = CampaignEnrollment::query()
            ->where('contact_id', $registration->contact_id)
            ->whereMorphedTo('source', $registration)
            ->where('campaign_key', 'webinar_attended')
            ->where('channel', $channel)
            ->where('purpose', MessagePurpose::Marketing->value)
            ->where('scope', 'webinar')
            ->first();

        $this->assertNotNull($enrollment);
        $this->assertSame(CampaignEnrollment::STATUS_ACTIVE, $enrollment->status);
        $this->assertSame(1, $enrollment->current_step);
        $this->assertNotNull($enrollment->last_scheduled_message_id);

        $scheduledMessage = $enrollment->lastScheduledMessage;

        $this->assertNotNull($scheduledMessage);
        $this->assertSame(Contact::class, $scheduledMessage->recipient_type);
        $this->assertSame($registration->contact_id, $scheduledMessage->recipient_id);
        $this->assertTrue($scheduledMessage->recipient->is($registration->contact));
        $this->assertSame($channel, $scheduledMessage->channel);
        $this->assertSame(MessagePurpose::Marketing->value, $scheduledMessage->purpose);
        $this->assertSame('webinar', $scheduledMessage->scope);
        $this->assertSame('campaign', $scheduledMessage->message_type);
        $this->assertSame($enrollment->id, $scheduledMessage->meta['campaign_enrollment_id']);
        $this->assertSame('webinar_attended', $scheduledMessage->meta['campaign_key']);
        $this->assertSame(1, $scheduledMessage->meta['campaign_step']);
        $this->assertSame('webinar_attended', $scheduledMessage->payload['campaign_key'] ?? 'webinar_attended');
    }
}