<?php

namespace Tests\Feature\FlowRoutes;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\DashboardAcknowledgement;
use App\Models\User;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Support\Guidance\FirstUseGuidance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowRouteLeadInDelayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['workflow', 'flow_routes']);
        $this->withoutMiddleware(ForceStagingAccess::class);
    }

    public function test_route_start_delay_creates_an_ordinary_first_wait_point(): void
    {
        $user = User::factory()->create();
        $route = $this->createRoute();
        $action = $this->createActionPoint($route);
        $this->createWaitCapability();

        $this
            ->actingAs($user)
            ->from(route('crm.flow-routes.index', ['edit_route' => $route->getKey()]))
            ->patch(route('crm.flow-routes.start-delay.update', $route), [
                'start_timing' => 'delayed',
                'wait_mode' => 'duration',
                'duration_value' => 2,
                'duration_unit' => 'business_days',
            ])
            ->assertRedirect(route('crm.flow-routes.index', [
                'edit_route' => $route->getKey(),
            ]))
            ->assertSessionHas(FirstUseGuidance::SESSION_KEY, function (array $guidance): bool {
                return isset(
                    $guidance['title'],
                    $guidance['message'],
                    $guidance['action_label'],
                    $guidance['action_url'],
                );
            });

        $points = $route->activeFlowRoutePoints()->orderBy('sort_order')->get();
        $wait = $points->first();

        $this->assertCount(2, $points);
        $this->assertSame(FlowRoutePointType::Wait->value, $wait->type);
        $this->assertSame(['business_days' => 2], $wait->definition);
        $this->assertTrue($wait->is_start);
        $this->assertSame($action->getKey(), $wait->next_flow_route_point_id);
        $this->assertFalse($action->refresh()->is_start);
        $this->assertDatabaseHas('dashboard_acknowledgements', [
            'user_id' => $user->getKey(),
            'surface' => FirstUseGuidance::SURFACE,
            'item_type' => FirstUseGuidance::TYPE_SETTING_LOCATION,
            'item_key' => 'business_days',
        ]);
    }

    public function test_saving_the_lead_in_delay_updates_the_existing_first_wait(): void
    {
        $user = User::factory()->create();
        $route = $this->createRoute();
        $action = $this->createActionPoint($route);
        $this->createWaitCapability();

        $this->actingAs($user)->patch(route('crm.flow-routes.start-delay.update', $route), [
            'start_timing' => 'delayed',
            'wait_mode' => 'duration',
            'duration_value' => 1,
            'duration_unit' => 'days',
        ]);

        $firstWaitId = $route->activeFlowRoutePoints()
            ->orderBy('sort_order')
            ->firstOrFail()
            ->getKey();

        $this->actingAs($user)->patch(route('crm.flow-routes.start-delay.update', $route), [
            'start_timing' => 'delayed',
            'wait_mode' => 'duration',
            'duration_value' => 3,
            'duration_unit' => 'business_days',
        ]);

        $points = $route->activeFlowRoutePoints()->orderBy('sort_order')->get();

        $this->assertCount(2, $points);
        $this->assertSame($firstWaitId, $points->first()->getKey());
        $this->assertSame(['business_days' => 3], $points->first()->definition);
        $this->assertSame($action->getKey(), $points->last()->getKey());
    }

    public function test_starting_immediately_removes_only_the_initial_wait(): void
    {
        $user = User::factory()->create();
        $route = $this->createRoute();
        $action = $this->createActionPoint($route);
        $this->createWaitCapability();

        $this->actingAs($user)->patch(route('crm.flow-routes.start-delay.update', $route), [
            'start_timing' => 'delayed',
            'wait_mode' => 'duration',
            'duration_value' => 2,
            'duration_unit' => 'business_days',
        ]);

        $wait = $route->activeFlowRoutePoints()->orderBy('sort_order')->firstOrFail();

        $this->actingAs($user)->patch(route('crm.flow-routes.start-delay.update', $route), [
            'start_timing' => 'immediate',
        ]);

        $this->assertFalse($wait->refresh()->is_active);
        $this->assertTrue($action->refresh()->is_active);
        $this->assertTrue($action->is_start);
        $this->assertNull($action->next_flow_route_point_id);
    }

    public function test_business_day_setting_location_guidance_is_shown_only_once_per_user(): void
    {
        $user = User::factory()->create();
        $route = $this->createRoute();
        $this->createActionPoint($route);
        $this->createWaitCapability();

        $input = [
            'start_timing' => 'delayed',
            'wait_mode' => 'duration',
            'duration_value' => 2,
            'duration_unit' => 'business_days',
        ];

        $this
            ->actingAs($user)
            ->patch(route('crm.flow-routes.start-delay.update', $route), $input)
            ->assertSessionHas(FirstUseGuidance::SESSION_KEY);

        $this
            ->actingAs($user)
            ->get(route('crm.flow-routes.index'))
            ->assertOk();

        $this
            ->actingAs($user)
            ->patch(route('crm.flow-routes.start-delay.update', $route), $input)
            ->assertSessionMissing(FirstUseGuidance::SESSION_KEY);

        $otherUser = User::factory()->create();

        $this
            ->actingAs($otherUser)
            ->patch(route('crm.flow-routes.start-delay.update', $route), $input)
            ->assertSessionHas(FirstUseGuidance::SESSION_KEY);

        $this->assertSame(1, DashboardAcknowledgement::query()
            ->where('user_id', $user->getKey())
            ->surface(FirstUseGuidance::SURFACE)
            ->item(FirstUseGuidance::TYPE_SETTING_LOCATION, 'business_days')
            ->count());
        $this->assertSame(1, DashboardAcknowledgement::query()
            ->where('user_id', $otherUser->getKey())
            ->surface(FirstUseGuidance::SURFACE)
            ->item(FirstUseGuidance::TYPE_SETTING_LOCATION, 'business_days')
            ->count());
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
    }

    private function createWaitCapability(): FlowRouteCapability
    {
        return FlowRouteCapability::query()->create([
            'key' => 'flow_routes.wait',
            'module_key' => 'flow_routes',
            'capability_type' => FlowRouteCapability::TYPE_ACTION,
            'point_type' => FlowRoutePointType::Wait->value,
            'handler_key' => FlowRoutePointType::Wait->value,
            'name' => 'Wait',
            'description' => null,
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
                'runtime' => [
                    'handler_available_at_sync' => true,
                ],
            ],
        ]);
    }

    private function createActionPoint(FlowRoute $route): FlowRoutePoint
    {
        return FlowRoutePoint::query()->create([
            'flow_route_id' => $route->getKey(),
            'key' => 'first_action',
            'type' => FlowRoutePointType::Noop->value,
            'name' => 'First action',
            'sort_order' => 10,
            'is_start' => true,
            'is_active' => true,
            'definition' => [],
            'settings' => [],
            'cancel_conditions' => [],
            'is_customized' => false,
            'meta' => [],
        ]);
    }
}