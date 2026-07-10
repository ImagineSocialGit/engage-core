<?php

namespace App\Modules\FlowRoutes\PointHandlers;

use App\Modules\FlowRoutes\Contracts\PointHandler;
use App\Modules\FlowRoutes\Data\Points\BranchEvaluatePointDefinition;
use App\Modules\FlowRoutes\Data\Points\PointExecutionContext;
use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Services\FlowRouteConditionEvaluatorRegistry;

class BranchEvaluatePointHandler implements PointHandler
{
    public function __construct(
        private readonly FlowRouteConditionEvaluatorRegistry $conditionEvaluatorRegistry,
    ) {}

    public function type(): string
    {
        return FlowRoutePointType::BranchEvaluate->value;
    }

    public function handle(PointExecutionContext $context): PointExecutionResult
    {
        $definition = BranchEvaluatePointDefinition::from(
            definition: $context->definition,
            settings: $context->settings,
        );

        if (! $definition->isValid()) {
            return PointExecutionResult::failed(
                reason: $definition->invalidReason ?? 'invalid_branch_evaluate_point_definition',
                meta: [
                    'branch_definition' => $definition->toMetaPayload(),
                    'flow_route_point_id' => $context->flowRoutePoint->getKey(),
                    'flow_route_point_key' => $context->flowRoutePoint->key,
                ],
            );
        }

        foreach ($definition->branches as $index => $branch) {
            $conditions = $branch['conditions'] ?? [];

            if ($this->isAssociativeArray($conditions)) {
                $conditions = [$conditions];
            }

            $conditions = is_array($conditions)
                ? array_values(array_filter($conditions, fn (mixed $condition): bool => is_array($condition)))
                : [];

            $mode = is_string($branch['mode'] ?? null)
                ? trim((string) $branch['mode'])
                : $definition->mode;

            $evaluation = $this->conditionEvaluatorRegistry->evaluateMany(
                conditions: $conditions,
                context: $context,
                mode: $mode,
            );

            if (! $evaluation->passed) {
                continue;
            }

            $targetFlowRoutePointKey = $this->nullableString($branch['target_flow_route_point_key'] ?? null);
            $target = $this->resolveTargetByKey($context, $targetFlowRoutePointKey);

            if (! $target) {
                return PointExecutionResult::failed(
                    reason: 'branch_target_not_found',
                    meta: [
                        'branch_definition' => $definition->toMetaPayload(),
                        'matched_branch_index' => $index,
                        'matched_branch' => $branch,
                        'condition_evaluation' => $evaluation->toMetaPayload(),
                        'target_flow_route_point_key' => $targetFlowRoutePointKey,
                    ],
                );
            }

            return PointExecutionResult::completed(
                reason: 'branch_matched',
                meta: [
                    'branch_definition' => $definition->toMetaPayload(),
                    'matched_branch_index' => $index,
                    'matched_branch' => $branch,
                    'condition_evaluation' => $evaluation->toMetaPayload(),
                    'advance_to_flow_route_point_id' => $target->getKey(),
                    'advance_to_flow_route_point_key' => $target->key,
                ],
            );
        }

        $defaultTarget = $this->resolveTargetByKey(
            context: $context,
            targetFlowRoutePointKey: $definition->defaultTargetFlowRoutePointKey,
        );

        if ($defaultTarget) {
            return PointExecutionResult::completed(
                reason: 'branch_default_target_selected',
                meta: [
                    'branch_definition' => $definition->toMetaPayload(),
                    'advance_to_flow_route_point_id' => $defaultTarget->getKey(),
                    'advance_to_flow_route_point_key' => $defaultTarget->key,
                ],
            );
        }

        return $this->noMatchResult(
            action: $definition->onNoMatch,
            meta: [
                'branch_definition' => $definition->toMetaPayload(),
            ],
        );
    }

    private function resolveTargetByKey(
        PointExecutionContext $context,
        ?string $targetFlowRoutePointKey,
    ): ?FlowRoutePoint {
        if ($targetFlowRoutePointKey === null) {
            return null;
        }

        return FlowRoutePoint::query()
            ->where('flow_route_id', $context->flowRoutePoint->flow_route_id)
            ->forKey($targetFlowRoutePointKey)
            ->active()
            ->first();
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function noMatchResult(string $action, array $meta): PointExecutionResult
    {
        return match ($action) {
            PointExecutionResult::STATUS_COMPLETED => PointExecutionResult::completed('branch_no_match', $meta),
            PointExecutionResult::STATUS_SKIPPED => PointExecutionResult::skipped('branch_no_match', $meta),
            PointExecutionResult::STATUS_FAILED => PointExecutionResult::failed('branch_no_match', $meta),
            default => PointExecutionResult::blocked('branch_no_match', $meta),
        };
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function isAssociativeArray(mixed $value): bool
    {
        return is_array($value)
            && $value !== []
            && array_keys($value) !== range(0, count($value) - 1);
    }
}
