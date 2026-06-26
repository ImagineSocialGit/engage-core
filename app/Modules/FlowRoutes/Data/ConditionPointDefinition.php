<?php

namespace App\Modules\FlowRoutes\Data;

use App\Modules\FlowRoutes\Data\PointExecutionResult;

class ConditionPointDefinition
{
    public const MODE_ALL = 'all';
    public const MODE_ANY = 'any';

    public const ACTION_COMPLETED = PointExecutionResult::STATUS_COMPLETED;
    public const ACTION_SKIPPED = PointExecutionResult::STATUS_SKIPPED;
    public const ACTION_BLOCKED = PointExecutionResult::STATUS_BLOCKED;
    public const ACTION_FAILED = PointExecutionResult::STATUS_FAILED;

    public const MODES = [
        self::MODE_ALL,
        self::MODE_ANY,
    ];

    public const ACTIONS = [
        self::ACTION_COMPLETED,
        self::ACTION_SKIPPED,
        self::ACTION_BLOCKED,
        self::ACTION_FAILED,
    ];

    /**
     * @param array<int, array<string, mixed>> $conditions
     */
    public function __construct(
        public readonly array $conditions,
        public readonly string $mode,
        public readonly string $onPass,
        public readonly string $onFail,
        public readonly ?string $invalidReason = null,
    ) {}

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $settings
     */
    public static function from(array $definition, array $settings = []): self
    {
        $conditions = $definition['conditions']
            ?? $definition['condition']
            ?? $settings['conditions']
            ?? [];

        if (self::isAssociativeArray($conditions)) {
            $conditions = [$conditions];
        }

        $conditions = is_array($conditions)
            ? array_values(array_filter($conditions, fn ($condition) => is_array($condition)))
            : [];

        $mode = self::normalizedMode($definition['mode'] ?? $settings['mode'] ?? self::MODE_ALL);
        $onPass = self::normalizedAction($definition['on_pass'] ?? $settings['on_pass'] ?? self::ACTION_COMPLETED);
        $onFail = self::normalizedAction($definition['on_fail'] ?? $settings['on_fail'] ?? self::ACTION_BLOCKED);

        if ($conditions === []) {
            return new self(
                conditions: [],
                mode: $mode,
                onPass: $onPass,
                onFail: $onFail,
                invalidReason: 'condition_point_requires_conditions',
            );
        }

        return new self(
            conditions: $conditions,
            mode: $mode,
            onPass: $onPass,
            onFail: $onFail,
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
            'conditions' => $this->conditions,
            'mode' => $this->mode,
            'on_pass' => $this->onPass,
            'on_fail' => $this->onFail,
            'invalid_reason' => $this->invalidReason,
        ];
    }

    private static function normalizedMode(mixed $mode): string
    {
        $mode = is_string($mode) ? trim($mode) : '';

        return in_array($mode, self::MODES, true)
            ? $mode
            : self::MODE_ALL;
    }

    private static function normalizedAction(mixed $action): string
    {
        $action = is_string($action) ? trim($action) : '';

        return in_array($action, self::ACTIONS, true)
            ? $action
            : self::ACTION_BLOCKED;
    }

    /**
     * @param array<mixed> $value
     */
    private static function isAssociativeArray(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }
}