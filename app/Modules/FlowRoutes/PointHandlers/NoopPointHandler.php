<?php

namespace App\Modules\FlowRoutes\PointHandlers;

use App\Modules\FlowRoutes\Contracts\PointHandler;
use App\Modules\FlowRoutes\Data\Points\PointExecutionContext;
use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Models\Point;

class NoopPointHandler implements PointHandler
{
    public function type(): string
    {
        return Point::TYPE_NOOP;
    }

    public function handle(PointExecutionContext $context): PointExecutionResult
    {
        return PointExecutionResult::completed(
            reason: 'noop_point_completed',
            meta: [
                'point_id' => $context->flowRoutePoint->point_id,
                'flow_route_point_id' => $context->flowRoutePoint->getKey(),
            ],
        );
    }
}