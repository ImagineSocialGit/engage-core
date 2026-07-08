<?php

namespace App\Modules\FlowRoutes\PointHandlers;

use App\Modules\FlowRoutes\Contracts\PointHandler;
use App\Modules\FlowRoutes\Data\Points\EventWaitPointDefinition;
use App\Modules\FlowRoutes\Data\Points\PointExecutionContext;
use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Models\Point;
use Carbon\CarbonImmutable;

class EventWaitPointHandler implements PointHandler
{
    public function type(): string { return Point::TYPE_EVENT_WAIT; }

    public function handle(PointExecutionContext $context): PointExecutionResult
    {
        $now = CarbonImmutable::now('UTC');
        $waitingState = $context->progress->waitingState();
        $waitingPlanItemId = is_numeric($waitingState['flow_route_plan_item_id'] ?? null)
            ? (int) $waitingState['flow_route_plan_item_id']
            : null;
        $waitingFlowRoutePointId = $context->progress->waitingFlowRoutePointId();

        if (
            ($waitingPlanItemId === null || $waitingPlanItemId === (int) $context->planItem?->getKey())
            && $waitingFlowRoutePointId === (int) $context->flowRoutePoint->getKey()
            && $this->hasMatchedEvent($waitingState)
        ) {
            return PointExecutionResult::completed('event_wait_matched', [
                'wait' => array_replace_recursive($waitingState, [
                    'resumed_at' => $now->toISOString(),
                ]),
            ]);
        }

        $definition = EventWaitPointDefinition::from($context->definition, $context->settings);

        if (! $definition->isValid()) {
            return PointExecutionResult::failed($definition->invalidReason ?? 'invalid_event_wait_point_definition', [
                'event_wait_definition' => $definition->toMetaPayload(),
                'flow_routes' => $context->flowRouteProvenance(),
            ]);
        }

        return PointExecutionResult::waiting('event_wait_point_waiting', [
            'wait' => [
                ...$context->flowRouteProvenance(),
                'flow_route_point_key' => $context->flowRoutePoint->key,
                'point_key' => $context->flowRoutePoint->point?->key,
                'point_type' => Point::TYPE_EVENT_WAIT,
                'started_waiting_at' => $now->toISOString(),
                'expected_event' => $definition->eventKey,
                'correlation' => $definition->correlation,
                'definition' => $definition->toMetaPayload(),
            ],
        ]);
    }

    private function hasMatchedEvent(array $waitingState): bool
    {
        $matchedEvent = $waitingState['matched_event'] ?? null;

        return is_array($matchedEvent)
            && is_string($matchedEvent['name'] ?? null)
            && trim($matchedEvent['name']) !== '';
    }
}
