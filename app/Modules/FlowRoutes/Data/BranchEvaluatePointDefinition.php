<?php

namespace App\Modules\FlowRoutes\Data;

use App\Modules\FlowRoutes\Data\ConditionPointDefinition;
use App\Modules\FlowRoutes\Data\PointExecutionResult;

class BranchEvaluatePointDefinition
{
    public const ON_NO_MATCH_BLOCKED = PointExecutionResult::STATUS_BLOCKED;
    public const ON_NO_MATCH_SKIPPED = PointExecutionResult::STATUS_SKIPPED;
    public const ON_NO_MATCH_COMPLETED = PointExecutionResult::STATUS_COMPLETED;
    public const ON_NO_MATCH_FAILED = PointExecutionResult::STATUS_FAILED;

    public const ON_NO_MATCH_ACTIONS = [
        self::ON_NO_MATCH_BLOCKED,
        self::ON_NO_MATCH_SKIPPED,
        self::ON_NO_MATCH_COMPLETED,
        self::ON_NO_MATCH_FAILED,
    ];

    /**
     * @param array<int, array<string, mixed>> $branches
     */
    public function __construct(
        public readonly array $branches,
        public readonly string $mode,
        public readonly string $onNoMatch,
        public readonly ?int $defaultTargetFlowRoutePointId = null,
        public readonly ?int $defaultTargetSortOrder = null,
        public readonly ?string $invalidReason = null,
    ) {}

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $settings
     */
    public static function from(array $definition, array $settings = []): self
    {
        $branches = $definition['branches'] ?? $settings['branches'] ?? [];

        $branches = is_array($branches)
            ? array_values(array_filter($branches, fn ($branch) => is_array($branch)))
            : [];

        $mode = self::normalizedMode($definition['mode'] ?? $settings['mode'] ?? ConditionPointDefinition::MODE_ALL);
        $onNoMatch = self::normalizedNoMatchAction(
            $definition['on_no_match']
                ?? $settings['on_no_match']
                ?? self::ON_NO_MATCH_BLOCKED
        );

        $defaultTargetFlowRoutePointId = self::nullableInteger(
            $definition['default_target_flow_route_point_id']
                ?? $settings['default_target_flow_route_point_id']
                ?? null
        );

        $defaultTargetSortOrder = self::nullableInteger(
            $definition['default_target_sort_order']
                ?? $settings['default_target_sort_order']
                ?? null
        );

        if ($branches === [] && ! $defaultTargetFlowRoutePointId && ! $defaultTargetSortOrder) {
            return new self(
                branches: [],
                mode: $mode,
                onNoMatch: $onNoMatch,
                invalidReason: 'branch_evaluate_point_requires_branches_or_default_target',
            );
        }

        return new self(
            branches: $branches,
            mode: $mode,
            onNoMatch: $onNoMatch,
            defaultTargetFlowRoutePointId: $defaultTargetFlowRoutePointId,
            defaultTargetSortOrder: $defaultTargetSortOrder,
        );
    }

    public function isValid(): bool
    {
        return $this->invalidReason === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toMetaPayload(): array
    {
        return [
            'branches' => $this->branches,
            'mode' => $this->mode,
            'on_no_match' => $this->onNoMatch,
            'default_target_flow_route_point_id' => $this->defaultTargetFlowRoutePointId,
            'default_target_sort_order' => $this->defaultTargetSortOrder,
            'invalid_reason' => $this->invalidReason,
        ];
    }

    private static function normalizedMode(mixed $mode): string
    {
        $mode = is_string($mode) ? trim($mode) : '';

        return in_array($mode, ConditionPointDefinition::MODES, true)
            ? $mode
            : ConditionPointDefinition::MODE_ALL;
    }

    private static function normalizedNoMatchAction(mixed $action): string
    {
        $action = is_string($action) ? trim($action) : '';

        return in_array($action, self::ON_NO_MATCH_ACTIONS, true)
            ? $action
            : self::ON_NO_MATCH_BLOCKED;
    }

    private static function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}