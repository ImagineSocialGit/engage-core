<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MessageTemplatePresetAssignmentResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_message_resolution_prefers_active_assignment_before_config(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',
                'payload_class' => EmailPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'subject' => 'Config subject',
                    'body' => 'Config body.',
                ],
            ],
        ]);

        $preset = MessageTemplatePreset::factory()->create([
            'key' => 'webinar_registration_confirmation.db',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['registration_created'],
            'payload' => [
                'subject' => 'DB subject',
                'body' => 'DB body.',
            ],
            'source_config_path' => 'messaging.email.definitions.transactional.webinar.confirmation',
        ]);

        MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->create();

        $definitions = app(MessageDefinitionResolver::class)->resolve(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $this->assertCount(1, $definitions);
        $this->assertSame('DB subject', $definitions[0]['payload']['subject']);
        $this->assertNull($definitions[0]['config_path']);
        $this->assertSame($preset->getKey(), data_get($definitions[0], 'meta.message_template_preset.id'));
    }

    public function test_campaign_step_variant_resolution_prefers_active_assignment_before_config(): void
    {
        Config::set('messaging.email.definitions.marketing.webinar_nurture', [
            'campaigns' => [
                'webinar_attended_nurture' => [
                    'steps' => [
                        1 => [
                            'variants' => [
                                'email' => [
                                    'dispatch_key' => 'campaign_step_due',
                                    'payload_class' => EmailPayload::class,
                                    'queue' => 'marketing',
                                    'payload' => [
                                        'subject' => 'Config campaign subject',
                                        'body' => 'Config campaign body.',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $preset = MessageTemplatePreset::factory()->create([
            'key' => 'webinar_nurture.attended.db_email_step_1',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'message_type' => 'webinar_attended_nurture_step_1',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'dispatch_keys' => ['campaign_step_due'],
            'payload' => [
                'subject' => 'DB campaign subject',
                'body' => 'DB campaign body.',
            ],
            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email',
        ]);

        MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->forCampaignStepVariant('webinar_attended_nurture', 1, 'email', $preset->source_config_path)
            ->create();

        $definition = app(MessageDefinitionResolver::class)->resolveCampaignStep(
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar_nurture',
            campaignKey: 'webinar_attended_nurture',
            stepNumber: 1,
            dispatchKey: 'campaign_step_due',
            variantKey: 'email',
            variantSourceConfigPath: $preset->source_config_path,
        );

        $this->assertIsArray($definition);
        $this->assertSame('DB campaign subject', $definition['payload']['subject']);
        $this->assertSame('webinar_attended_nurture', $definition['campaign_key']);
        $this->assertSame(1, $definition['step']);
        $this->assertSame('email', $definition['variant']);
        $this->assertNull($definition['config_path']);
    }

    public function test_resolution_falls_back_to_config_when_no_assignment_exists(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',
                'payload_class' => EmailPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'subject' => 'Config subject',
                    'body' => 'Config body.',
                ],
            ],
        ]);

        $definitions = app(MessageDefinitionResolver::class)->resolve(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $this->assertCount(1, $definitions);
        $this->assertSame('Config subject', $definitions[0]['payload']['subject']);
        $this->assertSame('messaging.email.definitions.transactional.webinar.confirmation', $definitions[0]['config_path']);
    }

    public function test_standard_resolution_uses_newest_active_assignment_for_same_message_context(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',
                'payload_class' => EmailPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'subject' => 'Config subject',
                    'body' => 'Config body.',
                ],
            ],
        ]);

        $oldPreset = MessageTemplatePreset::factory()->create([
            'key' => 'webinar_registration_confirmation.old',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['registration_created'],
            'payload' => [
                'subject' => 'Old DB subject',
                'body' => 'Old DB body.',
            ],
        ]);

        $newPreset = MessageTemplatePreset::factory()->create([
            'key' => 'webinar_registration_confirmation.new',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['registration_created'],
            'payload' => [
                'subject' => 'New DB subject',
                'body' => 'New DB body.',
            ],
        ]);

        MessageTemplatePresetAssignment::factory()
            ->forPreset($oldPreset)
            ->create();

        MessageTemplatePresetAssignment::factory()
            ->forPreset($newPreset)
            ->create();

        $definitions = app(MessageDefinitionResolver::class)->resolve(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $this->assertCount(1, $definitions);
        $this->assertSame('New DB subject', $definitions[0]['payload']['subject']);
        $this->assertSame($newPreset->getKey(), data_get($definitions[0], 'meta.message_template_preset.id'));
    }

    public function test_expired_assignment_is_ignored_and_resolution_falls_back_to_config(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',
                'payload_class' => EmailPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'subject' => 'Config subject',
                    'body' => 'Config body.',
                ],
            ],
        ]);

        $preset = MessageTemplatePreset::factory()->create([
            'key' => 'webinar_registration_confirmation.expired',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['registration_created'],
            'payload' => [
                'subject' => 'Expired DB subject',
                'body' => 'Expired DB body.',
            ],
        ]);

        MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->create([
                'ends_at' => now()->subMinute(),
            ]);

        $definitions = app(MessageDefinitionResolver::class)->resolve(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $this->assertCount(1, $definitions);
        $this->assertSame('Config subject', $definitions[0]['payload']['subject']);
        $this->assertSame('messaging.email.definitions.transactional.webinar.confirmation', $definitions[0]['config_path']);
    }

    public function test_standard_resolution_uses_source_specific_synced_assignment_before_config(): void
    {
        $sourceConfigPath = 'messaging.email.definitions.transactional.webinar.confirmation';

        Config::set($sourceConfigPath, [
            'key' => 'confirmation',
            'dispatch_key' => 'registration_created',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'payload' => [
                'subject' => 'Config subject',
                'body' => 'Config body.',
            ],
        ]);

        $preset = MessageTemplatePreset::factory()->create([
            'key' => 'email.transactional.webinar.confirmation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['registration_created'],
            'payload' => [
                'subject' => 'Customized synced subject',
                'body' => 'Customized synced body.',
            ],
            'source_config_path' => $sourceConfigPath,
            'is_customized' => true,
            'customized_at' => now(),
        ]);

        MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->create([
                'surface' => 'webinar_registrations',
                'message_type' => 'confirmation',
                'source_config_path' => $sourceConfigPath,
                'meta' => [
                    'source' => 'config_sync',
                    'source_config_path' => $sourceConfigPath,
                ],
            ]);

        $definitions = app(MessageDefinitionResolver::class)->resolve(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $confirmationDefinitions = collect($definitions)
            ->where('key', 'confirmation')
            ->values();

        $this->assertCount(1, $confirmationDefinitions);
        $this->assertSame('Customized synced subject', $confirmationDefinitions[0]['payload']['subject']);
        $this->assertSame($sourceConfigPath, $confirmationDefinitions[0]['source_config_path']);
        $this->assertSame($preset->getKey(), data_get($confirmationDefinitions[0], 'meta.message_template_preset.id'));
    }

    public function test_manual_standard_assignment_overrides_source_specific_seed_assignment_for_same_message_type(): void
    {
        $sourceConfigPath = 'messaging.email.definitions.transactional.webinar.confirmation';

        Config::set($sourceConfigPath, [
            'key' => 'confirmation',
            'dispatch_key' => 'registration_created',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'payload' => [
                'subject' => 'Config subject',
                'body' => 'Config body.',
            ],
        ]);

        $seedPreset = MessageTemplatePreset::factory()->create([
            'key' => 'email.transactional.webinar.confirmation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['registration_created'],
            'payload' => [
                'subject' => 'Seed subject',
                'body' => 'Seed body.',
            ],
            'source_config_path' => $sourceConfigPath,
        ]);

        MessageTemplatePresetAssignment::factory()
            ->forPreset($seedPreset)
            ->create([
                'surface' => 'webinar_registrations',
                'message_type' => 'confirmation',
                'source_config_path' => $sourceConfigPath,
            ]);

        $manualPreset = MessageTemplatePreset::factory()->create([
            'key' => 'email.transactional.webinar.confirmation_manual',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['registration_created'],
            'payload' => [
                'subject' => 'Manual subject',
                'body' => 'Manual body.',
            ],
            'source_config_path' => $sourceConfigPath,
        ]);

        MessageTemplatePresetAssignment::factory()
            ->forPreset($manualPreset)
            ->create([
                'surface' => 'webinar_registrations',
                'message_type' => 'confirmation',
                'source_config_path' => null,
                'meta' => [
                    'source' => 'crm_assignment',
                ],
            ]);

        $definitions = app(MessageDefinitionResolver::class)->resolve(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $confirmationDefinitions = collect($definitions)
            ->where('key', 'confirmation')
            ->values();

        $this->assertCount(1, $confirmationDefinitions);
        $this->assertSame('Manual subject', $confirmationDefinitions[0]['payload']['subject']);
        $this->assertSame($manualPreset->getKey(), data_get($confirmationDefinitions[0], 'meta.message_template_preset.id'));
        $this->assertFalse(collect($definitions)->contains(
            fn (array $definition): bool => data_get($definition, 'payload.subject') === 'Seed subject',
        ));
    }

    public function test_source_specific_standard_assignments_preserve_multiple_list_definitions_and_semantic_keys(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'reminders' => [
                [
                    'key' => 'reminder_10_day',
                    'dispatch_key' => 'registration_created',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'reminders',
                    'payload' => [
                        'subject' => 'Config first reminder',
                        'body' => 'Config first body.',
                    ],
                ],
                [
                    'key' => 'reminder_1_day',
                    'dispatch_key' => 'registration_created',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'reminders',
                    'payload' => [
                        'subject' => 'Config second reminder',
                        'body' => 'Config second body.',
                    ],
                ],
            ],
        ]);

        foreach ([
            [
                'index' => 0,
                'key' => 'reminder_10_day',
                'subject' => 'DB first reminder',
            ],
            [
                'index' => 1,
                'key' => 'reminder_1_day',
                'subject' => 'DB second reminder',
            ],
        ] as $definition) {
            $sourceConfigPath = 'messaging.email.definitions.transactional.webinar.reminders.'.$definition['index'];

            $preset = MessageTemplatePreset::factory()->create([
                'key' => 'email.transactional.webinar.'.$definition['key'],
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'message_type' => 'reminder',
                'payload_class' => EmailPayload::class,
                'queue' => 'reminders',
                'dispatch_keys' => ['registration_created'],
                'payload' => [
                    'subject' => $definition['subject'],
                    'body' => $definition['subject'].' body.',
                ],
                'source_config_path' => $sourceConfigPath,
            ]);

            MessageTemplatePresetAssignment::factory()
                ->forPreset($preset)
                ->create([
                    'surface' => 'webinar_registrations',
                    'message_type' => 'reminder',
                    'source_config_path' => $sourceConfigPath,
                ]);
        }

        $definitions = app(MessageDefinitionResolver::class)->resolve(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $this->assertCount(2, $definitions);
        $this->assertEqualsCanonicalizing([
            'reminder_10_day',
            'reminder_1_day',
        ], collect($definitions)->pluck('key')->all());
        $this->assertEqualsCanonicalizing([
            'DB first reminder',
            'DB second reminder',
        ], collect($definitions)->pluck('payload.subject')->all());
    }

}



