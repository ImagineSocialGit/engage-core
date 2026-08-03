<?php

namespace App\Support\ProjectState;

class ProjectStateImportValueMapper
{
    /**
     * @param array<int|string, mixed> $valueMap
     */
    public function map(mixed $value, array $valueMap): mixed
    {
        if ($value === null) {
            return null;
        }

        $key = is_bool($value)
            ? ($value ? '1' : '0')
            : (string) $value;

        return array_key_exists($key, $valueMap)
            ? $valueMap[$key]
            : $value;
    }

    public function display(mixed $value): string
    {
        if ($value === null) {
            return '[null]';
        }

        if (is_bool($value)) {
            return $value ? '[true]' : '[false]';
        }

        return '['.(string) $value.']';
    }
}