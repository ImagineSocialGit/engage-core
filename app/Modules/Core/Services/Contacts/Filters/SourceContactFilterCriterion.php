<?php

namespace App\Modules\Core\Services\Contacts\Filters;

use App\Modules\Core\Contracts\Contacts\ContactFilterCriterion;
use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Builder;

class SourceContactFilterCriterion implements ContactFilterCriterion
{
    public function key(): string
    {
        return 'source';
    }

    public function sortOrder(): int
    {
        return 30;
    }

    public function label(): string
    {
        return 'Source';
    }

    public function help(): ?string
    {
        return 'Match contacts by acquisition source.';
    }

    public function options(): array
    {
        return Contact::query()
            ->whereNotNull('source')
            ->where('source', '!=', '')
            ->distinct()
            ->orderBy('source')
            ->pluck('source')
            ->map(fn (string $value): array => ['value' => $value, 'label' => $value])
            ->values()
            ->all();
    }

    public function normalize(mixed $values): array
    {
        return $this->allowedValues($values);
    }

    public function apply(Builder $query, array $values): void
    {
        $query->whereIn('contacts.source', $values);
    }

    /** @return array<int, string> */
    private function allowedValues(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $allowed = array_column($this->options(), 'value');

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => is_string($value) && in_array(trim($value), $allowed, true)
                ? trim($value)
                : null,
            $values,
        ))));
    }
}