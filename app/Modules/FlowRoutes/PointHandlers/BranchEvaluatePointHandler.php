<?php

namespace App\Modules\FlowRoutes\PointHandlers;

use App\Modules\FlowRoutes\Contracts\PointHandler;
use App\Modules\FlowRoutes\Data\BranchEvaluatePointDefinition;
use App\Modules\FlowRoutes\Data\PointExecutionContext;
use App\Modules\FlowRoutes\Data\PointExecutionResult;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Models\Point;
use App\Modules\FlowRoutes\Services\FlowRouteConditionEvaluatorRegistry;

class BranchEvaluatePointHandler implements PointHandler
{
    public function __construct(
        private readonly FlowRouteConditionEvaluatorRegistry $conditionEvaluatorRegistry,
    ) {}

    public function type(): string
    {
        return Point::TYPE_BRANCH_EVALUATE;
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
                    'point_id' => $context->flowRoutePoint->point_id,
                ],
            );
        }

        foreach ($definition->branches as $index => $branch) {
            $conditions = $branch['conditions'] ?? $branch['condition'] ?? [];

            if ($this->isAssociativeArray($conditions)) {
                $conditions = [$conditions];
            }

            $conditions = is_array($conditions)
                ? array_values(array_filter($conditions, fn ($condition) => is_array($condition)))
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

            $target = $this->resolveTarget($context, $branch);

            if (! $target) {
                return PointExecutionResult::failed(
                    reason: 'branch_target_not_found',
                    meta: [
                        'branch_definition' => $definition->toMetaPayload(),
                        'matched_branch_index' => $index,
                        'matched_branch' => $branch,
                        'condition_evaluation' => $evaluation->toMetaPayload(),
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
                    'advance_to_sort_order' => $target->sort_order,
                ],
            );
        }

        $defaultTarget = $this->resolveDefaultTarget($context, $definition);

        if ($defaultTarget) {
            return PointExecutionResult::completed(
                reason: 'branch_default_target_selected',
                meta: [
                    'branch_definition' => $definition->toMetaPayload(),
                    'advance_to_flow_route_point_id' => $defaultTarget->getKey(),
                    'advance_to_sort_order' => $defaultTarget->sort_order,
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

    /**
     * @param array<string, mixed> $branch
     */
    private function resolveTarget(PointExecutionContext $context, array $branch): ?FlowRoutePoint
    {
        $targetFlowRoutePointId = $this->nullableInteger($branch['target_flow_route_point_id'] ?? null);
        $targetSortOrder = $this->nullableInteger($branch['target_sort_order'] ?? null);

        return $this->resolveTargetByIdentifiers(
            context: $context,
            targetFlowRoutePointId: $targetFlowRoutePointId,
            targetSortOrder: $targetSortOrder,
        );
    }

    private function resolveDefaultTarget(
        PointExecutionContext $context,
        BranchEvaluatePointDefinition $definition,
    ): ?FlowRoutePoint {
        return $this->resolveTargetByIdentifiers(
            context: $context,
            targetFlowRoutePointId: $definition->defaultTargetFlowRoutePointId,
            targetSortOrder: $definition->defaultTargetSortOrder,
        );
    }

    private function resolveTargetByIdentifiers(
        PointExecutionContext $context,
        ?int $targetFlowRoutePointId,
        ?int $targetSortOrder,
    ): ?FlowRoutePoint {
        $query = FlowRoutePoint::query()
            ->with('point')
            ->where('flow_route_id', $context->flowRoutePoint->flow_route_id)
            ->active()
            ->whereHas('point', fn ($pointQuery) => $pointQuery->active());

        if ($targetFlowRoutePointId) {
            return $query
                ->whereKey($targetFlowRoutePointId)
                ->first();
        }

        if ($targetSortOrder !== null) {
            return $query
                ->where('sort_order', $targetSortOrder)
                ->first();
        }

        return null;
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

    private function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<mixed> $value
     */
    private function isAssociativeArray(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }
}