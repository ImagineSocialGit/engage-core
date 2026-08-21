<?php

namespace Tests\Feature\Messaging;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageTemplateCarouselEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_template_family_uses_the_canonical_published_preview_and_inline_editor_carousel(): void
    {
        config()->set('modules.enabled', ['messaging']);

        $user = User::factory()->create();
        $first = $this->emailPreset(
            key: 'email.transactional.fixture.carousel.first',
            name: 'First Email',
            subject: 'Published first subject',
            body: 'Published first body.',
            order: 10,
        );
        $second = $this->emailPreset(
            key: 'email.transactional.fixture.carousel.second',
            name: 'Second Email',
            subject: 'Published second subject',
            body: 'Published second body.',
            order: 20,
        );

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/message-templates?group=fixture:carousel')
            ->assertOk()
            ->assertViewIs('crm.messaging.message-templates.index')
            ->assertViewHas('messageLibrary', function (mixed $library) use ($first, $second): bool {
                if (! is_array($library)) {
                    return false;
                }

                $messages = $library['channels']['email']['messages'] ?? [];

                return ($library['message_count'] ?? null) === 2
                    && count($messages) === 2
                    && ($messages[0]['preset_id'] ?? null) === $first->getKey()
                    && ($messages[1]['preset_id'] ?? null) === $second->getKey()
                    && ($messages[0]['payload']['subject'] ?? null) === 'Published first subject'
                    && ($messages[1]['payload']['subject'] ?? null) === 'Published second subject';
            })
            ->assertSee('data-message-editor-carousel', false)
            ->assertSee('data-message-editor-mode', false)
            ->assertSee('data-message-editor-channel="email"', false)
            ->assertSee('data-message-editor-published-preview', false)
            ->assertSee('data-message-editor-form', false)
            ->assertSee('Published first subject')
            ->assertSee('Published second subject')
            ->assertSee('Save &amp; publish', false)
            ->assertDontSee('payload_class');
    }

    private function emailPreset(
        string $key,
        string $name,
        string $subject,
        string $body,
        int $order,
    ): MessageTemplatePreset {
        $preset = MessageTemplatePreset::factory()->create([
            'key' => $key,
            'name' => $name,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'fixture',
            'message_type' => 'fixture_carousel_'.($order / 10),
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['fixture_dispatched'],
            'payload' => [
                'subject' => $subject,
                'body' => $body,
            ],
            'tokens' => [],
            'source_config_path' => 'messaging.email.definitions.transactional.fixture.carousel.'.$order,
        ]);

        MessageTemplateCatalogEntry::factory()
            ->forPreset($preset)
            ->create([
                'module_key' => 'messaging',
                'module_label' => 'Messaging',
                'surface' => 'message_templates',
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'fixture',
                'group_key' => 'fixture:carousel',
                'group_label' => 'Fixture Carousel',
                'item_key' => $key,
                'item_label' => $name,
                'item_order' => $order,
                'usage_type' => 'fixture',
            ]);

        $template = MessageTemplate::query()->create([
            'key' => $key,
            'name' => $name,
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
        ]);

        app(PublishMessageTemplateVersionAction::class)->handle(
            messageTemplate: $template,
            payload: [
                'subject' => $subject,
                'body' => $body,
            ],
        );

        return $preset;
    }
}