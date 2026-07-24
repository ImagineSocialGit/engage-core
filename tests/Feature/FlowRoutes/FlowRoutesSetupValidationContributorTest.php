<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use App\Modules\FlowRoutes\Services\PointHandlerRegistry;
use App\Modules\FlowRoutes\Validation\FlowRoutesSetupValidationContributor;
use App\Modules\Tasks\Models\TaskTemplate;
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

        Config::set('presets.modules.webinars.flow-routes.groups', []);
        Config::set('presets.modules.webinars.flow-routes.definitions', []);
    }

    public function test_it_reports_an_active_compact_route_without_an_active_point(): void
    {
        $this->selectDefinitions(['route_one']);

        Config::set(
            'presets.modules.webinars.flow-routes.definitions.route_one',
            $this->routeDefinition(
                pointKey: 'start',
                point: [
                    'type' => FlowRoutePointType::Noop->value,
                    'is_active' => false,
                ],
            ),
        );

        $this->assertContains(
            'flow_routes.definition_invalid',
            array_column($this->findings(), 'code'),
        );
    }

    public function test_it_reports_missing_task_template_contact_status_and_campaign_references(): void
    {
        Config::set('modules.enabled', [
            'workflow',
            'flow_routes',
            'tasks',
            'campaigns',
            'messaging',
        ]);
        $this->selectDefinitions([
            'create_task_route',
            'change_status_route',
            'campaign_route',
        ]);

        Config::set(
            'presets.modules.webinars.flow-routes.definitions.create_task_route',
            $this->routeDefinition(
                pointKey: 'create_task',
                point: [
                    'type' => FlowRoutePointType::CreateTask->value,
                    'definition' => [
                        'task_template_key' => '__missing_task_template__',
                    ],
                ],
            ),
        );

        Config::set(
            'presets.modules.webinars.flow-routes.definitions.change_status_route',
            $this->routeDefinition(
                pointKey: 'change_status',
                point: [
                    'type' => FlowRoutePointType::ChangeStatus->value,
                    'definition' => [
                        'contact_status_key' => '__missing_status__',
                    ],
                ],
            ),
        );

        Config::set(
            'presets.modules.webinars.flow-routes.definitions.campaign_route',
            $this->routeDefinition(
                pointKey: 'enroll',
                point: [
                    'type' => FlowRoutePointType::EnrollCampaign->value,
                    'definition' => [
                        'campaign_key' => '__missing_campaign__',
                    ],
                ],
            ),
        );

        $codes = array_column($this->findings(), 'code');

        $this->assertContains('flow_routes.task_template_missing', $codes);
        $this->assertContains('flow_routes.contact_status_missing', $codes);
        $this->assertContains('flow_routes.campaign_missing', $codes);
    }

    public function test_it_accepts_inactive_campaign_reference_as_dormant_but_valid(): void
    {
        Config::set('modules.enabled', [
            'workflow',
            'flow_routes',
            'campaigns',
            'messaging',
        ]);
        $this->selectDefinitions(['campaign_route']);

        $campaign = Campaign::factory()->create([
            'key' => 'dormant_campaign',
            'status' => Campaign::STATUS_INACTIVE,
        ]);

        Config::set(
            'presets.modules.webinars.flow-routes.definitions.campaign_route',
            $this->routeDefinition(
                pointKey: 'enroll',
                point: [
                    'type' => FlowRoutePointType::EnrollCampaign->value,
                    'definition' => [
                        'campaign_key' => $campaign->key,
                    ],
                ],
            ),
        );

        $this->assertNotContains(
            'flow_routes.campaign_missing',
            array_column($this->findings(), 'code'),
        );
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
            PointHandlerRegistry::class,
            new PointHandlerRegistry([]),
        );

        $this->assertContains(
            'flow_routes.runtime_point_handler_missing',
            array_column($this->findings(), 'code'),
        );
    }

    public function test_it_accepts_valid_selected_noop_route_without_runtime_findings(): void
    {
        Config::set('modules.enabled', ['workflow', 'flow_routes']);
        $this->selectDefinitions(['noop_route']);

        Config::set(
            'presets.modules.webinars.flow-routes.definitions.noop_route',
            $this->routeDefinition(
                pointKey: 'start',
                point: [
                    'type' => FlowRoutePointType::Noop->value,
                ],
            ),
        );

        $this->assertSame([], $this->findings());
    }

    public function test_it_distinguishes_declared_capability_from_missing_executable_handler(): void
    {
        Config::set('modules.enabled', ['workflow', 'flow_routes', 'tasks']);
        $this->selectDefinitions(['create_task_route']);

        $template = TaskTemplate::factory()->create([
            'key' => 'route.follow_up',
            'title' => 'Follow up with contact',
        ]);

        Config::set(
            'presets.modules.webinars.flow-routes.definitions.create_task_route',
            $this->routeDefinition(
                pointKey: 'create_task',
                point: [
                    'type' => FlowRoutePointType::CreateTask->value,
                    'definition' => [
                        'task_template_key' => $template->key,
                    ],
                ],
            ),
        );

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
    private function routeDefinition(string $pointKey, array $point): array
    {
        return [
            'name' => 'Validation Route',
            'points' => [
                $pointKey => $point,
            ],
        ];
    }

    /** @param array<int, string> $definitionKeys */
    private function selectDefinitions(array $definitionKeys): void
    {
        Config::set(
            'presets.packages.flow_routes_validation_test.groups.flow_routes',
            ['test_group'],
        );
        Config::set(
            'presets.modules.webinars.flow-routes.groups.test_group',
            $definitionKeys,
        );
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