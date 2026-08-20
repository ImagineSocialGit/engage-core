<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Actions\SyncMessageTemplatePresetsAction;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCompositionLayer;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\MessageConfigValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Tests\TestCase;

class MessageTemplateCompositionValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_validation_accepts_render_slot_supplied_by_matching_composition_layer(): void
    {
        $this->configureWebinarDefinition(
            compositionUrl: '{webinar_join_url}',
        );

        $issues = app(MessageConfigValidator::class)->validateRoute(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $this->assertSame([], $issues);
    }

    public function test_config_validation_still_rejects_unknown_token_inside_inherited_composition(): void
    {
        $this->configureWebinarDefinition(
            compositionUrl: '{not_a_real_token}',
        );

        $issues = app(MessageConfigValidator::class)->validateRoute(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $this->assertContains(
            'Payload references unknown token [{not_a_real_token}].',
            array_column($issues, 'message'),
        );
    }

    public function test_preset_sync_validates_effective_payload_after_composition_and_rolls_back_invalid_content(): void
    {
        $this->configureWebinarDefinition(
            compositionUrl: '{not_a_real_token}',
        );

        try {
            app(SyncMessageTemplatePresetsAction::class)->handle();
            $this->fail('Expected composed token validation to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'Payload references unknown token [{not_a_real_token}].',
                $exception->getMessage(),
            );
        }

        $this->assertSame(0, MessageTemplateCompositionLayer::query()->count());
        $this->assertSame(0, MessageTemplatePreset::query()->count());
        $this->assertSame(0, MessageTemplate::query()->count());

        $this->configureWebinarDefinition(
            compositionUrl: '{webinar_join_url}',
        );

        $result = app(SyncMessageTemplatePresetsAction::class)->handle();

        $this->assertSame(1, $result['composition_layers_created']);

        $template = MessageTemplate::query()
            ->with('currentVersion')
            ->sole();
        $preset = MessageTemplatePreset::query()->sole();
        $payload = $template->currentVersion?->payload() ?? [];

        $this->assertSame('Join', data_get($payload, 'cta.label'));
        $this->assertSame('{webinar_join_url}', data_get($payload, 'cta.url'));
        $this->assertSame('Join here: {cta}', data_get($payload, 'body'));
        $this->assertContains('webinar_join_url', $preset->tokens ?? []);
    }

    private function configureWebinarDefinition(string $compositionUrl): void
    {
        Config::set('client.key', 'composition-validation-client');
        Config::set('modules.enabled', [
            'messaging',
            'webinars',
        ]);
        Config::set('messaging.composition.layers', [
            'confirmation_action' => [
                'scope_type' => 'family',
                'channel' => 'email',
                'family_key' => 'confirmation',
                'source_version' => 1,
                'payload' => [
                    'cta' => [
                        'label' => 'Join',
                        'url' => $compositionUrl,
                    ],
                ],
            ],
        ]);
        Config::set('messaging.email.definitions', [
            'transactional' => [
                'webinar' => [
                    'homebuyer-game-plan' => [
                        'confirmations' => [
                            [
                                'key' => 'confirmation',
                                'dispatch_key' => 'registration_created',
                                'message_type' => 'confirmation',
                                'payload_class' => EmailPayload::class,
                                'queue' => 'confirmation_messages',
                                'payload' => [
                                    'subject' => 'Registration confirmed',
                                    'body' => 'Join here: {cta}',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        Config::set('messaging.sms.definitions', []);
    }
}