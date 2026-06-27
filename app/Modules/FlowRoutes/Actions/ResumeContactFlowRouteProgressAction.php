<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
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
        $now = $now
            ? CarbonImmutable::instance($now)->utc()
            : CarbonImmutable::now('UTC');

        $prepared = DB::transaction(function () use ($progress, $now) {
            $progress = ContactFlowRouteProgress::query()
                ->lockForUpdate()
                ->with('currentFlowRoutePoint.point')
                ->findOrFail($progress->getKey());

            if ($progress->isTerminal()) {
                return PointExecutionResult::blocked(
                    reason: 'flow_route_progress_terminal',
                    meta: [
                        'progress_id' => $progress->getKey(),
                        'progress_status' => $progress->status,
                    ],
                );
            }

            if (! $progress->isWaiting()) {
                return PointExecutionResult::blocked(
                    reason: 'flow_route_progress_not_waiting',
                    meta: [
                        'progress_id' => $progress->getKey(),
                        'progress_status' => $progress->status,
                    ],
                );
            }

            if (! $progress->current_flow_route_point_id) {
                return PointExecutionResult::blocked(
                    reason: 'flow_route_progress_missing_current_point',
                    meta: [
                        'progress_id' => $progress->getKey(),
                    ],
                );
            }

            $waitingFlowRoutePointId = $progress->waitingFlowRoutePointId();

            if (
                $waitingFlowRoutePointId !== null
                && $waitingFlowRoutePointId !== (int) $progress->current_flow_route_point_id
            ) {
                return PointExecutionResult::blocked(
                    reason: 'flow_route_progress_waiting_point_mismatch',
                    meta: [
                        'progress_id' => $progress->getKey(),
                        'current_flow_route_point_id' => $progress->current_flow_route_point_id,
                        'waiting_flow_route_point_id' => $waitingFlowRoutePointId,
                    ],
                );
            }

            if (! $progress->isDueToResume($now)) {
                return PointExecutionResult::waiting(
                    reason: 'flow_route_progress_wait_not_due',
                    meta: [
                        'progress_id' => $progress->getKey(),
                        'waiting' => $progress->waitingState(),
                        'checked_at' => $now->toISOString(),
                    ],
                );
            }

            $meta = $progress->meta ?? [];
            $resumeAttempts = $meta['resume_attempts'] ?? [];

            if (! is_array($resumeAttempts)) {
                $resumeAttempts = [];
            }

            $resumeAttempts[] = [
                'attempted_at' => $now->toISOString(),
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
}