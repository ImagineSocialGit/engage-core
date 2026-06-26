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
            'contact_status_id' => $status->id,
            'name' => 'New Lead Route',
            'version' => 1,
            'is_active' => true,
        ]);

        $point = Point::query()->create([
            'key' => 'wait_one_day',
            'type' => Point::TYPE_WAIT,
            'name' => 'Wait One Day',
        ]);

        $flowRoutePoint = FlowRoutePoint::query()->create([
            'flow_route_id' => $flowRoute->id,
            'point_id' => $point->id,
            'sort_order' => 10,
            'is_active' => true,
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
            'contact_status_id' => $oldStatus->id,
            'name' => 'New Lead Route',
            'version' => 1,
            'is_active' => true,
        ]);

        $newRoute = FlowRoute::query()->create([
            'contact_status_id' => $newStatus->id,
            'name' => 'Consultation Scheduled Route',
            'version' => 1,
            'is_active' => true,
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

        $this->assertSame(ContactFlowRouteProgress::STATUS_SUPERSEDED, $oldProgress->status);
        $this->assertSame('workflow_status_changed', $oldProgress->cancellation_reason);
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

        FlowRoute::query()->create([
            'contact_status_id' => $oldStatus->id,
            'name' => 'New Lead Route',
            'version' => 1,
            'is_active' => true,
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
            'contact_status_id' => $status->id,
            'name' => 'Inactive New Lead Route',
            'version' => 1,
            'is_active' => false,
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