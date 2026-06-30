<?php

namespace App\Modules\FlowRoutes\Data\Points;

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
        public readonly ?string $defaultTargetFlowRoutePointKey = null,
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
            ? array_values(array_filter($branches, fn (mixed $branch): bool => is_array($branch)))
            : [];

        $mode = self::normalizedMode($definition['mode'] ?? $settings['mode'] ?? ConditionPointDefinition::MODE_ALL);

        $onNoMatch = self::normalizedNoMatchAction(
            $definition['on_no_match']
                ?? $settings['on_no_match']
                ?? self::ON_NO_MATCH_BLOCKED
        );

        $defaultTargetFlowRoutePointKey = self::nullableString(
            $definition['default_target_flow_route_point_key']
                ?? $settings['default_target_flow_route_point_key']
                ?? null
        );

        if ($branches === [] && $defaultTargetFlowRoutePointKey === null) {
            return new self(
                branches: [],
                mode: $mode,
                onNoMatch: $onNoMatch,
                invalidReason: 'branch_evaluate_point_requires_branches_or_default_target',
            );
        }

        foreach ($branches as $branch) {
            if (! self::branchHasTarget($branch)) {
                return new self(
                    branches: $branches,
                    mode: $mode,
                    onNoMatch: $onNoMatch,
                    defaultTargetFlowRoutePointKey: $defaultTargetFlowRoutePointKey,
                    invalidReason: 'branch_evaluate_point_branch_missing_target_flow_route_point_key',
                );
            }
        }

        return new self(
            branches: $branches,
            mode: $mode,
            onNoMatch: $onNoMatch,
            defaultTargetFlowRoutePointKey: $defaultTargetFlowRoutePointKey,
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
            'default_target_flow_route_point_key' => $this->defaultTargetFlowRoutePointKey,
            'invalid_reason' => $this->invalidReason,
        ];
    }

    /**
     * @param array<string, mixed> $branch
     */
    private static function branchHasTarget(array $branch): bool
    {
        return self::nullableString($branch['target_flow_route_point_key'] ?? null) !== null;
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

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}