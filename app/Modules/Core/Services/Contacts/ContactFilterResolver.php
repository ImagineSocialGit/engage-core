<?php

namespace App\Modules\Core\Services\Contacts;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Support\Contacts\ContactFilterCriterionRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class ContactFilterResolver
{
    public function __construct(
        private readonly ContactFilterCriterionRegistry $criteria,
    ) {}

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

        $query = match ($type) {
            'all' => $this->allContactsQuery(),
            'criteria' => $this->criteriaQuery($filter),
            'contact_ids' => $this->contactIdsQuery($filter),
            'import_batch' => $this->importBatchQuery($filter),
            'imported' => $this->importedContactsQuery(),
            'tag' => $this->tagsQuery($filter),
            default => $this->emptyContactsQuery(),
        };

        $this->applyContactExclusions($query, $filter);

        return $query;
    }

    /** @return Builder<Contact> */
    private function allContactsQuery(): Builder
    {
        return Contact::query()->orderBy('id');
    }

    /**
     * @param array<string, mixed> $filter
     * @return Builder<Contact>
     */
    private function criteriaQuery(array $filter): Builder
    {
        $criteria = is_array($filter['criteria'] ?? null)
            ? $filter['criteria']
            : [];

        if ($criteria === []) {
            return $this->emptyContactsQuery();
        }

        $query = Contact::query();
        $this->criteria->apply($query, $criteria);

        return $query->orderBy('id');
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
    private function importBatchQuery(array $filter): Builder
    {
        $importBatchIds = $this->integerValues($filter['import_batch_ids'] ?? []);

        if ($importBatchIds === []) {
            return $this->emptyContactsQuery();
        }

        return Contact::query()
            ->importedInBatches($importBatchIds)
            ->orderBy('id');
    }

    /** @return Builder<Contact> */
    private function importedContactsQuery(): Builder
    {
        return Contact::query()
            ->where(function (Builder $query): void {
                $query
                    ->where('source', 'import')
                    ->orWhereNotNull('contact_import_batch_id')
                    ->orWhereHas('importOccurrences')
                    ->orWhere('meta->imported', true)
                    ->orWhereNotNull('meta->imported_at');
            })
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
     * Apply reusable subtractive audience rules after the positive/base filter.
     * Unknown or malformed exclusion criteria fail closed because silently
     * dropping an exclusion can broaden a bulk audience unexpectedly.
     *
     * @param Builder<Contact> $query
     * @param array<string, mixed> $filter
     */
    private function applyContactExclusions(Builder $query, array $filter): void
    {
        $excludedContactIds = $this->integerValues(
            $filter['exclude_contact_ids'] ?? [],
        );

        if ($excludedContactIds !== []) {
            $query->whereNotIn('contacts.id', $excludedContactIds);
        }

        $rawCriteria = $filter['exclude_criteria'] ?? [];

        if ($rawCriteria === null || $rawCriteria === []) {
            return;
        }

        if (! is_array($rawCriteria)) {
            $query->whereRaw('1 = 0');

            return;
        }

        try {
            $normalized = $this->criteria->normalize($rawCriteria);
        } catch (InvalidArgumentException) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($normalized === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $excluded = Contact::query();
        $this->criteria->apply($excluded, $normalized);

        $query->whereNotIn(
            'contacts.id',
            $excluded
                ->reorder()
                ->select('contacts.id'),
        );
    }

    /** @return Builder<Contact> */
    private function emptyContactsQuery(): Builder
    {
        return Contact::query()
            ->whereRaw('1 = 0')
            ->orderBy('id');
    }

    private function filterType(mixed $value): string
    {
        return is_string($value) && trim($value) !== ''
            ? str_replace('-', '_', strtolower(trim($value)))
            : 'all';
    }

    /** @return array<int, int> */
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

    /** @return array<int, string> */
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