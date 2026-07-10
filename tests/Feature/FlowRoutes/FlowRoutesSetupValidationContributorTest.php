<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Services\PointHandlerRegistry;
use App\Modules\FlowRoutes\Validation\FlowRoutesSetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class FlowRoutesSetupValidationContributorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('client.preset', 'flow_routes_validation_test');
        Config::set('presets.packages.flow_routes_validation_test', [
            'groups' => [
                'contact_statuses' => [],
                'tasks' => [],
                'campaigns' => [],
                'flow_routes' => [],
            ],
        ]);

        Config::set('presets.flow-routes.groups', []);
        Config::set('presets.flow-routes.definitions', []);
    }

    public function test_it_reports_missing_selected_group_and_definition(): void
    {
        Config::set('presets.packages.flow_routes_validation_test.groups.flow_routes', [
            '__test_missing_group__',
            'test_group',
        ]);

        Config::set('presets.flow-routes.groups.test_group', [
            '__test_missing_route__',
        ]);

        $codes = array_column($this->findings(), 'code');

        $this->assertContains('flow_routes.group_missing', $codes);
        $this->assertContains('flow_routes.definition_missing', $codes);
    }

    public function test_it_reports_invalid_graph_and_key_mismatch_through_existing_preset_dto(): void
    {
        Config::set('presets.packages.flow_routes_validation_test.groups.flow_routes', ['test_group']);
        Config::set('presets.flow-routes.groups.test_group', ['route_one']);
        Config::set('presets.flow-routes.definitions.route_one', [
            'key' => 'different_key',
            'name' => 'Route One',
            'trigger' => [
                'type' => 'manual',
            ],
            'is_active' => true,
            'points' => [
                [
                    'key' => 'start',
                    'type' => 'noop',
                    'is_start' => true,
                    'next_point_key' => 'missing',
                ],
            ],
        ]);

        $codes = array_column($this->findings(), 'code');

        $this->assertContains('flow_routes.definition_key_mismatch', $codes);
        $this->assertContains('flow_routes.definition_invalid', $codes);
    }

    public function test_it_reports_missing_task_template_contact_status_and_campaign_references(): void
    {
        Config::set('modules.enabled', ['workflow', 'flow_routes', 'tasks', 'campaigns', 'messaging']);
        Config::set('presets.packages.flow_routes_validation_test.groups.flow_routes', ['test_group']);
        Config::set('presets.flow-routes.groups.test_group', [
            'create_task_route',
            'change_status_route',
            'campaign_route',
        ]);

        Config::set('presets.flow-routes.definitions.create_task_route', $this->routeDefinition(
            key: 'create_task_route',
            point: [
                'key' => 'create_task',
                'type' => 'create_task',
                'capability_key' => 'tasks.create_task',
                'is_start' => true,
                'definition' => [
                    'task_template_key' => '__missing_task_template__',
                ],
            ],
        ));

        Config::set('presets.flow-routes.definitions.change_status_route', $this->routeDefinition(
            key: 'change_status_route',
            point: [
                'key' => 'change_status',
                'type' => 'change_status',
                'capability_key' => 'flow_routes.change_status',
                'is_start' => true,
                'definition' => [
                    'contact_status_key' => '__missing_status__',
                ],
            ],
        ));

        Config::set('presets.flow-routes.definitions.campaign_route', $this->routeDefinition(
            key: 'campaign_route',
            point: [
                'key' => 'enroll',
                'type' => 'enroll_campaign',
                'capability_key' => 'campaigns.enroll_contact',
                'is_start' => true,
                'definition' => [
                    'campaign_key' => '__missing_campaign__',
                ],
            ],
        ));

        $codes = array_column($this->findings(), 'code');

        $this->assertContains('flow_routes.task_template_missing', $codes);
        $this->assertContains('flow_routes.contact_status_missing', $codes);
        $this->assertContains('flow_routes.campaign_missing', $codes);
    }

    public function test_it_reports_runtime_active_route_without_registered_handler(): void
    {
        $route = FlowRoute::query()->create([
            'key' => 'runtime_route',
            'name' => 'Runtime Route',
            'version' => 1,
            'is_current_version' => true,
            'trigger_type' => FlowRoute::TRIGGER_MANUAL,
            'is_active' => true,
            'meta' => [],
        ]);

        FlowRoutePoint::query()->create([
            'flow_route_id' => $route->id,
            'key' => 'customized_point',
            'type' => FlowRoutePointType::CreateTask->value,
            'name' => 'Customized point',
            'description' => null,
            'sort_order' => 10,
            'is_start' => true,
            'is_active' => true,
            'definition' => [],
            'settings' => [],
            'cancel_conditions' => [],
            'is_customized' => true,
            'meta' => [],
        ]);

        $this->app->instance(
            \App\Modules\FlowRoutes\Services\PointHandlerRegistry::class,
            new \App\Modules\FlowRoutes\Services\PointHandlerRegistry([]),
        );

        $this->assertContains(
            'flow_routes.runtime_point_handler_missing',
            array_column($this->findings(), 'code'),
        );
    }


    public function test_it_accepts_valid_selected_noop_route_without_runtime_findings(): void
    {
        Config::set('presets.packages.flow_routes_validation_test.groups.flow_routes', ['test_group']);
        Config::set('presets.flow-routes.groups.test_group', ['noop_route']);

        Config::set('presets.flow-routes.definitions.noop_route', $this->routeDefinition(
            key: 'noop_route',
            point: [
                'key' => 'start',
                'type' => FlowRoutePointType::Noop->value,
                'is_start' => true,
            ],
        ));

        $this->assertSame([], $this->findings());
    }

    public function test_it_distinguishes_declared_capability_from_missing_executable_handler(): void
    {
        Config::set('modules.enabled', ['workflow', 'flow_routes', 'tasks']);
        Config::set('presets.packages.flow_routes_validation_test.groups.flow_routes', ['test_group']);
        Config::set('presets.flow-routes.groups.test_group', ['create_task_route']);

        Config::set('presets.flow-routes.definitions.create_task_route', $this->routeDefinition(
            key: 'create_task_route',
            point: [
                'key' => 'create_task',
                'type' => FlowRoutePointType::CreateTask->value,
                'capability_key' => 'tasks.create_task',
                'is_start' => true,
                'definition' => [
                    'title' => 'Follow up with contact',
                ],
            ],
        ));

        $this->app->instance(
            PointHandlerRegistry::class,
            new PointHandlerRegistry([]),
        );

        $codes = array_column($this->findings(), 'code');

        $this->assertContains('flow_routes.point_handler_missing', $codes);
        $this->assertNotContains('flow_routes.capability_missing', $codes);
    }

    public function test_it_reports_multiple_current_versions_for_one_logical_route(): void
    {
        FlowRoute::query()->create([
            'key' => 'logical_route',
            'name' => 'Logical Route v1',
            'version' => 1,
            'is_current_version' => true,
            'trigger_type' => FlowRoute::TRIGGER_MANUAL,
            'is_active' => false,
            'meta' => [],
        ]);

        FlowRoute::query()->create([
            'key' => 'logical_route',
            'name' => 'Logical Route v2',
            'version' => 2,
            'is_current_version' => true,
            'trigger_type' => FlowRoute::TRIGGER_MANUAL,
            'is_active' => false,
            'meta' => [],
        ]);

        $this->assertContains(
            'flow_routes.multiple_current_versions',
            array_column($this->findings(), 'code'),
        );
    }

    public function test_it_reports_active_binding_to_inactive_or_historical_route(): void
    {
        $route = FlowRoute::query()->create([
            'key' => 'historical_route',
            'name' => 'Historical Route',
            'version' => 1,
            'is_current_version' => false,
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => 'webinar.attended',
            'is_active' => true,
            'meta' => [],
        ]);

        FlowRouteTriggerBinding::query()->create([
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => 'webinar.attended',
            'flow_route_id' => $route->getKey(),
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
            'meta' => [],
        ]);

        $this->assertContains(
            'flow_routes.active_binding_route_unavailable',
            array_column($this->findings(), 'code'),
        );
    }

    /**
     * @param array<string, mixed> $point
     * @return array<string, mixed>
     */
    private function routeDefinition(string $key, array $point): array
    {
        return [
            'key' => $key,
            'name' => str($key)->headline()->toString(),
            'trigger' => ['type' => 'manual'],
            'is_active' => true,
            'points' => [$point],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findings(): array
    {
        return array_map(
            fn (SetupValidationFinding $finding): array => $finding->toArray(),
            iterator_to_array(
                app(FlowRoutesSetupValidationContributor::class)->findings(),
                false,
            ),
        );
    }
}
