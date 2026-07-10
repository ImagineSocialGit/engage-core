<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MessageDefinitionResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_content_only_message_definitions_for_scope(): void
    {
        Config::set('messaging.email.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',
                'payload_class' => EmailPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'subject' => 'Registered',
                    'body' => 'Thanks',
                ],
            ],
            'reminders' => [[
                'dispatch_keys' => ['registration_created', 'webinar_rescheduled'],
                'payload_class' => EmailPayload::class,
                'queue' => 'reminders',
                'payload' => [
                    'subject' => 'Reminder',
                    'body' => 'Tomorrow',
                ],
            ]],
        ]);

        $definitions = app(MessageDefinitionResolver::class)->resolve(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $this->assertCount(2, $definitions);

        foreach ($definitions as $definition) {
            $this->assertArrayNotHasKey('timing', $definition);
            $this->assertArrayNotHasKey('schedule', $definition);
            $this->assertArrayNotHasKey('conditions', $definition);
        }

        $this->assertSame('confirmation', $definitions[0]['message_type']);
        $this->assertSame(['registration_created'], $definitions[0]['dispatch_keys']);
        $this->assertSame('messaging.email.transactional.webinar.confirmation', $definitions[0]['config_path']);
    }

    public function test_it_returns_empty_when_scope_missing(): void
    {
        $this->assertSame([], app(MessageDefinitionResolver::class)->resolve(
            channel: 'email',
            purpose: 'transactional',
            scope: 'does_not_exist',
        ));
    }
}
