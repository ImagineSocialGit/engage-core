<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\Core\Models\ContactStatus;
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

        app(SyncFlowRoutePresetsAction::class)->handle('mortgage');

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

    public function test_it_syncs_status_triggered_mortgage_flow_routes_with_contact_status_bindings(): void
    {
        $prospectStatus = ContactStatus::query()->create([
            'key' => 'prospect',
            'name' => 'Prospect',
        ]);

        app(SyncFlowRoutePresetsAction::class)->handle('mortgage');

        $prospectRoute = FlowRoute::query()
            ->where('key', 'smoke_prospect_cancel_nurture_and_create_task')
            ->firstOrFail();

        $this->assertSame(FlowRoute::TRIGGER_CONTACT_STATUS, $prospectRoute->trigger_type);
        $this->assertSame('prospect', $prospectRoute->trigger_key);
        $this->assertSame($prospectStatus->id, $prospectRoute->contact_status_id);

        $this->assertDatabaseHas('flow_route_trigger_bindings', [
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => 'prospect',
            'flow_route_id' => $prospectRoute->getKey(),
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
        ]);
    }

    public function test_it_syncs_default_attended_nurture_and_prospect_cancellation_routes(): void
    {
        ContactStatus::query()->create([
            'key' => 'prospect',
            'name' => 'Prospect',
        ]);

        app(SyncFlowRoutePresetsAction::class)->handle('mortgage');

        $attendedRoute = FlowRoute::query()
            ->where('key', 'webinar_attended_campaign_enrollment')
            ->firstOrFail();

        $attendedEnrollmentPoint = $this->flowRoutePoint($attendedRoute, 'enroll_webinar_attended_nurture');

        $this->assertTrue($attendedEnrollmentPoint->is_start);
        $this->assertSame('webinar_attended_nurture', $attendedEnrollmentPoint->definition['campaign_key'] ?? null);
        $this->assertNull($attendedEnrollmentPoint->next_flow_route_point_id);

        $disabledLegacySmokeRoute = FlowRoute::query()
            ->where('key', 'smoke_webinar_attended_nurture_test_enrollment')
            ->firstOrFail();

        $this->assertFalse((bool) $disabledLegacySmokeRoute->is_active);

        $prospectRoute = FlowRoute::query()
            ->where('key', 'smoke_prospect_cancel_nurture_and_create_task')
            ->firstOrFail();

        $cancelPoint = $this->flowRoutePoint($prospectRoute, 'smoke_cancel_attended_email_nurture');
        $createTaskPoint = $this->flowRoutePoint($prospectRoute, 'smoke_create_prospect_task');

        $this->assertTrue($cancelPoint->is_start);
        $this->assertSame('webinar_attended_nurture', $cancelPoint->definition['campaign_key'] ?? null);
        $this->assertSame($createTaskPoint->id, $cancelPoint->next_flow_route_point_id);
        $this->assertNull($createTaskPoint->next_flow_route_point_id);
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

        app(SyncFlowRoutePresetsAction::class)->handle('mortgage');

        $attendedRoutes = FlowRoute::query()
            ->where('trigger_type', FlowRoute::TRIGGER_AUTOMATION_EVENT)
            ->where('trigger_key', 'webinar.attended')
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        $this->assertCount(2, $attendedRoutes);

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

        $disabledRoute = FlowRoute::query()
            ->where('key', 'smoke_webinar_attended_nurture_test_enrollment')
            ->firstOrFail();

        $this->assertFalse((bool) $disabledRoute->is_active);
        $this->assertSame(0, $disabledRoute->triggerBindings()->count());
    }

    public function test_preset_sync_does_not_reactivate_existing_default_binding_without_force(): void
    {
        ContactStatus::query()->create([
            'key' => 'prospect',
            'name' => 'Prospect',
        ]);

        app(SyncFlowRoutePresetsAction::class)->handle('mortgage');

        $binding = FlowRouteTriggerBinding::query()
            ->where('trigger_type', FlowRoute::TRIGGER_CONTACT_STATUS)
            ->where('trigger_key', 'prospect')
            ->firstOrFail();

        $binding->forceFill([
            'is_active' => false,
            'meta' => [
                'changed_by' => 'ui',
            ],
        ])->save();

        app(SyncFlowRoutePresetsAction::class)->handle('mortgage');

        $binding->refresh();

        $this->assertFalse((bool) $binding->is_active);
        $this->assertSame('ui', $binding->meta['changed_by'] ?? null);
        $this->assertSame(1, FlowRouteTriggerBinding::query()
            ->where('trigger_type', FlowRoute::TRIGGER_CONTACT_STATUS)
            ->where('trigger_key', 'prospect')
            ->count());
    }

    private function flowRoutePoint(FlowRoute $flowRoute, string $key): FlowRoutePoint
    {
        return FlowRoutePoint::query()
            ->where('flow_route_id', $flowRoute->id)
            ->where('key', $key)
            ->firstOrFail();
    }
}
