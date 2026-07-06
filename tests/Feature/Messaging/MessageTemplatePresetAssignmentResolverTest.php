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
        Config::set('messaging.email.transactional.webinar', [
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
            'source_config_path' => 'messaging.email.transactional.webinar.confirmation',
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

    public function test_campaign_step_resolution_prefers_active_assignment_before_config(): void
    {
        Config::set('messaging.email.marketing.webinar_nurture', [
            'campaigns' => [
                'webinar_attended_nurture' => [
                    'steps' => [
                        1 => [
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
            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1',
        ]);

        MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->forCampaignStep('webinar_attended_nurture', 1)
            ->create();

        $definition = app(MessageDefinitionResolver::class)->resolveCampaignStep(
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar_nurture',
            campaignKey: 'webinar_attended_nurture',
            stepNumber: 1,
            dispatchKey: 'campaign_step_due',
        );

        $this->assertIsArray($definition);
        $this->assertSame('DB campaign subject', $definition['payload']['subject']);
        $this->assertSame('webinar_attended_nurture', $definition['campaign_key']);
        $this->assertSame(1, $definition['step']);
        $this->assertNull($definition['config_path']);
    }

    public function test_resolution_falls_back_to_config_when_no_assignment_exists(): void
    {
        Config::set('messaging.email.transactional.webinar', [
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
        ]);

        $definitions = app(MessageDefinitionResolver::class)->resolve(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $this->assertCount(1, $definitions);
        $this->assertSame('Config subject', $definitions[0]['payload']['subject']);
        $this->assertSame('messaging.email.transactional.webinar.confirmation', $definitions[0]['config_path']);
    }
}
