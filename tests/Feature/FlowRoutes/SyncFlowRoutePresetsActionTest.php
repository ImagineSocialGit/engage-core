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
        $attendedStatus = ContactStatus::query()->create([
            'key' => 'attended_webinar',
            'name' => 'Attended Webinar',
        ]);

        $inProcessStatus = ContactStatus::query()->create([
            'key' => 'in_process',
            'name' => 'In Process',
        ]);

        app(SyncFlowRoutePresetsAction::class)->handle('mortgage');

        $attendedRoute = FlowRoute::query()
            ->where('key', 'smoke_attended_webinar_to_in_process')
            ->firstOrFail();

        $inProcessRoute = FlowRoute::query()
            ->where('key', 'smoke_in_process_task_completion_message')
            ->firstOrFail();

        $this->assertSame(FlowRoute::TRIGGER_CONTACT_STATUS, $attendedRoute->trigger_type);
        $this->assertSame('attended_webinar', $attendedRoute->trigger_key);
        $this->assertSame($attendedStatus->id, $attendedRoute->contact_status_id);

        $this->assertSame(FlowRoute::TRIGGER_CONTACT_STATUS, $inProcessRoute->trigger_type);
        $this->assertSame('in_process', $inProcessRoute->trigger_key);
        $this->assertSame($inProcessStatus->id, $inProcessRoute->contact_status_id);
    }

    public function test_it_syncs_next_point_links_for_multipoint_mortgage_routes(): void
    {
        ContactStatus::query()->create([
            'key' => 'attended_webinar',
            'name' => 'Attended Webinar',
        ]);

        ContactStatus::query()->create([
            'key' => 'in_process',
            'name' => 'In Process',
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

        $taskRoute = FlowRoute::query()
            ->where('key', 'smoke_in_process_task_completion_message')
            ->firstOrFail();

        $createTaskPoint = $this->flowRoutePoint($taskRoute, 'smoke_create_attended_webinar_review_task');
        $waitPoint = $this->flowRoutePoint($taskRoute, 'smoke_wait_for_review_task_completed');
        $emailPoint = $this->flowRoutePoint($taskRoute, 'smoke_send_task_done_email');
        $smsPoint = $this->flowRoutePoint($taskRoute, 'smoke_send_task_done_sms');

        $this->assertTrue($createTaskPoint->is_start);
        $this->assertSame($waitPoint->id, $createTaskPoint->next_flow_route_point_id);
        $this->assertSame($emailPoint->id, $waitPoint->next_flow_route_point_id);
        $this->assertSame($smsPoint->id, $emailPoint->next_flow_route_point_id);
        $this->assertNull($smsPoint->next_flow_route_point_id);
    }

    private function flowRoutePoint(FlowRoute $flowRoute, string $key): FlowRoutePoint
    {
        return FlowRoutePoint::query()
            ->where('flow_route_id', $flowRoute->id)
            ->where('key', $key)
            ->firstOrFail();
    }
}

