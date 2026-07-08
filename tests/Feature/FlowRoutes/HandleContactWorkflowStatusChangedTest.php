<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use App\Modules\FlowRoutes\Models\Point;
use App\Modules\Workflow\Actions\TransitionContactWorkflowStatusAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HandleContactWorkflowStatusChangedTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_status_change_starts_and_executes_flow_route_progress(): void
    {
        $contact = Contact::factory()->create();

        $status = ContactStatus::query()->create([
            'key' => 'new_lead',
            'name' => 'New Lead',
        ]);

        $flowRoute = FlowRoute::query()->create([
            'key' => 'new_lead_route',
            'contact_status_id' => $status->id,
            'name' => 'New Lead Route',
            'description' => null,
            'version' => 1,
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $status->key,
            'is_active' => true,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $this->bindRouteToContactStatus($flowRoute, $status);

        $point = Point::query()->create([
            'key' => 'mark_started',
            'type' => Point::TYPE_NOOP,
            'name' => 'Mark Started',
        ]);

        $flowRoutePoint = FlowRoutePoint::query()->create([
            'flow_route_id' => $flowRoute->id,
            'point_id' => $point->id,
            'key' => 'mark_started',
            'sort_order' => 10,
            'is_start' => true,
            'is_active' => true,
            'next_flow_route_point_id' => null,
            'definition' => [],
            'settings' => [],
            'cancel_conditions' => [],
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        app(TransitionContactWorkflowStatusAction::class)->handle(
            contact: $contact,
            toStatus: $status,
            reason: 'manual_update',
            source: 'test',
        );

        $progress = ContactFlowRouteProgress::query()->first();

        $this->assertNotNull($progress);
        $this->assertSame($contact->id, $progress->contact_id);
        $this->assertSame($status->id, $progress->contact_status_id);
        $this->assertSame($flowRoute->id, $progress->flow_route_id);
        $this->assertNull($progress->current_flow_route_point_id);
        $this->assertSame(ContactFlowRouteProgress::STATUS_COMPLETED, $progress->status);
        $this->assertNotNull($progress->started_at);
        $this->assertNotNull($progress->completed_at);
        $this->assertSame('test', $progress->meta['started_from_workflow_transition']['source']);
    }

    public function test_workflow_status_change_supersedes_existing_progress_and_starts_new_progress(): void
    {
        $contact = Contact::factory()->create();

        $oldStatus = ContactStatus::query()->create([
            'key' => 'new_lead',
            'name' => 'New Lead',
        ]);

        $newStatus = ContactStatus::query()->create([
            'key' => 'consultation_scheduled',
            'name' => 'Consultation Scheduled',
        ]);

        $oldRoute = FlowRoute::query()->create([
            'key' => 'new_lead_route',
            'contact_status_id' => $oldStatus->id,
            'name' => 'New Lead Route',
            'description' => null,
            'version' => 1,
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $oldStatus->key,
            'is_active' => true,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $newRoute = FlowRoute::query()->create([
            'key' => 'consultation_scheduled_route',
            'contact_status_id' => $newStatus->id,
            'name' => 'Consultation Scheduled Route',
            'description' => null,
            'version' => 1,
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $newStatus->key,
            'is_active' => true,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $this->bindRouteToContactStatus($oldRoute, $oldStatus);
        $this->bindRouteToContactStatus($newRoute, $newStatus);

        $oldPoint = Point::query()->create([
            'key' => 'old_wait',
            'type' => Point::TYPE_WAIT,
            'name' => 'Old Wait',
        ]);

        FlowRoutePoint::query()->create([
            'flow_route_id' => $oldRoute->id,
            'point_id' => $oldPoint->id,
            'key' => 'old_wait',
            'sort_order' => 10,
            'is_start' => true,
            'is_active' => true,
            'next_flow_route_point_id' => null,
            'definition' => [
                'seconds' => 3600,
            ],
            'settings' => [],
            'cancel_conditions' => [],
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $newPoint = Point::query()->create([
            'key' => 'new_noop',
            'type' => Point::TYPE_NOOP,
            'name' => 'New Noop',
        ]);

        FlowRoutePoint::query()->create([
            'flow_route_id' => $newRoute->id,
            'point_id' => $newPoint->id,
            'key' => 'new_noop',
            'sort_order' => 10,
            'is_start' => true,
            'is_active' => true,
            'next_flow_route_point_id' => null,
            'definition' => [],
            'settings' => [],
            'cancel_conditions' => [],
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        Queue::fake();

        app(TransitionContactWorkflowStatusAction::class)->handle(
            contact: $contact,
            toStatus: $oldStatus,
            reason: 'manual_update',
            source: 'test',
        );

        app(TransitionContactWorkflowStatusAction::class)->handle(
            contact: $contact->refresh(),
            toStatus: $newStatus,
            reason: 'manual_update',
            source: 'test',
        );

        $oldProgress = ContactFlowRouteProgress::query()
            ->where('flow_route_id', $oldRoute->id)
            ->first();

        $newProgress = ContactFlowRouteProgress::query()
            ->where('flow_route_id', $newRoute->id)
            ->first();

        $this->assertNotNull($oldProgress);
        $this->assertNotNull($newProgress);

        $this->assertSame(ContactFlowRouteProgress::STATUS_SUPERSEDED, $oldProgress->status);
        $this->assertSame('workflow_status_changed', $oldProgress->cancellation_reason);
        $this->assertNull($oldProgress->resume_at);
        $this->assertNull($oldProgress->waiting_event_key);
        $this->assertNotNull($oldProgress->cancelled_at);
        $this->assertArrayNotHasKey('waiting', $oldProgress->meta ?? []);

        $oldPlanItem = ContactFlowRoutePlanItem::query()
            ->where('contact_flow_route_progress_id', $oldProgress->getKey())
            ->firstOrFail();

        $oldProgressItem = ContactFlowRouteProgressItem::query()
            ->where('contact_flow_route_progress_id', $oldProgress->getKey())
            ->firstOrFail();

        $this->assertSame(ContactFlowRoutePlanItem::STATUS_CANCELLED, $oldPlanItem->status);
        $this->assertSame('workflow_status_changed', $oldPlanItem->result_reason);
        $this->assertNull($oldPlanItem->resume_at);
        $this->assertNull($oldPlanItem->waiting_event_key);

        $this->assertSame(ContactFlowRouteProgressItem::STATUS_CANCELLED, $oldProgressItem->status);
        $this->assertSame('workflow_status_changed', $oldProgressItem->result_reason);
        $this->assertNull($oldProgressItem->resume_at);
        $this->assertNull($oldProgressItem->waiting_event_key);

        $this->assertSame(ContactFlowRouteProgress::STATUS_COMPLETED, $newProgress->status);
        $this->assertSame($newStatus->id, $newProgress->contact_status_id);
        $this->assertNotNull($newProgress->completed_at);
    }

    public function test_workflow_status_change_without_matching_active_route_only_cancels_existing_progress(): void
    {
        $contact = Contact::factory()->create();

        $oldStatus = ContactStatus::query()->create([
            'key' => 'new_lead',
            'name' => 'New Lead',
        ]);

        $newStatus = ContactStatus::query()->create([
            'key' => 'closed_lost',
            'name' => 'Closed Lost',
        ]);

        $route = FlowRoute::query()->create([
            'key' => 'new_lead_route',
            'contact_status_id' => $oldStatus->id,
            'name' => 'New Lead Route',
            'description' => null,
            'version' => 1,
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $oldStatus->key,
            'is_active' => true,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $this->bindRouteToContactStatus($route, $oldStatus);

        $point = Point::query()->create([
            'key' => 'wait_for_next_status',
            'type' => Point::TYPE_WAIT,
            'name' => 'Wait For Next Status',
        ]);

        FlowRoutePoint::query()->create([
            'flow_route_id' => $route->id,
            'point_id' => $point->id,
            'key' => 'wait_for_next_status',
            'sort_order' => 10,
            'is_start' => true,
            'is_active' => true,
            'next_flow_route_point_id' => null,
            'definition' => [
                'seconds' => 3600,
            ],
            'settings' => [],
            'cancel_conditions' => [],
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        Queue::fake();

        app(TransitionContactWorkflowStatusAction::class)->handle(
            contact: $contact,
            toStatus: $oldStatus,
            reason: 'manual_update',
            source: 'test',
        );

        app(TransitionContactWorkflowStatusAction::class)->handle(
            contact: $contact->refresh(),
            toStatus: $newStatus,
            reason: 'manual_update',
            source: 'test',
        );

        $this->assertSame(1, ContactFlowRouteProgress::query()->superseded()->count());
        $this->assertSame(0, ContactFlowRouteProgress::query()->active()->count());
    }

    public function test_change_status_route_continues_to_next_point_without_superseding_itself(): void
    {
        $contact = Contact::factory()->create();

        $fromStatus = ContactStatus::query()->create([
            'key' => 'phase_4b_from_status',
            'name' => 'Phase 4B From Status',
            'is_active' => true,
        ]);

        $toStatus = ContactStatus::query()->create([
            'key' => 'phase_4b_to_status',
            'name' => 'Phase 4B To Status',
            'is_active' => true,
        ]);

        $flowRoute = FlowRoute::query()->create([
            'key' => 'phase_4b_change_status_handoff',
            'contact_status_id' => $fromStatus->getKey(),
            'name' => 'Phase 4B Change Status Handoff',
            'description' => null,
            'version' => 1,
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $fromStatus->key,
            'is_active' => true,
            'source_version' => 'test',
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $this->bindRouteToContactStatus($flowRoute, $fromStatus);

        $changeStatusPoint = Point::query()->create([
            'key' => 'phase_4b_change_status',
            'type' => Point::TYPE_CHANGE_STATUS,
            'name' => 'Change Status',
            'description' => null,
            'default_definition' => [],
            'default_settings' => [],
            'is_active' => true,
            'source_version' => 'test',
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $noopPoint = Point::query()->create([
            'key' => 'phase_4b_after_change_status_noop',
            'type' => Point::TYPE_NOOP,
            'name' => 'After Change Status Noop',
            'description' => null,
            'default_definition' => [],
            'default_settings' => [],
            'is_active' => true,
            'source_version' => 'test',
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $noopRoutePoint = FlowRoutePoint::query()->create([
            'flow_route_id' => $flowRoute->getKey(),
            'point_id' => $noopPoint->getKey(),
            'key' => 'after_change_status_noop',
            'sort_order' => 20,
            'is_start' => false,
            'is_active' => true,
            'next_flow_route_point_id' => null,
            'definition' => [],
            'settings' => [],
            'cancel_conditions' => [],
            'source_version' => 'test',
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        FlowRoutePoint::query()->create([
            'flow_route_id' => $flowRoute->getKey(),
            'point_id' => $changeStatusPoint->getKey(),
            'key' => 'change_status_to_phase_4b_to_status',
            'sort_order' => 10,
            'is_start' => true,
            'is_active' => true,
            'next_flow_route_point_id' => $noopRoutePoint->getKey(),
            'definition' => [
                'contact_status_key' => $toStatus->key,
                'reason' => 'phase_4b_change_status_handoff_test',
                'on_same_status' => 'skipped',
                'force' => false,
            ],
            'settings' => [],
            'cancel_conditions' => [],
            'source_version' => 'test',
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        app(TransitionContactWorkflowStatusAction::class)->handle(
            contact: $contact,
            toStatus: $fromStatus,
            reason: 'manual_update',
            source: 'test',
        );

        $progresses = ContactFlowRouteProgress::query()
            ->where('flow_route_id', $flowRoute->getKey())
            ->get();

        $this->assertCount(1, $progresses);

        $progress = $progresses->first();

        $this->assertNotNull($progress);
        $this->assertSame(ContactFlowRouteProgress::STATUS_COMPLETED, $progress->status);
        $this->assertNull($progress->current_flow_route_point_id);

        $profile = \App\Modules\Workflow\Models\ContactWorkflowProfile::query()
            ->where('contact_id', $contact->getKey())
            ->firstOrFail();

        $this->assertSame($toStatus->getKey(), $profile->contact_status_id);
        $this->assertSame(0, ContactFlowRouteProgress::query()->superseded()->count());

        $this->assertSame(2, \App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem::query()
            ->where('contact_flow_route_progress_id', $progress->getKey())
            ->where('status', \App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem::STATUS_COMPLETED)
            ->count());

        $this->assertSame(2, \App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem::query()
            ->where('contact_flow_route_progress_id', $progress->getKey())
            ->where('status', \App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem::STATUS_COMPLETED)
            ->count());
    }

    public function test_inactive_route_does_not_start_progress(): void
    {
        $contact = Contact::factory()->create();

        $status = ContactStatus::query()->create([
            'key' => 'new_lead',
            'name' => 'New Lead',
        ]);

        $inactiveRoute = FlowRoute::query()->create([
            'key' => 'inactive_new_lead_route',
            'contact_status_id' => $status->id,
            'name' => 'Inactive New Lead Route',
            'description' => null,
            'version' => 1,
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $status->key,
            'is_active' => false,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $this->bindRouteToContactStatus($inactiveRoute, $status);

        app(TransitionContactWorkflowStatusAction::class)->handle(
            contact: $contact,
            toStatus: $status,
            reason: 'manual_update',
            source: 'test',
        );

        $this->assertSame(0, ContactFlowRouteProgress::query()->count());
    }

    private function bindRouteToContactStatus(FlowRoute $flowRoute, ContactStatus $status): void
    {
        FlowRouteTriggerBinding::query()->create([
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $status->key,
            'flow_route_id' => $flowRoute->getKey(),
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
            'meta' => [
                'source' => 'test',
            ],
        ]);
    }

}
