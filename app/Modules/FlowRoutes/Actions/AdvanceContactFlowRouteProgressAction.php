<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Data\PointExecutionResult;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use Illuminate\Support\Carbon;

class AdvanceContactFlowRouteProgressAction
{
    public function __construct(
        private readonly CompleteContactFlowRouteProgressAction $completeContactFlowRouteProgress,
    ) {}

    public function handle(
        ContactFlowRouteProgress $progress,
        FlowRoutePoint $fromFlowRoutePoint,
        ?PointExecutionResult $result = null,
    ): ContactFlowRouteProgress {
        if (! $progress->isRunnable()) {
            return $progress;
        }

        $nextFlowRoutePoint = $this->nextActiveFlowRoutePoint($fromFlowRoutePoint);

        if (! $nextFlowRoutePoint) {
            return $this->completeContactFlowRouteProgress->handle($progress, $result);
        }

        $advancedAt = Carbon::now();

        $progress->forceFill([
            'status' => ContactFlowRouteProgress::STATUS_ACTIVE,
            'current_flow_route_point_id' => $nextFlowRoutePoint->getKey(),
            'meta' => $this->mergedMeta(
                progress: $progress,
                fromFlowRoutePoint: $fromFlowRoutePoint,
                nextFlowRoutePoint: $nextFlowRoutePoint,
                result: $result,
                advancedAt: $advancedAt,
            ),
        ])->save();

        return $progress->refresh();
    }

    private function nextActiveFlowRoutePoint(FlowRoutePoint $fromFlowRoutePoint): ?FlowRoutePoint
    {
        return FlowRoutePoint::query()
            ->with('point')
            ->where('flow_route_id', $fromFlowRoutePoint->flow_route_id)
            ->where('sort_order', '>', $fromFlowRoutePoint->sort_order)
            ->active()
            ->whereHas('point', fn ($query) => $query->active())
            ->ordered()
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function mergedMeta(
        ContactFlowRouteProgress $progress,
        FlowRoutePoint $fromFlowRoutePoint,
        FlowRoutePoint $nextFlowRoutePoint,
        ?PointExecutionResult $result,
        Carbon $advancedAt,
    ): array {
        $meta = $progress->meta ?? [];

        unset($meta['waiting']);

        $meta['last_advanced'] = [
            'advanced_at' => $advancedAt->toISOString(),
            'from_flow_route_point_id' => $fromFlowRoutePoint->getKey(),
            'to_flow_route_point_id' => $nextFlowRoutePoint->getKey(),
            'result' => $result?->toMetaPayload(),
        ];

        $history = $meta['advancement_history'] ?? [];

        if (! is_array($history)) {
            $history = [];
        }

        $history[] = $meta['last_advanced'];

        $meta['advancement_history'] = array_slice($history, -50);

        return $meta;
    }
}