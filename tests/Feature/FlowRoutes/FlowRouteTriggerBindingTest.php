<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use App\Modules\FlowRoutes\Services\FlowRouteTriggerBindingResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowRouteTriggerBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_binding_selects_one_active_route_for_contact_status_trigger(): void
    {
        $status = ContactStatus::query()->create([
            'key' => 'prospect',
            'name' => 'Prospect',
        ]);

        $availableRoute = FlowRoute::query()->create([
            'key' => 'prospect_available_route',
            'contact_status_id' => $status->getKey(),
            'owner_type' => null,
            'owner_id' => null,
            'owner_group' => 'sales',
            'name' => 'Prospect Available Route',
            'description' => null,
            'version' => 1,
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $status->key,
            'is_active' => true,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $unselectedRoute = FlowRoute::query()->create([
            'key' => 'prospect_unselected_route',
            'contact_status_id' => $status->getKey(),
            'owner_type' => null,
            'owner_id' => null,
            'owner_group' => 'ops',
            'name' => 'Prospect Unselected Route',
            'description' => null,
            'version' => 1,
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $status->key,
            'is_active' => true,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $binding = FlowRouteTriggerBinding::query()->create([
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $status->key,
            'flow_route_id' => $availableRoute->getKey(),
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
            'meta' => [
                'source' => 'test',
            ],
        ]);

        $this->assertTrue($binding->flowRoute->is($availableRoute));
        $this->assertSame(FlowRoute::TRIGGER_CONTACT_STATUS, $binding->trigger_type);
        $this->assertSame($status->key, $binding->trigger_key);
        $this->assertTrue($binding->is_active);
        $this->assertSame('sales', $binding->flowRoute->owner_group);

        $selectedRoute = app(FlowRouteTriggerBindingResolver::class)
            ->selectedFlowRouteForContactStatus($status);

        $this->assertTrue($selectedRoute?->is($availableRoute));
        $this->assertFalse($selectedRoute?->is($unselectedRoute));
    }

    public function test_resolver_ignores_binding_when_selected_route_is_inactive(): void
    {
        $status = ContactStatus::query()->create([
            'key' => 'closed_lost',
            'name' => 'Closed Lost',
        ]);

        $inactiveRoute = FlowRoute::query()->create([
            'key' => 'inactive_closed_lost_route',
            'contact_status_id' => $status->getKey(),
            'owner_type' => null,
            'owner_id' => null,
            'owner_group' => 'system',
            'name' => 'Inactive Closed Lost Route',
            'description' => null,
            'version' => 1,
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $status->key,
            'is_active' => false,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        FlowRouteTriggerBinding::query()->create([
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $status->key,
            'flow_route_id' => $inactiveRoute->getKey(),
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
            'meta' => [],
        ]);

        $this->assertNull(
            app(FlowRouteTriggerBindingResolver::class)
                ->selectedFlowRouteForContactStatus($status),
        );
    }


    public function test_resolver_returns_all_selected_global_automation_event_routes(): void
    {
        $firstRoute = FlowRoute::query()->create([
            'key' => 'webinar_attended_status_transition',
            'contact_status_id' => null,
            'owner_type' => null,
            'owner_id' => null,
            'owner_group' => 'system',
            'name' => 'Webinar Attended Status Transition',
            'description' => null,
            'version' => 1,
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => 'webinar.attended',
            'is_active' => true,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $secondRoute = FlowRoute::query()->create([
            'key' => 'webinar_attended_campaign_enrollment',
            'contact_status_id' => null,
            'owner_type' => null,
            'owner_id' => null,
            'owner_group' => 'sales',
            'name' => 'Webinar Attended Campaign Enrollment',
            'description' => null,
            'version' => 1,
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => 'webinar.attended',
            'is_active' => true,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        FlowRouteTriggerBinding::query()->create([
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => 'webinar.attended',
            'flow_route_id' => $firstRoute->getKey(),
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
            'meta' => [],
        ]);

        FlowRouteTriggerBinding::query()->create([
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => 'webinar.attended',
            'flow_route_id' => $secondRoute->getKey(),
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
            'meta' => [],
        ]);

        $selectedRoutes = app(FlowRouteTriggerBindingResolver::class)
            ->selectedFlowRoutes(
                triggerType: FlowRoute::TRIGGER_AUTOMATION_EVENT,
                triggerKey: 'webinar.attended',
            );

        $this->assertCount(2, $selectedRoutes);
        $this->assertTrue($selectedRoutes->contains(fn (FlowRoute $route): bool => $route->is($firstRoute)));
        $this->assertTrue($selectedRoutes->contains(fn (FlowRoute $route): bool => $route->is($secondRoute)));
    }

    public function test_context_specific_binding_overrides_global_automation_event_binding(): void
    {
        $globalRoute = FlowRoute::query()->create([
            'key' => 'global_webinar_attended_route',
            'contact_status_id' => null,
            'owner_type' => null,
            'owner_id' => null,
            'owner_group' => 'system',
            'name' => 'Global Webinar Attended Route',
            'description' => null,
            'version' => 1,
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => 'webinar.attended',
            'is_active' => true,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $contextRoute = FlowRoute::query()->create([
            'key' => 'context_webinar_attended_route',
            'contact_status_id' => null,
            'owner_type' => null,
            'owner_id' => null,
            'owner_group' => 'ops',
            'name' => 'Context Webinar Attended Route',
            'description' => null,
            'version' => 1,
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => 'webinar.attended',
            'is_active' => true,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        FlowRouteTriggerBinding::query()->create([
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => 'webinar.attended',
            'flow_route_id' => $globalRoute->getKey(),
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
            'meta' => [],
        ]);

        FlowRouteTriggerBinding::query()->create([
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => 'webinar.attended',
            'flow_route_id' => $contextRoute->getKey(),
            'context_type' => 'webinar_series',
            'context_id' => 25,
            'is_active' => true,
            'meta' => [],
        ]);

        $resolver = app(FlowRouteTriggerBindingResolver::class);

        $this->assertTrue(
            $resolver
                ->selectedFlowRouteForAutomationEvent('webinar.attended')
                ?->is($globalRoute),
        );

        $this->assertTrue(
            $resolver
                ->selectedFlowRouteForAutomationEvent('webinar.attended', 'webinar_series', 25)
                ?->is($contextRoute),
        );

        $this->assertTrue(
            $resolver
                ->selectedFlowRouteForAutomationEvent('webinar.attended', 'webinar_series', 99)
                ?->is($globalRoute),
        );
    }

    public function test_resolver_returns_all_selected_routes_for_contact_status_trigger(): void
    {
        $status = ContactStatus::query()->create([
            'key' => 'qualified',
            'name' => 'Qualified',
        ]);

        $firstRoute = FlowRoute::query()->create([
            'key' => 'qualified_first_route',
            'contact_status_id' => $status->getKey(),
            'owner_type' => null,
            'owner_id' => null,
            'owner_group' => 'sales',
            'name' => 'Qualified First Route',
            'description' => null,
            'version' => 1,
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $status->key,
            'is_active' => true,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $secondRoute = FlowRoute::query()->create([
            'key' => 'qualified_second_route',
            'contact_status_id' => $status->getKey(),
            'owner_type' => null,
            'owner_id' => null,
            'owner_group' => 'ops',
            'name' => 'Qualified Second Route',
            'description' => null,
            'version' => 1,
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $status->key,
            'is_active' => true,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        foreach ([$firstRoute, $secondRoute] as $route) {
            FlowRouteTriggerBinding::query()->create([
                'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
                'trigger_key' => $status->key,
                'flow_route_id' => $route->getKey(),
                'context_type' => null,
                'context_id' => null,
                'is_active' => true,
                'meta' => [],
            ]);
        }

        $selectedRoutes = app(FlowRouteTriggerBindingResolver::class)
            ->selectedFlowRoutesForContactStatus($status);

        $this->assertCount(2, $selectedRoutes);
        $this->assertTrue($selectedRoutes->contains(fn (FlowRoute $route): bool => $route->is($firstRoute)));
        $this->assertTrue($selectedRoutes->contains(fn (FlowRoute $route): bool => $route->is($secondRoute)));
    }

}
