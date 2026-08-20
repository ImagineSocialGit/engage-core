<?php

namespace Tests\Feature\Messaging;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Actions\UpsertMessageTemplateCompositionLayerAction;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplateCompositionLayer;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Payloads\SmsPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageTemplateCompositionEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_library_exposes_shared_content_and_exact_published_preview(): void
    {
        config()->set('modules.enabled', ['messaging']);
        config()->set('client.key', 'fixture-client');

        $user = User::factory()->create();
        [$preset, $template] = $this->message('fixture.reminder.one', 'Reminder One', 'Source message');
        $layer = app(UpsertMessageTemplateCompositionLayerAction::class)->handle(
            scopeType: MessageTemplateCompositionLayer::SCOPE_FAMILY,
            channel: 'sms',
            payload: ['message' => 'Shared reminder'],
            clientKey: 'fixture-client',
            familyKey: 'reminder',
            source: 'config',
            isCustomized: false,
        );
        $preset->forceFill(['payload' => []])->save();
        app(PublishMessageTemplateVersionAction::class)->handle($template, []);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/message-templates?preset='.$preset->getKey())
            ->assertOk()
            ->assertSee('Shared content')
            ->assertSee('Impact review')
            ->assertSee('Exact published preview')
            ->assertSee('Shared reminder')
            ->assertSee(route('crm.messaging.message-templates.composition-layers.update', $layer));
    }

    public function test_message_edit_stores_only_delta_and_matching_baseline_clears_override(): void
    {
        config()->set('modules.enabled', ['messaging']);
        config()->set('client.key', 'fixture-client');

        $user = User::factory()->create();
        [$preset, $template] = $this->message('fixture.reminder.delta', 'Reminder Delta', 'Base message');
        app(PublishMessageTemplateVersionAction::class)->handle($template, $preset->payload);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->patch('http://crm.'.config('app.root_domain').'/message-templates/'.$preset->getKey(), [
                'payload' => ['message' => 'Custom message'],
            ])
            ->assertRedirect();

        $preset->refresh();
        $template->refresh()->load('currentVersion');
        $override = MessageTemplateCompositionLayer::query()
            ->where('scope_type', MessageTemplateCompositionLayer::SCOPE_MESSAGE)
            ->where('message_template_id', $template->getKey())
            ->sole();

        $this->assertSame(['message' => 'Base message'], $preset->payload);
        $this->assertFalse($preset->is_customized);
        $this->assertTrue($template->is_customized);
        $this->assertNotNull($template->customized_at);
        $this->assertSame(['message' => 'Custom message'], $override->payload);
        $this->assertSame('Custom message', $template->currentVersion->payload()['message']);

        $this->actingAs($user)
            ->patch('http://crm.'.config('app.root_domain').'/message-templates/'.$preset->getKey(), [
                'payload' => ['message' => 'Base message'],
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('message_template_composition_layers', [
            'scope_type' => MessageTemplateCompositionLayer::SCOPE_MESSAGE,
            'message_template_id' => $template->getKey(),
        ]);

        $template->refresh()->load('currentVersion');
        $this->assertFalse($template->is_customized);
        $this->assertNull($template->customized_at);
        $this->assertSame('Base message', $template->currentVersion->payload()['message']);
    }

    public function test_shared_layer_publish_republishes_inheriting_messages_without_materializing_source_copy(): void
    {
        config()->set('modules.enabled', ['messaging']);
        config()->set('client.key', 'fixture-client');

        $user = User::factory()->create();
        [$firstPreset, $firstTemplate] = $this->message('fixture.reminder.first', 'First Reminder', 'Temporary');
        [$secondPreset, $secondTemplate] = $this->message('fixture.reminder.second', 'Second Reminder', 'Temporary');
        $firstPreset->forceFill(['payload' => []])->save();
        $secondPreset->forceFill(['payload' => []])->save();

        $layer = app(UpsertMessageTemplateCompositionLayerAction::class)->handle(
            scopeType: MessageTemplateCompositionLayer::SCOPE_FAMILY,
            channel: 'sms',
            payload: ['message' => 'Shared old'],
            clientKey: 'fixture-client',
            familyKey: 'reminder',
            source: 'config',
            isCustomized: false,
        );

        $publisher = app(PublishMessageTemplateVersionAction::class);
        $oldFirst = $publisher->handle($firstTemplate, []);
        $oldSecond = $publisher->handle($secondTemplate, []);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->patch(route('crm.messaging.message-templates.composition-layers.update', $layer), [
                'payload' => ['message' => 'Shared new'],
            ])
            ->assertRedirect();

        $layer->refresh();
        $firstTemplate->refresh()->load('currentVersion');
        $secondTemplate->refresh()->load('currentVersion');

        $this->assertTrue($layer->is_customized);
        $this->assertSame('Shared new', $layer->payload['message']);
        $this->assertSame([], $firstPreset->refresh()->payload);
        $this->assertSame([], $secondPreset->refresh()->payload);
        $this->assertSame('Shared new', $firstTemplate->currentVersion->payload()['message']);
        $this->assertSame('Shared new', $secondTemplate->currentVersion->payload()['message']);
        $this->assertNotSame($oldFirst->getKey(), $firstTemplate->current_version_id);
        $this->assertNotSame($oldSecond->getKey(), $secondTemplate->current_version_id);
        $this->assertSame('Shared old', $oldFirst->fresh()->payload()['message']);
        $this->assertSame('Shared old', $oldSecond->fresh()->payload()['message']);
    }

    /** @return array{MessageTemplatePreset, MessageTemplate} */
    private function message(string $key, string $name, string $message): array
    {
        $preset = MessageTemplatePreset::factory()->create([
            'key' => $key,
            'name' => $name,
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'fixture',
            'message_type' => 'reminder',
            'payload_class' => SmsPayload::class,
            'queue' => 'notifications',
            'dispatch_keys' => ['fixture_dispatched'],
            'payload' => ['message' => $message],
            'tokens' => [],
        ]);

        MessageTemplateCatalogEntry::factory()
            ->forPreset($preset)
            ->create([
                'module_key' => 'messaging',
                'module_label' => 'Messaging',
                'surface' => 'message_templates',
                'group_key' => 'fixture:reminders',
                'group_label' => 'Fixture Reminders',
                'item_key' => $key,
                'item_label' => $name,
                'item_order' => 10,
                'usage_type' => 'fixture',
            ]);

        $template = MessageTemplate::query()->create([
            'key' => $key,
            'name' => $name,
            'channel' => 'sms',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'composition_family_key' => 'reminder',
            'source' => 'test',
        ]);

        return [$preset, $template];
    }
}