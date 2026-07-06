<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Actions\SyncMessageTemplatePresetsAction;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MessageTemplatePresetSyncActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_syncs_message_configs_into_presets_and_assignments(): void
    {
        Config::set('messaging.sms', []);
        Config::set('messaging.email', [
            'transactional' => [
                'webinar' => [
                    'confirmation' => [
                        'dispatch_key' => 'registration_created',
                        'timing' => 'immediate',
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
        ]);

        $result = app(SyncMessageTemplatePresetsAction::class)->handle();

        $this->assertSame(2, $result['created']);
        $this->assertSame(2, $result['assignments_created']);

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

        $this->assertDatabaseHas('message_template_preset_assignments', [
            'message_template_preset_id' => $confirmation->getKey(),
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'campaign_key' => null,
            'campaign_step' => null,
            'is_active' => true,
        ]);

        $campaignStep = MessageTemplatePreset::query()
            ->where('key', 'email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1')
            ->firstOrFail();

        $this->assertSame('webinar_attended_nurture_step_1', $campaignStep->message_type);

        $this->assertDatabaseHas('message_template_preset_assignments', [
            'message_template_preset_id' => $campaignStep->getKey(),
            'surface' => 'campaigns',
            'campaign_key' => 'webinar_attended_nurture',
            'campaign_step' => 1,
            'is_active' => true,
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
                        'timing' => 'immediate',
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
                        'timing' => 'immediate',
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
                        'timing' => 'immediate',
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

}
