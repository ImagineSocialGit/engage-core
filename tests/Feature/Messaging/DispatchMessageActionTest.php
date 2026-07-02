<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
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

        $contact = $this->contactWithConsent();

        $messages = app(DispatchMessageAction::class)->handle(
            recipient: $contact,
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

        $this->assertSame(Contact::class, $message->recipient_type);
        $this->assertSame($contact->id, $message->recipient_id);
        $this->assertTrue($message->recipient->is($contact));

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
            recipient: $this->contactWithConsent(),
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

        Carbon::setTestNow('2026-06-11 09:00:00');

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
            recipient: $this->contactWithConsent(),
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
            dispatchKeys: 'webinar_ended',
            triggeredAt: $triggeredAt,
        );

        $message = ScheduledMessage::first();

        $this->assertNotNull($message);

        $this->assertEquals(
            $triggeredAt->copy()->addMinutes(15),
            $message->send_at,
        );
    }

    public function test_it_creates_anchored_schedule(): void
    {
        Queue::fake();

        Carbon::setTestNow('2026-06-11 14:00:00');

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
            recipient: $this->contactWithConsent(),
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
            dispatchKeys: 'registration_created',
            anchor: $anchor,
        );

        $message = ScheduledMessage::first();

        $this->assertNotNull($message);

        $this->assertEquals(
            $anchor->copy()->subMinutes(30),
            $message->send_at,
        );
    }

    public function test_it_skips_failed_conditions(): void
    {
        Queue::fake();

        Config::set('messaging.email.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',

                'conditions' => [
                    'contact.source' => 'referral',
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
            recipient: $this->contactWithConsent(attributes: [
                'source' => 'webinar',
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
            recipient: $this->contactWithConsent(),
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

        $this->assertSame('person@example.com', $message->payload['to']);

        $this->assertSame(
            'messaging.email.transactional.webinar.confirmation',
            $message->meta['definition_config_path'],
        );
    }

    public function test_it_hydrates_sms_payload_with_contact_phone_and_message_context(): void
    {
        Queue::fake();

        Config::set('messaging.sms.marketing.broadcast', [
            'broadcast' => [
                'dispatch_key' => 'broadcast_send',
                'timing' => 'immediate',
                'payload_class' => SmsPayload::class,
                'queue' => 'marketing',
                'payload' => [
                    'message' => 'Config fallback message',
                ],
            ],
        ]);

        $contact = $this->contactWithConsent(
            channel: 'sms',
            purpose: 'marketing',
            scope: 'broadcast',
            attributes: [
                'email' => null,
                'phone' => '+15555550123',
            ],
        );

        $messages = app(DispatchMessageAction::class)->handle(
            recipient: $contact,
            channel: 'sms',
            purpose: 'marketing',
            scope: 'broadcast',
            dispatchKeys: 'broadcast_send',
            payload: [
                'message' => 'Runtime SMS broadcast.',
            ],
        );

        $this->assertCount(1, $messages);

        $message = ScheduledMessage::query()->first();

        $this->assertNotNull($message);
        $this->assertSame('sms', $message->channel);
        $this->assertSame('marketing', $message->purpose);
        $this->assertSame('broadcast', $message->scope);
        $this->assertSame('broadcast', $message->message_type);
        $this->assertSame(SmsPayload::class, $message->payload_class);
        $this->assertSame('marketing', $message->queue);

        $this->assertSame('+15555550123', $message->payload['to']);
        $this->assertSame('sms', $message->payload['channel']);
        $this->assertSame('marketing', $message->payload['purpose']);
        $this->assertSame('broadcast', $message->payload['scope']);
        $this->assertSame('broadcast', $message->payload['message_type']);
        $this->assertSame('Runtime SMS broadcast.', $message->payload['message']);
        $this->assertSame(Contact::class, $message->payload['recipient_type']);
        $this->assertSame($contact->id, $message->payload['recipient_id']);

        Queue::assertPushed(SendScheduledMessageJob::class);
    }

    public function test_it_schedules_no_sms_message_when_contact_has_no_phone(): void
    {
        Queue::fake();

        Config::set('messaging.sms.marketing.broadcast', [
            'broadcast' => [
                'dispatch_key' => 'broadcast_send',
                'timing' => 'immediate',
                'payload_class' => SmsPayload::class,
                'queue' => 'marketing',
                'payload' => [
                    'message' => 'Runtime SMS broadcast.',
                ],
            ],
        ]);

        $contact = $this->contactWithConsent(
            channel: 'sms',
            purpose: 'marketing',
            scope: 'broadcast',
            attributes: [
                'email' => 'person@example.com',
                'phone' => null,
            ],
        );

        $messages = app(DispatchMessageAction::class)->handle(
            recipient: $contact,
            channel: 'sms',
            purpose: 'marketing',
            scope: 'broadcast',
            dispatchKeys: 'broadcast_send',
            payload: [
                'message' => 'Runtime SMS broadcast.',
            ],
        );

        $this->assertSame([], $messages);
        $this->assertDatabaseCount('scheduled_messages', 0);

        Queue::assertNothingPushed();
    }

    public function test_it_filters_campaign_messages_by_criteria(): void
    {
        Queue::fake();

        Config::set('messaging.email.marketing.webinar', [
            'first_drip' => [
                'dispatch_key' => 'marketing_message_sent',
                'campaign_key' => 'webinar_attended',
                'step' => 1,
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'delay',
                    'minutes' => 60,
                ],
                'payload_class' => EmailPayload::class,
                'queue' => 'marketing',
                'payload' => [
                    'subject' => 'Step 1',
                    'body' => 'First',
                ],
            ],

            'second_drip' => [
                'dispatch_key' => 'marketing_message_sent',
                'campaign_key' => 'webinar_attended',
                'step' => 2,
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'delay',
                    'minutes' => 120,
                ],
                'payload_class' => EmailPayload::class,
                'queue' => 'marketing',
                'payload' => [
                    'subject' => 'Step 2',
                    'body' => 'Second',
                ],
            ],
        ]);

        app(DispatchMessageAction::class)->handle(
            recipient: $this->contactWithConsent('marketing'),
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar',
            dispatchKeys: 'marketing_message_sent',
            criteria: [
                'campaign_key' => 'webinar_attended',
                'step' => 2,
            ],
        );

        $this->assertDatabaseCount('scheduled_messages', 1);

        $message = ScheduledMessage::first();

        $this->assertSame('second_drip', $message->message_type);
        $this->assertSame('webinar_attended', $message->meta['campaign_key']);
        $this->assertSame(2, $message->meta['campaign_step']);
        $this->assertSame('Step 2', $message->payload['subject']);
    }

    public function test_it_schedules_nothing_when_campaign_criteria_do_not_match(): void
    {
        Queue::fake();

        Config::set('messaging.email.marketing.webinar', [
            'first_drip' => [
                'dispatch_key' => 'marketing_message_sent',
                'campaign_key' => 'webinar_attended',
                'step' => 1,
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'delay',
                    'minutes' => 60,
                ],
                'payload_class' => EmailPayload::class,
                'queue' => 'marketing',
                'payload' => [
                    'subject' => 'Step 1',
                    'body' => 'First',
                ],
            ],
        ]);

        $messages = app(DispatchMessageAction::class)->handle(
            recipient: $this->contactWithConsent('marketing'),
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar',
            dispatchKeys: 'marketing_message_sent',
            criteria: [
                'campaign_key' => 'webinar_attended',
                'step' => 2,
            ],
        );

        $this->assertSame([], $messages);
        $this->assertDatabaseCount('scheduled_messages', 0);
    }

    public function test_it_throws_when_campaign_criteria_match_multiple_definitions(): void
    {
        Queue::fake();

        Config::set('messaging.email.marketing.webinar', [
            'first_drip_a' => [
                'dispatch_key' => 'marketing_message_sent',
                'campaign_key' => 'webinar_attended',
                'step' => 2,
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'delay',
                    'minutes' => 60,
                ],
                'payload_class' => EmailPayload::class,
                'queue' => 'marketing',
                'payload' => [
                    'subject' => 'A',
                    'body' => 'A',
                ],
            ],

            'first_drip_b' => [
                'dispatch_key' => 'marketing_message_sent',
                'campaign_key' => 'webinar_attended',
                'step' => 2,
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'delay',
                    'minutes' => 90,
                ],
                'payload_class' => EmailPayload::class,
                'queue' => 'marketing',
                'payload' => [
                    'subject' => 'B',
                    'body' => 'B',
                ],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Dispatch criteria matched multiple message definitions.');

        app(DispatchMessageAction::class)->handle(
            recipient: $this->contactWithConsent('marketing'),
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar',
            dispatchKeys: 'marketing_message_sent',
            criteria: [
                'campaign_key' => 'webinar_attended',
                'step' => 2,
            ],
        );
    }

    public function test_it_builds_sms_broadcast_payload_from_resolved_scheduled_message_payload(): void
    {
        $payload = SmsPayload::fromArray([
            'to' => '+15555550123',
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'message_type' => 'broadcast',
            'message' => 'Runtime SMS broadcast.',
            'recipient_type' => 'contact',
            'recipient_id' => 123,
        ]);

        $this->assertSame('+15555550123', $payload->to());
        $this->assertSame('Runtime SMS broadcast.', $payload->message());
        $this->assertSame('broadcast', $payload->kind());
        $this->assertSame('marketing', $payload->purpose());
    }
    
    private function contactWithConsent(
        string $purpose = 'transactional',
        string $scope = 'webinar',
        array $attributes = [],
        string $channel = 'email',
    ): Contact {
        $contact = Contact::factory()->create([
            'email' => 'person@example.com',
            ...$attributes,
        ]);

        MessageConsent::query()->create([
            'contact_id' => $contact->id,
            'channel' => $channel,
            'purpose' => $purpose,
            'scope' => $scope,
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