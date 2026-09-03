<?php

namespace Tests\Feature\InboundMessaging;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\InboundMessaging\Automation\InboundEmailRouteAutomationTriggerAuthoringContributor;
use App\Modules\InboundMessaging\Models\InboundEmailRoute;
use App\Support\AutomationTriggers\AutomationTriggerAuthoringRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboundEmailRouteAutomationTriggerAuthoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableModules();
        config()->set(
            'messaging.email.inbound_domain',
            'inbound.example.test',
        );
        $this->withoutMiddleware(ForceStagingAccess::class);
    }

    public function test_inbound_address_is_a_registered_flow_route_trigger_without_exposing_internal_authoring(): void
    {
        $active = InboundEmailRoute::query()->create([
            'key' => 'website_forms',
            'local_part' => 'website-forms',
            'label' => 'Website Forms',
            'source' => 'crm',
            'context_key' => null,
            'is_active' => true,
        ]);
        InboundEmailRoute::query()->create([
            'key' => 'disabled_address',
            'local_part' => 'disabled',
            'label' => 'Disabled Address',
            'source' => 'crm',
            'context_key' => null,
            'is_active' => false,
        ]);

        $contributor = app(
            InboundEmailRouteAutomationTriggerAuthoringContributor::class,
        );

        $this->assertTrue($contributor->available(
            InboundEmailRouteAutomationTriggerAuthoringContributor::KEY,
        ));

        $field = collect($contributor->fields(
            InboundEmailRouteAutomationTriggerAuthoringContributor::KEY,
        ))->firstWhere('name', 'inbound_email_route_key');

        $this->assertIsArray($field);
        $values = collect($field['options'])->pluck('value')->all();
        $this->assertContains($active->key, $values);
        $this->assertNotContains('disabled_address', $values);

        $selection = $contributor->selection(
            InboundEmailRouteAutomationTriggerAuthoringContributor::KEY,
            ['inbound_email_route_key' => $active->key],
        );

        $this->assertSame('automation_event', $selection->triggerType);
        $this->assertSame(
            InboundEmailRouteAutomationTriggerAuthoringContributor::EVENT_KEY,
            $selection->triggerKey,
        );

        $condition = collect($selection->entryConditions)->firstWhere(
            'path',
            InboundEmailRouteAutomationTriggerAuthoringContributor::ROUTE_KEY_EVENT_PATH,
        );

        $this->assertIsArray($condition);
        $this->assertSame($active->key, $condition['value']);
    }

    public function test_flow_routes_create_surface_accepts_inbound_address_prefill_through_generic_trigger_contract(): void
    {
        $route = InboundEmailRoute::query()->create([
            'key' => 'vendor_updates',
            'local_part' => 'vendor-updates',
            'label' => 'Vendor Updates',
            'source' => 'crm',
            'context_key' => null,
            'is_active' => true,
        ]);

        $this->actingAs(User::factory()->create())
            ->get('http://crm.'.config('app.root_domain').'/flow-routes?'.http_build_query([
                'create' => 1,
                'trigger_authoring_key' =>
                    InboundEmailRouteAutomationTriggerAuthoringContributor::KEY,
                'inbound_email_route_key' => $route->key,
            ]))
            ->assertOk()
            ->assertViewHas(
                'createRouteTriggerKey',
                InboundEmailRouteAutomationTriggerAuthoringContributor::KEY,
            )
            ->assertViewHas(
                'createRouteTriggerValues',
                fn (array $values): bool =>
                    ($values['inbound_email_route_key'] ?? null) === $route->key,
            );
    }

    private function enableModules(): void
    {
        config()->set('modules.enabled', [
            'core',
            'workflow',
            'flow_routes',
            'messaging',
            'inbound_messaging',
        ]);

        $this->app->forgetInstance(AutomationTriggerAuthoringRegistry::class);
    }
}