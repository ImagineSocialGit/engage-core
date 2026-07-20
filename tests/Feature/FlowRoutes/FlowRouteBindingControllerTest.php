<?php

namespace Tests\Feature\FlowRoutes;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowRouteBindingControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_renders_contact_status_and_automation_event_bindings(): void
    {
        config()->set('modules.enabled', [
            'workflow',
            'flow_routes',
        ]);

        $user = User::factory()->create();

        $status = ContactStatus::query()->create([
            'key' => 'prospect',
            'name' => 'Prospect',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $statusRoute = $this->createContactStatusRoute(
            status: $status,
            key: 'prospect_route',
            name: 'Prospect Route',
        );

        $this->createBinding(
            triggerType: FlowRoute::TRIGGER_CONTACT_STATUS,
            triggerKey: $status->key,
            flowRoute: $statusRoute,
        );

        $eventRoute = $this->createAutomationEventRoute(
            eventKey: 'webinar.attended',
            key: 'webinar_attended_route',
            name: 'Webinar Attended Route',
        );

        $this->createBinding(
            triggerType: FlowRoute::TRIGGER_AUTOMATION_EVENT,
            triggerKey: 'webinar.attended',
            flowRoute: $eventRoute,
        );

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/flow-routes/bindings')
            ->assertOk()
            ->assertSee($statusRoute->name)
            ->assertSee($eventRoute->name)
            ->assertSee($status->key)
            ->assertSee('webinar.attended')
            ->assertSee('selectedStatus', false)
            ->assertSee('selectedActivityModule', false);
    }

    public function test_status_assignment_keeps_route_context_brief(): void
    {
        config()->set('modules.enabled', [
            'workflow',
            'flow_routes',
        ]);

        config()->set('contacts.labels.singular', 'lead');

        $user = User::factory()->create();

        $status = ContactStatus::query()->create([
            'key' => 'attempting_contact',
            'name' => 'Attempting Contact',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $route = $this->createContactStatusRoute(
            status: $status,
            key: 'attempting_contact_follow_up',
            name: 'Attempting Contact Follow-Up',
        );

        FlowRoutePoint::query()->create([
            'flow_route_id' => $route->getKey(),
            'flow_route_capability_id' => null,
            'key' => 'wait_one_week',
            'type' => FlowRoutePointType::Wait->value,
            'name' => 'Wait one week',
            'description' => null,
            'sort_order' => 10,
            'is_start' => true,
            'is_active' => true,
            'next_flow_route_point_id' => null,
            'definition' => [
                'weeks' => 1,
            ],
            'settings' => [],
            'cancel_conditions' => [
                [
                    'type' => 'contact_status_changed',
                ],
            ],
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $this->createBinding(
            triggerType: FlowRoute::TRIGGER_CONTACT_STATUS,
            triggerKey: $status->key,
            flowRoute: $route,
        );

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/flow-routes/bindings')
            ->assertOk()
            ->assertSee($route->name)
            ->assertSee($status->key);
    }

    public function test_assignment_page_accepts_contextual_activity_navigation_state(): void
    {
        config()->set('modules.enabled', [
            'workflow',
            'flow_routes',
        ]);

        $user = User::factory()->create();

        $route = $this->createAutomationEventRoute(
            eventKey: 'webinar.attended',
            key: 'webinar_attended_route',
            name: 'Webinar Attended Route',
        );

        $this->createBinding(
            triggerType: FlowRoute::TRIGGER_AUTOMATION_EVENT,
            triggerKey: 'webinar.attended',
            flowRoute: $route,
        );

        $this->withoutMiddleware(ForceStagingAccess::class);

        $response = $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/flow-routes/bindings?tab=activity&module=webinars&event=webinar.attended');

        $response
            ->assertOk()
            ->assertSee('event-webinar-attended', false)
            ->assertSee(
                'selectedActivityModule: '.\Illuminate\Support\Js::from('webinars'),
                false,
            )
            ->assertSee(
                'tab: '.\Illuminate\Support\Js::from('activity'),
                false,
            )
            ->assertSee('focusedTarget', false)
            ->assertSee('ring-orange-500', false);
    }

    public function test_assignment_page_accepts_contextual_status_navigation_state(): void
    {
        config()->set('modules.enabled', [
            'workflow',
            'flow_routes',
        ]);

        $user = User::factory()->create();

        $status = ContactStatus::query()->create([
            'key' => 'attempting_contact',
            'name' => 'Attempting Contact',
            'is_active' => true,
        ]);

        $route = $this->createContactStatusRoute(
            status: $status,
            key: 'attempting_contact_follow_up',
            name: 'Attempting Contact Follow-Up',
        );

        $this->createBinding(
            triggerType: FlowRoute::TRIGGER_CONTACT_STATUS,
            triggerKey: $status->key,
            flowRoute: $route,
        );

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/flow-routes/bindings?tab=status&status=attempting_contact#status-attempting-contact')
            ->assertOk()
            ->assertSee('status-attempting-contact', false)
            ->assertSee(
                'selectedStatus: '.\Illuminate\Support\Js::from('attempting_contact'),
                false,
            )
            ->assertSee('focusedTarget', false);
    }

    public function test_it_updates_single_contact_status_binding(): void
    {
        config()->set('modules.enabled', [
            'workflow',
            'flow_routes',
        ]);

        $user = User::factory()->create();

        $status = ContactStatus::query()->create([
            'key' => 'prospect',
            'name' => 'Prospect',
            'is_active' => true,
        ]);

        $oldRoute = $this->createContactStatusRoute(
            status: $status,
            key: 'old_prospect_route',
            name: 'Old Prospect Route',
        );

        $newRoute = $this->createContactStatusRoute(
            status: $status,
            key: 'new_prospect_route',
            name: 'New Prospect Route',
        );

        $oldBinding = $this->createBinding(
            triggerType: FlowRoute::TRIGGER_CONTACT_STATUS,
            triggerKey: $status->key,
            flowRoute: $oldRoute,
        );

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->patch('http://crm.'.config('app.root_domain').'/flow-routes/bindings', [
                'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
                'trigger_key' => $status->key,
                'flow_route_id' => $newRoute->getKey(),
            ])
            ->assertRedirect(route('crm.flow-routes.bindings.index'));

        $this->assertFalse($oldBinding->refresh()->is_active);

        $this->assertDatabaseHas('flow_route_trigger_bindings', [
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $status->key,
            'flow_route_id' => $newRoute->getKey(),
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
        ]);
    }

    public function test_it_updates_multiple_automation_event_bindings(): void
    {
        config()->set('modules.enabled', [
            'workflow',
            'flow_routes',
        ]);

        $user = User::factory()->create();

        $firstRoute = $this->createAutomationEventRoute(
            eventKey: 'webinar.attended',
            key: 'attended_status_route',
            name: 'Attended Status Route',
        );

        $secondRoute = $this->createAutomationEventRoute(
            eventKey: 'webinar.attended',
            key: 'attended_campaign_route',
            name: 'Attended Campaign Route',
        );

        $removedRoute = $this->createAutomationEventRoute(
            eventKey: 'webinar.attended',
            key: 'removed_attended_route',
            name: 'Removed Attended Route',
        );

        $removedBinding = $this->createBinding(
            triggerType: FlowRoute::TRIGGER_AUTOMATION_EVENT,
            triggerKey: 'webinar.attended',
            flowRoute: $removedRoute,
        );

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->patch('http://crm.'.config('app.root_domain').'/flow-routes/bindings', [
                'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
                'trigger_key' => 'webinar.attended',
                'flow_route_ids' => [
                    $firstRoute->getKey(),
                    $secondRoute->getKey(),
                ],
            ])
            ->assertRedirect(route('crm.flow-routes.bindings.index'));

        $this->assertFalse($removedBinding->refresh()->is_active);

        foreach ([$firstRoute, $secondRoute] as $route) {
            $this->assertDatabaseHas('flow_route_trigger_bindings', [
                'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
                'trigger_key' => 'webinar.attended',
                'flow_route_id' => $route->getKey(),
                'context_type' => null,
                'context_id' => null,
                'is_active' => true,
            ]);
        }
    }

    private function createContactStatusRoute(
        ContactStatus $status,
        string $key,
        string $name,
    ): FlowRoute {
        return FlowRoute::query()->create([
            'key' => $key,
            'contact_status_id' => $status->getKey(),
            'owner_type' => null,
            'owner_id' => null,
            'owner_group' => null,
            'name' => $name,
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
    }

    private function createAutomationEventRoute(
        string $eventKey,
        string $key,
        string $name,
    ): FlowRoute {
        return FlowRoute::query()->create([
            'key' => $key,
            'contact_status_id' => null,
            'owner_type' => null,
            'owner_id' => null,
            'owner_group' => null,
            'name' => $name,
            'description' => null,
            'version' => 1,
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => $eventKey,
            'is_active' => true,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);
    }

    private function createBinding(
        string $triggerType,
        string $triggerKey,
        FlowRoute $flowRoute,
    ): FlowRouteTriggerBinding {
        return FlowRouteTriggerBinding::query()->create([
            'trigger_type' => $triggerType,
            'trigger_key' => $triggerKey,
            'flow_route_id' => $flowRoute->getKey(),
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
            'meta' => [
                'source' => 'test',
            ],
        ]);
    }
}