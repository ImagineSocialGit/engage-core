<?php

namespace App\Modules\FlowRoutes\PointHandlers;

use App\Modules\FlowRoutes\Contracts\PointHandler;
use App\Modules\FlowRoutes\Data\Points\PointExecutionContext;
use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;

class NoopPointHandler implements PointHandler
{
    public function type(): string
    {
        return FlowRoutePointType::Noop->value;
    }

    public function handle(PointExecutionContext $context): PointExecutionResult
    {
        return PointExecutionResult::completed(
            reason: 'noop_point_completed',
            meta: [
                'flow_route_point_id' => $context->flowRoutePoint->getKey(),
                'flow_routes' => $context->flowRouteProvenance(),
            ],
        );
    }
}
