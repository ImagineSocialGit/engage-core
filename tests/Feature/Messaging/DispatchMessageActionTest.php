<?php

namespace Tests\Feature\Messaging;

use App\Actions\Messaging\DispatchMessageAction;
use App\Jobs\Messaging\SendScheduledMessageJob;
use App\Messaging\Payloads\EmailPayload;
use App\Models\Contact;
use App\Models\ScheduledMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchMessageActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_immediate_message(): void
    {
        Queue::fake();

        Config::set('messaging.email.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',
                'timing' => 'immediate',
                'payload_class' => EmailPayload::class,
                'queue' => 'confirmation_messages',

                'payload' => [
                    'subject' => 'Registered',
                    'body' => 'Hello {first_name}',
                ],
            ],
        ]);

        $contact = Contact::factory()->create();

        $messages = app(DispatchMessageAction::class)->handle(
            contact: $contact,
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
            dispatchKeys: 'registration_created',
        );

        $this->assertCount(1, $messages);

        $message = ScheduledMessage::first();

        $this->assertNotNull($message);

        $this->assertSame('email', $message->channel);
        $this->assertSame('transactional', $message->purpose);
        $this->assertSame('webinar', $message->scope);
        $this->assertSame('confirmation', $message->message_type);

        Queue::assertPushed(SendScheduledMessageJob::class);
    }

    public function test_it_filters_dispatch_keys(): void
    {
        Queue::fake();

        Config::set('messaging.email.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'consent_granted',
                'timing' => 'immediate',
                'payload_class' => EmailPayload::class,
                'queue' => 'notifications',
                'payload' => [
                    'subject' => 'A',
                    'body' => 'B',
                ],
            ],
        ]);

        app(DispatchMessageAction::class)->handle(
            contact: Contact::factory()->create(),
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
            dispatchKeys: 'registration_created',
        );

        $this->assertDatabaseCount(
            'scheduled_messages',
            0
        );
    }

    public function test_it_creates_delay_schedule(): void
    {
        Queue::fake();

        Config::set('messaging.email.transactional.webinar', [
            'follow_up' => [
                'dispatch_key' => 'webinar_ended',

                'timing' => 'scheduled',

                'schedule' => [
                    'type' => 'delay',
                    'minutes' => 15,
                ],

                'payload_class' => EmailPayload::class,

                'queue' => 'notifications',

                'payload' => [
                    'subject' => 'Follow Up',
                    'body' => 'Hi',
                ],
            ],
        ]);

        $triggeredAt = Carbon::parse('2026-06-11 10:00:00');

        app(DispatchMessageAction::class)->handle(
            contact: Contact::factory()->create(),
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
            dispatchKeys: 'webinar_ended',
            triggeredAt: $triggeredAt,
        );

        $this->assertEquals(
            $triggeredAt->copy()->addMinutes(15),
            ScheduledMessage::first()->send_at,
        );
    }

    public function test_it_creates_anchored_schedule(): void
    {
        Queue::fake();

        Config::set('messaging.email.transactional.webinar', [
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
                    'body' => 'Soon',
                ],
            ],
        ]);

        $anchor = Carbon::parse('2026-06-11 15:00:00');

        app(DispatchMessageAction::class)->handle(
            contact: Contact::factory()->create(),
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
            dispatchKeys: 'registration_created',
            anchor: $anchor,
        );

        $this->assertEquals(
            $anchor->copy()->subMinutes(30),
            ScheduledMessage::first()->send_at,
        );
    }

    public function test_it_skips_failed_conditions(): void
    {
        Queue::fake();

        Config::set('messaging.email.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',

                'conditions' => [
                    'contact.status' => 'converted',
                ],

                'timing' => 'immediate',

                'payload_class' => EmailPayload::class,

                'queue' => 'notifications',

                'payload' => [
                    'subject' => 'A',
                    'body' => 'B',
                ],
            ],
        ]);

        app(DispatchMessageAction::class)->handle(
            contact: Contact::factory()->create([
                'status' => 'new',
            ]),
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
            dispatchKeys: 'registration_created',
        );

        $this->assertDatabaseCount(
            'scheduled_messages',
            0
        );
    }

    public function test_it_stores_payload_and_meta(): void
    {
        Queue::fake();

        Config::set('messaging.email.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',

                'timing' => 'immediate',

                'payload_class' => EmailPayload::class,

                'queue' => 'notifications',

                'payload' => [
                    'subject' => 'Registered',
                    'body' => 'Hello',
                ],
            ],
        ]);

        app(DispatchMessageAction::class)->handle(
            contact: Contact::factory()->create(),
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
            dispatchKeys: 'registration_created',
            payload: [
                'tokens' => [
                    'first_name' => 'Jeff',
                ],
            ],
        );

        $message = ScheduledMessage::first();

        $this->assertSame(
            EmailPayload::class,
            $message->payload_class,
        );

        $this->assertSame(
            'Registered',
            $message->payload['subject'],
        );

        $this->assertSame(
            'messaging.email.transactional.webinar.confirmation',
            $message->meta['definition_config_path'],
        );
    }
}