<?php

namespace App\Modules\Core\Requests\Concerns;

use Illuminate\Validation\Rule;

trait NormalizesContactFilter
{
    /**
     * @return array<string, mixed>
     */
    protected function contactFilterRules(
        string $typeField = 'contact_filter_type',
        string $tagField = 'contact_tag',
        string $idsField = 'contact_ids',
    ): array {
        return [
            $typeField => ['required', 'string', Rule::in(['all', 'tag', 'contact_ids', 'imported'])],
            $tagField => ['nullable', 'string', 'max:100', 'required_if:'.$typeField.',tag'],
            $idsField => ['nullable', 'array', 'required_if:'.$typeField.',contact_ids'],
            $idsField.'.*' => ['integer', Rule::exists('contacts', 'id')],
        ];
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    protected function contactFilterAttributes(
        array $validated,
        string $typeField = 'contact_filter_type',
        string $tagField = 'contact_tag',
        string $idsField = 'contact_ids',
    ): array {
        $type = $this->normalizedContactFilterType($validated[$typeField] ?? null);

        return match ($type) {
            'tag' => [
                'type' => 'tag',
                'tags' => $this->normalizedContactFilterTags([
                    $validated[$tagField] ?? null,
                ]),
            ],
            'contact_ids' => [
                'type' => 'contact_ids',
                'contact_ids' => $this->normalizedContactFilterIds($validated[$idsField] ?? []),
            ],
            'imported' => [
                'type' => 'imported',
            ],
            default => [
                'type' => 'all',
            ],
        };
    }

    private function normalizedContactFilterType(mixed $value): string
    {
        return is_string($value) && trim($value) !== ''
            ? str_replace('-', '_', strtolower(trim($value)))
            : 'all';
    }

    /**
     * @return array<int, int>
     */
    private function normalizedContactFilterIds(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null,
            $values,
        ), fn (?int $value): bool => $value !== null && $value > 0)));
    }

    /**
     * @return array<int, string>
     */
    private function normalizedContactFilterTags(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
                ? str_replace('-', '_', strtolower(trim($value)))
                : null,
            $values,
        ))));
    }
}