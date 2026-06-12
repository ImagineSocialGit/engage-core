<?php

namespace Tests\Feature\Messaging;

use App\Messaging\Payloads\EmailPayload;
use App\Services\Messaging\MessageDefinitionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Tests\TestCase;

class MessageDefinitionResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_all_message_definitions_for_scope(): void
    {
        Config::set('messaging.email.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',
                'timing' => 'immediate',
                'payload_class' => EmailPayload::class,
                'queue' => 'confirmation_messages',

                'payload' => [
                    'subject' => 'Registered',
                    'body' => 'Thanks',
                ],
            ],

            'reminder' => [
                'dispatch_keys' => [
                    'registration_created',
                    'webinar_rescheduled',
                ],

                'timing' => 'scheduled',

                'schedule' => [
                    'type' => 'anchored',
                    'minutes' => -1440,
                ],

                'payload_class' => EmailPayload::class,

                'queue' => 'reminders',

                'payload' => [
                    'subject' => 'Reminder',
                    'body' => 'Tomorrow',
                ],
            ],
        ]);

        $definitions = app(MessageDefinitionResolver::class)->resolve(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $this->assertCount(2, $definitions);

        $confirmation = $definitions[0];

        $this->assertSame('email', $confirmation['channel']);
        $this->assertSame('transactional', $confirmation['purpose']);
        $this->assertSame('webinar', $confirmation['scope']);

        $this->assertSame(
            'confirmation',
            $confirmation['message_type']
        );

        $this->assertSame(
            'messaging.email.transactional.webinar.confirmation',
            $confirmation['config_path']
        );

        $this->assertSame(
            ['registration_created'],
            $confirmation['dispatch_keys']
        );

        $this->assertSame(
            EmailPayload::class,
            $confirmation['payload_class']
        );
    }

    public function test_it_returns_empty_when_scope_missing(): void
    {
        $definitions = app(MessageDefinitionResolver::class)->resolve(
            channel: 'email',
            purpose: 'transactional',
            scope: 'does_not_exist',
        );

        $this->assertSame([], $definitions);
    }

    public function test_it_normalizes_single_dispatch_key(): void
    {
        Config::set('messaging.email.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',

                'timing' => 'immediate',

                'payload_class' => EmailPayload::class,

                'queue' => 'confirmation_messages',

                'payload' => [
                    'subject' => 'Registered',
                    'body' => 'Thanks',
                ],
            ],
        ]);

        $definitions = app(MessageDefinitionResolver::class)->resolve(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $this->assertSame(
            ['registration_created'],
            $definitions[0]['dispatch_keys']
        );
    }

    public function test_it_accepts_delay_schedule(): void
    {
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
                    'body' => 'Hello',
                ],
            ],
        ]);

        $definitions = app(MessageDefinitionResolver::class)->resolve(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $this->assertSame(
            [
                'type' => 'delay',
                'minutes' => 15,
            ],
            $definitions[0]['schedule']
        );
    }

    public function test_it_accepts_negative_anchor_schedule(): void
    {
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
                    'body' => 'Starts soon',
                ],
            ],
        ]);

        $definitions = app(MessageDefinitionResolver::class)->resolve(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $this->assertSame(
            -30,
            $definitions[0]['schedule']['minutes']
        );
    }

    public function test_it_rejects_invalid_timing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Config::set('messaging.email.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',

                'timing' => 'later',

                'payload_class' => EmailPayload::class,

                'queue' => 'confirmation_messages',

                'payload' => [
                    'subject' => 'Registered',
                    'body' => 'Thanks',
                ],
            ],
        ]);

        app(MessageDefinitionResolver::class)->resolve(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );
    }

    public function test_it_rejects_invalid_schedule_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Config::set('messaging.email.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',

                'timing' => 'scheduled',

                'schedule' => [
                    'type' => 'webinar_relative',
                    'minutes' => -10,
                ],

                'payload_class' => EmailPayload::class,

                'queue' => 'confirmation_messages',

                'payload' => [
                    'subject' => 'Registered',
                    'body' => 'Thanks',
                ],
            ],
        ]);

        app(MessageDefinitionResolver::class)->resolve(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );
    }
}