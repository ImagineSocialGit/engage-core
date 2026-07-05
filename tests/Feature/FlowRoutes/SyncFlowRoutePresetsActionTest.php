<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Actions\SyncFlowRoutePresetsAction;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
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
    }

    public function test_it_syncs_next_point_links_for_multipoint_mortgage_routes(): void
    {
        ContactStatus::query()->create([
            'key' => 'prospect',
            'name' => 'Prospect',
        ]);

        app(SyncFlowRoutePresetsAction::class)->handle('mortgage');

        $nurtureRoute = FlowRoute::query()
            ->where('key', 'smoke_webinar_attended_nurture_test_enrollment')
            ->firstOrFail();

        $emailEnrollmentPoint = $this->flowRoutePoint($nurtureRoute, 'enroll_webinar_attended_nurture_email_test');
        $smsEnrollmentPoint = $this->flowRoutePoint($nurtureRoute, 'enroll_webinar_attended_nurture_sms_test');

        $this->assertTrue($emailEnrollmentPoint->is_start);
        $this->assertSame($smsEnrollmentPoint->id, $emailEnrollmentPoint->next_flow_route_point_id);
        $this->assertNull($smsEnrollmentPoint->next_flow_route_point_id);

        $prospectRoute = FlowRoute::query()
            ->where('key', 'smoke_prospect_cancel_nurture_and_create_task')
            ->firstOrFail();

        $cancelEmailPoint = $this->flowRoutePoint($prospectRoute, 'smoke_cancel_attended_email_nurture');
        $cancelSmsPoint = $this->flowRoutePoint($prospectRoute, 'smoke_cancel_attended_sms_nurture');
        $createTaskPoint = $this->flowRoutePoint($prospectRoute, 'smoke_create_prospect_task');

        $this->assertTrue($cancelEmailPoint->is_start);
        $this->assertSame($cancelSmsPoint->id, $cancelEmailPoint->next_flow_route_point_id);
        $this->assertSame($createTaskPoint->id, $cancelSmsPoint->next_flow_route_point_id);
        $this->assertNull($createTaskPoint->next_flow_route_point_id);
    }

    private function flowRoutePoint(FlowRoute $flowRoute, string $key): FlowRoutePoint
    {
        return FlowRoutePoint::query()
            ->where('flow_route_id', $flowRoute->id)
            ->where('key', $key)
            ->firstOrFail();
    }
}
