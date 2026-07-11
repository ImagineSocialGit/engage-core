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

class FlowRouteControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_current_routes_with_business_language_summaries_and_module_owned_points(): void
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

        $route = FlowRoute::query()->create([
            'key' => 'attempting_contact_follow_up',
            'contact_status_id' => $status->getKey(),
            'owner_type' => null,
            'owner_id' => null,
            'owner_group' => 'client',
            'name' => 'Attempting Contact Follow-Up',
            'description' => 'Creates a task and checks back later.',
            'version' => 1,
            'is_current_version' => true,
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $status->key,
            'is_active' => true,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

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
            'definition' => ['weeks' => 1],
            'settings' => [],
            'cancel_conditions' => [
                ['type' => 'contact_status_changed'],
            ],
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        FlowRoutePoint::query()->create([
            'flow_route_id' => $route->getKey(),
            'flow_route_capability_id' => null,
            'key' => 'create_follow_up_task',
            'type' => FlowRoutePointType::CreateTask->value,
            'name' => 'Create follow-up task',
            'description' => null,
            'sort_order' => 20,
            'is_start' => false,
            'is_active' => true,
            'next_flow_route_point_id' => null,
            'definition' => [
                'title' => 'Create follow-up task',
            ],
            'settings' => [],
            'cancel_conditions' => [],
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        FlowRouteTriggerBinding::query()->create([
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $status->key,
            'flow_route_id' => $route->getKey(),
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
            'meta' => [],
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/flow-routes')
            ->assertOk()
            ->assertSee('Manage Routes')
            ->assertSee('Assignments')
            ->assertSee('Attempting Contact Follow-Up')
            ->assertSee('When a lead moves to Attempting Contact.')
            ->assertSee('2 Points')
            ->assertSee('Show route flow')
            ->assertDontSee('Details')
            ->assertDontSee('Runs when')
            ->assertSee('Wait 1 week')
            ->assertSee('Continue only while the lead remains in Attempting Contact.')
            ->assertSee('aria-label="Route flow"', false)
            ->assertSee('data-module="tasks"', false)
            ->assertSee('Assigned')
            ->assertSee(
                route('crm.flow-routes.bindings.index', [
                    'tab' => 'status',
                    'status' => 'attempting_contact',
                ]).'#status-attempting-contact'
            )
            ->assertDontSee('Availability')
            ->assertDontSee('Available</dd>', false)
            ->assertDontSee('Engage Core');
    }

    public function test_index_separates_currently_running_and_available_automatic_behavior(): void
    {
        config()->set('modules.enabled', [
            'workflow',
            'flow_routes',
        ]);

        $user = User::factory()->create();

        $assignedRoute = $this->createAutomationAction(
            key: 'webinar_attended_status_transition',
            name: 'Webinar Attended Status Transition',
            pointType: FlowRoutePointType::ChangeStatus->value,
            pointKey: 'move_to_attended',
            pointName: 'Move to Attended Webinar',
            definition: [
                'contact_status_key' => 'attended_webinar',
            ],
        );

        $availableRoute = $this->createAutomationAction(
            key: 'webinar_attended_follow_up',
            name: 'Webinar Attended Follow-Up',
            pointType: FlowRoutePointType::EnrollCampaign->value,
            pointKey: 'start_attended_nurture',
            pointName: 'Start Webinar Attended Nurture',
            definition: [
                'campaign_key' => 'webinar_attended_nurture',
            ],
        );

        FlowRouteTriggerBinding::query()->create([
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => 'webinar.attended',
            'flow_route_id' => $assignedRoute->getKey(),
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
            'meta' => [],
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/flow-routes')
            ->assertOk()
            ->assertSee('Automatic Behavior')
            ->assertSee('Webinars')
            ->assertSee('When someone attends a webinar.')
            ->assertSee('Currently runs')
            ->assertSee('Move the contact to Attended Webinar.')
            ->assertSee('Available but not assigned')
            ->assertSee('Start Campaign: Webinar Attended Nurture.')
            ->assertSee('If assigned, messages are still sent only when communication permissions and delivery rules allow.')
            ->assertDontSee('Webinar Attended Status Transition')
            ->assertDontSee('Webinar Attended Follow-Up')
            ->assertDontSee('Engage Core');

        $this->assertFalse($availableRoute->activeTriggerBindings()->exists());
    }

    public function test_index_shows_only_current_route_versions(): void
    {
        config()->set('modules.enabled', [
            'workflow',
            'flow_routes',
        ]);

        $user = User::factory()->create();

        FlowRoute::query()->create([
            'key' => 'versioned_route',
            'contact_status_id' => null,
            'owner_type' => null,
            'owner_id' => null,
            'owner_group' => null,
            'name' => 'Old Route Version',
            'description' => null,
            'version' => 1,
            'is_current_version' => false,
            'trigger_type' => FlowRoute::TRIGGER_MANUAL,
            'trigger_key' => null,
            'is_active' => true,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        FlowRoute::query()->create([
            'key' => 'versioned_route',
            'contact_status_id' => null,
            'owner_type' => null,
            'owner_id' => null,
            'owner_group' => null,
            'name' => 'Current Route Version',
            'description' => null,
            'version' => 2,
            'is_current_version' => true,
            'trigger_type' => FlowRoute::TRIGGER_MANUAL,
            'trigger_key' => null,
            'is_active' => true,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/flow-routes')
            ->assertOk()
            ->assertSee('Current Route Version')
            ->assertDontSee('Old Route Version');
    }

    private function createAutomationAction(
        string $key,
        string $name,
        string $pointType,
        string $pointKey,
        string $pointName,
        array $definition,
    ): FlowRoute {
        $route = FlowRoute::query()->create([
            'key' => $key,
            'contact_status_id' => null,
            'owner_type' => null,
            'owner_id' => null,
            'owner_group' => 'webinars',
            'name' => $name,
            'description' => null,
            'version' => 1,
            'is_current_version' => true,
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => 'webinar.attended',
            'is_active' => true,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        FlowRoutePoint::query()->create([
            'flow_route_id' => $route->getKey(),
            'flow_route_capability_id' => null,
            'key' => $pointKey,
            'type' => $pointType,
            'name' => $pointName,
            'description' => null,
            'sort_order' => 10,
            'is_start' => true,
            'is_active' => true,
            'next_flow_route_point_id' => null,
            'definition' => $definition,
            'settings' => [],
            'cancel_conditions' => [],
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        return $route;
    }
}
