<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlan;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Services\FlowRouteProgressMetaCanonicalizer;
use Illuminate\Support\Carbon;

class CompleteContactFlowRouteProgressAction
{
    public function __construct(
        private readonly FlowRouteProgressMetaCanonicalizer $progressMetaCanonicalizer,
    ) {}

    public function handle(
        ContactFlowRouteProgress $progress,
        ?PointExecutionResult $result = null,
    ): ContactFlowRouteProgress {
        $completedAt = Carbon::now();

        $progress->loadMissing('plan');

        if ($progress->plan instanceof ContactFlowRoutePlan) {
            $progress->plan->forceFill([
                'status' => ContactFlowRoutePlan::STATUS_COMPLETED,
                'completed_at' => $completedAt,
            ])->save();
        }

        $progress->forceFill([
            'status' => ContactFlowRouteProgress::STATUS_COMPLETED,
            'completed_at' => $completedAt,
            'current_flow_route_point_id' => null,
            'resume_at' => null,
            'waiting_event_key' => null,
            'meta' => $this->terminalMeta($progress),
        ])->save();

        return $progress->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function terminalMeta(
        ContactFlowRouteProgress $progress,
    ): array {
        $meta = $this->progressMetaCanonicalizer->forPersistence(
            $progress->meta ?? [],
        );
        unset(
            $meta['waiting'],
            $meta['immediate_execution_continuation'],
        );

        return $meta;
    }
}