<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\FlowRoutes\Actions\ExecuteCurrentFlowRoutePointAction;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Jobs\ResumeFlowRouteProgressJob;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FlowRouteOverdueWaitRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_recovery_advances_an_overdue_wait_after_its_redis_job_is_lost(): void
    {
        Queue::fake();

        $progress = $this->createWaitingProgress(seconds: 1);

        Queue::assertPushed(ResumeFlowRouteProgressJob::class, 1);
        $this->assertSame(ContactFlowRouteProgress::STATUS_WAITING, $progress->fresh()->status);

        // The delayed job is deliberately never handled; it is lost from Redis.
        $this->travel(2)->seconds();

        try {
            $this->runScheduledRecovery();

            $this->assertSame(ContactFlowRouteProgress::STATUS_ACTIVE, $progress->fresh()->status);
            $this->assertNotNull($progress->fresh()->current_flow_route_point_id);
            $this->assertNull($progress->fresh()->completed_at);
            $this->assertNull($progress->fresh()->resume_at);
            Queue::assertPushed(ResumeFlowRouteProgressJob::class, 1);

            $count = ContactFlowRouteProgressItem::query()
                ->where('contact_flow_route_progress_id', $progress->getKey())
                ->count();

            $this->runScheduledRecovery();

            $this->assertSame(ContactFlowRouteProgress::STATUS_ACTIVE, $progress->fresh()->status);
            $this->assertSame($count, ContactFlowRouteProgressItem::query()
                ->where('contact_flow_route_progress_id', $progress->getKey())
                ->count());
        } finally {
            $this->travelBack();
        }
    }

    public function test_scheduled_recovery_does_not_resume_a_future_wait_or_cancelled_progress(): void
    {
        Queue::fake();

        $future = $this->createWaitingProgress(seconds: 300);
        $cancelled = $this->createWaitingProgress(seconds: 1);
        $cancelled->forceFill([
            'status' => ContactFlowRouteProgress::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ])->save();

        $this->travel(2)->seconds();

        try {
            $this->runScheduledRecovery();

            $this->assertSame(ContactFlowRouteProgress::STATUS_WAITING, $future->fresh()->status);
            $this->assertSame(ContactFlowRouteProgress::STATUS_CANCELLED, $cancelled->fresh()->status);
            $this->assertNull($cancelled->fresh()->completed_at);
        } finally {
            $this->travelBack();
        }
    }

    private function createWaitingProgress(int $seconds): ContactFlowRouteProgress
    {
        $route = FlowRoute::factory()->create();

        $wait = FlowRoutePoint::factory()->start()->type(FlowRoutePointType::Wait)->create([
            'flow_route_id' => $route->getKey(),
            'definition' => ['seconds' => $seconds],
        ]);

        FlowRoutePoint::factory()->type(FlowRoutePointType::Noop)->create([
            'flow_route_id' => $route->getKey(),
            'sort_order' => 10,
        ]);

        $progress = ContactFlowRouteProgress::factory()->create([
            'flow_route_id' => $route->getKey(),
            'current_flow_route_point_id' => $wait->getKey(),
        ]);

        app(ExecuteCurrentFlowRoutePointAction::class)->handle($progress);

        $this->assertSame(ContactFlowRouteProgress::STATUS_WAITING, $progress->fresh()->status);

        return $progress;
    }

    private function runScheduledRecovery(): void
    {
        $event = collect(app(Schedule::class)->events())->first(
            fn ($scheduled): bool => $scheduled instanceof CallbackEvent
                && $scheduled->description === 'flow-routes:resume-overdue-waits',
        );

        $this->assertInstanceOf(CallbackEvent::class, $event);

        $event->run(app());
    }
}