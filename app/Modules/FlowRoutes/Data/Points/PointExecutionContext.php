<?php

namespace App\Modules\FlowRoutes\Data\Points;

use App\Modules\FlowRoutes\Models\ContactFlowRoutePlan;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;

class PointExecutionContext
{
    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly ContactFlowRouteProgress $progress,
        public readonly FlowRoutePoint $flowRoutePoint,
        public readonly array $definition,
        public readonly array $settings,
        public readonly array $meta = [],
        public readonly ?ContactFlowRoutePlan $plan = null,
        public readonly ?ContactFlowRoutePlanItem $planItem = null,
        public readonly ?ContactFlowRouteProgressItem $progressItem = null,
    ) {}

    public function pointType(): string
    {
        return (string) $this->flowRoutePoint->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function flowRouteProvenance(): array
    {
        return [
            'flow_route_progress_id' => $this->progress->getKey(),
            'flow_route_plan_id' => $this->plan?->getKey(),
            'flow_route_plan_item_id' => $this->planItem?->getKey(),
            'flow_route_progress_item_id' => $this->progressItem?->getKey(),
            'flow_route_id' => $this->progress->flow_route_id,
            'flow_route_point_id' => $this->flowRoutePoint->getKey(),
            'flow_route_capability_id' => $this->flowRoutePoint->flow_route_capability_id,
            'point_type' => $this->flowRoutePoint->type,
            'subject_type' => $this->progress->subject_type,
            'subject_id' => $this->progress->subject_id,
        ];
    }
}
