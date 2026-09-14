<?php

namespace App\Modules\Forms\Services;

use App\Modules\Forms\Data\PublishedForm;

final class FormVisibilityEvaluator
{
    public function __construct(
        private readonly HostedFormLayoutResolver $layouts,
    ) {}

    /**
     * @param array<string, mixed> $input
     * @return array<int, string>
     */
    public function visibleFieldKeys(PublishedForm $form, array $input): array
    {
        $layout = $this->layouts->resolve($form);
        $fieldKeys = $form->fieldKeys();
        $knownFields = array_fill_keys($fieldKeys, true);
        $conditionByTarget = [];

        foreach ($layout['conditions'] as $condition) {
            foreach ($condition['targets'] as $target) {
                if (isset($knownFields[$target])) {
                    $conditionByTarget[$target] = $condition;
                }
            }
        }

        $visible = [];

        foreach ($fieldKeys as $fieldKey) {
            $visible[$fieldKey] = ! isset($conditionByTarget[$fieldKey]);
        }

        $maximumIterations = max(1, count($fieldKeys) + 1);

        for ($iteration = 0; $iteration < $maximumIterations; $iteration++) {
            $changed = false;
            $next = $visible;

            foreach ($conditionByTarget as $target => $condition) {
                $matches = $this->matches(
                    form: $form,
                    condition: $condition,
                    input: $input,
                    visible: $visible,
                );

                if ($next[$target] !== $matches) {
                    $next[$target] = $matches;
                    $changed = true;
                }
            }

            $visible = $next;

            if (! $changed) {
                break;
            }
        }

        return array_values(array_filter(
            $fieldKeys,
            static fn (string $fieldKey): bool => $visible[$fieldKey] ?? false,
        ));
    }

    /**
     * @param array<string, mixed> $condition
     * @param array<string, mixed> $input
     * @param array<string, bool> $visible
     */
    private function matches(
        PublishedForm $form,
        array $condition,
        array $input,
        array $visible,
    ): bool {
        $results = [];

        foreach ($condition['when'] as $predicate) {
            $fieldKey = (string) $predicate['field'];

            if (! ($visible[$fieldKey] ?? false)) {
                $results[] = false;

                continue;
            }

            $value = $this->conditionValue(
                $form,
                $fieldKey,
                $input[$fieldKey] ?? null,
            );
            $results[] = $this->predicateMatches($predicate, $value);
        }

        return ($condition['match'] ?? 'all') === 'any'
            ? in_array(true, $results, true)
            : ! in_array(false, $results, true);
    }

    /**
     * @param array<string, mixed> $predicate
     */
    private function predicateMatches(array $predicate, mixed $actual): bool
    {
        $operator = (string) $predicate['operator'];

        return match ($operator) {
            'equals' => $actual === ($predicate['value'] ?? null),
            'not_equals' => $actual !== ($predicate['value'] ?? null),
            'contains' => is_array($actual)
                && in_array($predicate['value'] ?? null, $actual, true),
            'filled' => $this->filled($actual),
            default => false,
        };
    }

    private function filled(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return ! is_array($value) || $value !== [];
    }

    private function conditionValue(
        PublishedForm $form,
        string $fieldKey,
        mixed $value,
    ): mixed {
        $type = (string) ($form->field($fieldKey)['type'] ?? 'text');

        if ($type === 'checkboxes') {
            if (! is_array($value)) {
                return [];
            }

            return array_values(array_unique(array_filter(array_map(
                static fn (mixed $item): string => is_string($item) ? trim($item) : '',
                $value,
            ), static fn (string $item): bool => $item !== '')));
        }

        if (in_array($type, ['checkbox', 'boolean'], true)) {
            if (is_bool($value)) {
                return $value;
            }

            if (is_int($value) && in_array($value, [0, 1], true)) {
                return $value === 1;
            }

            if (is_string($value)) {
                return match (strtolower(trim($value))) {
                    '1', 'true', 'yes', 'on' => true,
                    '0', 'false', 'no', 'off', '' => false,
                    default => null,
                };
            }

            return false;
        }

        if (is_string($value)) {
            $value = trim($value);

            return $value !== '' ? $value : null;
        }

        return is_scalar($value) ? $value : null;
    }
}