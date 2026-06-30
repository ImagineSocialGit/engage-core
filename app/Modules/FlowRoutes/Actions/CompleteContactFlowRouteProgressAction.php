<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use Illuminate\Support\Carbon;

class CompleteContactFlowRouteProgressAction
{
    public function handle(
        ContactFlowRouteProgress $progress,
        ?PointExecutionResult $result = null,
    ): ContactFlowRouteProgress {
        $completedAt = Carbon::now();

        $progress->forceFill([
            'status' => ContactFlowRouteProgress::STATUS_COMPLETED,
            'completed_at' => $completedAt,
            'current_flow_route_point_id' => null,
            'resume_at' => null,
            'waiting_event_key' => null,
            'meta' => $this->mergedMeta($progress, $result, $completedAt),
        ])->save();

        return $progress->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function mergedMeta(
        ContactFlowRouteProgress $progress,
        ?PointExecutionResult $result,
        Carbon $completedAt,
    ): array {
        $meta = $progress->meta ?? [];

        unset($meta['waiting']);

        $meta['completed'] = [
            'completed_at' => $completedAt->toISOString(),
            'result' => $result?->toMetaPayload(),
        ];

        return $meta;
    }
}