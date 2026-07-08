<?php

namespace App\Modules\FlowRoutes\PointHandlers;

use App\Modules\FlowRoutes\Contracts\PointHandler;
use App\Modules\FlowRoutes\Data\Points\PointExecutionContext;
use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Data\Points\WaitPointDefinition;
use App\Modules\FlowRoutes\Models\Point;
use Carbon\CarbonImmutable;

class WaitPointHandler implements PointHandler
{
    public function type(): string { return Point::TYPE_WAIT; }

    public function handle(PointExecutionContext $context): PointExecutionResult
    {
        $now = CarbonImmutable::now('UTC');
        $waitingState = $context->progress->waitingState();
        $waitingFlowRoutePointId = $context->progress->waitingFlowRoutePointId();
        $resumeAt = $context->progress->waitingResumeAt();

        if ($waitingFlowRoutePointId === (int) $context->flowRoutePoint->getKey() && $resumeAt instanceof CarbonImmutable) {
            if ($resumeAt->greaterThan($now)) {
                return PointExecutionResult::waiting('wait_point_not_due', [
                    'wait' => array_replace_recursive($waitingState, [
                        'checked_at' => $now->toISOString(),
                    ]),
                    'flow_routes' => $context->flowRouteProvenance(),
                ]);
            }

            return PointExecutionResult::completed('wait_point_due', [
                'wait' => array_replace_recursive($waitingState, [
                    'resumed_at' => $now->toISOString(),
                ]),
                'flow_routes' => $context->flowRouteProvenance(),
            ]);
        }

        $definition = WaitPointDefinition::from($context->definition, $context->settings, $now);

        if (! $definition->isValid()) {
            return PointExecutionResult::failed($definition->invalidReason ?? 'invalid_wait_point_definition', [
                'wait_definition' => $definition->toMetaPayload(),
                'flow_routes' => $context->flowRouteProvenance(),
            ]);
        }

        if ($definition->isImmediate($now)) {
            return PointExecutionResult::completed('wait_point_immediate', [
                'wait_definition' => $definition->toMetaPayload(),
                'flow_routes' => $context->flowRouteProvenance(),
            ]);
        }

        return PointExecutionResult::waiting('wait_point_scheduled', [
            'wait' => [
                ...$context->flowRouteProvenance(),
                'flow_route_point_key' => $context->flowRoutePoint->key,
                'point_key' => $context->flowRoutePoint->point?->key,
                'point_type' => Point::TYPE_WAIT,
                'started_waiting_at' => $now->toISOString(),
                'resume_at' => $definition->resumeAt?->toISOString(),
                'definition' => $definition->toMetaPayload(),
            ],
            'flow_routes' => $context->flowRouteProvenance(),
        ]);
    }
}

