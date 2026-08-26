<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Actions\CreateReusableMessageTemplateAction;
use App\Modules\Messaging\Data\ReusableMessageTemplateAuthoringContext;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\RouteAuthoringMessageTemplateEligibilityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteAuthoringMessageTemplateEligibilityResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prefers_contextual_reusable_messages_and_preserves_legacy_explicit_opt_in(): void
    {
        config()->set('modules.enabled', ['workflow', 'flow_routes', 'messaging']);

        $contextual = app(CreateReusableMessageTemplateAction::class)->handle(
            name: 'Direct Route Follow-Up',
            channel: 'email',
            payload: ['subject' => 'Hello', 'body' => 'Hi {first_name}'],
            context: new ReusableMessageTemplateAuthoringContext(
                contextKey: RouteAuthoringMessageTemplateEligibilityResolver::SELECTION_CONTEXT,
                purpose: 'marketing',
                scope: 'general',
                dispatchKey: 'flow_route_send_message',
                messageType: 'flow_route_message',
                payloadClass: EmailPayload::class,
                queue: 'marketing',
                moduleKey: 'flow_routes',
                moduleLabel: 'Flow Routes',
                surface: 'route_send_message_points',
                groupKey: 'flow_routes:direct:marketing:email',
                groupLabel: 'Flow Route Messages',
                usageType: 'flow_route_direct',
                selectionContexts: ['flow_routes'],
            ),
        );

        $legacy = MessageTemplatePreset::factory()->create([
            'name' => 'Legacy Explicit Route Template',
            'purpose' => 'transactional',
            'scope' => 'general',
            'dispatch_keys' => ['legacy_route_message'],
            'status' => MessageTemplatePreset::STATUS_ACTIVE,
            'is_active' => true,
            'meta' => ['route_authoring' => ['eligible' => true]],
        ]);

        $lifecycle = MessageTemplatePreset::factory()->create([
            'name' => 'Webinar Reminder',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'dispatch_keys' => ['registration_created'],
            'status' => MessageTemplatePreset::STATUS_ACTIVE,
            'is_active' => true,
        ]);
        MessageTemplateCatalogEntry::factory()->create([
            'message_template_preset_id' => $lifecycle->getKey(),
            'module_key' => 'webinars',
            'surface' => 'webinar_registrations',
            'usage_type' => 'webinar_reminder',
            'is_active' => true,
        ]);

        $eligible = app(RouteAuthoringMessageTemplateEligibilityResolver::class)->eligiblePresets();

        $this->assertEqualsCanonicalizing(
            [$contextual->getKey(), $legacy->getKey()],
            $eligible->pluck('id')->all(),
        );
        $this->assertFalse($eligible->contains(fn (MessageTemplatePreset $candidate): bool => $candidate->is($lifecycle)));
    }

    public function test_internal_template_is_never_route_eligible_even_with_explicit_opt_in(): void
    {
        config()->set('modules.enabled', ['workflow', 'flow_routes', 'messaging']);

        $preset = MessageTemplatePreset::factory()->create([
            'name' => 'Internal Notification',
            'purpose' => 'internal',
            'scope' => 'internal_notifications',
            'dispatch_keys' => ['internal_notification'],
            'status' => MessageTemplatePreset::STATUS_ACTIVE,
            'is_active' => true,
            'meta' => ['route_authoring' => ['eligible' => true]],
        ]);

        $eligible = app(RouteAuthoringMessageTemplateEligibilityResolver::class)->eligiblePresets();

        $this->assertFalse($eligible->contains(fn (MessageTemplatePreset $candidate): bool => $candidate->is($preset)));
    }
}