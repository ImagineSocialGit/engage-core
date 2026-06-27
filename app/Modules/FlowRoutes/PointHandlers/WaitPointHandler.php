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
    public function type(): string
    {
        return Point::TYPE_WAIT;
    }

    public function handle(PointExecutionContext $context): PointExecutionResult
    {
        $now = CarbonImmutable::now('UTC');
        $waitingState = $context->progress->waitingState();
        $waitingFlowRoutePointId = $context->progress->waitingFlowRoutePointId();
        $resumeAt = $context->progress->waitingResumeAt();

        if (
            $waitingFlowRoutePointId === (int) $context->flowRoutePoint->getKey()
            && $resumeAt instanceof CarbonImmutable
        ) {
            if ($resumeAt->greaterThan($now)) {
                return PointExecutionResult::waiting(
                    reason: 'wait_point_not_due',
                    meta: [
                        'wait' => array_replace_recursive($waitingState, [
                            'checked_at' => $now->toISOString(),
                        ]),
                    ],
                );
            }

            return PointExecutionResult::completed(
                reason: 'wait_point_due',
                meta: [
                    'wait' => array_replace_recursive($waitingState, [
                        'resumed_at' => $now->toISOString(),
                    ]),
                ],
            );
        }

        $definition = WaitPointDefinition::from(
            definition: $context->definition,
            settings: $context->settings,
            now: $now,
        );

        if (! $definition->isValid()) {
            return PointExecutionResult::failed(
                reason: $definition->invalidReason ?? 'invalid_wait_point_definition',
                meta: [
                    'wait_definition' => $definition->toMetaPayload(),
                    'flow_route_point_id' => $context->flowRoutePoint->getKey(),
                    'point_id' => $context->flowRoutePoint->point_id,
                ],
            );
        }

        if ($definition->isImmediate($now)) {
            return PointExecutionResult::completed(
                reason: 'wait_point_immediate',
                meta: [
                    'wait_definition' => $definition->toMetaPayload(),
                    'flow_route_point_id' => $context->flowRoutePoint->getKey(),
                    'point_id' => $context->flowRoutePoint->point_id,
                ],
            );
        }

        return PointExecutionResult::waiting(
            reason: 'wait_point_scheduled',
            meta: [
                'wait' => [
                    'flow_route_point_id' => $context->flowRoutePoint->getKey(),
                    'point_id' => $context->flowRoutePoint->point_id,
                    'point_type' => Point::TYPE_WAIT,
                    'started_waiting_at' => $now->toISOString(),
                    'resume_at' => $definition->resumeAt?->toISOString(),
                    'definition' => $definition->toMetaPayload(),
                ],
            ],
        );
    }
}