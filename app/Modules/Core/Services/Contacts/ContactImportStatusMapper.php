<?php

namespace App\Modules\Core\Services\Contacts;

use App\Modules\Core\Data\Contacts\ContactImportStatusMappingResult;
use App\Modules\Core\Models\ContactStatus;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ContactImportStatusMapper
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @return Collection<int, string>
     */
    public function discoverIncomingStatuses(
        array $rows,
        ?string $sourceColumn,
    ): Collection {
        if ($sourceColumn === null || trim($sourceColumn) === '') {
            return collect();
        }

        return collect($rows)
            ->map(fn (array $row): ?string => $this->normalizeStatusValue($row[$sourceColumn] ?? null))
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    /**
     * @param array<string, mixed> $mapping
     * @return array<string, int>
     */
    public function normalizeSubmittedMapping(array $mapping): array
    {
        $normalized = [];

        foreach ($mapping as $incomingStatus => $contactStatusId) {
            $incomingStatus = $this->normalizeStatusValue($incomingStatus);

            if ($incomingStatus === null) {
                continue;
            }

            if ($contactStatusId === null || $contactStatusId === '') {
                continue;
            }

            if (! is_numeric($contactStatusId)) {
                continue;
            }

            $contactStatusId = (int) $contactStatusId;

            if ($contactStatusId <= 0) {
                continue;
            }

            $normalized[$incomingStatus] = $contactStatusId;
        }

        return $normalized;
    }

    /**
     * @param array<string, int> $mapping
     * @return array<int, ContactStatus>
     */
    public function validateActiveStatusMapping(array $mapping): array
    {
        if ($mapping === []) {
            return [];
        }

        $requestedIds = array_values(array_unique(array_values($mapping)));

        $statuses = ContactStatus::query()
            ->whereIn('id', $requestedIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $missingIds = array_values(array_diff(
            $requestedIds,
            $statuses->keys()->map(fn (mixed $id): int => (int) $id)->all(),
        ));

        if ($missingIds !== []) {
            throw ValidationException::withMessages([
                'status_mapping' => 'One or more mapped statuses are missing or inactive.',
            ]);
        }

        return $statuses->all();
    }

    /**
     * @param array<string, int> $mapping
     * @param array<int, ContactStatus> $statusesById
     */
    public function resolve(
        ?string $originalStatus,
        array $mapping,
        array $statusesById,
    ): ContactImportStatusMappingResult {
        $originalStatus = $this->normalizeStatusValue($originalStatus);

        if ($originalStatus === null) {
            return ContactImportStatusMappingResult::missing();
        }

        $contactStatusId = $mapping[$originalStatus] ?? null;

        if ($contactStatusId === null) {
            return ContactImportStatusMappingResult::unmapped($originalStatus);
        }

        $status = $statusesById[$contactStatusId] ?? null;

        if (! $status instanceof ContactStatus) {
            return ContactImportStatusMappingResult::unmapped($originalStatus);
        }

        return ContactImportStatusMappingResult::mapped(
            originalStatus: $originalStatus,
            contactStatusId: (int) $status->getKey(),
            contactStatusName: $status->name,
        );
    }

    /**
     * @param array<int, ContactImportStatusMappingResult> $results
     * @param array<string, int> $mapping
     * @return array<string, mixed>
     */
    public function batchMeta(
        ?string $sourceColumn,
        array $mapping,
        array $results,
    ): array {
        $mappedValues = [];
        $unmappedValues = [];
        $mappedCount = 0;
        $unmappedCount = 0;
        $missingCount = 0;

        foreach ($results as $result) {
            if ($result->state === 'mapped') {
                $mappedCount++;

                if ($result->originalStatus !== null) {
                    $mappedValues[$result->originalStatus] = $result->contactStatusId;
                }

                continue;
            }

            if ($result->state === 'unmapped') {
                $unmappedCount++;

                if ($result->originalStatus !== null) {
                    $unmappedValues[] = $result->originalStatus;
                }

                continue;
            }

            if ($result->state === 'missing') {
                $missingCount++;
            }
        }

        $unmappedValues = array_values(array_unique($unmappedValues));
        sort($unmappedValues);

        ksort($mappedValues);

        return [
            'field' => 'import_status',
            'source_column' => $sourceColumn,
            'mapped' => $mappedValues,
            'submitted_mapping' => $mapping,
            'unmapped' => $unmappedValues,
            'mapped_count' => $mappedCount,
            'unmapped_count' => $unmappedCount,
            'missing_count' => $missingCount,
            'review_required' => $unmappedCount > 0,
        ];
    }

    private function normalizeStatusValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}