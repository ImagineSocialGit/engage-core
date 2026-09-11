<?php

namespace App\Modules\Core\Services\Contacts;

use App\Modules\Core\Data\Contacts\ContactImportTreatmentSelection;
use App\Modules\Core\Models\ContactStatus;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ContactImportStatusMapper
{
    public function materializeSelection(
        ContactImportTreatmentSelection $selection,
    ): ContactImportTreatmentSelection {
        if ($selection->targetKey !== 'contact_status'
            || $selection->mode !== ContactImportTreatmentSelection::MODE_COLUMN
        ) {
            return $selection;
        }

        $valueMap = $selection->valueMap;
        $activeStatuses = ContactStatus::query()
            ->active()
            ->ordered()
            ->get();
        $nextSortOrder = min(65535, max(0, (int) ContactStatus::query()->max('sort_order')) + 10);

        foreach ($valueMap as $sourceValue => $values) {
            if ($values !== [ContactImportTreatmentSelection::SOURCE_VALUE]) {
                continue;
            }

            $name = $this->displayName($sourceValue);

            if (mb_strlen($name) > 255) {
                throw ValidationException::withMessages([
                    'treatments.contact_status.value_map' => 'A legacy status name is too long to create as a Contact Status. Map it to an existing status instead.',
                ]);
            }

            $status = $this->matchingActiveStatus($activeStatuses, $name);

            if (! $status instanceof ContactStatus) {
                $normalizedName = $this->normalizeName($name);
                $status = ContactStatus::query()->create([
                    'key' => $this->uniqueImportedKey($normalizedName),
                    'name' => $name,
                    'description' => null,
                    'category' => null,
                    'color' => null,
                    'is_core' => false,
                    'is_active' => true,
                    'is_customized' => true,
                    'customized_at' => now(),
                    'sort_order' => $nextSortOrder,
                    'source_version' => null,
                    'meta' => [
                        'created_from_contact_import' => true,
                    ],
                ]);

                $nextSortOrder = min(65535, $nextSortOrder + 10);
                $activeStatuses->push($status);
            }

            $valueMap[$sourceValue] = [(string) $status->getKey()];
        }

        return new ContactImportTreatmentSelection(
            targetKey: $selection->targetKey,
            mode: $selection->mode,
            sourceColumn: $selection->sourceColumn,
            fixedValues: $selection->fixedValues,
            valueMap: $valueMap,
        );
    }

    /** @param Collection<int, ContactStatus> $activeStatuses */
    private function matchingActiveStatus(
        Collection $activeStatuses,
        string $name,
    ): ?ContactStatus {
        $normalizedName = $this->normalizeName($name);
        $matches = $activeStatuses
            ->filter(
                fn (ContactStatus $status): bool =>
                    $this->normalizeName($status->name) === $normalizedName,
            )
            ->values();

        if ($matches->count() > 1) {
            throw ValidationException::withMessages([
                'treatments.contact_status.value_map' => "More than one active Contact Status is named [{$name}]. Choose the intended status manually.",
            ]);
        }

        $status = $matches->first();

        return $status instanceof ContactStatus
            ? $status
            : null;
    }

    private function uniqueImportedKey(string $normalizedName): string
    {
        $base = 'imported_status_'.substr(hash('sha256', $normalizedName), 0, 16);
        $key = $base;
        $suffix = 2;

        while (ContactStatus::query()->where('key', $key)->exists()) {
            $key = $base.'_'.$suffix;
            $suffix++;
        }

        return $key;
    }

    private function displayName(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        return $value !== '' ? $value : 'Imported Status';
    }

    private function normalizeName(string $value): string
    {
        return mb_strtolower($this->displayName($value));
    }
}