<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Models\Point;
use App\Modules\Workflow\Actions\TransitionContactWorkflowStatusAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandleContactWorkflowStatusChangedTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_status_change_starts_active_flow_route_progress(): void
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

        $point = Point::query()->create([
            'key' => 'wait_one_day',
            'type' => Point::TYPE_WAIT,
            'name' => 'Wait One Day',
        ]);

        $flowRoutePoint = FlowRoutePoint::query()->create([
            'flow_route_id' => $flowRoute->id,
            'point_id' => $point->id,
            'key' => 'wait_one_day',
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
        $this->assertSame($flowRoutePoint->id, $progress->current_flow_route_point_id);
        $this->assertSame(ContactFlowRouteProgress::STATUS_ACTIVE, $progress->status);
        $this->assertNotNull($progress->started_at);
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

        $oldPoint = Point::query()->create([
            'key' => 'old_noop',
            'type' => Point::TYPE_NOOP,
            'name' => 'Old Noop',
        ]);

        FlowRoutePoint::query()->create([
            'flow_route_id' => $oldRoute->id,
            'point_id' => $oldPoint->id,
            'key' => 'old_noop',
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

        $this->assertSame(ContactFlowRouteProgress::STATUS_ACTIVE, $newProgress->status);
        $this->assertSame($newStatus->id, $newProgress->contact_status_id);
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

        $point = Point::query()->create([
            'key' => 'noop',
            'type' => Point::TYPE_NOOP,
            'name' => 'Noop',
        ]);

        FlowRoutePoint::query()->create([
            'flow_route_id' => $route->id,
            'point_id' => $point->id,
            'key' => 'noop',
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

    public function test_inactive_route_does_not_start_progress(): void
    {
        $contact = Contact::factory()->create();

        $status = ContactStatus::query()->create([
            'key' => 'new_lead',
            'name' => 'New Lead',
        ]);

        FlowRoute::query()->create([
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

        app(TransitionContactWorkflowStatusAction::class)->handle(
            contact: $contact,
            toStatus: $status,
            reason: 'manual_update',
            source: 'test',
        );

        $this->assertSame(0, ContactFlowRouteProgress::query()->count());
    }
}