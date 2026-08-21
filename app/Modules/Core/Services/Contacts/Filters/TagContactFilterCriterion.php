<?php

namespace App\Modules\Core\Services\Contacts\Filters;

use App\Modules\Core\Contracts\Contacts\ContactFilterCriterion;
use App\Modules\Core\Models\ContactTag;
use Illuminate\Database\Eloquent\Builder;

class TagContactFilterCriterion implements ContactFilterCriterion
{
    public function key(): string
    {
        return 'tag';
    }

    public function sortOrder(): int
    {
        return 50;
    }

    public function label(): string
    {
        return 'Tags';
    }

    public function help(): ?string
    {
        return 'Match contacts carrying at least one selected tag.';
    }

    public function options(): array
    {
        return ContactTag::query()
            ->whereNotNull('tag')
            ->where('tag', '!=', '')
            ->distinct()
            ->orderBy('tag')
            ->pluck('tag')
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
        $query->whereExists(function ($subquery) use ($values): void {
            $subquery
                ->selectRaw('1')
                ->from('contact_tags')
                ->whereColumn('contact_tags.contact_id', 'contacts.id')
                ->whereIn('contact_tags.tag', $values);
        });
    }
}