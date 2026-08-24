<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\FlowRoutes\Actions\SyncFlowRouteCapabilitiesAction;
use App\Modules\FlowRoutes\Actions\SyncFlowRoutePresetsAction;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use App\Support\Presets\Data\ResolvedPresetDomain;
use App\Support\Presets\Enums\PresetDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowRoutePresetStaleRetirementTest extends TestCase
{
    use RefreshDatabase;

    public function test_preset_route_removed_from_resolved_domain_is_retired_with_its_default_trigger_binding(): void
    {
        app(SyncFlowRouteCapabilitiesAction::class)->handle();

        $sync = app(SyncFlowRoutePresetsAction::class);

        $sync->handle($this->resolved([
            'retired_route' => [
                'event_key' => 'test.route.triggered',
                'version' => 1,
                'name' => 'Retired route',
                'source_version' => 'test_v1',
                'points' => [
                    'start' => [
                        'type' => 'noop',
                    ],
                ],
            ],
        ]));

        $route = FlowRoute::query()
            ->where('key', 'retired_route')
            ->firstOrFail();

        $this->assertTrue($route->is_active);
        $this->assertDatabaseHas('flow_route_trigger_bindings', [
            'flow_route_id' => $route->getKey(),
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => 'test.route.triggered',
            'is_active' => true,
        ]);

        $result = $sync->handle($this->resolved([]));

        $this->assertFalse($route->fresh()->is_active);
        $this->assertSame(1, $result->updated['flow_routes']);
        $this->assertSame(1, $result->updated['flow_route_trigger_bindings']);

        $this->assertDatabaseHas('flow_route_trigger_bindings', [
            'flow_route_id' => $route->getKey(),
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => 'test.route.triggered',
            'is_active' => false,
        ]);
    }

    public function test_customized_stale_route_is_preserved_without_force(): void
    {
        app(SyncFlowRouteCapabilitiesAction::class)->handle();

        $sync = app(SyncFlowRoutePresetsAction::class);

        $sync->handle($this->resolved([
            'custom_route' => [
                'event_key' => 'test.custom.triggered',
                'version' => 1,
                'name' => 'Custom route',
                'source_version' => 'test_v1',
                'points' => [
                    'start' => [
                        'type' => 'noop',
                    ],
                ],
            ],
        ]));

        $route = FlowRoute::query()
            ->where('key', 'custom_route')
            ->firstOrFail();

        $route->forceFill([
            'is_customized' => true,
            'customized_at' => now(),
        ])->save();

        $result = $sync->handle($this->resolved([]));

        $this->assertTrue($route->fresh()->is_active);
        $this->assertSame(1, $result->skipped['flow_routes']);
        $this->assertNotEmpty($result->warnings);
        $this->assertTrue(
            FlowRouteTriggerBinding::query()
                ->where('flow_route_id', $route->getKey())
                ->where('is_active', true)
                ->exists(),
        );
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     */
    private function resolved(array $definitions): ResolvedPresetDomain
    {
        $keys = array_keys($definitions);

        return new ResolvedPresetDomain(
            presetKey: 'test',
            domain: PresetDomain::FlowRoutes,
            selectedGroups: ['test_group'],
            selectedContributors: ['test'],
            definitionKeys: $keys,
            definitions: $definitions,
            provenance: array_fill_keys($keys, [
                'contributor' => 'test',
                'source' => 'test.flow-routes',
            ]),
            definitionGroups: array_fill_keys($keys, ['test_group']),
        );
    }
}