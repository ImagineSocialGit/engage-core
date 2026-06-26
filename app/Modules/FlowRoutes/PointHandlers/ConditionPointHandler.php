<?php

namespace App\Modules\FlowRoutes\PointHandlers;

use App\Modules\FlowRoutes\Contracts\PointHandler;
use App\Modules\FlowRoutes\Data\ConditionPointDefinition;
use App\Modules\FlowRoutes\Data\PointExecutionContext;
use App\Modules\FlowRoutes\Data\PointExecutionResult;
use App\Modules\FlowRoutes\Models\Point;
use App\Modules\FlowRoutes\Services\FlowRouteConditionEvaluatorRegistry;

class ConditionPointHandler implements PointHandler
{
    public function __construct(
        private readonly FlowRouteConditionEvaluatorRegistry $conditionEvaluatorRegistry,
    ) {}

    public function type(): string
    {
        return Point::TYPE_CONDITION;
    }

    public function handle(PointExecutionContext $context): PointExecutionResult
    {
        $definition = ConditionPointDefinition::from(
            definition: $context->definition,
            settings: $context->settings,
        );

        if (! $definition->isValid()) {
            return PointExecutionResult::failed(
                reason: $definition->invalidReason ?? 'invalid_condition_point_definition',
                meta: [
                    'condition_definition' => $definition->toMetaPayload(),
                    'flow_route_point_id' => $context->flowRoutePoint->getKey(),
                    'point_id' => $context->flowRoutePoint->point_id,
                ],
            );
        }

        $evaluation = $this->conditionEvaluatorRegistry->evaluateMany(
            conditions: $definition->conditions,
            context: $context,
            mode: $definition->mode,
        );

        return $this->resultForAction(
            action: $evaluation->passed ? $definition->onPass : $definition->onFail,
            reason: $evaluation->passed ? 'condition_point_passed' : 'condition_point_failed',
            meta: [
                'condition_definition' => $definition->toMetaPayload(),
                'condition_evaluation' => $evaluation->toMetaPayload(),
                'flow_route_point_id' => $context->flowRoutePoint->getKey(),
                'point_id' => $context->flowRoutePoint->point_id,
            ],
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function resultForAction(string $action, string $reason, array $meta): PointExecutionResult
    {
        return match ($action) {
            PointExecutionResult::STATUS_COMPLETED => PointExecutionResult::completed($reason, $meta),
            PointExecutionResult::STATUS_SKIPPED => PointExecutionResult::skipped($reason, $meta),
            PointExecutionResult::STATUS_FAILED => PointExecutionResult::failed($reason, $meta),
            default => PointExecutionResult::blocked($reason, $meta),
        };
    }
}