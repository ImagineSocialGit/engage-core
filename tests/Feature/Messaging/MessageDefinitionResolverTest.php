<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
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
        Config::set('messaging.email.definitions.transactional.webinar', [
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
        $this->assertSame('messaging.email.definitions.transactional.webinar.confirmation', $definitions[0]['config_path']);
    }

    public function test_it_returns_empty_when_scope_missing(): void
    {
        $this->assertSame([], app(MessageDefinitionResolver::class)->resolve(
            channel: 'email',
            purpose: 'transactional',
            scope: 'does_not_exist',
        ));
    }
    public function test_exact_assigned_reminder_replaces_only_its_matching_config_definition(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'reminders' => [
                [
                    'key' => 'reminder_1_day',
                    'dispatch_key' => 'registration_created',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'reminders',
                    'payload' => [
                        'subject' => 'Config one day',
                        'body' => 'Config one day body.',
                    ],
                ],
                [
                    'key' => 'reminder_30_minute',
                    'dispatch_key' => 'registration_created',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'reminders',
                    'payload' => [
                        'subject' => 'Config thirty minute',
                        'body' => 'Config thirty minute body.',
                    ],
                ],
            ],
        ]);

        $preset = MessageTemplatePreset::factory()->create([
            'key' => 'email.transactional.webinar.reminder_1_day.custom',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'reminder',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',
            'dispatch_keys' => ['registration_created'],
            'payload' => [
                'subject' => 'Assigned one day',
                'body' => 'Assigned one day body.',
            ],
        ]);

        MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->create([
                'surface' => 'webinar_registrations',
                'definition_key' => 'reminder_1_day',
                'source_config_path' => 'messaging.email.definitions.transactional.webinar.reminders.0',
            ]);

        $definitions = app(MessageDefinitionResolver::class)->resolve(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $this->assertCount(2, $definitions);
        $this->assertSame([
            'reminder_1_day' => 'Assigned one day',
            'reminder_30_minute' => 'Config thirty minute',
        ], collect($definitions)->mapWithKeys(
            fn (array $definition): array => [$definition['key'] => $definition['payload']['subject']],
        )->sortKeys()->all());
    }

}
