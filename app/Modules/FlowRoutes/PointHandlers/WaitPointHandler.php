<?php

namespace App\Modules\FlowRoutes\PointHandlers;

use App\Modules\FlowRoutes\Contracts\PointHandler;
use App\Modules\FlowRoutes\Data\PointExecutionContext;
use App\Modules\FlowRoutes\Data\PointExecutionResult;
use App\Modules\FlowRoutes\Models\Point;

class WaitPointHandler implements PointHandler
{
    public function type(): string
    {
        return Point::TYPE_WAIT;
    }

    public function handle(PointExecutionContext $context): PointExecutionResult
    {
        return PointExecutionResult::waiting(
            reason: 'wait_point_requires_future_scheduler',
            meta: [
                'point_id' => $context->flowRoutePoint->point_id,
                'flow_route_point_id' => $context->flowRoutePoint->getKey(),
                'definition' => $context->definition,
                'settings' => $context->settings,
            ],
        );
    }
}