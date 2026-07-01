<?php

namespace Tests\Feature\Webinars;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Webinars\Actions\DispatchWebinarRegistrationMessagesAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchWebinarRegistrationMessagesActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureWebinarRegistrationChannelAvailability();
    }

    public function test_it_dispatches_registration_created_email_messages_when_sms_is_hidden_for_registration(): void
    {
        Queue::fake();

        $this->configureRegistrationMessages();

        $registration = $this->registrationForContact(
            contact: $this->contactWithTransactionalConsent([
                MessageChannel::Email->value,
                MessageChannel::Sms->value,
            ]),
        );

        app(DispatchWebinarRegistrationMessagesAction::class)->handle($registration);

        $this->assertDatabaseHas('scheduled_messages', [
            'recipient_type' => Contact::class,
            'recipient_id' => $registration->contact_id,
            'context_type' => $registration->getMorphClass(),
            'context_id' => $registration->id,
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Transactional->value,
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('scheduled_messages', [
            'recipient_type' => Contact::class,
            'recipient_id' => $registration->contact_id,
            'channel' => MessageChannel::Email->value,
            'message_type' => 'reminder',
        ]);

        $this->assertDatabaseMissing('scheduled_messages', [
            'recipient_type' => Contact::class,
            'recipient_id' => $registration->contact_id,
            'channel' => MessageChannel::Sms->value,
        ]);

        $this->assertSame(2, ScheduledMessage::query()->count());

        Queue::assertPushed(SendScheduledMessageJob::class, 2);
    }

    public function test_it_dispatches_registration_created_sms_messages_when_sms_is_available_and_consented(): void
    {
        Queue::fake();

        $this->enableWebinarRegistrationSms();
        $this->configureRegistrationMessages();

        $registration = $this->registrationForContact(
            contact: $this->contactWithTransactionalConsent([
                MessageChannel::Email->value,
                MessageChannel::Sms->value,
            ]),
        );

        app(DispatchWebinarRegistrationMessagesAction::class)->handle($registration);

        $this->assertDatabaseHas('scheduled_messages', [
            'recipient_type' => Contact::class,
            'recipient_id' => $registration->contact_id,
            'context_type' => $registration->getMorphClass(),
            'context_id' => $registration->id,
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Transactional->value,
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('scheduled_messages', [
            'recipient_type' => Contact::class,
            'recipient_id' => $registration->contact_id,
            'context_type' => $registration->getMorphClass(),
            'context_id' => $registration->id,
            'channel' => MessageChannel::Sms->value,
            'purpose' => MessagePurpose::Transactional->value,
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => SmsPayload::class,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('scheduled_messages', [
            'recipient_type' => Contact::class,
            'recipient_id' => $registration->contact_id,
            'channel' => MessageChannel::Email->value,
            'message_type' => 'reminder',
        ]);

        $this->assertDatabaseHas('scheduled_messages', [
            'recipient_type' => Contact::class,
            'recipient_id' => $registration->contact_id,
            'channel' => MessageChannel::Sms->value,
            'message_type' => 'reminder',
        ]);

        $this->assertSame(4, ScheduledMessage::query()->count());

        Queue::assertPushed(SendScheduledMessageJob::class, 4);
    }

    public function test_it_does_not_dispatch_sms_when_sms_is_available_but_not_consented(): void
    {
        Queue::fake();

        $this->enableWebinarRegistrationSms();
        $this->configureRegistrationMessages();

        $registration = $this->registrationForContact(
            contact: $this->contactWithTransactionalConsent([
                MessageChannel::Email->value,
            ]),
        );

        app(DispatchWebinarRegistrationMessagesAction::class)->handle($registration);

        $this->assertDatabaseHas('scheduled_messages', [
            'recipient_type' => Contact::class,
            'recipient_id' => $registration->contact_id,
            'channel' => MessageChannel::Email->value,
        ]);

        $this->assertDatabaseMissing('scheduled_messages', [
            'recipient_type' => Contact::class,
            'recipient_id' => $registration->contact_id,
            'channel' => MessageChannel::Sms->value,
        ]);

        $this->assertSame(2, ScheduledMessage::query()->count());

        Queue::assertPushed(SendScheduledMessageJob::class, 2);
    }


    public function test_it_does_not_dispatch_sms_when_contact_has_sms_consent_but_registration_did_not_accept_sms(): void
    {
        Queue::fake();

        $this->enableWebinarRegistrationSms();
        $this->configureRegistrationMessages();

        $registration = $this->registrationForContact(
            contact: $this->contactWithTransactionalConsent([
                MessageChannel::Email->value,
                MessageChannel::Sms->value,
            ]),
            acceptedTransactionalChannels: [
                MessageChannel::Email->value,
            ],
        );

        app(DispatchWebinarRegistrationMessagesAction::class)->handle($registration);

        $this->assertDatabaseHas('scheduled_messages', [
            'recipient_type' => Contact::class,
            'recipient_id' => $registration->contact_id,
            'channel' => MessageChannel::Email->value,
        ]);

        $this->assertDatabaseMissing('scheduled_messages', [
            'recipient_type' => Contact::class,
            'recipient_id' => $registration->contact_id,
            'channel' => MessageChannel::Sms->value,
        ]);

        $this->assertSame(2, ScheduledMessage::query()->count());

        Queue::assertPushed(SendScheduledMessageJob::class, 2);
    }

    private function configureRegistrationMessages(): void
    {
        Config::set('messaging.email.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',
                'timing' => 'immediate',
                'payload_class' => EmailPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'subject' => 'Registered',
                    'body' => 'You are registered.',
                ],
            ],

            'reminder' => [
                'dispatch_key' => 'registration_created',
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'anchored',
                    'minutes' => -30,
                ],
                'payload_class' => EmailPayload::class,
                'queue' => 'reminders',
                'payload' => [
                    'subject' => 'Reminder',
                    'body' => 'Starts soon.',
                ],
            ],
        ]);

        Config::set('messaging.sms.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',
                'timing' => 'immediate',
                'payload_class' => SmsPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'message' => 'You are registered.',
                ],
            ],

            'reminder' => [
                'dispatch_key' => 'registration_created',
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'anchored',
                    'minutes' => -30,
                ],
                'payload_class' => SmsPayload::class,
                'queue' => 'reminders',
                'payload' => [
                    'message' => 'Starts soon.',
                ],
            ],
        ]);
    }

    /**
     * @param array<int, string> $channels
     */
    private function contactWithTransactionalConsent(array $channels): Contact
    {
        $contact = Contact::factory()->create([
            'email' => 'jeff@example.com',
            'phone' => '+15555550123',
        ]);

        foreach ($channels as $channel) {
            MessageConsent::query()->create([
                'contact_id' => $contact->id,
                'channel' => $channel,
                'purpose' => MessagePurpose::Transactional->value,
                'scope' => 'webinar',
                'consented_at' => now()->subMinute(),
                'source' => 'test',
            ]);
        }

        return $contact;
    }

    /**
     * @param array<int, string> $acceptedTransactionalChannels
     */
    private function registrationForContact(
        Contact $contact,
        array $acceptedTransactionalChannels = [MessageChannel::Email->value, MessageChannel::Sms->value],
    ): WebinarRegistration {

        $series = WebinarSeries::factory()->create();

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->addDay(),
        ]);

        return WebinarRegistration::query()->create([
            'contact_id' => $contact->id,
            'webinar_id' => $webinar->id,
            'webinar_slug' => $webinar->slug,
            'status' => 'pending',
            'source' => 'test',
            'first_name' => 'Jeff',
            'last_name' => 'Yarnall',
            'email' => $contact->email,
            'phone' => $contact->phone,
            'registered_at' => now(),
            'meta' => [
                'accepted_channels' => [
                    'transactional' => $acceptedTransactionalChannels,
                ],
            ],
        ]);
    }

    private function configureWebinarRegistrationChannelAvailability(): void
    {
        Config::set('messaging.channel_availability.email', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => false,
            'surfaces' => [
                'webinar_registrations' => true,
            ],
            'purpose_scopes' => [
                'transactional:webinar' => true,
                'marketing:webinar_nurture' => true,
            ],
        ]);

        Config::set('messaging.channel_availability.sms', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => true,
            'surfaces' => [
                'webinar_registrations' => false,
            ],
            'purpose_scopes' => [
                'transactional:webinar' => true,
                'marketing:webinar_nurture' => true,
            ],
        ]);
    }

    private function enableWebinarRegistrationSms(): void
    {
        Config::set('messaging.channel_availability.sms.surfaces.webinar_registrations', true);
    }
}
