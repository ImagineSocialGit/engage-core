<?php

namespace App\Modules\FlowRoutes\PointHandlers;

use App\Modules\FlowRoutes\Contracts\PointHandler;
use App\Modules\FlowRoutes\Data\EventWaitPointDefinition;
use App\Modules\FlowRoutes\Data\PointExecutionContext;
use App\Modules\FlowRoutes\Data\PointExecutionResult;
use App\Modules\FlowRoutes\Models\Point;
use Carbon\CarbonImmutable;

class EventWaitPointHandler implements PointHandler
{
    public function type(): string
    {
        return Point::TYPE_EVENT_WAIT;
    }

    public function handle(PointExecutionContext $context): PointExecutionResult
    {
        $now = CarbonImmutable::now('UTC');
        $waitingState = $context->progress->waitingState();
        $waitingFlowRoutePointId = $context->progress->waitingFlowRoutePointId();

        if (
            $waitingFlowRoutePointId === (int) $context->flowRoutePoint->getKey()
            && $this->hasMatchedEvent($waitingState)
        ) {
            return PointExecutionResult::completed(
                reason: 'event_wait_matched',
                meta: [
                    'wait' => array_replace_recursive($waitingState, [
                        'resumed_at' => $now->toISOString(),
                    ]),
                ],
            );
        }

        $definition = EventWaitPointDefinition::from(
            definition: $context->definition,
            settings: $context->settings,
        );

        if (! $definition->isValid()) {
            return PointExecutionResult::failed(
                reason: $definition->invalidReason ?? 'invalid_event_wait_point_definition',
                meta: [
                    'event_wait_definition' => $definition->toMetaPayload(),
                    'flow_route_point_id' => $context->flowRoutePoint->getKey(),
                    'point_id' => $context->flowRoutePoint->point_id,
                ],
            );
        }

        return PointExecutionResult::waiting(
            reason: 'event_wait_point_waiting',
            meta: [
                'wait' => [
                    'flow_route_point_id' => $context->flowRoutePoint->getKey(),
                    'point_id' => $context->flowRoutePoint->point_id,
                    'point_type' => Point::TYPE_EVENT_WAIT,
                    'started_waiting_at' => $now->toISOString(),
                    'expected_event' => $definition->expectedEvent,
                    'correlation' => $definition->correlation,
                    'definition' => $definition->toMetaPayload(),
                ],
            ],
        );
    }

    /**
     * @param array<string, mixed> $waitingState
     */
    private function hasMatchedEvent(array $waitingState): bool
    {
        $matchedEvent = $waitingState['matched_event'] ?? null;

        return is_array($matchedEvent)
            && is_string($matchedEvent['name'] ?? null)
            && trim($matchedEvent['name']) !== '';
    }
}