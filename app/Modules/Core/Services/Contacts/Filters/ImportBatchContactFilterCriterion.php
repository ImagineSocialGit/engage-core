<?php

namespace App\Modules\Core\Services\Contacts\Filters;

use App\Modules\Core\Contracts\Contacts\ContactFilterCriterion;
use App\Modules\Core\Models\ContactImportBatch;
use Illuminate\Database\Eloquent\Builder;

class ImportBatchContactFilterCriterion implements ContactFilterCriterion
{
    public function key(): string
    {
        return 'import_batch';
    }

    public function sortOrder(): int
    {
        return 60;
    }

    public function label(): string
    {
        return 'Import batches';
    }

    public function help(): ?string
    {
        return 'Include contacts that appeared in one of the selected imports.';
    }

    public function options(): array
    {
        return ContactImportBatch::query()
            ->latest('imported_at')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (ContactImportBatch $batch): array => [
                'value' => (string) $batch->getKey(),
                'label' => trim(sprintf(
                    '%s — %s — %s contacts',
                    $batch->name ?: 'Import #'.$batch->getKey(),
                    $batch->imported_at?->format('M j, Y') ?? 'No import date',
                    number_format((int) $batch->successful_count),
                )),
            ])
            ->values()
            ->all();
    }

    public function normalize(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => is_numeric($value) && (int) $value > 0
                ? (string) ((int) $value)
                : null,
            $values,
        ))));
    }

    public function apply(Builder $query, array $values): void
    {
        $ids = array_map('intval', $values);

        $query->where(function (Builder $query) use ($ids): void {
            $query
                ->whereIn('contacts.contact_import_batch_id', $ids)
                ->orWhereExists(function ($subquery) use ($ids): void {
                    $subquery
                        ->selectRaw('1')
                        ->from('contact_import_occurrences')
                        ->whereColumn('contact_import_occurrences.contact_id', 'contacts.id')
                        ->whereIn('contact_import_occurrences.contact_import_batch_id', $ids);
                });
        });
    }
}