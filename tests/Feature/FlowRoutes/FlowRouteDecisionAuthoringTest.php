<?php

namespace Tests\Feature\FlowRoutes;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Services\FlowRoutePointPlacementPolicy;
use App\Modules\FlowRoutes\Services\FlowRoutePresentationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowRouteDecisionAuthoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['workflow', 'flow_routes']);
        $this->withoutMiddleware(ForceStagingAccess::class);
    }

    public function test_decision_is_authorable_and_creates_a_forward_path_before_its_destination(): void
    {
        $user = User::factory()->create();
        [$route, $sourceStatus] = $this->route();
        $destinationStatus = ContactStatus::query()->create([
            'key' => 'engaged',
            'name' => 'Engaged',
            'is_active' => true,
            'sort_order' => 20,
        ]);
        $destination = $this->point($route, 'create_follow_up_task', 10, true);
        $capability = $this->capability();

        $catalogResponse = $this->actingAs($user)->get(route('crm.flow-routes.index', [
            'edit_route' => $route->getKey(),
        ]));

        $catalogResponse->assertOk();
        $decisionCapability = $catalogResponse
            ->viewData('routeEditors')
            ->get($route->getKey())['capabilities']
            ->firstWhere('point_type', FlowRoutePointType::BranchEvaluate->value);

        $this->assertIsArray($decisionCapability);
        $this->assertContains(
            'decision_target_point_key',
            collect($decisionCapability['fields'])->pluck('name')->filter()->all(),
        );

        $this->actingAs($user)
            ->post('http://crm.'.config('app.root_domain').'/flow-routes/'.$route->getKey().'/points', [
                'capability_id' => $capability->getKey(),
                'name' => 'Should this contact be handled as engaged?',
                'decision_fact' => 'contact_status',
                'decision_status_key' => $destinationStatus->key,
                'decision_target_point_key' => $destination->key,
                'decision_otherwise_target_point_key' => '',
            ])
            ->assertRedirect(route('crm.flow-routes.index', [
                'edit_route' => $route->getKey(),
            ]));

        $decision = FlowRoutePoint::query()
            ->where('flow_route_id', $route->getKey())
            ->where('type', FlowRoutePointType::BranchEvaluate->value)
            ->firstOrFail();
        $ordered = $route->activeFlowRoutePoints()->orderBy('sort_order')->get();
        $condition = $decision->definition['branches'][0]['conditions'][0];

        $this->assertSame([$decision->getKey(), $destination->getKey()], $ordered->pluck('id')->all());
        $this->assertSame('contact_status', $condition['source']);
        $this->assertSame('equals', $condition['operator']);
        $this->assertSame($destinationStatus->key, $condition['value']);
        $this->assertSame(
            $destination->key,
            $decision->definition['branches'][0]['target_flow_route_point_key'],
        );
        $this->assertSame($sourceStatus->getKey(), $route->contact_status_id);

        $presentation = app(FlowRoutePresentationResolver::class)->route($route->fresh());
        $presentedDecision = collect($presentation['presented_points'])
            ->firstWhere('key', $decision->key);

        $this->assertIsArray($presentedDecision);
        $this->assertSame(FlowRoutePointType::BranchEvaluate->value, $presentedDecision['type']);
        $this->assertNull($presentedDecision['label']);
        $this->assertCount(2, $presentedDecision['decision_paths']);
        $this->assertSame(
            $destination->key,
            $presentedDecision['decision_paths'][0]['destination_key'],
        );

        $this->actingAs($user)
            ->get(route('crm.flow-routes.index', ['edit_route' => $route->getKey()]))
            ->assertOk()
            ->assertSee('data-flow-route-decision-paths', false);
    }

    public function test_decision_paths_must_reference_active_later_points(): void
    {
        $policy = app(FlowRoutePointPlacementPolicy::class);
        $destination = new FlowRoutePoint([
            'key' => 'destination',
            'type' => FlowRoutePointType::CreateTask->value,
        ]);
        $decision = new FlowRoutePoint([
            'key' => 'decision',
            'type' => FlowRoutePointType::BranchEvaluate->value,
            'definition' => [
                'branches' => [[
                    'conditions' => [[
                        'source' => 'contact_tags',
                        'operator' => 'contains',
                        'value' => 'Hand Raiser',
                    ]],
                    'target_flow_route_point_key' => 'destination',
                ]],
                'on_no_match' => 'completed',
            ],
        ]);

        $this->assertSame(
            FlowRoutePointPlacementPolicy::VIOLATION_DECISION_TARGET_NOT_FORWARD,
            $policy->firstViolation(collect([$destination, $decision])),
        );
        $this->assertSame(
            FlowRoutePointPlacementPolicy::VIOLATION_DECISION_TARGET_MISSING,
            $policy->firstViolation(collect([$decision])),
        );
        $this->assertNull($policy->firstViolation(collect([$decision, $destination])));
    }

    /** @return array{0: FlowRoute, 1: ContactStatus} */
    private function route(): array
    {
        $status = ContactStatus::query()->create([
            'key' => 'attempting_contact',
            'name' => 'Attempting Contact',
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $route = FlowRoute::query()->create([
            'key' => 'decision_authoring_fixture',
            'contact_status_id' => $status->getKey(),
            'owner_type' => null,
            'owner_id' => null,
            'owner_group' => 'client',
            'name' => 'Decision authoring fixture',
            'description' => null,
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

        return [$route, $status];
    }

    private function capability(): FlowRouteCapability
    {
        return FlowRouteCapability::query()->create([
            'key' => 'flow_routes.branch_evaluate',
            'module_key' => 'flow_routes',
            'capability_type' => FlowRouteCapability::TYPE_ACTION,
            'point_type' => FlowRoutePointType::BranchEvaluate->value,
            'handler_key' => FlowRoutePointType::BranchEvaluate->value,
            'event_key' => null,
            'action_key' => null,
            'name' => 'Branch Evaluate',
            'description' => null,
            'category' => null,
            'surface' => null,
            'supported_subjects' => [],
            'required_modules' => ['flow_routes'],
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
                'runtime' => ['handler_available_at_sync' => true],
            ],
        ]);
    }

    private function point(
        FlowRoute $route,
        string $key,
        int $sortOrder,
        bool $isStart,
    ): FlowRoutePoint {
        return FlowRoutePoint::query()->create([
            'flow_route_id' => $route->getKey(),
            'flow_route_capability_id' => null,
            'key' => $key,
            'type' => FlowRoutePointType::CreateTask->value,
            'name' => 'Create follow-up task',
            'description' => null,
            'sort_order' => $sortOrder,
            'is_start' => $isStart,
            'is_active' => true,
            'next_flow_route_point_id' => null,
            'definition' => ['title' => 'Follow up'],
            'settings' => [],
            'cancel_conditions' => [],
            'source_version' => 'test',
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);
    }
}