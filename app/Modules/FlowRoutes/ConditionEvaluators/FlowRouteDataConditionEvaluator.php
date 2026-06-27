<?php

namespace App\Modules\FlowRoutes\ConditionEvaluators;

use App\Modules\FlowRoutes\Contracts\FlowRouteConditionEvaluator;
use App\Modules\FlowRoutes\Data\FlowRouteConditionEvaluation;
use App\Modules\FlowRoutes\Data\Points\PointExecutionContext;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Throwable;

class FlowRouteDataConditionEvaluator implements FlowRouteConditionEvaluator
{
    public const OPERATOR_EQUALS = 'equals';
    public const OPERATOR_NOT_EQUALS = 'not_equals';
    public const OPERATOR_IN = 'in';
    public const OPERATOR_NOT_IN = 'not_in';
    public const OPERATOR_EXISTS = 'exists';
    public const OPERATOR_MISSING = 'missing';
    public const OPERATOR_EMPTY = 'empty';
    public const OPERATOR_NOT_EMPTY = 'not_empty';
    public const OPERATOR_CONTAINS = 'contains';
    public const OPERATOR_NOT_CONTAINS = 'not_contains';
    public const OPERATOR_GREATER_THAN = 'greater_than';
    public const OPERATOR_GREATER_THAN_OR_EQUALS = 'greater_than_or_equals';
    public const OPERATOR_LESS_THAN = 'less_than';
    public const OPERATOR_LESS_THAN_OR_EQUALS = 'less_than_or_equals';
    public const OPERATOR_BEFORE = 'before';
    public const OPERATOR_BEFORE_OR_EQUALS = 'before_or_equals';
    public const OPERATOR_AFTER = 'after';
    public const OPERATOR_AFTER_OR_EQUALS = 'after_or_equals';

    public const OPERATORS = [
        self::OPERATOR_EQUALS,
        self::OPERATOR_NOT_EQUALS,
        self::OPERATOR_IN,
        self::OPERATOR_NOT_IN,
        self::OPERATOR_EXISTS,
        self::OPERATOR_MISSING,
        self::OPERATOR_EMPTY,
        self::OPERATOR_NOT_EMPTY,
        self::OPERATOR_CONTAINS,
        self::OPERATOR_NOT_CONTAINS,
        self::OPERATOR_GREATER_THAN,
        self::OPERATOR_GREATER_THAN_OR_EQUALS,
        self::OPERATOR_LESS_THAN,
        self::OPERATOR_LESS_THAN_OR_EQUALS,
        self::OPERATOR_BEFORE,
        self::OPERATOR_BEFORE_OR_EQUALS,
        self::OPERATOR_AFTER,
        self::OPERATOR_AFTER_OR_EQUALS,
    ];

    public function supports(array $condition, PointExecutionContext $context): bool
    {
        $source = $this->source($condition);
        $operator = $this->operator($condition);

        return in_array($operator, self::OPERATORS, true)
            && in_array($source, $this->supportedSources(), true);
    }

    public function evaluate(array $condition, PointExecutionContext $context): FlowRouteConditionEvaluation
    {
        $source = $this->source($condition);
        $path = $this->path($condition);
        $operator = $this->operator($condition);

        if ($path === null && ! in_array($operator, [
            self::OPERATOR_EXISTS,
            self::OPERATOR_MISSING,
        ], true)) {
            return FlowRouteConditionEvaluation::failed(
                reason: 'condition_path_missing',
                meta: [
                    'condition' => $condition,
                ],
            );
        }

        $actual = $this->valueFromSource($source, $path, $context);

        $expected = $this->expectedValue($condition, $context);
        $passed = $this->compare($actual, $operator, $expected);

        return $passed
            ? FlowRouteConditionEvaluation::passed(
                reason: 'condition_passed',
                meta: $this->meta($source, $path, $operator, $actual, $expected),
            )
            : FlowRouteConditionEvaluation::failed(
                reason: 'condition_failed',
                meta: $this->meta($source, $path, $operator, $actual, $expected),
            );
    }

    /**
     * @return array<int, string>
     */
    private function supportedSources(): array
    {
        return [
            'progress',
            'progress_meta',
            'workflow_profile',
            'contact',
            'contact_status',
            'flow_route',
            'flow_route_point',
            'point',
            'definition',
            'settings',
            'execution_meta',
        ];
    }

    /**
     * @param array<string, mixed> $condition
     */
    private function source(array $condition): string
    {
        $source = $condition['source'] ?? 'progress';

        return is_string($source) && trim($source) !== ''
            ? trim($source)
            : 'progress';
    }

    /**
     * @param array<string, mixed> $condition
     */
    private function path(array $condition): ?string
    {
        $path = $condition['path'] ?? $condition['field'] ?? null;

        return is_string($path) && trim($path) !== ''
            ? trim($path)
            : null;
    }

