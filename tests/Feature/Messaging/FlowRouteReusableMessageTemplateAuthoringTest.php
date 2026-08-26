<?php

namespace Tests\Feature\Messaging;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Services\RouteAuthoringMessageTemplateEligibilityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowRouteReusableMessageTemplateAuthoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_editor_can_create_a_reusable_message_with_context_owned_by_messaging(): void
    {
        config()->set('modules.enabled', ['workflow', 'flow_routes', 'messaging']);
        config()->set('messaging.channel_availability.email.runtime_supported', true);
        config()->set('messaging.channel_availability.email.surfaces.route_send_message_points', true);
        config()->set('messaging.channel_availability.email.purpose_scopes', ['*' => true]);

        $user = User::factory()->create();
        $this->withoutMiddleware(ForceStagingAccess::class);

        $response = $this->actingAs($user)->postJson(
            'http://crm.'.config('app.root_domain').'/message-templates/reusable/flow-route',
            [
                'channel' => 'email',
                'purpose' => 'marketing',
                'name' => 'Past Client Check-In',
                'subject' => 'Checking in, {first_name}',
                'body' => 'Hi {first_name}, just checking in.',
            ],
        );

        $response->assertCreated()
            ->assertJsonPath('name', 'Past Client Check-In')
            ->assertJsonPath('channel', 'email')
            ->assertJsonPath('purpose', 'marketing');

        $preset = MessageTemplatePreset::query()->sole();
        $template = MessageTemplate::query()->sole();
        $catalog = MessageTemplateCatalogEntry::query()->sole();

        $this->assertSame($preset->key, $template->key);
        $this->assertSame('general', $preset->scope);
        $this->assertEquals(['flow_route_send_message'], $preset->dispatch_keys);
        $this->assertSame('flow_route_message', $preset->message_type);
        $this->assertSame('marketing', $preset->queue);
        $this->assertSame('flow_routes', data_get($preset->meta, 'authoring.context_key'));
        $this->assertEquals(['flow_routes'], data_get($catalog->meta, 'authoring.selection_contexts'));
        $this->assertSame('flow_routes', $catalog->module_key);
        $this->assertSame('route_send_message_points', $catalog->surface);
        $this->assertSame('flow_route_direct', $catalog->usage_type);

        $eligible = app(RouteAuthoringMessageTemplateEligibilityResolver::class)->eligiblePresets();
        $this->assertTrue($eligible->contains(fn (MessageTemplatePreset $candidate): bool => $candidate->is($preset)));
    }

    public function test_route_message_creation_rejects_token_not_available_in_flow_route_send_context(): void
    {
        config()->set('modules.enabled', ['workflow', 'flow_routes', 'messaging']);
        $user = User::factory()->create();
        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)->postJson(
            'http://crm.'.config('app.root_domain').'/message-templates/reusable/flow-route',
            [
                'channel' => 'email',
                'purpose' => 'marketing',
                'name' => 'Invalid Route Message',
                'subject' => 'Hello',
                'body' => 'Join {webinar_title}',
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors('message_template');

        $this->assertSame(0, MessageTemplatePreset::query()->count());
    }

    public function test_send_message_authoring_remains_available_before_the_first_reusable_template_exists(): void
    {
        config()->set('modules.enabled', ['workflow', 'flow_routes', 'messaging']);
        config()->set('messaging.channel_availability.email.runtime_supported', true);
        config()->set('messaging.channel_availability.email.surfaces.route_send_message_points', true);
        config()->set('messaging.channel_availability.email.purpose_scopes', ['*' => true]);

        $contributor = app(\App\Modules\Messaging\Automation\MessagingAutomationPointAuthoringContributor::class);
        $context = new \App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext();

        $this->assertTrue($contributor->available('send_message', $context));

        $fields = $contributor->fields('send_message', [], $context);

        $this->assertCount(1, $fields);
        $this->assertSame('component', $fields[0]['type']);
        $this->assertSame('messaging.route-message-template-picker', $fields[0]['component']);
        $this->assertEquals([], $fields[0]['options']);
        $this->assertNotEmpty($fields[0]['available_fields']);
    }

}