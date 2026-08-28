<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Actions\PublishMessageTemplatePresetOverrideAction;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublishMessageTemplatePresetOverrideActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_copy_only_callers_preserve_existing_missing_field_behavior_until_it_is_explicitly_cleared(): void
    {
        $preset = MessageTemplatePreset::factory()->create([
            'key' => 'email.transactional.fixture.preserve-fallbacks',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'fixture',
            'message_type' => 'preserve_fallbacks',
            'payload_class' => EmailPayload::class,
            'payload' => [
                'subject' => 'Original subject',
                'body' => 'Original body.',
            ],
        ]);

        $action = app(PublishMessageTemplatePresetOverrideAction::class);

        $action->handle($preset, [
            'subject' => 'Hello {first_name}',
            'body' => 'First body.',
            'token_fallbacks' => [[
                'token' => 'first_name',
                'missing_behavior' => 'fallback_value',
                'fallback' => 'there',
            ]],
        ]);

        $action->handle($preset->fresh(), [
            'subject' => 'Updated {first_name}',
            'body' => 'Second body.',
        ]);

        $template = MessageTemplate::query()
            ->with('currentVersion')
            ->where('key', $preset->key)
            ->firstOrFail();

        $this->assertEquals([
            [
                'token' => 'first_name',
                'missing_behavior' => 'fallback_value',
                'fallback' => 'there',
            ],
        ], $template->currentVersion->payload()['token_fallbacks']);

        $action->handle($preset->fresh(), [
            'subject' => 'Updated {first_name}',
            'body' => 'Second body.',
            'token_fallbacks' => [],
        ]);

        $template->refresh()->load('currentVersion');

        $this->assertSame([], $template->currentVersion->payload()['token_fallbacks']);
    }
}