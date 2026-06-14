<?php

namespace App\Services;

use Illuminate\Support\Arr;
use InvalidArgumentException;

class ConditionChecker
{
    /**
     * Supports both:
     *
     * [
     *     'webinar_registration.attended_at_filled' => true,
     * ]
     *
     * and:
     *
     * [
     *     [
     *         'field' => 'registration.attended_at',
     *         'operator' => 'filled',
     *     ],
     * ]
     *
     * @param  array<int|string, mixed>  $conditions
     * @param  array<string, mixed>  $context
     */
    public function passes(array $conditions, array $context): bool
    {
        foreach ($conditions as $key => $condition) {
            if (is_array($condition) && array_key_exists('field', $condition)) {
                if (! $this->passesStructuredCondition($condition, $context)) {
                    return false;
                }

                continue;
            }

            if (! is_string($key) || trim($key) === '') {
                throw new InvalidArgumentException('Condition keys must be non-empty strings.');
            }

            if (! $this->passesLegacyCondition(trim($key), $condition, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $context
     */
    private function passesStructuredCondition(array $condition, array $context): bool
    {
        $field = $condition['field'] ?? null;

        if (! is_string($field) || trim($field) === '') {
            throw new InvalidArgumentException('Condition [field] must be a non-empty string.');
        }

        $operator = $condition['operator'] ?? 'eq';

        if (! is_string($operator) || trim($operator) === '') {
            throw new InvalidArgumentException('Condition [operator] must be a non-empty string.');
        }

        return $this->evaluate(
            path: trim($field),
            operator: $this->normalizeOperator($operator),
            expected: $condition['value'] ?? null,
            context: $context,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function passesLegacyCondition(string $key, mixed $expected, array $context): bool
    {
        [$path, $operator] = $this->parseLegacyKey($key);

        return $this->evaluate(
            path: $path,
            operator: $operator,
            expected: $expected,
            context: $context,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function evaluate(string $path, string $operator, mixed $expected, array $context): bool
    {
        $actual = Arr::get($context, $path);

        return match ($operator) {
            'eq' => $actual == $expected,
            'not' => $actual != $expected,

            'in' => $this->expectedArrayContains($expected, $actual),
            'not_in' => ! $this->expectedArrayContains($expected, $actual),

            'exists' => Arr::has($context, $path),
            'missing' => ! Arr::has($context, $path),

            'filled' => filled($actual),
            'blank' => blank($actual),

            'truthy' => (bool) $actual === true,
            'falsy' => (bool) $actual === false,

            'gt' => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
            'gte' => is_numeric($actual) && is_numeric($expected) && $actual >= $expected,
            'lt' => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
            'lte' => is_numeric($actual) && is_numeric($expected) && $actual <= $expected,

            default => throw new InvalidArgumentException("Unsupported condition operator [{$operator}]."),
        };
    }

    /**
     * Supports:
     *
     * contact.status => 'converted'
     * contact.status_not_in => ['converted']
     * webinar_registration.attended => false
     * contact.email_exists => true
     *
     * @return array{0: string, 1: string}
     */
    private function parseLegacyKey(string $key): array
    {
        $operators = [
            '_not_in' => 'not_in',
            '_exists' => 'exists',
            '_missing' => 'missing',
            '_filled' => 'filled',
            '_blank' => 'blank',
            '_truthy' => 'truthy',
            '_falsy' => 'falsy',
            '_not' => 'not',
            '_gte' => 'gte',
            '_lte' => 'lte',
            '_gt' => 'gt',
            '_lt' => 'lt',
            '_in' => 'in',
        ];

        foreach ($operators as $suffix => $operator) {
            if (str_ends_with($key, $suffix)) {
                return [
                    substr($key, 0, -strlen($suffix)),
                    $operator,
                ];
            }
        }

        return [$key, 'eq'];
    }

    private function normalizeOperator(string $operator): string
    {
        return str_replace('-', '_', strtolower(trim($operator)));
    }

    private function expectedArrayContains(mixed $expected, mixed $actual): bool
    {
        if (! is_array($expected)) {
            throw new InvalidArgumentException('Condition [in/not_in] operators require an array value.');
        }

        return in_array($actual, $expected, true);
    }
}