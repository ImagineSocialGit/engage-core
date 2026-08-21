<?php

namespace App\Modules\Core\Services\Contacts\Filters;

use App\Modules\Core\Contracts\Contacts\ContactFilterCriterion;
use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Builder;

class SubsourceContactFilterCriterion implements ContactFilterCriterion
{
    public function key(): string
    {
        return 'subsource';
    }

    public function sortOrder(): int
    {
        return 40;
    }

    public function label(): string
    {
        return 'Subsource';
    }

    public function help(): ?string
    {
        return 'Narrow the audience by the more specific acquisition source.';
    }

    public function options(): array
    {
        return Contact::query()
            ->whereNotNull('subsource')
            ->where('subsource', '!=', '')
            ->distinct()
            ->orderBy('subsource')
            ->pluck('subsource')
            ->map(fn (string $value): array => ['value' => $value, 'label' => $value])
            ->values()
            ->all();
    }

    public function normalize(mixed $values): array
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

    public function apply(Builder $query, array $values): void
    {
        $query->whereIn('contacts.subsource', $values);
    }
}