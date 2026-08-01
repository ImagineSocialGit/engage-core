<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Actions\SyncMessageTemplatePresetsAction;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MessageTemplateSyncVersionCutoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_publishes_versioned_templates_without_adding_duplicate_versions(): void
    {
        $this->setConfirmationDefinition('Original subject');

        $firstResult = app(SyncMessageTemplatePresetsAction::class)->handle();
        $secondResult = app(SyncMessageTemplatePresetsAction::class)->handle();

        $template = MessageTemplate::query()
            ->with('currentVersion')
            ->where('key', 'email.transactional.webinar.confirmation')
            ->firstOrFail();

        $this->assertSame(1, $firstResult['templates_created']);
        $this->assertSame(1, $firstResult['template_versions_created']);
        $this->assertSame(1, $secondResult['templates_updated']);
        $this->assertSame(1, $secondResult['template_versions_reused']);
        $this->assertSame(1, MessageTemplateVersion::query()->count());
        $this->assertSame('Original subject', $template->currentVersion?->subject);
        $this->assertEquals([
            'body' => 'Hi {first_name}.',
        ], $template->currentVersion?->content);

        $this->setConfirmationDefinition('Changed subject');

        $changedResult = app(SyncMessageTemplatePresetsAction::class)->handle();
        $template->refresh()->load('currentVersion');

        $this->assertSame(1, $changedResult['template_versions_created']);
        $this->assertSame(2, MessageTemplateVersion::query()->count());
        $this->assertSame(2, $template->currentVersion?->version);
        $this->assertSame('Changed subject', $template->currentVersion?->subject);
    }

    public function test_initial_cutover_preserves_existing_customized_legacy_copy(): void
    {
        $this->setConfirmationDefinition('Config subject');

        MessageTemplatePreset::query()->create([
            'key' => 'email.transactional.webinar.confirmation',
            'name' => 'Customized Confirmation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['registration_created'],
            'payload' => [
                'subject' => 'Customized subject',
                'body' => 'Customized body {first_name}.',
            ],
            'tokens' => ['first_name'],
            'status' => MessageTemplatePreset::STATUS_ACTIVE,
            'is_active' => true,
            'source' => 'config',
            'source_config_path' => 'messaging.email.definitions.transactional.webinar.default.confirmation',
            'is_customized' => true,
            'customized_at' => now()->subMinute(),
        ]);

        $result = app(SyncMessageTemplatePresetsAction::class)->handle();

        $template = MessageTemplate::query()
            ->with('currentVersion')
            ->where('key', 'email.transactional.webinar.confirmation')
            ->firstOrFail();

        $this->assertSame(1, $result['customized_skipped']);
        $this->assertTrue($template->is_customized);
        $this->assertSame('Customized Confirmation', $template->name);
        $this->assertSame('Customized subject', $template->currentVersion?->subject);
        $this->assertEquals([
            'body' => 'Customized body {first_name}.',
        ], $template->currentVersion?->content);
    }

    public function test_sync_projects_customized_versioned_copy_back_to_legacy_runtime_rows(): void
    {
        $this->setConfirmationDefinition('Config subject');
        app(SyncMessageTemplatePresetsAction::class)->handle();

        $template = MessageTemplate::query()
            ->where('key', 'email.transactional.webinar.confirmation')
            ->firstOrFail();
        $template->forceFill([
            'name' => 'Customized Canonical Confirmation',
            'description' => 'Canonical helper copy.',
            'is_customized' => true,
            'customized_at' => now(),
        ])->save();

        app(PublishMessageTemplateVersionAction::class)->handle($template, [
            'subject' => 'Canonical customized subject',
            'body' => 'Canonical customized body {first_name}.',
        ]);

        $preset = MessageTemplatePreset::query()
            ->where('key', $template->key)
            ->firstOrFail();
        $preset->forceFill([
            'payload' => [
                'subject' => 'Stale legacy subject',
                'body' => 'Stale legacy body.',
            ],
            'tokens' => [],
            'is_customized' => false,
            'customized_at' => null,
        ])->save();

        $result = app(SyncMessageTemplatePresetsAction::class)->handle();
        $preset->refresh();

        $this->assertSame(1, $result['templates_customized_skipped']);
        $this->assertTrue($preset->is_customized);
        $this->assertSame('Customized Canonical Confirmation', $preset->name);
        $this->assertSame('Canonical helper copy.', $preset->description);
        $this->assertSame('Canonical customized subject', $preset->payload['subject']);
        $this->assertSame('Canonical customized body {first_name}.', $preset->payload['body']);
        $this->assertEqualsCanonicalizing(['first_name'], $preset->tokens);
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