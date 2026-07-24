<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Actions\SyncFlowRouteCapabilitiesAction;
use App\Modules\FlowRoutes\Actions\SyncFlowRoutePresetsAction;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use App\Support\Presets\Data\ResolvedPresetDomain;
use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetCompositionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncFlowRoutePresetsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_syncs_webinar_outcome_status_transition_routes(): void
    {
        ContactStatus::query()->create([
            'key' => 'attended_webinar',
            'name' => 'Attended Webinar',
        ]);

        ContactStatus::query()->create([
            'key' => 'missed_webinar',
            'name' => 'Missed Webinar',
        ]);

        $this->syncWebinarFlowRoutes();

        $attendedRoute = FlowRoute::query()
            ->where('key', 'webinar_attended_status_transition')
            ->firstOrFail();

        $missedRoute = FlowRoute::query()
            ->where('key', 'webinar_missed_status_transition')
            ->firstOrFail();

        $this->assertSame('automation_event', $attendedRoute->trigger_type);
        $this->assertSame('webinar.attended', $attendedRoute->trigger_key);
        $this->assertNull($attendedRoute->contact_status_id);

        $this->assertSame('automation_event', $missedRoute->trigger_type);
        $this->assertSame('webinar.missed', $missedRoute->trigger_key);
        $this->assertNull($missedRoute->contact_status_id);

        $this->assertDatabaseHas('flow_route_trigger_bindings', [
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => 'webinar.attended',
            'flow_route_id' => $attendedRoute->getKey(),
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('flow_route_trigger_bindings', [
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => 'webinar.missed',
            'flow_route_id' => $missedRoute->getKey(),
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
        ]);

        $attendedPoint = $this->flowRoutePoint($attendedRoute, 'change_status_to_attended_webinar');
        $missedPoint = $this->flowRoutePoint($missedRoute, 'change_status_to_missed_webinar');

        $this->assertSame('attended_webinar', $attendedPoint->definition['contact_status_key'] ?? null);
        $this->assertSame('missed_webinar', $missedPoint->definition['contact_status_key'] ?? null);

        $this->assertSame(10, $attendedPoint->sort_order);
        $this->assertTrue($attendedPoint->is_start);
        $this->assertNull($attendedPoint->next_flow_route_point_id);
        $this->assertSame([], $attendedPoint->settings);
        $this->assertSame('flow_routes.change_status', $attendedPoint->capability?->key);
        $this->assertSame('webinar_attended_event', $attendedPoint->definition['reason'] ?? null);
        $this->assertFalse($attendedPoint->definition['force'] ?? true);
        $this->assertSame('skipped', $attendedPoint->definition['on_same_status'] ?? null);
        $this->assertEquals([
            'source' => 'flow_route',
            'trigger_type' => 'automation_event',
            'event_key' => 'webinar.attended',
        ], $attendedPoint->definition['meta'] ?? null);
        $this->assertSame([
            'category' => 'webinar',
            'default_role' => 'status_transition',
        ], data_get($attendedRoute->meta, 'definition'));
        $this->assertNull(data_get($attendedPoint->meta, 'definition'));
    }

    public function test_it_syncs_default_webinar_campaign_enrollment_routes(): void
    {
        $this->syncWebinarFlowRoutes();

        $attendedRoute = FlowRoute::query()
            ->where('key', 'webinar_attended_campaign_enrollment')
            ->firstOrFail();

        $missedRoute = FlowRoute::query()
            ->where('key', 'webinar_missed_campaign_enrollment')
            ->firstOrFail();

        $attendedEnrollmentPoint = $this->flowRoutePoint($attendedRoute, 'enroll_webinar_attended_nurture');
        $missedEnrollmentPoint = $this->flowRoutePoint($missedRoute, 'enroll_webinar_missed_nurture');

        $this->assertSame(FlowRoute::TRIGGER_AUTOMATION_EVENT, $attendedRoute->trigger_type);
        $this->assertSame('webinar.attended', $attendedRoute->trigger_key);
        $this->assertNull($attendedRoute->contact_status_id);

        $this->assertSame(FlowRoute::TRIGGER_AUTOMATION_EVENT, $missedRoute->trigger_type);
        $this->assertSame('webinar.missed', $missedRoute->trigger_key);
        $this->assertNull($missedRoute->contact_status_id);

        $this->assertTrue($attendedEnrollmentPoint->is_start);
        $this->assertSame(10, $attendedEnrollmentPoint->sort_order);
        $this->assertSame('campaigns.enroll_contact', $attendedEnrollmentPoint->capability?->key);
        $this->assertSame('webinar_attended_nurture', $attendedEnrollmentPoint->definition['campaign_key'] ?? null);
        $this->assertSame('skipped', $attendedEnrollmentPoint->definition['on_already_enrolled'] ?? null);
        $this->assertSame([], $attendedEnrollmentPoint->definition['payload'] ?? null);
        $this->assertEquals([
            'source' => 'flow_route',
            'reason' => 'webinar_attended_event',
        ], $attendedEnrollmentPoint->definition['meta'] ?? null);
        $this->assertEquals([
            'source' => 'flow_route',
            'trigger_type' => 'automation_event',
            'event_key' => 'webinar.attended',
        ], $attendedEnrollmentPoint->definition['start_context'] ?? null);
        $this->assertSame([], $attendedEnrollmentPoint->definition['exit_conditions'] ?? null);
        $this->assertNull($attendedEnrollmentPoint->next_flow_route_point_id);

        $this->assertTrue($missedEnrollmentPoint->is_start);
        $this->assertSame('webinar_missed_nurture', $missedEnrollmentPoint->definition['campaign_key'] ?? null);
        $this->assertNull($missedEnrollmentPoint->next_flow_route_point_id);
    }

    public function test_preset_sync_materializes_the_ordered_point_map_as_an_explicit_runtime_graph(): void
    {
        app(SyncFlowRouteCapabilitiesAction::class)->handle();

        app(SyncFlowRoutePresetsAction::class)->handle(new ResolvedPresetDomain(
            presetKey: 'test',
            domain: PresetDomain::FlowRoutes,
            selectedGroups: ['test_group'],
            selectedContributors: ['test'],
            definitionKeys: ['ordered_route'],
            definitions: [
                'ordered_route' => [
                    'name' => 'Ordered Route',
                    'source_version' => 'v1',
                    'points' => [
                        'start' => [
                            'type' => 'noop',
                        ],
                        'disabled_placeholder' => [
                            'type' => 'noop',
                            'is_active' => false,
                        ],
                        'wait_one_day' => [
                            'type' => 'wait',
                            'definition' => [
                                'days' => 1,
                            ],
                        ],
                    ],
                ],
            ],
            provenance: [
                'ordered_route' => [
                    'contributor' => 'test',
                    'source' => 'test.flow-routes',
                ],
            ],
            definitionGroups: [
                'ordered_route' => ['test_group'],
            ],
        ));

        $route = FlowRoute::query()
            ->where('key', 'ordered_route')
            ->firstOrFail();
        $start = $this->flowRoutePoint($route, 'start');
        $disabled = $this->flowRoutePoint($route, 'disabled_placeholder');
        $wait = $this->flowRoutePoint($route, 'wait_one_day');

        $this->assertSame(10, $start->sort_order);
        $this->assertTrue($start->is_start);
        $this->assertSame($wait->getKey(), $start->next_flow_route_point_id);
        $this->assertSame('flow_routes.noop', $start->capability?->key);

        $this->assertSame(20, $disabled->sort_order);
        $this->assertFalse($disabled->is_active);
        $this->assertFalse($disabled->is_start);
        $this->assertNull($disabled->next_flow_route_point_id);

        $this->assertSame(30, $wait->sort_order);
        $this->assertFalse($wait->is_start);
        $this->assertNull($wait->next_flow_route_point_id);
        $this->assertSame('flow_routes.wait', $wait->capability?->key);
        $this->assertSame(['days' => 1], $wait->definition);
        $this->assertSame('v1', $wait->source_version);
    }

    public function test_preset_sync_creates_all_default_active_bindings_for_shared_automation_trigger(): void
    {
        ContactStatus::query()->create([
            'key' => 'attended_webinar',
            'name' => 'Attended Webinar',
        ]);

        ContactStatus::query()->create([
            'key' => 'missed_webinar',
            'name' => 'Missed Webinar',
        ]);

        $this->syncWebinarFlowRoutes();

        $attendedRoutes = FlowRoute::query()
            ->where('trigger_type', FlowRoute::TRIGGER_AUTOMATION_EVENT)
            ->where('trigger_key', 'webinar.attended')
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        $missedRoutes = FlowRoute::query()
            ->where('trigger_type', FlowRoute::TRIGGER_AUTOMATION_EVENT)
            ->where('trigger_key', 'webinar.missed')
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        $this->assertCount(2, $attendedRoutes);
        $this->assertCount(2, $missedRoutes);

        $this->assertSame(
            2,
            FlowRouteTriggerBinding::query()
                ->where('trigger_type', FlowRoute::TRIGGER_AUTOMATION_EVENT)
                ->where('trigger_key', 'webinar.attended')
                ->whereNull('context_type')
                ->whereNull('context_id')
                ->where('is_active', true)
                ->whereIn('flow_route_id', $attendedRoutes)
                ->count(),
        );

        $this->assertSame(
            2,
            FlowRouteTriggerBinding::query()
                ->where('trigger_type', FlowRoute::TRIGGER_AUTOMATION_EVENT)
                ->where('trigger_key', 'webinar.missed')
                ->whereNull('context_type')
                ->whereNull('context_id')
                ->where('is_active', true)
                ->whereIn('flow_route_id', $missedRoutes)
                ->count(),
        );
    }

    private function flowRoutePoint(FlowRoute $flowRoute, string $key): FlowRoutePoint
    {
        return FlowRoutePoint::query()
            ->where('flow_route_id', $flowRoute->id)
            ->where('key', $key)
            ->firstOrFail();
    }

    private function syncWebinarFlowRoutes(): void
    {
        config()->set('presets.packages.mortgage', [
            'groups' => [
                'flow_routes' => [
                    'webinar_default',
                ],
            ],
        ]);

        app(SyncFlowRouteCapabilitiesAction::class)->handle();

        app(SyncFlowRoutePresetsAction::class)->handle(
            app(PresetCompositionResolver::class)->resolve(
                'mortgage',
                PresetDomain::FlowRoutes,
            ),
        );
    }
}