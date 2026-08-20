<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Actions\SyncMessageTemplatePresetsAction;
use App\Modules\Messaging\Actions\UpsertMessageTemplateCompositionLayerAction;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCompositionLayer;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MessageTemplateCompositionCustomizationSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_sync_preserves_message_override_without_materializing_it_into_source_preset(): void
    {
        $this->setConfirmationDefinition('Source subject');
        app(SyncMessageTemplatePresetsAction::class)->handle();

        $preset = MessageTemplatePreset::query()
            ->where('key', 'email.transactional.webinar.confirmation')
            ->firstOrFail();
        $template = MessageTemplate::query()
            ->where('key', $preset->key)
            ->firstOrFail();

        $override = app(UpsertMessageTemplateCompositionLayerAction::class)->handle(
            scopeType: MessageTemplateCompositionLayer::SCOPE_MESSAGE,
            channel: 'email',
            payload: ['subject' => 'CRM override subject'],
            messageTemplate: $template,
            source: 'crm',
            isCustomized: true,
        );

        $template->forceFill([
            'is_customized' => true,
            'customized_at' => $override->customized_at ?? now(),
        ])->save();

        $this->setConfirmationDefinition('Changed source subject');
        app(SyncMessageTemplatePresetsAction::class)->handle();

        $preset->refresh();
        $template->refresh()->load('currentVersion');

        $this->assertFalse($preset->is_customized);
        $this->assertNull($preset->customized_at);
        $this->assertSame('Changed source subject', $preset->payload['subject']);
        $this->assertTrue($template->is_customized);
        $this->assertNotNull($template->customized_at);
        $this->assertSame('CRM override subject', $template->currentVersion?->subject);
        $this->assertSame('Hi {first_name}.', $template->currentVersion?->content['body'] ?? null);
        $this->assertDatabaseHas('message_template_composition_layers', [
            'id' => $override->getKey(),
            'scope_type' => MessageTemplateCompositionLayer::SCOPE_MESSAGE,
            'message_template_id' => $template->getKey(),
        ]);
    }

    public function test_force_sync_clears_message_override_and_returns_template_to_source_copy(): void
    {
        $this->setConfirmationDefinition('Source subject');
        app(SyncMessageTemplatePresetsAction::class)->handle();

        $template = MessageTemplate::query()
            ->where('key', 'email.transactional.webinar.confirmation')
            ->firstOrFail();

        $override = app(UpsertMessageTemplateCompositionLayerAction::class)->handle(
            scopeType: MessageTemplateCompositionLayer::SCOPE_MESSAGE,
            channel: 'email',
            payload: ['subject' => 'CRM override subject'],
            messageTemplate: $template,
            source: 'crm',
            isCustomized: true,
        );

        $template->forceFill([
            'is_customized' => true,
            'customized_at' => $override->customized_at ?? now(),
        ])->save();

        app(SyncMessageTemplatePresetsAction::class)->handle(force: true);

        $template->refresh()->load('currentVersion');

        $this->assertFalse($template->is_customized);
        $this->assertNull($template->customized_at);
        $this->assertSame('Source subject', $template->currentVersion?->subject);
        $this->assertDatabaseMissing('message_template_composition_layers', [
            'scope_type' => MessageTemplateCompositionLayer::SCOPE_MESSAGE,
            'message_template_id' => $template->getKey(),
        ]);
    }

    private function setConfirmationDefinition(string $subject): void
    {
        Config::set('messaging.sms', []);
        Config::set('messaging.email.definitions', [
            'transactional' => [
                'webinar' => [
                    'default' => [
                        'confirmation' => [
                            'dispatch_key' => 'registration_created',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'confirmation_messages',
                            'payload' => [
                                'subject' => $subject,
                                'body' => 'Hi {first_name}.',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }
}