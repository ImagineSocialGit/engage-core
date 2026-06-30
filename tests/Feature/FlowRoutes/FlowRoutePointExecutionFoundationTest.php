<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\FlowRoutes\Actions\ExecuteCurrentFlowRoutePointAction;
use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Jobs\ResumeFlowRouteProgressJob;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Models\Point;
use App\Modules\FlowRoutes\PointHandlers\NoopPointHandler;
use App\Modules\FlowRoutes\PointHandlers\WaitPointHandler;
use App\Modules\FlowRoutes\Services\PointHandlerRegistry;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FlowRoutePointExecutionFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_resolves_registered_point_handlers(): void
    {
        $registry = new PointHandlerRegistry([
            new NoopPointHandler(),
            new WaitPointHandler(),
        ]);

        $this->assertTrue($registry->has(Point::TYPE_NOOP));
        $this->assertTrue($registry->has(Point::TYPE_WAIT));
        $this->assertInstanceOf(NoopPointHandler::class, $registry->resolve(Point::TYPE_NOOP));
        $this->assertInstanceOf(WaitPointHandler::class, $registry->resolve(Point::TYPE_WAIT));
    }

    public function test_noop_point_completes_and_advances_to_next_point(): void
    {
        $setup = $this->createProgressWithPoints([
            Point::TYPE_NOOP,
            Point::TYPE_WAIT,
        ]);

        $result = app(ExecuteCurrentFlowRoutePointAction::class)->handle($setup['progress']);

        $this->assertSame(PointExecutionResult::STATUS_COMPLETED, $result->status);

        $setup['progress']->refresh();

        $this->assertSame(ContactFlowRouteProgress::STATUS_ACTIVE, $setup['progress']->status);
        $this->assertSame($setup['flow_route_points'][1]->getKey(), $setup['progress']->current_flow_route_point_id);
        $this->assertSame(Point::TYPE_WAIT, $setup['progress']->currentFlowRoutePoint->point->type);
    }

    public function test_noop_point_completes_route_when_no_next_point_exists(): void
    {
        $setup = $this->createProgressWithPoints([
            Point::TYPE_NOOP,
        ]);

        $result = app(ExecuteCurrentFlowRoutePointAction::class)->handle($setup['progress']);

        $this->assertSame(PointExecutionResult::STATUS_COMPLETED, $result->status);

        $setup['progress']->refresh();

        $this->assertSame(ContactFlowRouteProgress::STATUS_COMPLETED, $setup['progress']->status);
        $this->assertNull($setup['progress']->current_flow_route_point_id);
        $this->assertNull($setup['progress']->resume_at);
        $this->assertNull($setup['progress']->waiting_event_key);
        $this->assertNotNull($setup['progress']->completed_at);
    }

    public function test_wait_point_returns_waiting_and_does_not_advance(): void
    {
        Queue::fake();

        $setup = $this->createProgressWithPoints([
            Point::TYPE_WAIT,
            Point::TYPE_NOOP,
        ]);

        $setup['flow_route_points'][0]->forceFill([
            'definition' => [
                'seconds' => 300,
            ],
        ])->save();

        $result = app(ExecuteCurrentFlowRoutePointAction::class)->handle($setup['progress']);

        $this->assertSame(PointExecutionResult::STATUS_WAITING, $result->status);

        $setup['progress']->refresh();

        $this->assertSame(ContactFlowRouteProgress::STATUS_WAITING, $setup['progress']->status);
        $this->assertSame($setup['flow_route_points'][0]->getKey(), $setup['progress']->current_flow_route_point_id);
        $this->assertSame($setup['flow_route_points'][0]->getKey(), $setup['progress']->waitingFlowRoutePointId());
        $this->assertNotNull($setup['progress']->resume_at);
        $this->assertNotNull($setup['progress']->waitingResumeAt());
        $this->assertNull($setup['progress']->waiting_event_key);

        Queue::assertPushed(ResumeFlowRouteProgressJob::class);
    }

    public function test_unknown_point_type_fails_progress(): void
    {
        $setup = $this->createProgressWithPoints([
            'future_handler_type',
        ]);

        $result = app(ExecuteCurrentFlowRoutePointAction::class)->handle($setup['progress']);

        $this->assertSame(PointExecutionResult::STATUS_FAILED, $result->status);
        $this->assertSame('point_handler_not_registered', $result->reason);

        $setup['progress']->refresh();

        $this->assertSame(ContactFlowRouteProgress::STATUS_FAILED, $setup['progress']->status);
        $this->assertNull($setup['progress']->resume_at);
        $this->assertNull($setup['progress']->waiting_event_key);
        $this->assertSame('point_handler_not_registered', $setup['progress']->failure_reason);
        $this->assertNotNull($setup['progress']->failed_at);
    }

    /**
     * @param array<int, string> $types
     * @return array{
     *     progress: ContactFlowRouteProgress,
     *     flow_route: FlowRoute,
     *     flow_route_points: array<int, FlowRoutePoint>
     * }
     */
    private function createProgressWithPoints(array $types): array
    {
        $contactId = DB::table('contacts')->insertGetId([
            'first_name' => 'FlowRoute',
            'last_name' => 'Test',
            'name' => 'FlowRoute Test',
            'email' => 'flowroute-test-'.uniqid().'@example.com',
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
            'key' => 'testing-'.uniqid(),
            'name' => 'Testing',
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
            'key' => 'testing-flow-route-'.uniqid(),
            'contact_status_id' => $contactStatusId,
            'name' => 'Testing FlowRoute',
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

        foreach ($types as $index => $type) {
            $point = Point::query()->create([
                'key' => 'test-'.$type.'-'.$index.'-'.uniqid(),
                'type' => $type,
                'name' => ucfirst(str_replace('_', ' ', $type)),
                'description' => null,
                'default_definition' => [],
                'default_settings' => [],
                'is_active' => true,
                'source_version' => null,
                'is_customized' => false,
                'customized_at' => null,
                'meta' => [],
            ]);

            $flowRoutePoints[] = FlowRoutePoint::query()->create([
                'flow_route_id' => $flowRoute->getKey(),
                'point_id' => $point->getKey(),
                'key' => 'route-point-'.$index.'-'.uniqid(),
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
            'completed_at' => null,
            'cancelled_at' => null,
            'failed_at' => null,
            'resume_at' => null,
            'waiting_event_key' => null,
            'cancellation_reason' => null,
            'failure_reason' => null,
            'meta' => [],
        ]);

        return [
            'progress' => $progress,
            'flow_route' => $flowRoute,
            'flow_route_points' => $flowRoutePoints,
        ];
    }
}