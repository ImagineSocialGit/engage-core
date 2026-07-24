<?php

namespace Tests\Feature\FlowRoutes;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\Tasks\Models\TaskTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowRouteEditorControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_exposes_authorable_capabilities_and_their_fields(): void
    {
        config()->set('modules.enabled', [
            'workflow',
            'flow_routes',
            'tasks',
            'messaging',
            'campaigns',
        ]);

        $user = User::factory()->create();
        $route = $this->createRoute();

        TaskTemplate::factory()->create([
            'key' => 'general.follow_up',
            'name' => 'Follow up',
            'title' => 'Follow up',
            'is_active' => true,
        ]);

        $this->createCapability(
            key: 'flow_routes.wait',
            moduleKey: 'flow_routes',
            pointType: FlowRoutePointType::Wait->value,
            name: 'Wait until time',
        );

        $this->createCapability(
            key: 'tasks.create_task',
            moduleKey: 'tasks',
            pointType: FlowRoutePointType::CreateTask->value,
            name: 'Create task',
        );

        $this->createPoint($route, 'existing_task', 10, true);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.flow-routes.index', [
                'edit_route' => $route->getKey(),
            ]));

        $response
            ->assertOk()
            ->assertViewIs('crm.flow-routes.index')
            ->assertViewHas('openRouteEditorId', $route->getKey());

        $routeEditors = $response->viewData('routeEditors');

        $this->assertTrue($routeEditors->has($route->getKey()));

        $capabilities = $routeEditors->get($route->getKey())['capabilities'];

        $this->assertSame(
            [
                'flow_routes.wait',
                'tasks.create_task',
            ],
            $capabilities
                ->pluck('key')
                ->sort()
                ->values()
                ->all(),
        );

        $waitCapability = $capabilities->firstWhere(
            'key',
            'flow_routes.wait',
        );

        $taskCapability = $capabilities->firstWhere(
            'key',
            'tasks.create_task',
        );

        $this->assertIsArray($waitCapability);
        $this->assertSame(
            FlowRoutePointType::Wait->value,
            $waitCapability['point_type'],
        );
        $this->assertNotSame('', trim((string) $waitCapability['tip']));
        $this->assertNotEmpty($waitCapability['use_cases']);

        $this->assertIsArray($taskCapability);
        $this->assertSame(
            FlowRoutePointType::CreateTask->value,
            $taskCapability['point_type'],
        );
        $this->assertNotSame('', trim((string) $taskCapability['tip']));
        $this->assertNotEmpty($taskCapability['use_cases']);

        $taskTemplateField = collect($taskCapability['fields'])
            ->firstWhere('name', 'task_template_key');

        $this->assertIsArray($taskTemplateField);
        $this->assertTrue($taskTemplateField['required']);
        $this->assertContains(
            'general.follow_up',
            collect($taskTemplateField['options'])
                ->pluck('value')
                ->all(),
        );
    }

    public function test_stop_campaign_capability_is_hidden_until_route_starts_a_campaign(): void
    {
        config()->set('modules.enabled', [
            'workflow',
            'flow_routes',
            'campaigns',
        ]);

        $user = User::factory()->create();
        $route = $this->createRoute();

        Campaign::factory()->create([
            'key' => 'welcome',
            'name' => 'Welcome',
            'status' => Campaign::STATUS_ACTIVE,
        ]);

        $this->createCapability(
            key: 'campaigns.enroll_contact',
            moduleKey: 'campaigns',
            pointType: FlowRoutePointType::EnrollCampaign->value,
            name: 'Enroll contact in campaign',
        );

        $this->createCapability(
            key: 'campaigns.cancel_enrollment',
            moduleKey: 'campaigns',
            pointType: FlowRoutePointType::CancelCampaign->value,
            name: 'Cancel campaign enrollment',
        );

        $this->withoutMiddleware(ForceStagingAccess::class);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.flow-routes.index', [
                'edit_route' => $route->getKey(),
            ]));

        $response->assertOk();

        $capabilityKeys = $response
            ->viewData('routeEditors')
            ->get($route->getKey())['capabilities']
            ->pluck('key')
            ->all();

        $this->assertContains(
            'campaigns.enroll_contact',
            $capabilityKeys,
        );

        $this->assertNotContains(
            'campaigns.cancel_enrollment',
            $capabilityKeys,
        );

        FlowRoutePoint::query()->create([
            'flow_route_id' => $route->getKey(),
            'key' => 'start_campaign',
            'type' => FlowRoutePointType::EnrollCampaign->value,
            'name' => 'Start Campaign',
            'sort_order' => 10,
            'is_start' => true,
            'is_active' => true,
            'definition' => ['campaign_key' => 'welcome'],
            'settings' => [],
            'cancel_conditions' => [],
            'is_customized' => false,
            'meta' => [],
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.flow-routes.index', [
                'edit_route' => $route->getKey(),
            ]));

        $response->assertOk();

        $capabilityKeys = $response
            ->viewData('routeEditors')
            ->get($route->getKey())['capabilities']
            ->pluck('key')
            ->all();

        $this->assertContains(
            'campaigns.enroll_contact',
            $capabilityKeys,
        );

        $this->assertContains(
            'campaigns.cancel_enrollment',
            $capabilityKeys,
        );
    }

    public function test_it_adds_wait_point_marks_route_customized_and_rebuilds_sequence(): void
    {
        config()->set('modules.enabled', [
            'workflow',
            'flow_routes',
        ]);

        $user = User::factory()->create();
        $route = $this->createRoute();

        $capability = $this->createCapability(
            key: 'flow_routes.wait',
            moduleKey: 'flow_routes',
            pointType: FlowRoutePointType::Wait->value,
            name: 'Wait until time',
        );

        $existingTask = $this->createPoint($route, 'existing_task', 10, true);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->post('http://crm.'.config('app.root_domain').'/flow-routes/'.$route->getKey().'/points', [
                'capability_id' => $capability->getKey(),
                'name' => 'Wait two days',
                'wait_mode' => 'duration',
                'duration_value' => 2,
                'duration_unit' => 'days',
            ])
            ->assertRedirect(route('crm.flow-routes.index', ['edit_route' => $route->getKey()]));

        $point = FlowRoutePoint::query()
            ->where('type', FlowRoutePointType::Wait->value)
            ->firstOrFail();
        $ordered = $route->activeFlowRoutePoints()->orderBy('sort_order')->get();

        $this->assertSame(FlowRoutePointType::Wait->value, $point->type);
        $this->assertSame(2, $point->definition['days']);
        $this->assertTrue($point->is_start);
        $this->assertSame($existingTask->getKey(), $point->next_flow_route_point_id);
        $this->assertTrue($point->is_customized);
        $this->assertSame([$point->getKey(), $existingTask->getKey()], $ordered->pluck('id')->all());

        $route->refresh();

        $this->assertTrue($route->is_customized);
        $this->assertNotNull($route->customized_at);
    }

    public function test_it_moves_points_and_keeps_start_and_next_pointers_in_sync(): void
    {
        config()->set('modules.enabled', [
            'workflow',
            'flow_routes',
        ]);

        $user = User::factory()->create();
        $route = $this->createRoute();

        $first = $this->createPoint($route, 'first', 10, true);
        $second = $this->createPoint($route, 'second', 20, false);
        $third = $this->createPoint($route, 'third', 30, false);

        $first->forceFill(['next_flow_route_point_id' => $second->getKey()])->save();
        $second->forceFill(['next_flow_route_point_id' => $third->getKey()])->save();

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->patch('http://crm.'.config('app.root_domain').'/flow-routes/'.$route->getKey().'/points/'.$first->getKey().'/move-down')
            ->assertRedirect(route('crm.flow-routes.index', ['edit_route' => $route->getKey()]));

        $ordered = $route->activeFlowRoutePoints()->orderBy('sort_order')->get();

        $this->assertSame(
            [$second->getKey(), $first->getKey(), $third->getKey()],
            $ordered->pluck('id')->all(),
        );

        $this->assertTrue($ordered[0]->is_start);
        $this->assertFalse($ordered[1]->is_start);
        $this->assertSame($ordered[1]->getKey(), $ordered[0]->next_flow_route_point_id);
        $this->assertSame($ordered[2]->getKey(), $ordered[1]->next_flow_route_point_id);
        $this->assertNull($ordered[2]->next_flow_route_point_id);
    }

    public function test_it_saves_drag_and_drop_point_order_and_rebuilds_sequence(): void
    {
        config()->set('modules.enabled', [
            'workflow',
            'flow_routes',
        ]);

        $user = User::factory()->create();
        $route = $this->createRoute();

        $first = $this->createPoint($route, 'first', 10, true);
        $second = $this->createPoint($route, 'second', 20, false);
        $third = $this->createPoint($route, 'third', 30, false);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->patch('http://crm.'.config('app.root_domain').'/flow-routes/'.$route->getKey().'/points/order', [
                'point_ids' => [
                    $third->getKey(),
                    $first->getKey(),
                    $second->getKey(),
                ],
            ])
            ->assertRedirect(route('crm.flow-routes.index', ['edit_route' => $route->getKey()]));

        $ordered = $route->activeFlowRoutePoints()->orderBy('sort_order')->get();

        $this->assertSame(
            [$third->getKey(), $first->getKey(), $second->getKey()],
            $ordered->pluck('id')->all(),
        );
        $this->assertTrue($ordered[0]->is_start);
        $this->assertSame($ordered[1]->getKey(), $ordered[0]->next_flow_route_point_id);
        $this->assertSame($ordered[2]->getKey(), $ordered[1]->next_flow_route_point_id);
        $this->assertNull($ordered[2]->next_flow_route_point_id);
    }

    public function test_removing_point_deactivates_it_and_preserves_customized_preset_boundary(): void
    {
        config()->set('modules.enabled', [
            'workflow',
            'flow_routes',
        ]);

        $user = User::factory()->create();
        $route = $this->createRoute();

        $first = $this->createPoint($route, 'first', 10, true);
        $second = $this->createPoint($route, 'second', 20, false);

        $first->forceFill(['next_flow_route_point_id' => $second->getKey()])->save();

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->delete('http://crm.'.config('app.root_domain').'/flow-routes/'.$route->getKey().'/points/'.$first->getKey())
            ->assertRedirect(route('crm.flow-routes.index', ['edit_route' => $route->getKey()]));

        $first->refresh();
        $second->refresh();

        $this->assertFalse($first->is_active);
        $this->assertTrue($first->is_customized);
        $this->assertFalse($first->is_start);
        $this->assertNull($first->next_flow_route_point_id);

        $this->assertTrue($second->is_active);
        $this->assertTrue($second->is_start);
        $this->assertNull($second->next_flow_route_point_id);
    }

    public function test_removing_point_is_rejected_when_it_would_leave_wait_as_final_point(): void
    {
        config()->set('modules.enabled', [
            'workflow',
            'flow_routes',
        ]);

        $user = User::factory()->create();
        $route = $this->createRoute();

        $wait = $this->createPoint(
            route: $route,
            key: 'wait',
            sortOrder: 10,
            isStart: true,
            type: FlowRoutePointType::Wait->value,
        );
        $task = $this->createPoint($route, 'task', 20, false);
        $wait->forceFill(['next_flow_route_point_id' => $task->getKey()])->save();

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->from(route('crm.flow-routes.index', ['edit_route' => $route->getKey()]))
            ->delete('http://crm.'.config('app.root_domain').'/flow-routes/'.$route->getKey().'/points/'.$task->getKey())
            ->assertRedirect(route('crm.flow-routes.index', ['edit_route' => $route->getKey()]))
            ->assertSessionHasErrors([
                'point_order' => "This Point can't be removed because it would leave Wait as the final Point. Add or move another Point after Wait first.",
            ]);

        $this->assertTrue($task->fresh()->is_active);
        $this->assertSame($task->getKey(), $wait->fresh()->next_flow_route_point_id);
    }

    public function test_reordering_is_rejected_when_change_status_would_not_be_final(): void
    {
        config()->set('modules.enabled', [
            'workflow',
            'flow_routes',
        ]);

        $user = User::factory()->create();
        $route = $this->createRoute();

        $task = $this->createPoint($route, 'task', 10, true);
        $changeStatus = $this->createPoint(
            route: $route,
            key: 'change_status',
            sortOrder: 20,
            isStart: false,
            type: FlowRoutePointType::ChangeStatus->value,
        );
        $task->forceFill(['next_flow_route_point_id' => $changeStatus->getKey()])->save();

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->from(route('crm.flow-routes.index', ['edit_route' => $route->getKey()]))
            ->patch('http://crm.'.config('app.root_domain').'/flow-routes/'.$route->getKey().'/points/order', [
                'point_ids' => [
                    $changeStatus->getKey(),
                    $task->getKey(),
                ],
            ])
            ->assertRedirect(route('crm.flow-routes.index', ['edit_route' => $route->getKey()]))
            ->assertSessionHasErrors('point_order');

        $ordered = $route->activeFlowRoutePoints()->orderBy('sort_order')->pluck('id')->all();

        $this->assertSame([$task->getKey(), $changeStatus->getKey()], $ordered);
    }

    public function test_adding_point_before_terminal_change_status_keeps_status_change_last(): void
    {
        config()->set('modules.enabled', [
            'workflow',
            'flow_routes',
            'tasks',
        ]);

        $user = User::factory()->create();
        $route = $this->createRoute();

        $changeStatus = $this->createPoint(
            route: $route,
            key: 'change_status',
            sortOrder: 10,
            isStart: true,
            type: FlowRoutePointType::ChangeStatus->value,
        );

        $capability = $this->createCapability(
            key: 'tasks.create_task',
            moduleKey: 'tasks',
            pointType: FlowRoutePointType::CreateTask->value,
            name: 'Create task',
        );

        $template = TaskTemplate::factory()->create([
            'key' => 'general.review_contact',
            'name' => 'Review contact',
            'title' => 'Review contact',
            'is_active' => true,
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->post('http://crm.'.config('app.root_domain').'/flow-routes/'.$route->getKey().'/points', [
                'capability_id' => $capability->getKey(),
                'task_template_key' => $template->key,
            ])
            ->assertRedirect(route('crm.flow-routes.index', ['edit_route' => $route->getKey()]));

        $ordered = $route->activeFlowRoutePoints()->orderBy('sort_order')->get();

        $this->assertCount(2, $ordered);
        $this->assertSame(FlowRoutePointType::CreateTask->value, $ordered[0]->type);
        $this->assertSame($changeStatus->getKey(), $ordered[1]->getKey());
        $this->assertSame($changeStatus->getKey(), $ordered[0]->next_flow_route_point_id);
        $this->assertNull($ordered[1]->next_flow_route_point_id);
    }

    public function test_editor_marks_terminal_status_as_not_draggable_and_disables_invalid_removal_in_the_ui(): void
    {
        config()->set('modules.enabled', [
            'workflow',
            'flow_routes',
        ]);

        $user = User::factory()->create();
        $route = $this->createRoute();

        $wait = $this->createPoint(
            route: $route,
            key: 'wait',
            sortOrder: 10,
            isStart: true,
            type: FlowRoutePointType::Wait->value,
        );
        $task = $this->createPoint($route, 'task', 20, false);
        $status = $this->createPoint(
            route: $route,
            key: 'change_status',
            sortOrder: 30,
            isStart: false,
            type: FlowRoutePointType::ChangeStatus->value,
        );

        $wait->forceFill(['next_flow_route_point_id' => $task->getKey()])->save();
        $task->forceFill(['next_flow_route_point_id' => $status->getKey()])->save();

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/flow-routes')
            ->assertOk()
            ->assertSee('data-point-movable="false"', false)
            ->assertSee(':disabled="!canRemove(', false)
            ->assertSee(':title="removalError(', false);
    }

    public function test_task_authoring_requires_a_template_and_does_not_offer_one_time_task_input(): void
    {
        config()->set('modules.enabled', ['workflow', 'flow_routes', 'tasks']);

        $user = User::factory()->create();
        $route = $this->createRoute();
        TaskTemplate::factory()->create([
            'key' => 'general.follow_up',
            'name' => 'Follow up',
            'title' => 'Follow up',
            'is_active' => true,
        ]);

        $this->createCapability(
            key: 'tasks.create_task',
            moduleKey: 'tasks',
            pointType: FlowRoutePointType::CreateTask->value,
            name: 'Create task',
        );

        $this->withoutMiddleware(ForceStagingAccess::class);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.flow-routes.index', [
                'edit_route' => $route->getKey(),
            ]));

        $response->assertOk();

        $capabilities = $response
            ->viewData('routeEditors')
            ->get($route->getKey())['capabilities'];

        $taskCapability = $capabilities->firstWhere(
            'key',
            'tasks.create_task',
        );

        $this->assertIsArray($taskCapability);

        $fieldNames = collect($taskCapability['fields'])
            ->pluck('name')
            ->filter()
            ->values()
            ->all();

        $this->assertContains(
            'task_template_key',
            $fieldNames,
        );

        $this->assertNotContains(
            'one_time_task',
            $fieldNames,
        );

        $taskTemplateField = collect($taskCapability['fields'])
            ->firstWhere('name', 'task_template_key');

        $this->assertIsArray($taskTemplateField);
        $this->assertTrue($taskTemplateField['required']);
        $this->assertContains(
            'general.follow_up',
            collect($taskTemplateField['options'])
                ->pluck('value')
                ->all(),
        );
    }

    public function test_create_task_authoring_requires_an_active_task_template(): void
    {
        config()->set('modules.enabled', ['workflow', 'flow_routes', 'tasks']);

        $user = User::factory()->create();
        $route = $this->createRoute();
        $capability = $this->createCapability(
            key: 'tasks.create_task',
            moduleKey: 'tasks',
            pointType: FlowRoutePointType::CreateTask->value,
            name: 'Create task',
        );

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->from(route('crm.flow-routes.index', ['edit_route' => $route->getKey()]))
            ->post('http://crm.'.config('app.root_domain').'/flow-routes/'.$route->getKey().'/points', [
                'capability_id' => $capability->getKey(),
                'title' => 'This stale inline input must not be accepted',
            ])
            ->assertRedirect(route('crm.flow-routes.index', ['edit_route' => $route->getKey()]))
            ->assertSessionHasErrors('task_template_key');

        $this->assertDatabaseMissing('flow_route_points', [
            'flow_route_id' => $route->getKey(),
            'type' => FlowRoutePointType::CreateTask->value,
        ]);
    }

    private function createRoute(): FlowRoute
    {
        $status = ContactStatus::query()->create([
            'key' => 'attempting_contact',
            'name' => 'Attempting Contact',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        return FlowRoute::query()->create([
            'key' => 'attempting_contact_follow_up',
            'contact_status_id' => $status->getKey(),
            'owner_type' => null,
            'owner_id' => null,
            'owner_group' => 'client',
            'name' => 'Attempting Contact Follow-Up',
            'description' => 'Keeps follow-up moving while the contact remains in this status.',
            'version' => 1,
            'is_current_version' => true,
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $status->key,
            'is_active' => true,
            'source_version' => 'test',
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);
    }

    private function createCapability(
        string $key,
        string $moduleKey,
        string $pointType,
        string $name,
    ): FlowRouteCapability {
        return FlowRouteCapability::query()->create([
            'key' => $key,
            'module_key' => $moduleKey,
            'capability_type' => FlowRouteCapability::TYPE_ACTION,
            'point_type' => $pointType,
            'handler_key' => $pointType,
            'event_key' => null,
            'action_key' => null,
            'name' => $name,
            'description' => null,
            'category' => null,
            'surface' => null,
            'supported_subjects' => [],
            'required_modules' => [$moduleKey],
            'input_schema' => [],
            'output_schema' => [],
            'available_fields' => [],
            'defaults' => [],
            'is_active' => true,
            'source' => 'test',
            'source_version' => 'test',
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [
                'runtime' => [
                    'handler_available_at_sync' => true,
                ],
            ],
        ]);
    }

    private function createPoint(
        FlowRoute $route,
        string $key,
        int $sortOrder,
        bool $isStart,
        string $type = FlowRoutePointType::CreateTask->value,
    ): FlowRoutePoint {
        return FlowRoutePoint::query()->create([
            'flow_route_id' => $route->getKey(),
            'flow_route_capability_id' => null,
            'key' => $key,
            'type' => $type,
            'name' => ucfirst($key),
            'description' => null,
            'sort_order' => $sortOrder,
            'is_start' => $isStart,
            'is_active' => true,
            'next_flow_route_point_id' => null,
            'definition' => match ($type) {
                FlowRoutePointType::Wait->value => ['days' => 1],
                FlowRoutePointType::ChangeStatus->value => ['contact_status_key' => 'attempting_contact'],
                default => ['title' => ucfirst($key)],
            },
            'settings' => [],
            'cancel_conditions' => [],
            'source_version' => 'test',
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);
    }
}