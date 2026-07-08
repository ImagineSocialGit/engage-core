<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\Workflow\Data\ContactWorkflowStatusTransition;
use Illuminate\Support\Facades\DB;

class HandleContactWorkflowStatusChangedAction
{
    public function __construct(
        private readonly CancelActiveFlowRouteProgressAction $cancelActiveFlowRouteProgress,
        private readonly StartFlowRouteProgressAction $startFlowRouteProgress,
        private readonly ExecuteCurrentFlowRoutePointAction $executeCurrentFlowRoutePoint,
    ) {}

    public function handle(ContactWorkflowStatusTransition $transition): ?ContactFlowRouteProgress
    {
        if (! $transition->changed()) {
            return null;
        }

        $progress = DB::transaction(function () use ($transition) {
            $originatingProgressId = $this->originatingFlowRouteProgressId($transition);

            $this->cancelActiveFlowRouteProgress->handle(
                transition: $transition,
                exceptProgressId: $originatingProgressId,
            );

            return $this->startFlowRouteProgress->handle($transition);
        });

        if (! $progress instanceof ContactFlowRouteProgress) {
            return null;
        }

        $this->executeProgressUntilIdle($progress);

        return $progress->refresh();
    }

    private function executeProgressUntilIdle(ContactFlowRouteProgress $progress): PointExecutionResult
    {
        $attempts = 0;
        $result = null;

        do {
            $result = $this->executeCurrentFlowRoutePoint->handle($progress);
            $progress->refresh();
            $attempts++;
        } while ($attempts < 25 && $result->shouldAdvance() && $progress->isActive());

        return $result ?? PointExecutionResult::blocked(
            reason: 'flow_route_progress_not_executed',
            meta: [
                'progress_id' => $progress->getKey(),
            ],
        );
    }

    private function originatingFlowRouteProgressId(ContactWorkflowStatusTransition $transition): ?int
    {
        $value = $transition->meta['flow_route']['flow_route_progress_id'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}