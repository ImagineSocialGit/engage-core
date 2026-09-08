<?php

namespace App\Modules\Core\Services\Contacts;

use App\Models\User;
use App\Modules\Core\Access\Services\ContactVisibility;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Support\Contacts\ContactFilterCriterionRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ContactResultSetResolver
{
    private const MAX_SEARCH_LENGTH = 120;

    public function __construct(
        private readonly ContactFilterCriterionRegistry $criteria,
        private readonly ContactFilterResolver $filters,
        private readonly ContactVisibility $visibility,
    ) {}

    /**
     * @return array<string, array<int, string>|string>
     */
    public function validationRules(string $field = 'contact_result'): array
    {
        return [
            $field => ['required', 'array'],
            $field.'.search' => ['nullable', 'string', 'max:'.self::MAX_SEARCH_LENGTH],
            $field.'.criteria' => ['nullable', 'array'],
            $field.'.criteria.*' => ['nullable', 'array'],
            $field.'.criteria.*.*' => ['nullable', 'string', 'max:191'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{search: string, criteria: array<string, array<int, string>>}
     */
    public function normalize(array $payload): array
    {
        $search = trim((string) ($payload['search'] ?? ''));
        $search = mb_substr($search, 0, self::MAX_SEARCH_LENGTH);

        $criteriaInput = is_array($payload['criteria'] ?? null)
            ? $payload['criteria']
            : [];

        $normalizedCriteria = $this->criteria->normalize($criteriaInput);

        foreach ($criteriaInput as $key => $values) {
            $requested = is_array($values)
                ? array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) && trim($value) !== ''))
                : [];

            if ($requested !== [] && ! array_key_exists((string) $key, $normalizedCriteria)) {
                throw new InvalidArgumentException(
                    'The selected Contact filter values are no longer available.',
                );
            }
        }

        return [
            'search' => $search,
            'criteria' => $normalizedCriteria,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{search: string, criteria: array<string, array<int, string>>}
     */
    public function normalizeForInput(array $payload, string $errorKey = 'contact_result'): array
    {
        try {
            return $this->normalize($payload);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                $errorKey => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return Builder<Contact>
     */
    public function query(array $payload): Builder
    {
        $state = $this->normalize($payload);
        $criteria = $state['criteria'];

        $query = $criteria === []
            ? Contact::query()
            : $this->filters->query([
                'type' => 'criteria',
                'criteria' => $criteria,
            ])->reorder();

        if ($state['search'] !== '') {
            $term = '%'.$this->escapeLike($state['search']).'%';

            $query->where(function (Builder $query) use ($term): void {
                $query
                    ->where('contacts.name', 'like', $term)
                    ->orWhere('contacts.first_name', 'like', $term)
                    ->orWhere('contacts.last_name', 'like', $term)
                    ->orWhere('contacts.email', 'like', $term)
                    ->orWhere('contacts.phone', 'like', $term)
                    ->orWhereRaw(
                        "TRIM(CONCAT(COALESCE(contacts.first_name, ''), ' ', COALESCE(contacts.last_name, ''))) LIKE ? ESCAPE '\\\\'",
                        [$term],
                    );
            });
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $payload
     * @return Builder<Contact>
     */
    public function visibleQuery(array $payload, User $user): Builder
    {
        return $this->visibility->apply($this->query($payload), $user);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function visibleCount(array $payload, User $user): int
    {
        return $this->visibleQuery($payload, $user)->count();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, int>
     */
    public function visibleIds(array $payload, User $user): array
    {
        return $this->visibleQuery($payload, $user)
            ->reorder()
            ->orderBy('contacts.id')
            ->pluck('contacts.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Build a durable Core Contact filter for another module.
     *
     * Criteria-only result sets retain the logical criteria so the receiving
     * surface can continue to preview them. Free-text searches are materialized
     * to the visible Contact IDs because search is an index-discovery concern,
     * not a ContactFilterResolver criterion.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function deferredFilter(array $payload, User $user): array
    {
        $state = $this->normalize($payload);

        if ($state['search'] !== '') {
            return [
                'type' => 'contact_ids',
                'contact_ids' => $this->visibleIds($state, $user),
            ];
        }

        if ($state['criteria'] !== []) {
            return [
                'type' => 'criteria',
                'criteria' => $state['criteria'],
            ];
        }

        return ['type' => 'all'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    public function contactIndexQuery(array $payload): array
    {
        $state = $this->normalize($payload);
        $query = [];

        if ($state['search'] !== '') {
            $query['search'] = $state['search'];
        }

        foreach ($state['criteria'] as $key => $values) {
            if ($values !== []) {
                $query[$key] = (string) $values[0];
            }
        }

        return $query;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value,
        );
    }
}