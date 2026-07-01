<?php

namespace App\Modules\Core\Services\Contacts;

use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ContactFilterResolver
{
    /**
     * @return Collection<int, Contact>
     */
    public function resolve(array $filter = []): Collection
    {
        return $this->query($filter)->get();
    }

    /**
     * @return Builder<Contact>
     */
    public function query(array $filter = []): Builder
    {
        $type = $this->filterType($filter['type'] ?? null);

        return match ($type) {
            'all' => $this->allContactsQuery(),
            'contact_ids' => $this->contactIdsQuery($filter),
            'tag' => $this->tagsQuery($filter),
            default => $this->emptyContactsQuery(),
        };
    }

    /**
     * @return Builder<Contact>
     */
    private function allContactsQuery(): Builder
    {
        return Contact::query()
            ->orderBy('id');
    }

    /**
     * @param array<string, mixed> $filter
     * @return Builder<Contact>
     */
    private function contactIdsQuery(array $filter): Builder
    {
        $contactIds = $this->integerValues($filter['contact_ids'] ?? []);

        if ($contactIds === []) {
            return $this->emptyContactsQuery();
        }

        return Contact::query()
            ->whereIn('id', $contactIds)
            ->orderBy('id');
    }

    /**
     * @param array<string, mixed> $filter
     * @return Builder<Contact>
     */
    private function tagsQuery(array $filter): Builder
    {
        $tags = $this->stringValues($filter['tags'] ?? []);

        if ($tags === []) {
            return $this->emptyContactsQuery();
        }

        return Contact::query()
            ->whereHas('tags', function (Builder $query) use ($tags): void {
                $query->whereIn('tag', $tags);
            })
            ->orderBy('id');
    }

    /**
     * @return Builder<Contact>
     */
    private function emptyContactsQuery(): Builder
    {
        return Contact::query()
            ->whereRaw('1 = 0')
            ->orderBy('id');
    }

    private function filterType(mixed $value): string
    {
        return is_string($value) && trim($value) !== ''
            ? strtolower(trim($value))
            : 'all';
    }

    /**
     * @return array<int, int>
     */
    private function integerValues(mixed $values): array
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
    private function stringValues(mixed $values): array
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