    /**
     * @param array<string, mixed> $condition
     */
    private function operator(array $condition): string
    {
        $operator = $condition['operator'] ?? self::OPERATOR_EQUALS;

        return is_string($operator) && trim($operator) !== ''
            ? trim($operator)
            : self::OPERATOR_EQUALS;
    }

    /**
     * @param array<string, mixed> $condition
     */
    private function expectedValue(array $condition, PointExecutionContext $context): mixed
    {
        if (array_key_exists('compare_source', $condition) || array_key_exists('compare_path', $condition)) {
            $source = $this->source([
                'source' => $condition['compare_source'] ?? 'progress',
            ]);

            $path = $this->path([
                'path' => $condition['compare_path'] ?? null,
            ]);

            return $this->valueFromSource($source, $path, $context);
        }

        if (array_key_exists('values', $condition)) {
            return $condition['values'];
        }

        return $condition['value'] ?? null;
    }

    private function valueFromSource(string $source, ?string $path, PointExecutionContext $context): mixed
    {
        $data = match ($source) {
            'progress' => $this->modelData($context->progress),
            'progress_meta' => $context->progress->meta ?? [],
            'workflow_profile' => $this->modelData($context->progress->contactWorkflowProfile),
            'contact' => $this->modelData($context->progress->contact),
            'contact_status' => $this->modelData($context->progress->contactStatus),
            'flow_route' => $this->modelData($context->progress->flowRoute),
            'flow_route_point' => $this->modelData($context->flowRoutePoint),
            'point' => $this->modelData($context->flowRoutePoint->point),
            'definition' => $context->definition,
            'settings' => $context->settings,
            'execution_meta' => $context->meta,
            default => [],
        };

        if ($path === null) {
            return $data;
        }

        return Arr::get($data, $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function modelData(?Model $model): array
    {
        if (! $model) {
            return [];
        }

        return array_replace_recursive(
            $model->getAttributes(),
            [
                'id' => $model->getKey(),
            ],
        );
    }

    private function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            self::OPERATOR_EQUALS => $this->normalized($actual) === $this->normalized($expected),
            self::OPERATOR_NOT_EQUALS => $this->normalized($actual) !== $this->normalized($expected),
            self::OPERATOR_IN => in_array($this->normalized($actual), $this->normalizedArray($expected), true),
            self::OPERATOR_NOT_IN => ! in_array($this->normalized($actual), $this->normalizedArray($expected), true),
            self::OPERATOR_EXISTS => $actual !== null,
            self::OPERATOR_MISSING => $actual === null,
            self::OPERATOR_EMPTY => $this->isEmpty($actual),
            self::OPERATOR_NOT_EMPTY => ! $this->isEmpty($actual),
            self::OPERATOR_CONTAINS => $this->contains($actual, $expected),
            self::OPERATOR_NOT_CONTAINS => ! $this->contains($actual, $expected),
            self::OPERATOR_GREATER_THAN => $this->numeric($actual) > $this->numeric($expected),
            self::OPERATOR_GREATER_THAN_OR_EQUALS => $this->numeric($actual) >= $this->numeric($expected),
            self::OPERATOR_LESS_THAN => $this->numeric($actual) < $this->numeric($expected),
            self::OPERATOR_LESS_THAN_OR_EQUALS => $this->numeric($actual) <= $this->numeric($expected),
            self::OPERATOR_BEFORE => $this->date($actual)?->lessThan($this->date($expected)) ?? false,
            self::OPERATOR_BEFORE_OR_EQUALS => $this->date($actual)?->lessThanOrEqualTo($this->date($expected)) ?? false,
            self::OPERATOR_AFTER => $this->date($actual)?->greaterThan($this->date($expected)) ?? false,
            self::OPERATOR_AFTER_OR_EQUALS => $this->date($actual)?->greaterThanOrEqualTo($this->date($expected)) ?? false,
            default => false,
        };
    }

    private function normalized(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }

    /**
     * @return array<int, mixed>
     */
    private function normalizedArray(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        return array_map(fn (mixed $item) => $this->normalized($item), $values);
    }

    private function numeric(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value->utc();
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    private function contains(mixed $actual, mixed $expected): bool
    {
        $expected = $this->normalized($expected);

        if (is_array($actual)) {
            return in_array($expected, $this->normalizedArray($actual), true);
        }

        if (is_string($actual)) {
            return str_contains($actual, (string) $expected);
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(
        string $source,
        ?string $path,
        string $operator,
        mixed $actual,
        mixed $expected,
    ): array {
        return [
            'source' => $source,
            'path' => $path,
            'operator' => $operator,
            'actual' => $actual,
            'expected' => $expected,
        ];
    }
}