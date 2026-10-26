<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem;
use App\Modules\FlowRoutes\Services\FlowRouteProgressMetaCanonicalizer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ResumeContactFlowRouteProgressAction
{
    public function __construct(
        private readonly ExecuteCurrentFlowRoutePointAction $executeCurrentFlowRoutePoint,
        private readonly ExecuteFlowRouteProgressUntilIdleAction $executeFlowRouteProgressUntilIdle,
        private readonly FlowRouteProgressMetaCanonicalizer $progressMetaCanonicalizer,
    ) {}

    public function handle(
        ContactFlowRouteProgress $progress,
        ?CarbonInterface $now = null,
    ): PointExecutionResult {
        $now = $now ? CarbonImmutable::instance($now)->utc() : CarbonImmutable::now('UTC');

        return DB::transaction(function () use ($progress, $now) {
            $prepared = (function () use ($progress, $now) {
                $progress = ContactFlowRouteProgress::query()
                    ->lockForUpdate()
                    ->with(['currentFlowRoutePoint', 'plan.items'])
                    ->findOrFail($progress->getKey());

                if ($progress->isTerminal()) {
                    return PointExecutionResult::blocked('flow_route_progress_terminal', [
                        'progress_id' => $progress->getKey(),
                        'progress_status' => $progress->status,
                    ]);
                }

                if (! $progress->isWaiting()) {
                    return PointExecutionResult::blocked('flow_route_progress_not_waiting', [
                        'progress_id' => $progress->getKey(),
                        'progress_status' => $progress->status,
                    ]);
                }

                if (! $progress->isDueToResume($now)) {
                    return PointExecutionResult::waiting('flow_route_progress_wait_not_due', [
                        'progress_id' => $progress->getKey(),
                        'resume_at' => $progress->resume_at?->toISOString(),
                        'waiting' => $progress->waitingState(),
                        'checked_at' => $now->toISOString(),
                    ]);
                }

                $waitingPlanItemId = $this->nullableInt($progress->waitingState()['flow_route_plan_item_id'] ?? null);

                $waitingProgressItemId = $this->nullableInt($progress->waitingState()['flow_route_progress_item_id'] ?? null);

                if ($waitingPlanItemId !== null) {
                    ContactFlowRoutePlanItem::query()
                        ->whereKey($waitingPlanItemId)
                        ->where('contact_flow_route_progress_id', $progress->getKey())
                        ->where('status', ContactFlowRoutePlanItem::STATUS_WAITING)
                        ->update([
                            'status' => ContactFlowRoutePlanItem::STATUS_ACTIVE,
                            'resume_at' => null,
                            'waiting_event_key' => null,
                        ]);
                }

                if ($waitingProgressItemId !== null) {
                    ContactFlowRouteProgressItem::query()
                        ->whereKey($waitingProgressItemId)
                        ->where('contact_flow_route_progress_id', $progress->getKey())
                        ->where('status', ContactFlowRouteProgressItem::STATUS_WAITING)
                        ->update([
                            'status' => ContactFlowRouteProgressItem::STATUS_COMPLETED,
                            'completed_at' => $now,
                            'resume_at' => null,
                            'waiting_event_key' => null,
                            'result_reason' => 'timed_wait_resumed',
                        ]);
                }

                $progress->forceFill([
                    'status' => ContactFlowRouteProgress::STATUS_ACTIVE,
                    'meta' => $this->progressMetaCanonicalizer->forPersistence(
                        $progress->meta ?? [],
                    ),
                ])->save();

                return $progress->refresh();
            })();

            if ($prepared instanceof PointExecutionResult) {
                return $prepared;
            }

            $result = $this->executeCurrentFlowRoutePoint->handle($prepared);

            // The resumed wait and the durable next-job marker must commit together.
            // If the process dies before this transaction commits, the wait stays due
            // and the existing due-wait sweep can safely retry the entire handoff.
            if ($result->shouldAdvance() && $prepared->refresh()->isActive()) {
                $this->executeFlowRouteProgressUntilIdle->scheduleContinuation($prepared);
            }

            return $result;
        });
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}