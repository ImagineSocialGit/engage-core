<?php

namespace Tests\Feature\Messaging;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageTemplateTokenValidationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_ui_template_edit_rejects_unknown_tokens_before_persisting(): void
    {
        config()->set('modules.enabled', [
            'messaging',
            'webinars',
        ]);

        $user = User::factory()->create();

        $preset = MessageTemplatePreset::factory()->create([
            'name' => 'Webinar Confirmation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['registration_created'],
            'payload' => [
                'subject' => 'You are registered',
                'body' => 'Old valid body.',
            ],
            'tokens' => [],
            'is_customized' => false,
            'customized_at' => null,
        ]);

        MessageTemplateCatalogEntry::factory()
            ->forPreset($preset)
            ->create([
                'module_key' => 'webinars',
                'module_label' => 'Webinars',
                'surface' => 'webinar_registrations',
                'group_key' => 'webinars:transactional:webinar:confirmation',
                'group_label' => 'Webinar Confirmations',
                'item_key' => 'email.transactional.webinar.confirmation',
                'item_label' => 'Confirmation Email',
                'item_order' => 0,
                'usage_type' => 'webinar_confirmation',
            ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $response = $this->actingAs($user)
            ->from('http://crm.'.config('app.root_domain').'/message-templates')
            ->patch(
                'http://crm.'.config('app.root_domain').'/message-templates/'.$preset->getKey(),
                [
                    'name' => 'Webinar Confirmation',
                    'description' => null,
                    'payload' => [
                        'subject' => 'You are registered',
                        'body' => 'Continue here: {not_a_real_token}',
                    ],
                ],
            );

        $response->assertRedirect();
        $response->assertSessionHasErrors('payload.body');

        $preset->refresh();

        $this->assertSame('Old valid body.', $preset->payload['body']);
        $this->assertFalse($preset->is_customized);
        $this->assertNull($preset->customized_at);
    }

    public function test_ui_template_edit_rejects_registered_tokens_unavailable_for_the_template_context(): void
    {
        config()->set('modules.enabled', [
            'messaging',
            'webinars',
        ]);

        $user = User::factory()->create();

        $preset = MessageTemplatePreset::factory()->create([
            'name' => 'Webinar Confirmation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['registration_created'],
            'payload' => [
                'subject' => 'You are registered',
                'body' => 'Old valid body.',
            ],
            'tokens' => [],
            'is_customized' => false,
            'customized_at' => null,
        ]);

        MessageTemplateCatalogEntry::factory()
            ->forPreset($preset)
            ->create([
                'module_key' => 'webinars',
                'module_label' => 'Webinars',
                'surface' => 'webinar_registrations',
                'group_key' => 'webinars:transactional:webinar:confirmation',
                'group_label' => 'Webinar Confirmations',
                'item_key' => 'email.transactional.webinar.confirmation',
                'item_label' => 'Confirmation Email',
                'item_order' => 0,
                'usage_type' => 'webinar_confirmation',
            ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $response = $this->actingAs($user)
            ->from('http://crm.'.config('app.root_domain').'/message-templates')
            ->patch(
                'http://crm.'.config('app.root_domain').'/message-templates/'.$preset->getKey(),
                [
                    'name' => 'Webinar Confirmation',
                    'description' => null,
                    'payload' => [
                        'subject' => 'You are registered',
                        'body' => 'Replay: {webinar_playback_url}',
                    ],
                ],
            );

        $response->assertRedirect();
        $response->assertSessionHasErrors('payload.body');

        $preset->refresh();

        $this->assertSame('Old valid body.', $preset->payload['body']);
        $this->assertFalse($preset->is_customized);
    }
}
