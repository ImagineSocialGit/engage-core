<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ResumeContactFlowRouteProgressAction
{
    public function __construct(
        private readonly ExecuteCurrentFlowRoutePointAction $executeCurrentFlowRoutePoint,
    ) {}

    public function handle(
        ContactFlowRouteProgress $progress,
        ?CarbonInterface $now = null,
    ): PointExecutionResult {
        $now = $now ? CarbonImmutable::instance($now)->utc() : CarbonImmutable::now('UTC');

        $prepared = DB::transaction(function () use ($progress, $now) {
            $progress = ContactFlowRouteProgress::query()
                ->lockForUpdate()
                ->with(['currentFlowRoutePoint.point', 'plan.items'])
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

            if ($waitingPlanItemId !== null) {
                ContactFlowRoutePlanItem::query()
                    ->whereKey($waitingPlanItemId)
                    ->where('contact_flow_route_progress_id', $progress->getKey())
                    ->where('status', ContactFlowRoutePlanItem::STATUS_WAITING)
                    ->update(['status' => ContactFlowRoutePlanItem::STATUS_ACTIVE]);
            }

            $meta = $progress->meta ?? [];
            $resumeAttempts = is_array($meta['resume_attempts'] ?? null) ? $meta['resume_attempts'] : [];
            $resumeAttempts[] = [
                'attempted_at' => $now->toISOString(),
                'resume_at' => $progress->resume_at?->toISOString(),
                'waiting' => $progress->waitingState(),
            ];

            $meta['resume_attempts'] = array_slice($resumeAttempts, -50);
            $meta['last_resume_attempt'] = end($meta['resume_attempts']) ?: null;

            $progress->forceFill([
                'status' => ContactFlowRouteProgress::STATUS_ACTIVE,
                'meta' => $meta,
            ])->save();

            return $progress->refresh();
        });

        if ($prepared instanceof PointExecutionResult) {
            return $prepared;
        }

        return $this->executeCurrentFlowRoutePoint->handle($prepared);
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
