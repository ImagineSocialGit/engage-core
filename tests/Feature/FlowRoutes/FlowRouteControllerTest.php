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

    public function test_conditional_point_fields_render_attribute_safe_alpine_expressions(): void
    {
        $this->view('crm.flow-routes.partials.point-fields', [
            'fieldSuffix' => 'test',
            'fields' => [
                [
                    'type' => 'select',
                    'name' => 'message_role',
                    'label' => 'Message role',
                    'state' => true,
                    'value' => 'reply',
                    'options' => [
                        ['value' => 'initiatory', 'label' => 'Initiatory'],
                        ['value' => 'reply', 'label' => 'Reply'],
                    ],
                ],
                [
                    'type' => 'component',
                    'component' => 'messaging.route-message-template-picker',
                    'name' => 'message_template_preset_id_email',
                    'label' => 'Email reply template',
                    'show_when' => [
                        'field' => 'message_role',
                        'equals' => 'reply',
                    ],
                    'active_when' => [
                        'field' => 'message_role',
                        'equals' => 'reply',
                    ],
                    'options' => [],
                    'create_url' => '#',
                    'available_fields' => [],
                    'available_channels' => ['email'],
                    'purposes' => [],
                ],
            ],
        ])
            ->assertSee('x-model="authoringState.message_role"', false)
            ->assertSee(
                'x-show="authoringState.message_role === \'reply\'"',
                false,
            )
            ->assertSee(
                'x-bind:disabled="authoringState.message_role !== \'reply\'"',
                false,
            )
            ->assertSee(
                'x-bind:required="authoringState.message_role === \'reply\'"',
                false,
            )
            ->assertDontSee('authoringState[', false);
    }

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
            ->assertViewHas('routes', function ($routes) use ($route): bool {
                $presented = collect($routes)->firstWhere('id', $route->getKey());

                return is_array($presented)
                    && ($presented['name'] ?? null) === $route->name
                    && collect($presented['presented_points'] ?? [])
                        ->contains(fn (mixed $point): bool => is_array($point)
                            && ($point['module_key'] ?? null) === 'tasks');
            })
            ->assertSee($route->name)
            ->assertSee('Create follow-up task')
            ->assertSee('data-flow-route-step-list', false)
            ->assertSee('data-module="tasks"', false)
            ->assertSee('data-flow-route-point-module-filters', false)
            ->assertDontSee(
                route('crm.flow-routes.bindings.index'),
                false,
            );
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
            ->assertViewHas('automaticBehaviors', function ($behaviors) use ($assignedRoute, $availableRoute): bool {
                $byId = collect($behaviors)->keyBy('id');

                return $byId->has($assignedRoute->getKey())
                    && $byId->has($availableRoute->getKey())
                    && (bool) data_get($byId->get($assignedRoute->getKey()), 'is_enabled') === true
                    && (bool) data_get($byId->get($availableRoute->getKey()), 'is_enabled') === false;
            })
            ->assertViewHas('routeSummary', function (array $summary): bool {
                return ($summary['automatic_behaviors'] ?? null) === 2
                    && ($summary['enabled'] ?? null) === 1;
            })
            ->assertSee($assignedRoute->name)
            ->assertSee($availableRoute->name)
            ->assertSee('data-automatic-behavior-sentence', false);

        $this->assertTrue($assignedRoute->activeTriggerBindings()->exists());
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