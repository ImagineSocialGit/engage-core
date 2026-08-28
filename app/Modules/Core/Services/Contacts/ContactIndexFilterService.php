<?php

namespace App\Modules\Core\Services\Contacts;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Support\Contacts\ContactFilterCriterionRegistry;
use Illuminate\Database\Eloquent\Builder;

final class ContactIndexFilterService
{
    private const PRIMARY_CRITERION_KEYS = [
        'status',
        'relationship',
        'source',
    ];

    private const MAX_SEARCH_LENGTH = 120;

    public function __construct(
        private readonly ContactFilterCriterionRegistry $criteria,
        private readonly ContactFilterResolver $resolver,
    ) {}

    /**
     * @param array<string, mixed> $input
     * @return array{
     *     search: string,
     *     criteria: array<string, array<int, string>>,
     *     primary: array<int, array<string, mixed>>,
     *     secondary: array<int, array<string, mixed>>,
     *     active: array<int, array{key: string, label: string, value: string, value_label: string}>,
     *     has_filters: bool,
     *     secondary_active_count: int
     * }
     */
    public function state(array $input): array
    {
        $search = $this->searchTerm($input['search'] ?? null);
        $criteria = [];
        $primary = [];
        $secondary = [];
        $active = [];
        $secondaryActiveCount = 0;

        if ($search !== '') {
            $active[] = [
                'key' => 'search',
                'label' => 'Search',
                'value' => $search,
                'value_label' => $search,
            ];
        }

        foreach ($this->criteria->definitions() as $definition) {
            $key = trim((string) ($definition['key'] ?? ''));
            $options = is_array($definition['options'] ?? null)
                ? array_values($definition['options'])
                : [];

            if ($key === '' || $options === []) {
                continue;
            }

            $selected = $this->selectedValue(
                input: $input[$key] ?? null,
                options: $options,
            );

            $definition['selected'] = $selected;

            if ($selected !== null) {
                $criteria[$key] = [$selected['value']];
                $active[] = [
                    'key' => $key,
                    'label' => (string) ($definition['label'] ?? $key),
                    'value' => $selected['value'],
                    'value_label' => $selected['label'],
                ];
            }

            if (in_array($key, self::PRIMARY_CRITERION_KEYS, true)) {
                $primary[$key] = $definition;
            } else {
                $secondary[] = $definition;

                if ($selected !== null) {
                    $secondaryActiveCount++;
                }
            }
        }

        $orderedPrimary = [];

        foreach (self::PRIMARY_CRITERION_KEYS as $key) {
            if (isset($primary[$key])) {
                $orderedPrimary[] = $primary[$key];
            }
        }

        return [
            'search' => $search,
            'criteria' => $criteria,
            'primary' => $orderedPrimary,
            'secondary' => $secondary,
            'active' => $active,
            'has_filters' => $search !== '' || $criteria !== [],
            'secondary_active_count' => $secondaryActiveCount,
        ];
    }

    /**
     * @param array{
     *     search: string,
     *     criteria: array<string, array<int, string>>
     * } $state
     * @return Builder<Contact>
     */
    public function query(array $state): Builder
    {
        $criteria = is_array($state['criteria'] ?? null)
            ? $state['criteria']
            : [];

        $query = $criteria === []
            ? Contact::query()
            : $this->resolver
                ->query([
                    'type' => 'criteria',
                    'criteria' => $criteria,
                ])
                ->reorder();

        $search = $this->searchTerm($state['search'] ?? null);

        if ($search === '') {
            return $query;
        }

        $pattern = '%'.$this->escapeLike($search).'%';

        return $query->where(function (Builder $searchQuery) use ($pattern): void {
            $searchQuery
                ->where('contacts.name', 'like', $pattern)
                ->orWhere('contacts.first_name', 'like', $pattern)
                ->orWhere('contacts.last_name', 'like', $pattern)
                ->orWhere('contacts.email', 'like', $pattern)
                ->orWhere('contacts.phone', 'like', $pattern)
                ->orWhereRaw(
                    "CONCAT_WS(' ', COALESCE(contacts.first_name, ''), COALESCE(contacts.last_name, '')) LIKE ?",
                    [$pattern],
                );
        });
    }

    private function searchTerm(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return mb_substr(trim($value), 0, self::MAX_SEARCH_LENGTH);
    }

    /**
     * @param array<int, array{value: string, label: string}> $options
     * @return array{value: string, label: string}|null
     */
    private function selectedValue(mixed $input, array $options): ?array
    {
        $requested = is_array($input) ? $input : [$input];

        foreach ($requested as $value) {
            if (! is_string($value) && ! is_int($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            foreach ($options as $option) {
                $optionValue = trim((string) ($option['value'] ?? ''));

                if ($optionValue !== $value) {
                    continue;
                }

                return [
                    'value' => $optionValue,
                    'label' => trim((string) ($option['label'] ?? $optionValue)),
                ];
            }
        }

        return null;
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