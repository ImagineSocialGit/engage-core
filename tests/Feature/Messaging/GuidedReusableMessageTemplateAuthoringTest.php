<?php

namespace Tests\Feature\Messaging;

use App\Models\User;
use App\Modules\Messaging\Contracts\ReusableMessageTemplateAuthoringOptionContributor;
use App\Modules\Messaging\Data\ReusableMessageTemplateAuthoringContext;
use App\Modules\Messaging\Data\ReusableMessageTemplateAuthoringOption;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\ReusableMessageTemplateAuthoringGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuidedReusableMessageTemplateAuthoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_guide_uses_server_registered_authoring_identity(): void
    {
        config()->set('modules.enabled', ['messaging']);

        $this->app->instance(
            ReusableMessageTemplateAuthoringGuide::class,
            new ReusableMessageTemplateAuthoringGuide([
                $this->contributor('test.general.email'),
            ]),
        );

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('crm.messaging.message-templates.create', ['use' => 'test.general.email']))
            ->assertOk()
            ->assertSee('data-reusable-message-authoring-option="test.general.email"', false)
            ->assertSee('data-create-reusable-message-template-submit', false);

        $response = $this->actingAs($user)->post(
            route('crm.messaging.message-templates.store'),
            [
                'authoring_option' => 'test.general.email',
                'name' => 'Operator reusable email',
                'subject' => 'Hello',
                'body' => 'Hi there.',
                'purpose' => 'internal',
                'scope' => 'forged',
                'queue' => 'webhooks',
                'module_key' => 'forged',
            ],
        );

        $preset = MessageTemplatePreset::query()
            ->where('name', 'Operator reusable email')
            ->sole();

        $response->assertRedirect(route('crm.messaging.message-templates.index', [
            'group' => 'test:general:email',
            'preset' => $preset->getKey(),
        ]));

        $this->assertSame('marketing', $preset->purpose);
        $this->assertSame('general', $preset->scope);
        $this->assertSame('marketing', $preset->queue);
        $this->assertSame('test_module', data_get($preset->meta, 'authoring.context_key'));

        $entry = MessageTemplateCatalogEntry::query()
            ->where('message_template_preset_id', $preset->getKey())
            ->sole();

        $this->assertSame('test', $entry->module_key);
        $this->assertSame('test:general:email', $entry->group_key);
    }

    public function test_unregistered_authoring_option_is_rejected_without_creating_a_template(): void
    {
        config()->set('modules.enabled', ['messaging']);
        $this->app->instance(
            ReusableMessageTemplateAuthoringGuide::class,
            new ReusableMessageTemplateAuthoringGuide([
                $this->contributor('test.general.email'),
            ]),
        );

        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('crm.messaging.message-templates.create'))
            ->post(route('crm.messaging.message-templates.store'), [
                'authoring_option' => 'forged.option',
                'name' => 'Should not exist',
                'subject' => 'Subject',
                'body' => 'Body',
            ])
            ->assertSessionHasErrors('authoring_option');

        $this->assertDatabaseMissing('message_template_presets', [
            'name' => 'Should not exist',
        ]);
    }

    private function contributor(string $key): ReusableMessageTemplateAuthoringOptionContributor
    {
        return new class($key) implements ReusableMessageTemplateAuthoringOptionContributor {
            public function __construct(private readonly string $key) {}

            public function options(): iterable
            {
                yield new ReusableMessageTemplateAuthoringOption(
                    key: $this->key,
                    label: 'Test reusable email',
                    description: 'Test authoring option.',
                    channel: 'email',
                    context: new ReusableMessageTemplateAuthoringContext(
                        contextKey: 'test_module',
                        purpose: 'marketing',
                        scope: 'general',
                        dispatchKey: 'flow_route_send_message',
                        messageType: 'test_message',
                        payloadClass: EmailPayload::class,
                        queue: 'marketing',
                        moduleKey: 'test',
                        moduleLabel: 'Test',
                        surface: 'route_send_message_points',
                        groupKey: 'test:general:email',
                        groupLabel: 'Test Messages',
                        usageType: 'test_reuse',
                        selectionContexts: ['test_module'],
                    ),
                );
            }
        };
    }
}