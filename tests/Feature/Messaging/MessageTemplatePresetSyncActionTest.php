<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Actions\SyncMessageTemplatePresetsAction;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Tests\TestCase;

class MessageTemplatePresetSyncActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_syncs_message_configs_into_presets_assignments_and_variant_campaign_catalog_entries(): void
    {
        Config::set('messaging.sms', []);
        Config::set('messaging.email', [
            'transactional' => [
                'webinar' => [
                    'confirmation' => [
                        'dispatch_key' => 'registration_created',
                        'payload_class' => EmailPayload::class,
                        'queue' => 'confirmation_messages',
                        'payload' => [
                            'subject' => 'Registered {first_name}',
                            'body' => 'Thanks for registering.',
                        ],
                    ],
                ],
            ],
            'marketing' => [
                'webinar_nurture' => [
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
                                                'subject' => 'Thanks for joining',
                                                'body' => 'Hi {first_name}, reply with your biggest question.',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $result = app(SyncMessageTemplatePresetsAction::class)->handle();

        $this->assertSame(2, $result['created']);
        $this->assertSame(2, $result['assignments_created']);
        $this->assertSame(2, $result['catalog_entries_created']);

        $confirmation = MessageTemplatePreset::query()
            ->where('key', 'email.transactional.webinar.confirmation')
            ->firstOrFail();

        $this->assertSame('email', $confirmation->channel);
        $this->assertSame('transactional', $confirmation->purpose);
        $this->assertSame('webinar', $confirmation->scope);
        $this->assertSame('confirmation', $confirmation->message_type);
        $this->assertSame(['registration_created'], $confirmation->dispatch_keys);
        $this->assertSame(['first_name'], $confirmation->tokens);
        $this->assertSame('messaging.email.transactional.webinar.confirmation', $confirmation->source_config_path);

        $campaignVariant = MessageTemplatePreset::query()
            ->where('key', 'email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email')
            ->firstOrFail();

        $this->assertSame('webinar_attended_nurture_step_1', $campaignVariant->message_type);
        $this->assertSame('messaging.email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email', $campaignVariant->source_config_path);

        $this->assertDatabaseHas('message_template_catalog_entries', [
            'message_template_preset_id' => $campaignVariant->getKey(),
            'module_key' => 'campaigns',
            'module_label' => 'Campaigns',
            'group_label' => 'Webinar Attended Nurture',
            'item_label' => 'Step 1 Email',
            'item_order' => 1,
            'usage_type' => 'campaign_step',
            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email',
        ]);

        $this->assertDatabaseHas('message_template_preset_assignments', [
            'message_template_preset_id' => $campaignVariant->getKey(),
            'surface' => 'campaigns',
            'campaign_key' => 'webinar_attended_nurture',
            'campaign_step' => 1,
            'campaign_step_variant_key' => 'email',
            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email',
            'is_active' => true,
        ]);
    }

    public function test_it_rejects_legacy_step_level_campaign_message_templates(): void
    {
        Config::set('messaging.sms', []);
        Config::set('messaging.email', [
            'marketing' => [
                'webinar_nurture' => [
                    'campaigns' => [
                        'webinar_attended_nurture' => [
                            'steps' => [
                                1 => [
                                    'dispatch_key' => 'campaign_step_due',
                                    'payload_class' => EmailPayload::class,
                                    'queue' => 'marketing',
                                    'payload' => [
                                        'subject' => 'Legacy subject',
                                        'body' => 'Legacy body.',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must define variant templates under [variants]');

        app(SyncMessageTemplatePresetsAction::class)->handle();
    }

    public function test_it_keeps_list_based_message_types_generic_and_distinguishes_by_source_config_path(): void
    {
        Config::set('messaging.sms', []);
        Config::set('messaging.email', [
            'transactional' => [
                'webinar' => [
                    'reminders' => [
                        [
                            'key' => 'webinar_reminder_1_day',
                            'dispatch_key' => 'registration_created',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'reminders',
                            'payload' => [
                                'subject' => 'Tomorrow',
                                'body' => 'Starts tomorrow.',
                            ],
                        ],
                        [
                            'key' => 'webinar_reminder_30_minute',
                            'dispatch_key' => 'registration_created',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'reminders',
                            'payload' => [
                                'subject' => 'Soon',
                                'body' => 'Starts soon.',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $result = app(SyncMessageTemplatePresetsAction::class)->handle();

        $this->assertSame(2, $result['created']);
        $this->assertSame(2, $result['assignments_created']);

        $presets = MessageTemplatePreset::query()
            ->where('scope', 'webinar')
            ->orderBy('source_config_path')
            ->get();

        $this->assertSame([
            'messaging.email.transactional.webinar.reminders.0',
            'messaging.email.transactional.webinar.reminders.1',
        ], $presets->pluck('source_config_path')->all());

        $this->assertSame([
            'email.transactional.webinar.webinar_reminder_1_day',
            'email.transactional.webinar.webinar_reminder_30_minute',
        ], $presets->pluck('key')->all());

        $this->assertSame(['reminder', 'reminder'], $presets->pluck('message_type')->all());
        $this->assertDatabaseMissing('message_template_presets', ['message_type' => 'reminder_1_day']);
        $this->assertDatabaseMissing('message_template_presets', ['message_type' => 'reminder_30_minute']);

        $this->assertDatabaseHas('message_template_preset_assignments', [
            'message_template_preset_id' => $presets[0]->getKey(),
            'message_type' => 'reminder',
        ]);
        $this->assertDatabaseHas('message_template_preset_assignments', [
            'message_template_preset_id' => $presets[1]->getKey(),
            'message_type' => 'reminder',
        ]);
    }

    public function test_it_does_not_overwrite_customized_presets_unless_forced(): void
    {
        Config::set('messaging.sms', []);
        Config::set('messaging.email', [
            'transactional' => [
                'webinar' => [
                    'confirmation' => [
                        'dispatch_key' => 'registration_created',
                        'payload_class' => EmailPayload::class,
                        'queue' => 'confirmation_messages',
                        'payload' => [
                            'subject' => 'Original subject',
                            'body' => 'Original body.',
                        ],
                    ],
                ],
            ],
        ]);

        app(SyncMessageTemplatePresetsAction::class)->handle();

        $preset = MessageTemplatePreset::query()
            ->where('key', 'email.transactional.webinar.confirmation')
            ->firstOrFail();

        $preset->forceFill([
            'payload' => [
                'subject' => 'Customized subject',
                'body' => 'Customized body.',
            ],
            'is_customized' => true,
            'customized_at' => now(),
        ])->save();

        Config::set('messaging.email.transactional.webinar.confirmation.payload.subject', 'Changed config subject');

        $result = app(SyncMessageTemplatePresetsAction::class)->handle();

        $this->assertSame(1, $result['customized_skipped']);
        $this->assertSame('Customized subject', $preset->refresh()->payload['subject']);

        app(SyncMessageTemplatePresetsAction::class)->handle(force: true);

        $preset->refresh();

        $this->assertSame('Changed config subject', $preset->payload['subject']);
        $this->assertFalse($preset->is_customized);
        $this->assertNull($preset->customized_at);
    }

    public function test_normal_sync_preserves_existing_assignment_for_same_context(): void
    {
        Config::set('messaging.sms', []);
        Config::set('messaging.email', [
            'transactional' => [
                'webinar' => [
                    'confirmation' => [
                        'dispatch_key' => 'registration_created',
                        'payload_class' => EmailPayload::class,
                        'queue' => 'confirmation_messages',
                        'payload' => [
                            'subject' => 'Config subject',
                            'body' => 'Config body.',
                        ],
                    ],
                ],
            ],
        ]);

        app(SyncMessageTemplatePresetsAction::class)->handle();

        $configPreset = MessageTemplatePreset::query()
            ->where('key', 'email.transactional.webinar.confirmation')
            ->firstOrFail();

        $customPreset = MessageTemplatePreset::factory()->create([
            'key' => 'email.transactional.webinar.confirmation.custom',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['registration_created'],
            'payload' => [
                'subject' => 'Custom subject',
                'body' => 'Custom body.',
            ],
        ]);

        $assignment = $configPreset->assignments()->firstOrFail();
        $assignment->forceFill([
            'message_template_preset_id' => $customPreset->getKey(),
        ])->save();

        $result = app(SyncMessageTemplatePresetsAction::class)->handle();

        $this->assertSame(1, $result['assignments_preserved']);
        $this->assertSame($customPreset->getKey(), $assignment->refresh()->message_template_preset_id);
        $this->assertDatabaseCount('message_template_preset_assignments', 1);
    }

    public function test_force_sync_repoints_existing_assignment_to_config_preset(): void
    {
        Config::set('messaging.sms', []);
        Config::set('messaging.email', [
            'transactional' => [
                'webinar' => [
                    'confirmation' => [
                        'dispatch_key' => 'registration_created',
                        'payload_class' => EmailPayload::class,
                        'queue' => 'confirmation_messages',
                        'payload' => [
                            'subject' => 'Config subject',
                            'body' => 'Config body.',
                        ],
                    ],
                ],
            ],
        ]);

        app(SyncMessageTemplatePresetsAction::class)->handle();

        $configPreset = MessageTemplatePreset::query()
            ->where('key', 'email.transactional.webinar.confirmation')
            ->firstOrFail();

        $customPreset = MessageTemplatePreset::factory()->create([
            'key' => 'email.transactional.webinar.confirmation.custom',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['registration_created'],
        ]);

        $assignment = $configPreset->assignments()->firstOrFail();
        $assignment->forceFill([
            'message_template_preset_id' => $customPreset->getKey(),
            'is_active' => false,
        ])->save();

        $result = app(SyncMessageTemplatePresetsAction::class)->handle(force: true);

        $this->assertSame(1, $result['assignments_updated']);
        $this->assertSame($configPreset->getKey(), $assignment->refresh()->message_template_preset_id);
        $this->assertTrue($assignment->is_active);
        $this->assertDatabaseCount('message_template_preset_assignments', 1);
    }


    public function test_it_syncs_multiple_reminders_as_separate_presets_without_schedule_specific_message_types(): void
    {
        Config::set('messaging.sms', []);
        Config::set('messaging.email', [
            'transactional' => [
                'webinar' => [
                    'reminders' => [
                        [
                            'key' => 'webinar_reminder_30_minute',
                            'dispatch_key' => 'registration_created',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'reminders',
                            'payload' => [
                                'subject' => '30 minutes',
                                'body' => 'Join soon, {first_name}.',
                            ],
                        ],
                        [
                            'key' => 'webinar_reminder_10_minute',
                            'dispatch_key' => 'registration_created',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'reminders',
                            'payload' => [
                                'subject' => '10 minutes',
                                'body' => 'Join now, {first_name}.',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $result = app(SyncMessageTemplatePresetsAction::class)->handle();

        $this->assertSame(2, $result['created']);
        $this->assertSame(2, $result['assignments_created']);
        $this->assertSame(2, $result['catalog_entries_created']);

        $this->assertDatabaseHas('message_template_presets', [
            'key' => 'email.transactional.webinar.webinar_reminder_30_minute',
            'message_type' => 'reminder',
            'name' => 'Webinar Reminders — Reminder Email',
            'source_config_path' => 'messaging.email.transactional.webinar.reminders.0',
        ]);

        $this->assertDatabaseHas('message_template_presets', [
            'key' => 'email.transactional.webinar.webinar_reminder_10_minute',
            'message_type' => 'reminder',
            'name' => 'Webinar Reminders — Reminder 2 Email',
            'source_config_path' => 'messaging.email.transactional.webinar.reminders.1',
        ]);

        $this->assertDatabaseMissing('message_template_presets', [
            'message_type' => 'reminder_30_minute',
        ]);

        $this->assertDatabaseMissing('message_template_presets', [
            'message_type' => 'reminder_10_minute',
        ]);

        $this->assertDatabaseHas('message_template_catalog_entries', [
            'group_label' => 'Webinar Reminders',
            'item_label' => 'Reminder Email',
            'item_order' => 0,
            'usage_type' => 'webinar_reminder',
            'source_config_path' => 'messaging.email.transactional.webinar.reminders.0',
        ]);

        $this->assertDatabaseHas('message_template_catalog_entries', [
            'group_label' => 'Webinar Reminders',
            'item_label' => 'Reminder 2 Email',
            'item_order' => 1,
            'usage_type' => 'webinar_reminder',
            'source_config_path' => 'messaging.email.transactional.webinar.reminders.1',
        ]);
    }

}
