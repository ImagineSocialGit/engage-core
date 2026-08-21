<?php

namespace App\Modules\Workflow\Services\Contacts\Filters;

use App\Modules\Core\Contracts\Contacts\ContactFilterCriterion;
use App\Modules\Core\Models\ContactStatus;
use Illuminate\Database\Eloquent\Builder;

class StatusContactFilterCriterion implements ContactFilterCriterion
{
    public function key(): string
    {
        return 'status';
    }

    public function sortOrder(): int
    {
        return 10;
    }

    public function label(): string
    {
        return 'Status';
    }

    public function help(): ?string
    {
        return 'Match contacts by their current lifecycle status.';
    }

    public function options(): array
    {
        return ContactStatus::query()
            ->active()
            ->ordered()
            ->get(['id', 'name'])
            ->map(fn (ContactStatus $status): array => [
                'value' => (string) $status->getKey(),
                'label' => $status->name,
            ])
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
            fn (mixed $value): ?string => is_numeric($value) && in_array((string) ((int) $value), $allowed, true)
                ? (string) ((int) $value)
                : null,
            $values,
        ))));
    }

    public function apply(Builder $query, array $values): void
    {
        $ids = array_map('intval', $values);

        $query->whereExists(function ($subquery) use ($ids): void {
            $subquery
                ->selectRaw('1')
                ->from('contact_workflow_profiles')
                ->whereColumn('contact_workflow_profiles.contact_id', 'contacts.id')
                ->whereIn('contact_workflow_profiles.contact_status_id', $ids);
        });
    }
}