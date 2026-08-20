<?php

namespace App\Modules\Core\Import\Treatments;

use App\Modules\Core\Actions\Contacts\UpdateContactStatusAction;
use App\Modules\Core\Contracts\Contacts\ContactImportTreatmentTarget;
use App\Modules\Core\Contracts\Contacts\UpdatesContactStatus;
use App\Modules\Core\Data\Contacts\ContactImportTreatmentApplication;
use App\Modules\Core\Data\Contacts\ContactImportTreatmentDefinition;
use App\Modules\Core\Models\ContactStatus;
use Illuminate\Validation\ValidationException;

final class ContactStatusImportTreatmentTarget implements ContactImportTreatmentTarget
{
    public function __construct(
        private readonly UpdateContactStatusAction $updateStatus,
    ) {}

    public function available(): bool
    {
        return app()->bound(UpdatesContactStatus::class);
    }

    public function definition(): ContactImportTreatmentDefinition
    {
        return new ContactImportTreatmentDefinition(
            key: 'contact_status',
            label: 'Contact Status',
            section: 'Contact',
            description: 'Apply one active CRM status to every imported row, or map values from a CSV column to active CRM statuses.',
            options: ContactStatus::query()
                ->active()
                ->ordered()
                ->get(['id', 'name'])
                ->map(fn (ContactStatus $status): array => [
                    'value' => (string) $status->getKey(),
                    'label' => $status->name,
                ])
                ->all(),
            sort: 10,
        );
    }

    public function normalizeValues(array $values): array
    {
        $ids = collect($values)
            ->filter(fn (mixed $value): bool => is_scalar($value))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        if ($ids->count() > 1) {
            throw ValidationException::withMessages([
                'treatments.contact_status' => 'Contact Status accepts one destination status per treatment value.',
            ]);
        }

        if ($ids->isEmpty()) {
            return [];
        }

        $id = $ids->first();

        $exists = ContactStatus::query()
            ->active()
            ->whereKey($id)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'treatments.contact_status' => 'The selected Contact Status is missing or inactive.',
            ]);
        }

        return [$id];
    }

    public function fieldOverrides(array $values): array
    {
        return [];
    }

    public function apply(ContactImportTreatmentApplication $application): void
    {
        $statusId = $application->values[0] ?? null;

        if ($statusId === null) {
            return;
        }

        $status = ContactStatus::query()
            ->active()
            ->findOrFail($statusId);

        $this->updateStatus->handle(
            contact: $application->contact,
            status: $status,
            reason: 'crm_import_treatment',
            source: 'crm_import',
            actor: $application->actor,
            meta: [
                'import_batch_id' => $application->batch->id,
                'import_occurrence_id' => $application->occurrence->id,
                'source_column' => $application->sourceColumn,
                'source_value' => $application->sourceValue,
            ],
            force: true,
        );
    }
}