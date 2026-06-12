<?php

namespace Tests\Feature\Webinars;

use App\Actions\Webinars\DispatchWebinarRegistrationMessagesAction;
use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Jobs\Messaging\SendScheduledMessageJob;
use App\Messaging\Payloads\EmailPayload;
use App\Messaging\Payloads\SmsPayload;
use App\Models\Contact;
use App\Models\ScheduledMessage;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use App\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchWebinarRegistrationMessagesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_registration_created_messages_for_email_and_sms(): void
    {
        Queue::fake();

        $this->configureRegistrationMessages();

        $series = WebinarSeries::factory()->create();

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->addDay(),
        ]);

        $contact = Contact::factory()->create([
            'email' => 'jeff@example.com',
            'phone' => '+15555550123',
        ]);

        $registration = WebinarRegistration::query()->create([
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
            'meta' => [],
        ]);

        app(DispatchWebinarRegistrationMessagesAction::class)->handle($registration);

        $this->assertDatabaseHas('scheduled_messages', [
            'contact_id' => $contact->id,
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
            'contact_id' => $contact->id,
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
            'contact_id' => $contact->id,
            'channel' => MessageChannel::Email->value,
            'message_type' => 'reminder',
        ]);

        $this->assertDatabaseHas('scheduled_messages', [
            'contact_id' => $contact->id,
            'channel' => MessageChannel::Sms->value,
            'message_type' => 'reminder',
        ]);

        $this->assertSame(4, ScheduledMessage::query()->count());

        Queue::assertPushed(SendScheduledMessageJob::class, 4);
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
}