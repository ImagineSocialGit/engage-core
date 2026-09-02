<?php

namespace Tests\Feature\FlowRoutes;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use App\Modules\FlowRoutes\Services\FlowRouteActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FlowRouteAutomationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['workflow', 'flow_routes']);
        $this->withoutMiddleware(ForceStagingAccess::class);
    }

    public function test_crm_route_with_one_point_stays_a_route_and_its_editor_reopens(): void
    {
        $user = User::factory()->create();
        $route = $this->route('crm_one_point', 'CRM one point', FlowRoute::AUTHORING_KIND_ROUTE);
        $this->changeStatusPoint($route, 'engaged');

        $response = $this->actingAs($user)->get(route('crm.flow-routes.index', [
            'edit_route' => $route->getKey(),
        ]));

        $response
            ->assertOk()
            ->assertViewHas('openRouteEditorId', $route->getKey())
            ->assertViewHas('routeEditors', fn ($editors): bool => $editors->has($route->getKey()))
            ->assertSee('data-flow-route-editor-state', false);

        $this->assertSame(
            FlowRoute::AUTHORING_KIND_ROUTE,
            data_get($route->fresh()->meta, 'authoring.kind'),
        );
    }

    public function test_route_can_be_converted_to_an_editable_automatic_behavior_and_archived(): void
    {
        $user = User::factory()->create();
        $route = $this->route('convert_me', 'Convert me', FlowRoute::AUTHORING_KIND_ROUTE);
        $this->changeStatusPoint($route, 'engaged');

        $this->actingAs($user)
            ->patch(route('crm.flow-routes.kind.update', $route), [
                'authoring_kind' => FlowRoute::AUTHORING_KIND_AUTOMATIC_BEHAVIOR,
            ])
            ->assertRedirect(route('crm.flow-routes.index', ['edit_route' => $route->getKey()]));

        $this->assertSame(
            FlowRoute::AUTHORING_KIND_AUTOMATIC_BEHAVIOR,
            data_get($route->fresh()->meta, 'authoring.kind'),
        );

        $this->actingAs($user)
            ->patch(route('crm.flow-routes.enabled.update', $route), ['enabled' => true])
            ->assertRedirect(route('crm.flow-routes.index'));

        $this->assertTrue($route->activeTriggerBindings()->global()->exists());

        $this->actingAs($user)
            ->delete(route('crm.flow-routes.destroy', $route))
            ->assertRedirect(route('crm.flow-routes.index'));

        $route->refresh();
        $this->assertTrue($route->isArchivedFromAuthoring());
        $this->assertFalse($route->is_active);
        $this->assertFalse($route->activeTriggerBindings()->exists());
    }

    public function test_automation_can_be_turned_on_from_the_editor_without_closing_it(): void
    {
        $user = User::factory()->create();
        $route = $this->route('editor_activation', 'Editor activation', FlowRoute::AUTHORING_KIND_ROUTE);
        $this->changeStatusPoint($route, 'engaged');

        $this->actingAs($user)
            ->patch(route('crm.flow-routes.enabled.update', $route), [
                'enabled' => true,
                'return_to_editor' => true,
            ])
            ->assertRedirect(route('crm.flow-routes.index', [
                'edit_route' => $route->getKey(),
            ]));

        $this->assertTrue($route->activeTriggerBindings()->global()->exists());

        $this->actingAs($user)
            ->get(route('crm.flow-routes.index', [
                'edit_route' => $route->getKey(),
            ]))
            ->assertOk()
            ->assertSee('data-flow-route-editor-state', false)
            ->assertSee('data-flow-route-editor-enabled', false);
    }

    public function test_same_event_and_conditions_cannot_enable_two_contact_status_mutations(): void
    {
        $first = $this->route('first_status', 'Move to Engaged', FlowRoute::AUTHORING_KIND_AUTOMATIC_BEHAVIOR, true);
        $second = $this->route('second_status', 'Move to Application Started', FlowRoute::AUTHORING_KIND_AUTOMATIC_BEHAVIOR);
        $this->changeStatusPoint($first, 'engaged');
        $this->changeStatusPoint($second, 'application_started');

        FlowRouteTriggerBinding::query()->create([
            'trigger_type' => $first->trigger_type,
            'trigger_key' => $first->trigger_key,
            'flow_route_id' => $first->getKey(),
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
            'meta' => [],
        ]);

        try {
            app(FlowRouteActivationService::class)->enable($second);
            $this->fail('The conflicting automation should not have been enabled.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('flow_route', $exception->errors());
        }

        $this->assertTrue($first->activeTriggerBindings()->global()->exists());
        $this->assertFalse($second->activeTriggerBindings()->exists());
    }

    public function test_same_event_can_enable_status_mutations_for_different_entry_conditions(): void
    {
        $first = $this->route(
            'cold_reply',
            'Cold reply',
            FlowRoute::AUTHORING_KIND_AUTOMATIC_BEHAVIOR,
            true,
            [['path' => 'payload.profile_key', 'operator' => 'equals', 'value' => 'cold_lead']],
        );
        $second = $this->route(
            'past_client_reply',
            'Past client reply',
            FlowRoute::AUTHORING_KIND_AUTOMATIC_BEHAVIOR,
            false,
            [['path' => 'payload.profile_key', 'operator' => 'equals', 'value' => 'past_client']],
        );
        $this->changeStatusPoint($first, 'engaged');
        $this->changeStatusPoint($second, 'engaged');

        FlowRouteTriggerBinding::query()->create([
            'trigger_type' => $first->trigger_type,
            'trigger_key' => $first->trigger_key,
            'flow_route_id' => $first->getKey(),
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
            'meta' => [],
        ]);

        app(FlowRouteActivationService::class)->enable($second);

        $this->assertTrue($second->activeTriggerBindings()->global()->exists());
    }

    /** @param array<int, array<string, mixed>> $entryConditions */
    private function route(
        string $key,
        string $name,
        string $kind,
        bool $active = false,
        array $entryConditions = [],
    ): FlowRoute {
        return FlowRoute::query()->create([
            'key' => $key,
            'contact_status_id' => null,
            'owner_type' => null,
            'owner_id' => null,
            'owner_group' => 'client',
            'name' => $name,
            'description' => null,
            'version' => 1,
            'is_current_version' => true,
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => 'appointment.scheduled',
            'is_active' => $active,
            'source_version' => null,
            'is_customized' => true,
            'customized_at' => now(),
            'meta' => [
                'authoring' => ['source' => 'crm', 'kind' => $kind],
                'definition' => ['entry_conditions' => $entryConditions],
            ],
        ]);
    }

    private function changeStatusPoint(FlowRoute $route, string $statusKey): FlowRoutePoint
    {
        return FlowRoutePoint::query()->create([
            'flow_route_id' => $route->getKey(),
            'flow_route_capability_id' => null,
            'key' => 'change_status_'.$statusKey,
            'type' => FlowRoutePointType::ChangeStatus->value,
            'name' => 'Change status',
            'description' => null,
            'sort_order' => 10,
            'is_start' => true,
            'is_active' => true,
            'next_flow_route_point_id' => null,
            'definition' => ['contact_status_key' => $statusKey],
            'settings' => [],
            'cancel_conditions' => [],
            'source_version' => null,
            'is_customized' => true,
            'customized_at' => now(),
            'meta' => [],
        ]);
    }
}