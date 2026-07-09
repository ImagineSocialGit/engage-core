<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Actions\SyncFlowRouteCapabilitiesAction;
use App\Modules\FlowRoutes\Actions\SyncFlowRoutePresetsAction;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
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

        $this->syncMortgageFlowRoutes();

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
    }

    public function test_it_syncs_default_webinar_campaign_enrollment_routes(): void
    {
        $this->syncMortgageFlowRoutes();

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
        $this->assertSame('webinar_attended_nurture', $attendedEnrollmentPoint->definition['campaign_key'] ?? null);
        $this->assertNull($attendedEnrollmentPoint->next_flow_route_point_id);

        $this->assertTrue($missedEnrollmentPoint->is_start);
        $this->assertSame('webinar_missed_nurture', $missedEnrollmentPoint->definition['campaign_key'] ?? null);
        $this->assertNull($missedEnrollmentPoint->next_flow_route_point_id);
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

        $this->syncMortgageFlowRoutes();

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

    private function syncMortgageFlowRoutes(): void
    {
        app(SyncFlowRouteCapabilitiesAction::class)->handle();

        app(SyncFlowRoutePresetsAction::class)->handle('mortgage');
    }
}