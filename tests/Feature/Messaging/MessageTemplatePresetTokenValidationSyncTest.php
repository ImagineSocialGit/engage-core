<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Actions\SyncMessageTemplatePresetsAction;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Tests\TestCase;

class MessageTemplatePresetTokenValidationSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('messaging.email.definitions', []);
        Config::set('messaging.sms.definitions', []);
    }

    public function test_config_sync_rejects_invalid_tokens_before_persisting_presets(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'confirmations' => [[
                'key' => 'confirmation',
                'dispatch_key' => 'registration_created',
                'payload_class' => EmailPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'subject' => 'Registered',
                    'body' => 'Continue here: {not_a_real_token}',
                ],
            ]],
        ]);

        try {
            app(SyncMessageTemplatePresetsAction::class)->handle();

            $this->fail('Expected invalid token config to block message template preset sync.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('{not_a_real_token}', $exception->getMessage());
        }

        $this->assertSame(0, MessageTemplatePreset::query()->count());
    }

    public function test_config_sync_uses_the_same_registry_backed_validation_and_token_extraction(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'confirmations' => [[
                'key' => 'confirmation',
                'dispatch_key' => 'registration_created',
                'payload_class' => EmailPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'subject' => 'Registered for {webinar_title}',
                    'body' => 'Hi {first_name}. Join: {webinar_join_url}',
                ],
            ]],
        ]);

        $result = app(SyncMessageTemplatePresetsAction::class)->handle();

        $this->assertSame(1, $result['created']);

        $preset = MessageTemplatePreset::query()
            ->where('key', 'email.transactional.webinar.confirmation')
            ->firstOrFail();

        $this->assertEqualsCanonicalizing([
            'webinar_title',
            'first_name',
            'webinar_join_url',
        ], $preset->tokens);
    }
}
