<?php

namespace Tests\Feature\Webinars;

use App\Actions\Webinars\PostEvent\DispatchWebinarOutcomeMessagesAction;
use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Jobs\Messaging\SendScheduledMessageJob;
use App\Messaging\Payloads\EmailPayload;
use App\Messaging\Payloads\SmsPayload;
use App\Models\CampaignEnrollment;
use App\Models\Contact;
use App\Models\ScheduledMessage;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchWebinarOutcomeMessagesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_attended_registration_dispatches_attended_messages(): void
    {
        Queue::fake();

        $this->configureOutcomeMessages();

        $webinar = Webinar::factory()->create([
            'ends_at' => now(),
        ]);

        $contact = $this->createContact();

        $registration = WebinarRegistration::query()->create([
            'contact_id' => $contact->id,
            'webinar_id' => $webinar->id,
            'webinar_slug' => $webinar->slug,
            'status' => 'registered',
            'source' => 'webinar_subdomain',
            'email' => $contact->email,
            'phone' => $contact->phone,
            'registered_at' => now(),
            'attended_at' => now(),
        ]);

        app(DispatchWebinarOutcomeMessagesAction::class)->handle($registration);

        $this->assertScheduled($registration, MessageChannel::Email->value, 'attended_follow_up');
        $this->assertScheduled($registration, MessageChannel::Sms->value, 'attended_follow_up');

        $this->assertDatabaseMissing('scheduled_messages', [
            'contact_id' => $contact->id,
            'message_type' => 'missed_follow_up',
        ]);

        Queue::assertPushed(SendScheduledMessageJob::class, 4);

        $this->assertCampaignEnrollmentCreated($registration, MessageChannel::Email->value);
        $this->assertCampaignEnrollmentCreated($registration, MessageChannel::Sms->value);
    }

    public function test_missed_registration_dispatches_missed_messages(): void
    {
        Queue::fake();

        $this->configureOutcomeMessages();

        $webinar = Webinar::factory()->create([
            'ends_at' => now(),
        ]);

        $contact = $this->createContact();

        $registration = WebinarRegistration::query()->create([
            'contact_id' => $contact->id,
            'webinar_id' => $webinar->id,
            'webinar_slug' => $webinar->slug,
            'status' => 'registered',
            'source' => 'webinar_subdomain',
            'email' => $contact->email,
            'phone' => $contact->phone,
            'registered_at' => now(),
            'attended_at' => null,
        ]);

        app(DispatchWebinarOutcomeMessagesAction::class)->handle($registration);

        $this->assertScheduled($registration, MessageChannel::Email->value, 'missed_follow_up');
        $this->assertScheduled($registration, MessageChannel::Sms->value, 'missed_follow_up');

        $this->assertDatabaseMissing('scheduled_messages', [
            'contact_id' => $contact->id,
            'message_type' => 'attended_follow_up',
        ]);

        Queue::assertPushed(SendScheduledMessageJob::class, 4);

        $this->assertCampaignEnrollmentCreated($registration, MessageChannel::Email->value);
        $this->assertCampaignEnrollmentCreated($registration, MessageChannel::Sms->value);
    }

    private function configureOutcomeMessages(): void
    {
        Config::set('messaging.email.transactional.webinar', [
            'attended_follow_up' => [
                'dispatch_key' => 'webinar_ended',
                'timing' => 'immediate',
                'conditions' => [
                    'webinar_registration.attended_at_truthy' => true,
                ],
                'payload_class' => EmailPayload::class,
                'queue' => 'notifications',
                'payload' => [
                    'subject' => 'Thanks for attending',
                    'body' => 'Thanks for attending.',
                ],
            ],

            'missed_follow_up' => [
                'dispatch_key' => 'webinar_ended',
                'timing' => 'immediate',
                'conditions' => [
                    'webinar_registration.attended_at_falsy' => true,
                ],
                'payload_class' => EmailPayload::class,
                'queue' => 'notifications',
                'payload' => [
                    'subject' => 'Sorry we missed you',
                    'body' => 'Sorry we missed you.',
                ],
            ],
        ]);

        Config::set('messaging.sms.transactional.webinar', [
            'attended_follow_up' => [
                'dispatch_key' => 'webinar_ended',
                'timing' => 'immediate',
                'conditions' => [
                    'webinar_registration.attended_at_truthy' => true,
                ],
                'payload_class' => SmsPayload::class,
                'queue' => 'notifications',
                'payload' => [
                    'message' => 'Thanks for attending.',
                ],
            ],

            'missed_follow_up' => [
                'dispatch_key' => 'webinar_ended',
                'timing' => 'immediate',
                'conditions' => [
                    'webinar_registration.attended_at_falsy' => true,
                ],
                'payload_class' => SmsPayload::class,
                'queue' => 'notifications',
                'payload' => [
                    'message' => 'Sorry we missed you.',
                ],
            ],
        ]);

        Config::set('messaging.email.marketing.webinar', [
            'attended_drip_step_1' => [
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
                    'subject' => 'What held you back?',
                    'body' => 'First marketing email.',
                ],
            ],
        ]);

        Config::set('messaging.sms.marketing.webinar', [
            'attended_drip_step_1' => [
                'dispatch_key' => 'webinar_ended',
                'campaign_key' => 'webinar_attended',
                'step' => 1,
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'delay',
                    'minutes' => 720,
                ],
                'payload_class' => SmsPayload::class,
                'queue' => 'marketing',
                'payload' => [
                    'message' => 'First marketing SMS.',
                ],
            ],
        ]);
    }

    private function createContact(): Contact
    {
        return Contact::query()->create([
            'first_name' => 'Jeff',
            'last_name' => 'Yarnall',
            'name' => 'Jeff Yarnall',
            'email' => 'attendee@example.com',
            'phone' => '+15555550123',
            'status' => 'new',
            'source' => 'webinar',
        ]);
    }

    private function assertScheduled(
        WebinarRegistration $registration,
        string $channel,
        string $messageType,
    ): void {
        $scheduledMessage = ScheduledMessage::query()
            ->where('contact_id', $registration->contact->getKey())
            ->whereMorphedTo('context', $registration)
            ->where('channel', $channel)
            ->where('message_type', $messageType)
            ->where('purpose', MessagePurpose::Transactional->value)
            ->where('scope', 'webinar')
            ->first();

        $this->assertNotNull($scheduledMessage);
        $this->assertSame('pending', $scheduledMessage->status);
        $this->assertSame(['webinar_ended'], $scheduledMessage->meta['dispatch_keys']);
        $this->assertArrayHasKey('conditions', $scheduledMessage->meta);
    }

    private function assertCampaignEnrollmentCreated(
        WebinarRegistration $registration,
        string $channel,
    ): void {
        $enrollment = CampaignEnrollment::query()
            ->where('contact_id', $registration->contact->getKey())
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
        $this->assertSame($registration->contact->getKey(), $scheduledMessage->contact_id);
        $this->assertSame($channel, $scheduledMessage->channel);
        $this->assertSame(MessagePurpose::Marketing->value, $scheduledMessage->purpose);
        $this->assertSame('webinar', $scheduledMessage->scope);
        $this->assertSame('attended_drip_step_1', $scheduledMessage->message_type);
        $this->assertSame($enrollment->id, $scheduledMessage->meta['campaign_enrollment_id']);
        $this->assertSame('webinar_attended', $scheduledMessage->meta['campaign_key']);
        $this->assertSame(1, $scheduledMessage->meta['campaign_step']);
    }
}