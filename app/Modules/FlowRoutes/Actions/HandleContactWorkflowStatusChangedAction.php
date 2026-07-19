<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\Workflow\Data\ContactWorkflowStatusTransition;
use Illuminate\Support\Facades\DB;

class HandleContactWorkflowStatusChangedAction
{
    public function __construct(
        private readonly CancelActiveFlowRouteProgressAction $cancelActiveFlowRouteProgress,
        private readonly StartFlowRouteProgressAction $startFlowRouteProgress,
        private readonly ExecuteFlowRouteProgressUntilIdleAction $executeFlowRouteProgressUntilIdle,
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

        $this->executeFlowRouteProgressUntilIdle->handle(
            progress: $progress,
            source: 'workflow_status_changed',
        );

        return $progress->refresh();
    }

    private function originatingFlowRouteProgressId(ContactWorkflowStatusTransition $transition): ?int
    {
        $value = $transition->meta['flow_route']['flow_route_progress_id'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}