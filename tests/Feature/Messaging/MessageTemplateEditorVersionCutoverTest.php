<?php

namespace Tests\Feature\Messaging;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageTemplateEditorVersionCutoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_publishes_and_reads_the_current_immutable_template_version(): void
    {
        config()->set('modules.enabled', ['messaging']);

        $user = User::factory()->create();

        $preset = MessageTemplatePreset::factory()->create([
            'key' => 'email.transactional.fixture.primary',
            'name' => 'Fixture Template',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'fixture',
            'message_type' => 'fixture_primary',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['fixture_dispatched'],
            'payload' => [
                'subject' => 'Initial fixture subject',
                'body' => 'Initial fixture body.',
            ],
            'tokens' => [],
            'source' => 'config',
            'source_version' => 1,
            'is_customized' => false,
            'customized_at' => null,
        ]);

        MessageTemplateCatalogEntry::factory()
            ->forPreset($preset)
            ->create([
                'module_key' => 'messaging',
                'module_label' => 'Messaging',
                'surface' => 'message_templates',
                'group_key' => 'fixture:transactional:primary',
                'group_label' => 'Fixture Group',
                'item_key' => 'email.transactional.fixture.primary',
                'item_label' => 'Fixture Template',
                'item_order' => 0,
                'usage_type' => 'fixture',
            ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $payload = [
            'name' => 'Updated Fixture Template',
            'description' => 'Fixture helper text.',
            'payload' => [
                'subject' => 'Updated fixture subject',
                'body' => 'Updated fixture body.',
            ],
        ];

        $editorUrl = 'http://crm.'.config('app.root_domain').'/message-templates?'.http_build_query([
            'channel' => 'email',
            'purpose' => 'transactional',
            'module' => 'messaging',
            'group' => 'fixture:transactional:primary',
            'preset' => $preset->getKey(),
        ]);

        $this->actingAs($user)
            ->patch(
                'http://crm.'.config('app.root_domain').'/message-templates/'.$preset->getKey(),
                $payload,
            )
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $template = MessageTemplate::query()
            ->with('currentVersion')
            ->where('key', $preset->key)
            ->firstOrFail();

        $this->assertTrue($template->is_customized);
        $this->assertSame($user->getKey(), $template->currentVersion?->created_by);
        $this->assertSame(
            'Updated fixture subject',
            $template->currentVersion?->subject,
        );
        $this->assertEquals([
            'body' => 'Updated fixture body.',
        ], $template->currentVersion?->content);

        $this->actingAs($user)
            ->get($editorUrl)
            ->assertOk()
            ->assertViewHas(
                'selectedTemplate',
                fn (mixed $selectedTemplate): bool =>
                    $selectedTemplate instanceof MessageTemplate
                    && $selectedTemplate->is($template),
            )
            ->assertViewHas(
                'currentTemplateVersion',
                fn (mixed $currentVersion): bool =>
                    $currentVersion instanceof MessageTemplateVersion
                    && $currentVersion->is($template->currentVersion),
            )
            ->assertViewHas(
                'editablePayload',
                fn (mixed $editablePayload): bool =>
                    is_array($editablePayload)
                    && ($editablePayload['subject'] ?? null) === 'Updated fixture subject'
                    && ($editablePayload['body'] ?? null) === 'Updated fixture body.',
            )
            ->assertViewHas(
                'tokens',
                fn (mixed $tokens): bool =>
                    is_array($tokens)
                    && $tokens === [],
            );

        $this->actingAs($user)
            ->patch(
                'http://crm.'.config('app.root_domain').'/message-templates/'.$preset->getKey(),
                $payload,
            )
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(1, MessageTemplateVersion::query()->count());

        $payload['payload']['body'] = 'Changed fixture body.';

        $this->actingAs($user)
            ->patch(
                'http://crm.'.config('app.root_domain').'/message-templates/'.$preset->getKey(),
                $payload,
            )
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $template->refresh()->load('currentVersion');

        $this->assertSame(2, MessageTemplateVersion::query()->count());
        $this->assertSame(2, $template->currentVersion?->version);
        $this->assertEquals([
            'body' => 'Changed fixture body.',
        ], $template->currentVersion?->content);
    }
}