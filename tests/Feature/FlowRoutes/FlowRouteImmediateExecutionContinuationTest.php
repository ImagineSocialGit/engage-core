<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\FlowRoutes\Actions\ExecuteFlowRouteProgressUntilIdleAction;
use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Jobs\ContinueFlowRouteProgressJob;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FlowRouteImmediateExecutionContinuationTest extends TestCase
{
    use RefreshDatabase;

    public function test_budget_exhaustion_persists_and_queues_continuation_until_route_settles(): void
    {
        config()->set('flow_routes.execution.immediate_execution_budget', 2);
        config()->set('flow_routes.execution.continuation_queue', 'flow-route-continuations');

        Queue::fake();

        $setup = $this->createProgressWithNoopPoints(4);
        $action = app(ExecuteFlowRouteProgressUntilIdleAction::class);

        $result = $action->handle(
            progress: $setup['progress'],
            source: 'test_entry_point',
        );

        $this->assertSame(PointExecutionResult::STATUS_COMPLETED, $result->status);

        $progress = $setup['progress']->refresh();

        $this->assertSame(ContactFlowRouteProgress::STATUS_ACTIVE, $progress->status);
        $this->assertSame($setup['flow_route_points'][2]->getKey(), $progress->current_flow_route_point_id);
        $this->assertSame('scheduled', data_get($progress->meta, 'immediate_execution_continuation.status'));
        $this->assertSame(1, data_get($progress->meta, 'immediate_execution_continuation.sequence'));
        $this->assertSame(2, data_get($progress->meta, 'immediate_execution_continuation.execution_budget'));
        $this->assertSame(2, data_get($progress->meta, 'immediate_execution_continuation.executions_in_slice'));
        $this->assertSame('test_entry_point', data_get($progress->meta, 'immediate_execution_continuation.source'));
        $this->assertSame(2, ContactFlowRouteProgressItem::query()
            ->where('contact_flow_route_progress_id', $progress->getKey())
            ->count());

        Queue::assertPushed(ContinueFlowRouteProgressJob::class, function (ContinueFlowRouteProgressJob $job) use ($progress) {
            return $job->contactFlowRouteProgressId === $progress->getKey()
                && $job->queue === 'flow-route-continuations';
        });
        Queue::assertPushedTimes(ContinueFlowRouteProgressJob::class, 1);

        $job = Queue::pushed(ContinueFlowRouteProgressJob::class)->first();

        $this->assertInstanceOf(ContinueFlowRouteProgressJob::class, $job);

        $job->handle($action);

        $progress->refresh();

        $this->assertSame(ContactFlowRouteProgress::STATUS_COMPLETED, $progress->status);
        $this->assertNull($progress->current_flow_route_point_id);
        $this->assertSame('settled', data_get($progress->meta, 'immediate_execution_continuation.status'));
        $this->assertSame('continuation_job', data_get($progress->meta, 'immediate_execution_continuation.settled_by'));
        $this->assertSame(2, data_get($progress->meta, 'immediate_execution_continuation.executions_in_final_slice'));
        $this->assertSame(4, ContactFlowRouteProgressItem::query()
            ->where('contact_flow_route_progress_id', $progress->getKey())
            ->count());

        Queue::assertPushedTimes(ContinueFlowRouteProgressJob::class, 1);
    }

    /**
     * @return array{
     *     progress: ContactFlowRouteProgress,
     *     flow_route_points: array<int, FlowRoutePoint>
     * }
     */
    private function createProgressWithNoopPoints(int $count): array
    {
        $contactId = DB::table('contacts')->insertGetId([
            'first_name' => 'FlowRoute',
            'last_name' => 'Continuation',
            'name' => 'FlowRoute Continuation',
            'email' => 'flow-route-continuation-'.uniqid().'@example.com',
            'phone' => null,
            'source' => 'test',
            'subsource' => null,
            'last_contacted_at' => null,
            'last_activity_at' => now(),
            'meta' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contactStatusId = DB::table('contact_statuses')->insertGetId([
            'key' => 'flow-route-continuation-'.uniqid(),
            'name' => 'FlowRoute Continuation',
            'description' => null,
            'category' => null,
            'color' => null,
            'is_core' => false,
            'is_active' => true,
            'sort_order' => 1,
            'source_version' => null,
            'meta' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $workflowProfile = ContactWorkflowProfile::query()->create([
            'contact_id' => $contactId,
            'contact_status_id' => $contactStatusId,
            'last_status_changed_at' => now(),
            'meta' => [],
        ]);

        $flowRoute = FlowRoute::query()->create([
            'key' => 'continuation-route-'.uniqid(),
            'contact_status_id' => $contactStatusId,
            'name' => 'Continuation Route',
            'description' => null,
            'version' => 1,
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => null,
            'is_active' => true,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $flowRoutePoints = [];

        for ($index = 0; $index < $count; $index++) {
            $flowRoutePoints[] = FlowRoutePoint::query()->create([
                'flow_route_id' => $flowRoute->getKey(),
                'type' => FlowRoutePointType::Noop->value,
                'name' => 'Noop '.($index + 1),
                'description' => null,
                'key' => 'continuation-noop-'.$index.'-'.uniqid(),
                'sort_order' => ($index + 1) * 10,
                'is_start' => $index === 0,
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
        }

        foreach ($flowRoutePoints as $index => $flowRoutePoint) {
            $nextFlowRoutePoint = $flowRoutePoints[$index + 1] ?? null;

            $flowRoutePoint->forceFill([
                'next_flow_route_point_id' => $nextFlowRoutePoint?->getKey(),
            ])->save();
        }

        $progress = ContactFlowRouteProgress::query()->create([
            'contact_id' => $contactId,
            'contact_status_id' => $contactStatusId,
            'contact_workflow_profile_id' => $workflowProfile->getKey(),
            'flow_route_id' => $flowRoute->getKey(),
            'current_flow_route_point_id' => $flowRoutePoints[0]->getKey(),
            'status' => ContactFlowRouteProgress::STATUS_ACTIVE,
            'started_at' => now(),
            'meta' => [],
        ]);

        return [
            'progress' => $progress,
            'flow_route_points' => $flowRoutePoints,
        ];
    }
}