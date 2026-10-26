<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\FlowRoutes\Actions\ExecuteCurrentFlowRoutePointAction;
use App\Modules\FlowRoutes\Actions\ExecuteFlowRouteProgressUntilIdleAction;
use App\Modules\FlowRoutes\Actions\RecoverStalledFlowRouteContinuationsAction;
use App\Modules\FlowRoutes\Actions\ResumeContactFlowRouteProgressAction;
use App\Modules\FlowRoutes\Actions\StartFlowRoutesFromAutomationEventAction;
use App\Modules\FlowRoutes\Data\Events\FlowRouteExternalEvent;
use App\Modules\Core\Models\Contact;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Jobs\ContinueFlowRouteProgressJob;
use App\Modules\FlowRoutes\Jobs\ResumeFlowRouteProgressJob;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FlowRouteRedisContinuationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_lost_budget_continuation_is_requeued_once_and_finishes_without_replaying_points(): void
    {
        config()->set('flow_routes.execution.immediate_execution_budget', 2);
        Queue::fake();

        $progress = $this->progressWithPoints([
            FlowRoutePointType::Noop,
            FlowRoutePointType::Noop,
            FlowRoutePointType::Noop,
            FlowRoutePointType::Noop,
        ]);

        app(ExecuteFlowRouteProgressUntilIdleAction::class)->handle($progress);

        $this->assertSame(ContactFlowRouteProgress::STATUS_ACTIVE, $progress->fresh()->status);
        $this->assertSame('scheduled', data_get($progress->fresh()->meta, 'immediate_execution_continuation.status'));
        $this->assertSame(2, $this->progressItems($progress));
        Queue::assertPushedTimes(ContinueFlowRouteProgressJob::class, 1);

        // The first continuation remains in the fake queue and never runs.
        $this->travel(6)->minutes();

        try {
            $this->runScheduledContinuationRecovery();
            Queue::assertPushedTimes(ContinueFlowRouteProgressJob::class, 2);

            // Claim is persisted in MySQL; a second sweep cannot flood Redis.
            $this->runScheduledContinuationRecovery();
            Queue::assertPushedTimes(ContinueFlowRouteProgressJob::class, 2);
            $this->assertSame(2, $this->progressItems($progress));

            $jobs = Queue::pushed(ContinueFlowRouteProgressJob::class);
            $recoveredJob = $jobs->last();
            $this->assertInstanceOf(ContinueFlowRouteProgressJob::class, $recoveredJob);

            // The original job can fail after recovery has claimed a new
            // delivery of the same sequence. Its stale token cannot poison it.
            $jobs->first()->failed(new RuntimeException('Original worker failed late'));
            $this->assertSame('scheduled', data_get($progress->fresh()->meta, 'immediate_execution_continuation.status'));
            $this->assertNotSame(
                $jobs->first()->continuationScheduledAt,
                $recoveredJob->continuationScheduledAt,
            );

            $recoveredJob->handle(app(ExecuteFlowRouteProgressUntilIdleAction::class));

            $this->assertSame(ContactFlowRouteProgress::STATUS_COMPLETED, $progress->fresh()->status);
            $this->assertArrayNotHasKey('immediate_execution_continuation', $progress->fresh()->meta ?? []);
            $this->assertSame(4, $this->progressItems($progress));

            // An obsolete original delivery is harmless after completion.
            $jobs->first()->handle(app(ExecuteFlowRouteProgressUntilIdleAction::class));
            $this->runScheduledContinuationRecovery();
            $this->assertSame(4, $this->progressItems($progress));
            Queue::assertPushedTimes(ContinueFlowRouteProgressJob::class, 2);
        } finally {
            $this->travelBack();
        }
    }

    public function test_recovery_claim_expires_and_can_requeue_again_if_replacement_is_lost(): void
    {
        config()->set('flow_routes.execution.immediate_execution_budget', 1);
        Queue::fake();

        $progress = $this->progressWithPoints([
            FlowRoutePointType::Noop,
            FlowRoutePointType::Noop,
            FlowRoutePointType::Noop,
        ]);
        app(ExecuteFlowRouteProgressUntilIdleAction::class)->handle($progress);

        $this->travel(6)->minutes();

        try {
            $action = app(RecoverStalledFlowRouteContinuationsAction::class);
            $this->assertSame(1, $action->handle()['dispatched']);
            $this->assertSame(0, $action->handle()['dispatched']);
            Queue::assertPushedTimes(ContinueFlowRouteProgressJob::class, 2);

            $this->travel(6)->minutes();

            $this->assertSame(1, $action->handle()['dispatched']);
            Queue::assertPushedTimes(ContinueFlowRouteProgressJob::class, 3);
        } finally {
            $this->travelBack();
        }
    }

    public function test_cancelled_failed_and_not_yet_stale_continuations_are_not_requeued(): void
    {
        config()->set('flow_routes.execution.immediate_execution_budget', 1);
        Queue::fake();

        $cancelled = $this->progressWithPoints([FlowRoutePointType::Noop, FlowRoutePointType::Noop]);
        $failed = $this->progressWithPoints([FlowRoutePointType::Noop, FlowRoutePointType::Noop]);
        $fresh = $this->progressWithPoints([FlowRoutePointType::Noop, FlowRoutePointType::Noop]);

        $runner = app(ExecuteFlowRouteProgressUntilIdleAction::class);
        $runner->handle($cancelled);
        $runner->handle($failed);

        $failedJob = Queue::pushed(ContinueFlowRouteProgressJob::class)->last();
        $this->assertInstanceOf(ContinueFlowRouteProgressJob::class, $failedJob);
        $failedJob->failed(new RuntimeException('Worker stopped'));

        $cancelled->forceFill([
            'status' => ContactFlowRouteProgress::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ])->save();

        $this->travel(6)->minutes();

        try {
            $runner->handle($fresh);
            Queue::assertPushedTimes(ContinueFlowRouteProgressJob::class, 3);
            $this->assertSame(0, app(RecoverStalledFlowRouteContinuationsAction::class)->handle()['dispatched']);
            Queue::assertPushedTimes(ContinueFlowRouteProgressJob::class, 3);
            $this->assertSame('failed', data_get($failed->fresh()->meta, 'immediate_execution_continuation.status'));
            $this->assertSame(ContactFlowRouteProgress::STATUS_CANCELLED, $cancelled->fresh()->status);
        } finally {
            $this->travelBack();
        }
    }

    public function test_old_continuation_failure_cannot_mark_a_newer_handoff_failed(): void
    {
        config()->set('flow_routes.execution.immediate_execution_budget', 1);
        Queue::fake();

        $progress = $this->progressWithPoints([
            FlowRoutePointType::Noop,
            FlowRoutePointType::Noop,
            FlowRoutePointType::Noop,
        ]);

        $executor = app(ExecuteFlowRouteProgressUntilIdleAction::class);
        $executor->handle($progress);
        $oldJob = Queue::pushed(ContinueFlowRouteProgressJob::class)->first();
        $this->assertInstanceOf(ContinueFlowRouteProgressJob::class, $oldJob);
        $this->assertSame(1, $oldJob->continuationSequence);

        // Laravel's worker releases ShouldBeUniqueUntilProcessing before
        // invoking handle(). Queue::fake plus a direct handle() call does
        // not perform that lifecycle step; leaving the lock held would
        // suppress the successor even though its MySQL marker is correct.
        app(UniqueLock::class)->release($oldJob);
        $oldJob->handle($executor);
        Queue::assertPushedTimes(ContinueFlowRouteProgressJob::class, 2);
        $this->assertSame('scheduled', data_get($progress->fresh()->meta, 'immediate_execution_continuation.status'));
        $this->assertSame(2, data_get($progress->fresh()->meta, 'immediate_execution_continuation.sequence'));

        $oldJob->failed(new RuntimeException('Stale failure notification'));

        $this->assertSame('scheduled', data_get($progress->fresh()->meta, 'immediate_execution_continuation.status'));
        $this->assertSame(2, data_get($progress->fresh()->meta, 'immediate_execution_continuation.sequence'));

        $newJob = Queue::pushed(ContinueFlowRouteProgressJob::class)->last();
        $this->assertSame(2, $newJob->continuationSequence);
        $newJob->handle($executor);

        $this->assertSame(ContactFlowRouteProgress::STATUS_COMPLETED, $progress->fresh()->status);
        $this->assertSame(3, $this->progressItems($progress));
    }

    public function test_due_wait_handoff_schedules_and_recovers_the_remaining_immediate_route(): void
    {
        Queue::fake();
        $progress = $this->progressWithPoints([
            FlowRoutePointType::Wait,
            FlowRoutePointType::Noop,
            FlowRoutePointType::Noop,
        ], waitSeconds: 1);

        app(ExecuteCurrentFlowRoutePointAction::class)->handle($progress);
        Queue::assertPushedTimes(ResumeFlowRouteProgressJob::class, 1);

        $this->travel(2)->seconds();

        try {
            $this->runScheduledWaitRecovery();

            $this->assertSame(ContactFlowRouteProgress::STATUS_ACTIVE, $progress->fresh()->status);
            $this->assertSame('scheduled', data_get($progress->fresh()->meta, 'immediate_execution_continuation.status'));
            Queue::assertPushedTimes(ContinueFlowRouteProgressJob::class, 1);
            $this->assertSame(2, $this->progressItems($progress));

            // The handoff job is lost too. Recover it from the DB marker.
            $this->travel(6)->minutes();
            $this->runScheduledContinuationRecovery();
            Queue::assertPushedTimes(ContinueFlowRouteProgressJob::class, 2);

            Queue::pushed(ContinueFlowRouteProgressJob::class)
                ->last()
                ->handle(app(ExecuteFlowRouteProgressUntilIdleAction::class));

            $this->assertSame(ContactFlowRouteProgress::STATUS_COMPLETED, $progress->fresh()->status);
            $this->assertSame(4, $this->progressItems($progress));
            $this->assertNull($progress->fresh()->resume_at);
        } finally {
            $this->travelBack();
        }
    }

    public function test_failed_wait_handoff_rolls_back_resume_so_due_wait_remains_recoverable(): void
    {
        Queue::fake();
        $progress = $this->progressWithPoints([
            FlowRoutePointType::Wait,
            FlowRoutePointType::Noop,
        ], waitSeconds: 1);

        app(ExecuteCurrentFlowRoutePointAction::class)->handle($progress);

        $this->travel(2)->seconds();

        try {
            $executor = Mockery::mock(ExecuteFlowRouteProgressUntilIdleAction::class);
            $executor->shouldReceive('scheduleContinuation')
                ->once()
                ->andThrow(new RuntimeException('Simulated handoff failure'));
            $this->app->instance(ExecuteFlowRouteProgressUntilIdleAction::class, $executor);

            try {
                app(ResumeContactFlowRouteProgressAction::class)->handle($progress, now());
                $this->fail('The handoff failure should propagate.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Simulated handoff failure', $exception->getMessage());
            }

            $this->assertSame(ContactFlowRouteProgress::STATUS_WAITING, $progress->fresh()->status);
            $this->assertNotNull($progress->fresh()->resume_at);
            $this->assertSame(1, $this->progressItems($progress));
            Queue::assertPushedTimes(ContinueFlowRouteProgressJob::class, 0);
        } finally {
            $this->travelBack();
        }
    }

    public function test_new_automation_event_progress_has_recoverable_marker_before_inline_execution(): void
    {
        Queue::fake();
        $contact = Contact::factory()->create();
        $route = FlowRoute::factory()->forAutomationEvent('test.inline_lost')->create();
        FlowRoutePoint::factory()->start()->type(FlowRoutePointType::Noop)->create([
            'flow_route_id' => $route->getKey(),
        ]);
        FlowRouteTriggerBinding::factory()->forAutomationEvent('test.inline_lost')->create([
            'flow_route_id' => $route->getKey(),
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
        ]);

        $pointExecutor = Mockery::mock(ExecuteCurrentFlowRoutePointAction::class);
        $pointExecutor->shouldReceive('handle')
            ->once()
            ->andThrow(new RuntimeException('Lost after event progress was committed'));
        $this->app->instance(ExecuteCurrentFlowRoutePointAction::class, $pointExecutor);

        try {
            app(StartFlowRoutesFromAutomationEventAction::class)->handle(
                FlowRouteExternalEvent::make(
                    name: 'test.inline_lost',
                    contactId: $contact->getKey(),
                ),
            );
            $this->fail('The simulated interruption should propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Lost after event progress was committed', $exception->getMessage());
        } finally {
            $this->app->forgetInstance(ExecuteCurrentFlowRoutePointAction::class);
        }

        $progress = ContactFlowRouteProgress::query()
            ->where('flow_route_id', $route->getKey())
            ->sole();
        $this->assertSame(ContactFlowRouteProgress::STATUS_ACTIVE, $progress->status);
        $this->assertSame(0, data_get($progress->meta, 'immediate_execution_continuation.sequence'));
        $this->assertSame(0, $this->progressItems($progress));

        $this->travel(6)->minutes();

        try {
            $this->runScheduledContinuationRecovery();
            Queue::assertPushedTimes(ContinueFlowRouteProgressJob::class, 1);
            Queue::pushed(ContinueFlowRouteProgressJob::class)
                ->first()
                ->handle(app(ExecuteFlowRouteProgressUntilIdleAction::class));

            $this->assertSame(ContactFlowRouteProgress::STATUS_COMPLETED, $progress->fresh()->status);
            $this->assertSame(1, $this->progressItems($progress));
        } finally {
            $this->travelBack();
        }
    }

    public function test_crash_before_first_inline_point_is_recovered_from_sequence_zero(): void
    {
        Queue::fake();
        $progress = $this->progressWithPoints([
            FlowRoutePointType::Noop,
            FlowRoutePointType::Noop,
        ]);

        $pointExecutor = Mockery::mock(ExecuteCurrentFlowRoutePointAction::class);
        $pointExecutor->shouldReceive('handle')
            ->once()
            ->andThrow(new RuntimeException('Simulated inline process loss'));
        $this->app->instance(ExecuteCurrentFlowRoutePointAction::class, $pointExecutor);

        try {
            app(ExecuteFlowRouteProgressUntilIdleAction::class)->handle($progress);
            $this->fail('The simulated inline interruption should propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated inline process loss', $exception->getMessage());
        } finally {
            $this->app->forgetInstance(ExecuteCurrentFlowRoutePointAction::class);
        }

        $continuation = data_get($progress->fresh()->meta, 'immediate_execution_continuation');
        $this->assertSame('scheduled', $continuation['status'] ?? null);
        $this->assertSame(0, $continuation['sequence'] ?? null);
        $this->assertSame(0, $this->progressItems($progress));
        Queue::assertPushedTimes(ContinueFlowRouteProgressJob::class, 0);

        $this->travel(6)->minutes();

        try {
            $this->runScheduledContinuationRecovery();
            Queue::assertPushedTimes(ContinueFlowRouteProgressJob::class, 1);
            $recovered = Queue::pushed(ContinueFlowRouteProgressJob::class)->first();
            $this->assertSame(0, $recovered->continuationSequence);
            $recovered->handle(app(ExecuteFlowRouteProgressUntilIdleAction::class));

            $this->assertSame(ContactFlowRouteProgress::STATUS_COMPLETED, $progress->fresh()->status);
            $this->assertSame(2, $this->progressItems($progress));
            $this->assertArrayNotHasKey('immediate_execution_continuation', $progress->fresh()->meta ?? []);
        } finally {
            $this->travelBack();
        }
    }

    public function test_lost_inline_process_resumes_after_last_committed_point_without_replay(): void
    {
        Queue::fake();
        $progress = $this->progressWithPoints([
            FlowRoutePointType::Noop,
            FlowRoutePointType::Noop,
            FlowRoutePointType::Noop,
        ]);

        $realPointExecutor = app(ExecuteCurrentFlowRoutePointAction::class);
        $calls = 0;
        $pointExecutor = Mockery::mock(ExecuteCurrentFlowRoutePointAction::class);
        $pointExecutor->shouldReceive('handle')
            ->andReturnUsing(function ($progress, $executionMeta = []) use ($realPointExecutor, &$calls) {
                $calls++;

                if ($calls === 2) {
                    throw new RuntimeException('Process lost after first committed point');
                }

                return $realPointExecutor->handle($progress, $executionMeta);
            });
        $this->app->instance(ExecuteCurrentFlowRoutePointAction::class, $pointExecutor);

        try {
            app(ExecuteFlowRouteProgressUntilIdleAction::class)->handle($progress);
            $this->fail('The simulated mid-chain interruption should propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Process lost after first committed point', $exception->getMessage());
        } finally {
            $this->app->forgetInstance(ExecuteCurrentFlowRoutePointAction::class);
        }

        $this->assertSame(1, $this->progressItems($progress));
        $this->assertSame(0, data_get($progress->fresh()->meta, 'immediate_execution_continuation.sequence'));

        $this->travel(6)->minutes();

        try {
            $this->runScheduledContinuationRecovery();
            Queue::assertPushedTimes(ContinueFlowRouteProgressJob::class, 1);
            Queue::pushed(ContinueFlowRouteProgressJob::class)
                ->first()
                ->handle(app(ExecuteFlowRouteProgressUntilIdleAction::class));

            $this->assertSame(3, $this->progressItems($progress));
            $this->assertSame(ContactFlowRouteProgress::STATUS_COMPLETED, $progress->fresh()->status);
            $this->assertArrayNotHasKey('immediate_execution_continuation', $progress->fresh()->meta ?? []);
        } finally {
            $this->travelBack();
        }
    }

    public function test_inline_marker_is_reused_and_does_not_enqueue_a_second_job(): void
    {
        Queue::fake();
        $progress = $this->progressWithPoints([FlowRoutePointType::Noop]);
        $action = app(\App\Modules\FlowRoutes\Actions\EnsureFlowRouteInlineRecoveryMarkerAction::class);

        $action->handle($progress);
        $original = data_get($progress->fresh()->meta, 'immediate_execution_continuation');
        $this->travel(1)->minute();

        try {
            $action->handle($progress);
            $this->assertSame($original, data_get($progress->fresh()->meta, 'immediate_execution_continuation'));
            Queue::assertPushedTimes(ContinueFlowRouteProgressJob::class, 0);
        } finally {
            $this->travelBack();
        }
    }

    public function test_inline_recovery_claim_is_not_created_for_terminal_progress(): void
    {
        $progress = $this->progressWithPoints([FlowRoutePointType::Noop]);
        $progress->forceFill([
            'status' => ContactFlowRouteProgress::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ])->save();

        app(\App\Modules\FlowRoutes\Actions\EnsureFlowRouteInlineRecoveryMarkerAction::class)->handle($progress);

        $this->assertArrayNotHasKey('immediate_execution_continuation', $progress->fresh()->meta ?? []);
    }

    /** @param list<FlowRoutePointType> $types */
    private function progressWithPoints(array $types, int $waitSeconds = 0): ContactFlowRouteProgress
    {
        $route = FlowRoute::factory()->create();
        $points = [];

        foreach ($types as $index => $type) {
            $point = FlowRoutePoint::factory()
                ->type($type)
                ->create([
                    'flow_route_id' => $route->getKey(),
                    'sort_order' => $index * 10,
                    'is_start' => $index === 0,
                    'definition' => $type === FlowRoutePointType::Wait
                        ? ['seconds' => $waitSeconds]
                        : [],
                ]);

            $points[] = $point;
        }

        foreach ($points as $index => $point) {
            $point->forceFill([
                'next_flow_route_point_id' => ($points[$index + 1] ?? null)?->getKey(),
            ])->save();
        }

        return ContactFlowRouteProgress::factory()->create([
            'flow_route_id' => $route->getKey(),
            'current_flow_route_point_id' => $points[0]->getKey(),
        ]);
    }

    private function progressItems(ContactFlowRouteProgress $progress): int
    {
        return ContactFlowRouteProgressItem::query()
            ->where('contact_flow_route_progress_id', $progress->getKey())
            ->count();
    }

    private function runScheduledWaitRecovery(): void
    {
        $this->runScheduled('flow-routes:resume-overdue-waits');
    }

    private function runScheduledContinuationRecovery(): void
    {
        $this->runScheduled('flow-routes:recover-stalled-continuations');
    }

    private function runScheduled(string $name): void
    {
        $event = collect(app(Schedule::class)->events())->first(
            fn ($event): bool => $event instanceof CallbackEvent && $event->description === $name,
        );

        $this->assertInstanceOf(CallbackEvent::class, $event);
        $event->run(app());
    }
}