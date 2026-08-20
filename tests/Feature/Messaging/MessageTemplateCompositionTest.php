<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Actions\UpsertMessageTemplateCompositionLayerAction;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCompositionLayer;
use App\Modules\Messaging\Services\MessageTemplateCompositionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class MessageTemplateCompositionTest extends TestCase
{
    use RefreshDatabase;

    public function test_bounded_layers_resolve_in_specificity_order_without_recursive_array_merging(): void
    {
        config()->set('client.key', 'example-client');

        $template = MessageTemplate::query()->create([
            'key' => 'email.transactional.webinar.homebuyer.reminder',
            'name' => 'Homebuyer reminder',
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'composition_context_key' => 'homebuyer_game_plan',
            'composition_family_key' => 'reminder',
        ]);

        $upsert = app(UpsertMessageTemplateCompositionLayerAction::class);

        $upsert->handle(
            scopeType: MessageTemplateCompositionLayer::SCOPE_PLATFORM,
            channel: 'email',
            payload: [
                'footer' => 'Platform footer',
            ],
            isCustomized: false,
            source: 'platform',
        );

        $upsert->handle(
            scopeType: MessageTemplateCompositionLayer::SCOPE_CLIENT,
            channel: 'email',
            clientKey: 'example-client',
            payload: [
                'cta' => [
                    'label' => 'Client CTA',
                    'url' => 'https://example.test/client',
                ],
            ],
        );

        $upsert->handle(
            scopeType: MessageTemplateCompositionLayer::SCOPE_FAMILY,
            channel: 'email',
            clientKey: 'example-client',
            familyKey: 'reminder',
            payload: [
                'footer' => 'Reminder footer',
            ],
        );

        $upsert->handle(
            scopeType: MessageTemplateCompositionLayer::SCOPE_CONTEXT,
            channel: 'email',
            clientKey: 'example-client',
            contextKey: 'homebuyer_game_plan',
            payload: [
                'body' => 'Context body',
            ],
        );

        $upsert->handle(
            scopeType: MessageTemplateCompositionLayer::SCOPE_CONTEXT_FAMILY,
            channel: 'email',
            clientKey: 'example-client',
            contextKey: 'homebuyer_game_plan',
            familyKey: 'reminder',
            payload: [
                'cta' => [
                    'label' => 'Context reminder CTA',
                    'url' => 'https://example.test/context-reminder',
                ],
            ],
        );

        $upsert->handle(
            scopeType: MessageTemplateCompositionLayer::SCOPE_MESSAGE,
            channel: 'email',
            messageTemplate: $template,
            payload: [
                'subject' => 'Operator override subject',
                'footer' => null,
            ],
        );

        $resolved = app(MessageTemplateCompositionResolver::class)->resolve(
            messageTemplate: $template,
            sourcePayload: [
                'subject' => 'Source subject',
                'body' => 'Specific reminder body',
            ],
        );

        $this->assertEquals([
            'cta' => [
                'label' => 'Context reminder CTA',
                'url' => 'https://example.test/context-reminder',
            ],
            'body' => 'Specific reminder body',
            'subject' => 'Operator override subject',
        ], $resolved);
    }

    public function test_publishing_snapshots_resolved_content_and_does_not_mutate_history_after_shared_edits(): void
    {
        config()->set('client.key', 'example-client');

        $template = MessageTemplate::query()->create([
            'key' => 'email.transactional.example.reminder',
            'name' => 'Example reminder',
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'composition_family_key' => 'reminder',
        ]);

        $publisher = app(PublishMessageTemplateVersionAction::class);
        $upsert = app(UpsertMessageTemplateCompositionLayerAction::class);

        $first = $publisher->handle($template, [
            'subject' => 'Reminder',
            'body' => 'Original body',
        ]);

        $upsert->handle(
            scopeType: MessageTemplateCompositionLayer::SCOPE_FAMILY,
            channel: 'email',
            clientKey: 'example-client',
            familyKey: 'reminder',
            payload: [
                'footer' => 'Shared footer',
            ],
        );

        $second = $publisher->handle($template, [
            'subject' => 'Reminder',
            'body' => 'Original body',
        ]);

        $same = $publisher->handle($template, [
            'subject' => 'Reminder',
            'body' => 'Original body',
        ]);

        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertSame($second->getKey(), $same->getKey());
        $this->assertEquals([
            'subject' => 'Reminder',
            'body' => 'Original body',
        ], $first->fresh()->payload());
        $this->assertEquals([
            'subject' => 'Reminder',
            'body' => 'Original body',
            'footer' => 'Shared footer',
        ], $second->fresh()->payload());
    }

    public function test_schema_rejects_unbounded_payload_fields(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(UpsertMessageTemplateCompositionLayerAction::class)->handle(
            scopeType: MessageTemplateCompositionLayer::SCOPE_CLIENT,
            channel: 'email',
            clientKey: 'example-client',
            payload: [
                'arbitrary_fragment_tree' => ['anything' => 'goes'],
            ],
        );
    }

    public function test_schema_rejects_invalid_selector_combinations(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(UpsertMessageTemplateCompositionLayerAction::class)->handle(
            scopeType: MessageTemplateCompositionLayer::SCOPE_CONTEXT_FAMILY,
            channel: 'email',
            clientKey: 'example-client',
            familyKey: 'reminder',
            payload: [
                'body' => 'Missing required context selector.',
            ],
        );
    }
}