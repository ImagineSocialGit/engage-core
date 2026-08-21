<?php

namespace App\Modules\Core\Support\Contacts;

use App\Modules\Core\Contracts\Contacts\ContactFilterCriterion;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

class ContactFilterCriterionRegistry
{
    /** @var array<string, ContactFilterCriterion>|null */
    private ?array $resolved = null;

    /**
     * @param iterable<ContactFilterCriterion> $criteria
     */
    public function __construct(
        private readonly iterable $criteria,
    ) {}

    /**
     * @return array<int, array{
     *     key: string,
     *     label: string,
     *     help: string|null,
     *     options: array<int, array{value: string, label: string}>
     * }>
     */
    public function definitions(): array
    {
        return array_values(array_map(
            fn (ContactFilterCriterion $criterion): array => [
                'key' => $criterion->key(),
                'label' => $criterion->label(),
                'help' => $criterion->help(),
                'options' => $criterion->options(),
            ],
            $this->all(),
        ));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, array<int, string>>
     */
    public function normalize(array $input): array
    {
        $known = array_keys($this->all());
        $unknown = array_values(array_diff(array_keys($input), $known));

        if ($unknown !== []) {
            sort($unknown);

            throw new InvalidArgumentException(
                'Unknown contact filter criteria: '.implode(', ', $unknown).'.',
            );
        }

        $normalized = [];

        foreach ($this->all() as $key => $criterion) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            $values = $criterion->normalize($input[$key]);

            if ($values !== []) {
                $normalized[$key] = $values;
            }
        }

        return $normalized;
    }

    /**
     * @param Builder<\App\Modules\Core\Models\Contact> $query
     * @param array<string, mixed> $criteria
     */
    public function apply(Builder $query, array $criteria): void
    {
        foreach ($criteria as $key => $values) {
            $criterion = $this->all()[$key] ?? null;

            if (! $criterion instanceof ContactFilterCriterion) {
                $query->whereRaw('1 = 0');

                continue;
            }

            $normalized = $criterion->normalize($values);

            if ($normalized === []) {
                $query->whereRaw('1 = 0');

                continue;
            }

            $criterion->apply($query, $normalized);
        }
    }

    /**
     * @return array<string, ContactFilterCriterion>
     */
    private function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $resolved = [];

        foreach ($this->criteria as $criterion) {
            if (! $criterion instanceof ContactFilterCriterion) {
                continue;
            }

            $key = trim($criterion->key());

            if ($key === '') {
                throw new InvalidArgumentException('Contact filter criterion keys cannot be empty.');
            }

            if (isset($resolved[$key])) {
                throw new InvalidArgumentException("Duplicate contact filter criterion [{$key}].");
            }

            $resolved[$key] = $criterion;
        }

        uasort($resolved, static fn (ContactFilterCriterion $left, ContactFilterCriterion $right): int => [
            $left->sortOrder(),
            $left->key(),
        ] <=> [
            $right->sortOrder(),
            $right->key(),
        ]);

        return $this->resolved = $resolved;
    }
}