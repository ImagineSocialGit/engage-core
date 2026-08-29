<?php

namespace App\Modules\Core\Services\Contacts;

use App\Models\User;
use App\Modules\Core\Actions\Contacts\CreateOrUpdateContactAction;
use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Data\Contacts\ContactImportPostProcessResult;
use App\Modules\Core\Data\Contacts\ContactImportTreatmentResolution;
use App\Modules\Core\Data\Contacts\ContactImportTreatmentSelection;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Modules\Core\Models\ContactImportRun;
use App\Modules\Core\Support\Contacts\ContactImportPostProcessorRegistry;
use App\Modules\Core\Support\Contacts\ContactImportRegistry;
use App\Modules\Core\Support\Contacts\ContactImportTreatmentRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class ContactImportBatchProcessor
{
    public function __construct(
        private readonly CreateOrUpdateContactAction $createOrUpdateContact,
        private readonly ContactImportRegistry $contactImportRegistry,
        private readonly ContactImportTreatmentRegistry $treatmentRegistry,
        private readonly ContactImportPostProcessorRegistry $postProcessorRegistry,
    ) {}

    public function isRunnable(int $contactImportBatchId): bool
    {
        return ContactImportRun::query()
            ->where('contact_import_batch_id', $contactImportBatchId)
            ->whereIn('status', [
                ContactImportRun::STATUS_PENDING,
                ContactImportRun::STATUS_PROCESSING,
            ])
            ->exists();
    }

    /**
     * Process one bounded CSV chunk.
     *
     * Returns true when every CSV row is durably processed and the batch is
     * ready for its exactly-once finalization job.
     */
    public function processNextChunk(int $contactImportBatchId): bool
    {
        $run = ContactImportRun::query()
            ->where('contact_import_batch_id', $contactImportBatchId)
            ->first();

        if (! $run instanceof ContactImportRun || $run->isFailed()) {
            return false;
        }

        if ($run->isFinalizing()) {
            return true;
        }

        if (! $run->isRunnable()) {
            return false;
        }

        $chunk = $this->readChunk($run);

        return DB::transaction(function () use ($contactImportBatchId, $run, $chunk): bool {
            $lockedRun = ContactImportRun::query()
                ->where('contact_import_batch_id', $contactImportBatchId)
                ->lockForUpdate()
                ->first();

            if (! $lockedRun instanceof ContactImportRun || $lockedRun->isFailed()) {
                return false;
            }

            if ($lockedRun->isFinalizing()) {
                return true;
            }

            if (! $lockedRun->isRunnable()) {
                return false;
            }

            if ((int) $lockedRun->next_byte_offset !== (int) $run->next_byte_offset
                || (int) $lockedRun->next_row_number !== (int) $run->next_row_number
            ) {
                return false;
            }

            $batch = ContactImportBatch::query()
                ->lockForUpdate()
                ->findOrFail($contactImportBatchId);

            if (in_array($batch->status, [
                ContactImportBatch::STATUS_COMPLETED,
                ContactImportBatch::STATUS_FAILED,
            ], true)) {
                return false;
            }

            $now = now();
            $startedAt = $lockedRun->started_at ?? $now;
            $rows = $chunk['rows'];
            $rowCount = count($rows);

            if ($rowCount === 0) {
                $lockedRun->forceFill([
                    'status' => ContactImportRun::STATUS_FINALIZING,
                    'started_at' => $startedAt,
                    'finalizing_at' => $lockedRun->finalizing_at ?? $now,
                ])->save();

                $batch->forceFill([
                    'status' => ContactImportBatch::STATUS_PROCESSING,
                ])->save();

                return true;
            }

            $headers = $this->stringList($lockedRun->headers);
            $mapping = $this->stringMap($lockedRun->mapping);
            $profileDefaults = is_array($lockedRun->profile_defaults)
                ? $lockedRun->profile_defaults
                : [];
            $postImportConfig = is_array($lockedRun->post_import_config)
                ? $lockedRun->post_import_config
                : [];
            $treatmentSelections = $this->treatmentSelections(
                $lockedRun->treatment_selections,
            );
            $stats = $this->processingStats(
                stored: $lockedRun->processing_stats,
                treatmentSelections: $treatmentSelections,
                postImportConfig: $postImportConfig,
            );
            $actor = $this->actor($lockedRun->actor_user_id);
            $importedAt = $batch->imported_at ?? $now;
            $firstRowNumber = (int) $lockedRun->next_row_number;
            $successfulDelta = 0;
            $failedDelta = 0;

            foreach ($rows as $index => $row) {
                $result = $this->processRow(
                    batch: $batch,
                    rowNumber: $firstRowNumber + $index,
                    rawRow: $row,
                    headers: $headers,
                    mapping: $mapping,
                    profileDefaults: $profileDefaults,
                    profileKey: $lockedRun->profile_key,
                    importMode: (string) $lockedRun->import_mode,
                    importedAt: $importedAt,
                    treatmentSelections: $treatmentSelections,
                    postImportConfig: $postImportConfig,
                    actor: $actor,
                    stats: $stats,
                );

                if ($result === 'created' || $result === 'updated') {
                    $successfulDelta++;
                } else {
                    $failedDelta++;
                }
            }

            $processedRows = (int) $lockedRun->processed_rows + $rowCount;
            $readyToFinalize = $chunk['eof']
                || $processedRows >= (int) $lockedRun->total_rows;

            $lockedRun->forceFill([
                'status' => $readyToFinalize
                    ? ContactImportRun::STATUS_FINALIZING
                    : ContactImportRun::STATUS_PROCESSING,
                'processing_stats' => $stats,
                'processed_rows' => $processedRows,
                'next_row_number' => $firstRowNumber + $rowCount,
                'next_byte_offset' => $chunk['next_byte_offset'],
                'started_at' => $startedAt,
                'finalizing_at' => $readyToFinalize
                    ? ($lockedRun->finalizing_at ?? $now)
                    : null,
            ])->save();

            $batch->forceFill([
                'status' => ContactImportBatch::STATUS_PROCESSING,
                'successful_count' => (int) $batch->successful_count + $successfulDelta,
                'failed_count' => (int) $batch->failed_count + $failedDelta,
            ])->save();

            return $readyToFinalize;
        }, 3);
    }

    public function finalizeBatch(int $contactImportBatchId): void
    {
        $csvPath = DB::transaction(function () use ($contactImportBatchId): ?string {
            $run = ContactImportRun::query()
                ->where('contact_import_batch_id', $contactImportBatchId)
                ->lockForUpdate()
                ->first();

            if (! $run instanceof ContactImportRun) {
                return null;
            }

            if ($run->isFailed()) {
                return null;
            }

            if (! $run->isFinalizing()
                || (int) $run->processed_rows < (int) $run->total_rows
            ) {
                throw new RuntimeException(
                    "Contact import batch [{$contactImportBatchId}] is not ready for finalization.",
                );
            }

            $batch = ContactImportBatch::query()
                ->lockForUpdate()
                ->findOrFail($contactImportBatchId);

            if ($batch->status === ContactImportBatch::STATUS_COMPLETED) {
                $path = $run->csv_path;
                $run->delete();

                return is_string($path) ? $path : null;
            }

            if ($batch->status === ContactImportBatch::STATUS_FAILED) {
                return null;
            }

            $treatmentSelections = $this->treatmentSelections(
                $run->treatment_selections,
            );
            $postImportConfig = is_array($run->post_import_config)
                ? $run->post_import_config
                : [];
            $stats = $this->processingStats(
                stored: $run->processing_stats,
                treatmentSelections: $treatmentSelections,
                postImportConfig: $postImportConfig,
            );

            $finalizationResults = $this->postProcessorRegistry->finalizeBatch(
                batch: $batch,
                configured: $postImportConfig,
            );

            $completedAt = now();
            $batch->forceFill([
                'status' => ContactImportBatch::STATUS_COMPLETED,
                'contact_count' => (int) $run->total_rows,
                'meta' => $this->durableBatchMeta(
                    batch: $batch,
                    stats: $stats,
                    treatmentSelections: $treatmentSelections,
                    postImportConfig: $postImportConfig,
                    finalizationResults: $finalizationResults,
                    completedAt: $completedAt->toISOString(),
                ),
            ])->save();

            $path = $run->csv_path;
            $run->delete();

            return is_string($path) ? $path : null;
        }, 3);

        if (is_string($csvPath) && $csvPath !== '') {
            Storage::disk('local')->delete($csvPath);
        }
    }

    public function markFailed(
        int $contactImportBatchId,
        ?Throwable $exception,
    ): void {
        DB::transaction(function () use ($contactImportBatchId, $exception): void {
            $run = ContactImportRun::query()
                ->where('contact_import_batch_id', $contactImportBatchId)
                ->lockForUpdate()
                ->first();

            $batch = ContactImportBatch::query()
                ->lockForUpdate()
                ->find($contactImportBatchId);

            if (! $batch instanceof ContactImportBatch
                || $batch->status === ContactImportBatch::STATUS_COMPLETED
            ) {
                return;
            }

            $failedAt = now();
            $message = $exception?->getMessage() ?: 'The queued contact import failed.';
            $message = mb_strcut($message, 0, 4000, 'UTF-8');

            if ($run instanceof ContactImportRun) {
                $run->forceFill([
                    'status' => ContactImportRun::STATUS_FAILED,
                    'failed_at' => $failedAt,
                    'failure_reason' => $message,
                ])->save();
            }

            $meta = is_array($batch->meta) ? $batch->meta : [];

            if ($run instanceof ContactImportRun) {
                try {
                    $treatmentSelections = $this->treatmentSelections(
                        $run->treatment_selections,
                    );
                    $postImportConfig = is_array($run->post_import_config)
                        ? $run->post_import_config
                        : [];
                    $stats = $this->processingStats(
                        stored: $run->processing_stats,
                        treatmentSelections: $treatmentSelections,
                        postImportConfig: $postImportConfig,
                    );

                    $meta = $this->durableBatchMeta(
                        batch: $batch,
                        stats: $stats,
                        treatmentSelections: $treatmentSelections,
                        postImportConfig: $postImportConfig,
                    );
                } catch (Throwable) {
                    // Failure reporting must not hide the original queue failure.
                }
            }

            $batch->forceFill([
                'status' => ContactImportBatch::STATUS_FAILED,
                'meta' => array_replace_recursive($meta, [
                    'failed_at' => $failedAt->toISOString(),
                    'failure' => [
                        'message' => $message,
                        'exception' => $exception !== null ? $exception::class : null,
                    ],
                ]),
            ])->save();
        }, 3);
    }

    /**
     * @param array<int, mixed> $rawRow
     * @param array<int, string> $headers
     * @param array<string, string> $mapping
     * @param array<string, mixed> $profileDefaults
     * @param array<string, ContactImportTreatmentSelection> $treatmentSelections
     * @param array<string, array<string, mixed>> $postImportConfig
     * @param array<string, mixed> $stats
     */
    private function processRow(
        ContactImportBatch $batch,
        int $rowNumber,
        array $rawRow,
        array $headers,
        array $mapping,
        array $profileDefaults,
        ?string $profileKey,
        string $importMode,
        mixed $importedAt,
        array $treatmentSelections,
        array $postImportConfig,
        ?Model $actor,
        array &$stats,
    ): string {
        $row = array_pad($rawRow, count($headers), null);
        $data = array_combine(
            $headers,
            array_slice($row, 0, count($headers)),
        );

        if (! is_array($data)) {
            $stats['skipped_count']++;

            return 'skipped';
        }

        $treatmentResolution = $this->treatmentRegistry->resolveRow(
            row: $data,
            selections: $treatmentSelections,
        );
        $contactData = [];

        foreach ($this->contactImportRegistry->contactAttributeFields() as $field) {
            $value = $this->contactImportRegistry->value(
                row: $data,
                mapping: $mapping,
                defaults: $profileDefaults,
                field: $field->key,
                overrides: $treatmentResolution->fieldOverrides,
            );

            if ($value !== null) {
                $contactData[$field->contactAttribute] = $value;
            }
        }

        $email = $contactData['email'] ?? null;

        if ($email === null) {
            $stats['skipped_count']++;

            return 'skipped';
        }

        $email = strtolower(trim((string) $email));

        if ($email === '') {
            $stats['skipped_count']++;

            return 'skipped';
        }

        $contactData['email'] = $email;

        $existingContact = Contact::query()
            ->where('email', $email)
            ->first(['id', 'source', 'subsource', 'meta']);

        if ($importMode === 'update' && $existingContact === null) {
            $stats['skipped_count']++;
            $stats['update_not_found_count']++;

            return 'skipped';
        }

        $this->recordTreatmentStats(
            $stats['treatment_stats'],
            $treatmentResolution,
        );

        if (array_key_exists('phone', $mapping)
            && $this->contactImportRegistry->mappedValue(
                row: $data,
                mapping: $mapping,
                field: 'phone',
            ) === null
        ) {
            $stats['phone_warning_count']++;
        }

        $wasExisting = $existingContact !== null;
        $existingImportedAt = is_array($existingContact?->meta)
            ? data_get($existingContact->meta, 'imported_at')
            : null;

        $originalSource = $this->contactImportRegistry->value(
            row: $data,
            mapping: $mapping,
            defaults: $profileDefaults,
            field: 'source',
        );
        $originalSubsource = $this->contactImportRegistry->value(
            row: $data,
            mapping: $mapping,
            defaults: $profileDefaults,
            field: 'subsource',
        );
        $originalStatus = $this->contactImportRegistry->value(
            row: $data,
            mapping: $mapping,
            defaults: $profileDefaults,
            field: 'import_status',
        );

        $sourceValues = $this->contactImportSourceValues(
            existingContact: $existingContact,
            originalSource: $originalSource,
            originalSubsource: $originalSubsource,
            fallbackToImport: $importMode === 'add',
        );

        $importedAtIso = method_exists($importedAt, 'toISOString')
            ? $importedAt->toISOString()
            : now()->toISOString();

        $contact = $this->createOrUpdateContact->handle(
            data: array_filter([
                ...$contactData,
                ...$sourceValues,
                'meta' => array_replace_recursive(
                    is_array($contactData['meta'] ?? null)
                        ? $contactData['meta']
                        : [],
                    [
                        'imported' => true,
                        'imported_at' => $existingImportedAt ?? $importedAtIso,
                        'import' => [
                            'batch_id' => $batch->getKey(),
                            'original_source' => $originalSource,
                            'original_subsource' => $originalSubsource,
                            'original_status' => $originalStatus,
                            'treatments' => $treatmentResolution->toMeta(),
                        ],
                    ],
                ),
            ], static fn (mixed $value): bool => $value !== null),
            statusKey: null,
            statusChangeReason: 'crm_import',
        );

        $contact->forceFill([
            'contact_import_batch_id' => $batch->getKey(),
        ])->save();

        $occurrence = ContactImportOccurrence::query()->create([
            'contact_import_batch_id' => $batch->getKey(),
            'contact_id' => $contact->getKey(),
            'row_number' => $rowNumber,
            'outcome' => $wasExisting
                ? ContactImportOccurrence::OUTCOME_UPDATED
                : ContactImportOccurrence::OUTCOME_CREATED,
            'identity_type' => 'email',
            'identity_value' => $email,
            'original_source' => $originalSource,
            'original_subsource' => $originalSubsource,
            'original_status' => $originalStatus,
            'row_fingerprint' => hash('sha256', serialize($data)),
            'meta' => [
                'treatments' => $treatmentResolution->toMeta(),
            ],
        ]);

        $context = new ContactImportContext(
            contact: $contact,
            batch: $batch,
            occurrence: $occurrence,
            row: $data,
            mapping: $mapping,
            defaults: $profileDefaults,
            overrides: $treatmentResolution->fieldOverrides,
            profileKey: $profileKey,
        );

        $this->contactImportRegistry->handleModuleImports($context);

        $this->treatmentRegistry->apply(
            resolution: $treatmentResolution,
            contact: $contact,
            batch: $batch,
            occurrence: $occurrence,
            actor: $actor,
        );

        $postImportResults = $this->postProcessorRegistry->process(
            context: $context,
            configured: $postImportConfig,
        );
        $postImportMeta = $this->postProcessorRegistry->resultsMeta(
            $postImportResults,
        );

        $this->recordPostImportStats(
            $stats['post_import_stats'],
            $postImportResults,
        );

        if ($postImportMeta !== []) {
            $occurrence->forceFill([
                'meta' => array_replace_recursive(
                    is_array($occurrence->meta) ? $occurrence->meta : [],
                    ['post_import' => $postImportMeta],
                ),
            ])->save();

            $contact->forceFill([
                'meta' => array_replace_recursive(
                    is_array($contact->meta) ? $contact->meta : [],
                    ['import' => ['post_import' => $postImportMeta]],
                ),
            ])->save();
        }

        if ($wasExisting) {
            $stats['updated_count']++;

            return 'updated';
        }

        $stats['created_count']++;

        return 'created';
    }

    /**
     * @return array{
     *     rows: array<int, array<int, mixed>>,
     *     next_byte_offset: int,
     *     eof: bool
     * }
     */
    private function readChunk(ContactImportRun $run): array
    {
        $csvPath = (string) $run->csv_path;

        if ($csvPath === '' || ! Storage::disk('local')->exists($csvPath)) {
            throw new RuntimeException(
                "Staged CSV for Contact import batch [{$run->contact_import_batch_id}] is unavailable.",
            );
        }

        $handle = fopen(Storage::disk('local')->path($csvPath), 'r');

        if ($handle === false) {
            throw new RuntimeException(
                "Staged CSV for Contact import batch [{$run->contact_import_batch_id}] could not be opened.",
            );
        }

        try {
            if (fseek($handle, (int) $run->next_byte_offset) !== 0) {
                throw new RuntimeException(
                    "Contact import batch [{$run->contact_import_batch_id}] could not seek to its durable CSV checkpoint.",
                );
            }

            $rows = [];
            $chunkRows = $this->chunkRows();

            while (count($rows) < $chunkRows
                && ($row = fgetcsv($handle)) !== false
            ) {
                $rows[] = $row;
            }

            $nextByteOffset = ftell($handle);

            if (! is_int($nextByteOffset)) {
                throw new RuntimeException(
                    "Contact import batch [{$run->contact_import_batch_id}] could not record its next CSV checkpoint.",
                );
            }

            return [
                'rows' => $rows,
                'next_byte_offset' => $nextByteOffset,
                'eof' => count($rows) < $chunkRows,
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param mixed $stored
     * @param array<string, ContactImportTreatmentSelection> $treatmentSelections
     * @param array<string, array<string, mixed>> $postImportConfig
     * @return array<string, mixed>
     */
    private function processingStats(
        mixed $stored,
        array $treatmentSelections,
        array $postImportConfig,
    ): array {
        $stats = is_array($stored) ? $stored : [];

        foreach ([
            'created_count',
            'updated_count',
            'skipped_count',
            'phone_warning_count',
            'update_not_found_count',
        ] as $key) {
            $stats[$key] = max(0, (int) ($stats[$key] ?? 0));
        }

        $treatmentStats = is_array($stats['treatment_stats'] ?? null)
            ? $stats['treatment_stats']
            : [];

        foreach (array_keys($treatmentSelections) as $targetKey) {
            $current = is_array($treatmentStats[$targetKey] ?? null)
                ? $treatmentStats[$targetKey]
                : [];

            $treatmentStats[$targetKey] = [
                'applied_count' => max(0, (int) ($current['applied_count'] ?? 0)),
                'unmapped_count' => max(0, (int) ($current['unmapped_count'] ?? 0)),
                'missing_count' => max(0, (int) ($current['missing_count'] ?? 0)),
            ];
        }

        $postImportStats = is_array($stats['post_import_stats'] ?? null)
            ? $stats['post_import_stats']
            : [];

        foreach (array_keys($postImportConfig) as $processorKey) {
            $current = is_array($postImportStats[$processorKey] ?? null)
                ? $postImportStats[$processorKey]
                : [];

            $postImportStats[$processorKey] = [
                'applied_count' => max(0, (int) ($current['applied_count'] ?? 0)),
                'partial_count' => max(0, (int) ($current['partial_count'] ?? 0)),
                'skipped_count' => max(0, (int) ($current['skipped_count'] ?? 0)),
                'blocked_count' => max(0, (int) ($current['blocked_count'] ?? 0)),
                'failed_count' => max(0, (int) ($current['failed_count'] ?? 0)),
            ];
        }

        $stats['treatment_stats'] = $treatmentStats;
        $stats['post_import_stats'] = $postImportStats;

        return $stats;
    }

    /**
     * @param mixed $stored
     * @return array<string, ContactImportTreatmentSelection>
     */
    private function treatmentSelections(mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $selections = [];

        foreach ($stored as $targetKey => $selection) {
            if (! is_string($targetKey)
                || trim($targetKey) === ''
                || ! is_array($selection)
            ) {
                throw new RuntimeException(
                    'Stored Contact import treatment selection is invalid.',
                );
            }

            $mode = $selection['mode'] ?? null;

            if (! is_string($mode)
                || ! in_array($mode, [
                    ContactImportTreatmentSelection::MODE_FIXED,
                    ContactImportTreatmentSelection::MODE_COLUMN,
                ], true)
            ) {
                throw new RuntimeException(
                    "Stored Contact import treatment [{$targetKey}] has an invalid mode.",
                );
            }

            $sourceColumn = $selection['source_column'] ?? null;

            if ($sourceColumn !== null && ! is_string($sourceColumn)) {
                throw new RuntimeException(
                    "Stored Contact import treatment [{$targetKey}] has an invalid source column.",
                );
            }

            $fixedValues = $this->stringList(
                $selection['fixed_values'] ?? [],
            );
            $valueMap = [];
            $storedValueMap = $selection['value_map'] ?? [];

            if (! is_array($storedValueMap)) {
                throw new RuntimeException(
                    "Stored Contact import treatment [{$targetKey}] has an invalid value map.",
                );
            }

            foreach ($storedValueMap as $sourceValue => $values) {
                if (! is_string($sourceValue)) {
                    throw new RuntimeException(
                        "Stored Contact import treatment [{$targetKey}] has an invalid mapped source value.",
                    );
                }

                $valueMap[$sourceValue] = $this->stringList($values);
            }

            $selections[$targetKey] = new ContactImportTreatmentSelection(
                targetKey: $targetKey,
                mode: $mode,
                sourceColumn: $sourceColumn,
                fixedValues: $fixedValues,
                valueMap: $valueMap,
            );
        }

        return $selections;
    }

    /**
     * @param array<string, mixed> $stats
     * @param array<string, ContactImportTreatmentSelection> $treatmentSelections
     * @param array<string, array<string, mixed>> $postImportConfig
     * @param array<string, ContactImportPostProcessResult> $finalizationResults
     * @return array<string, mixed>
     */
    private function durableBatchMeta(
        ContactImportBatch $batch,
        array $stats,
        array $treatmentSelections,
        array $postImportConfig,
        array $finalizationResults = [],
        ?string $completedAt = null,
    ): array {
        $meta = is_array($batch->meta) ? $batch->meta : [];

        $result = array_replace_recursive($meta, [
            'created_count' => (int) $stats['created_count'],
            'updated_count' => (int) $stats['updated_count'],
            'phone_warning_count' => (int) $stats['phone_warning_count'],
            'update_not_found_count' => (int) $stats['update_not_found_count'],
            'treatments' => $this->treatmentRegistry->batchMeta(
                stats: $stats['treatment_stats'],
                selections: $treatmentSelections,
            ),
            'post_import' => $this->postImportBatchMeta(
                stats: $stats['post_import_stats'],
                config: $postImportConfig,
                finalizationResults: $finalizationResults,
            ),
        ]);

        if ($completedAt !== null) {
            $result['completed_at'] = $completedAt;
        }

        return $result;
    }

    /**
     * @param array<string, array{applied_count: int, partial_count: int, skipped_count: int, blocked_count: int, failed_count: int}> $stats
     * @param array<string, array<string, mixed>> $config
     * @param array<string, ContactImportPostProcessResult> $finalizationResults
     * @return array<string, mixed>
     */
    private function postImportBatchMeta(
        array $stats,
        array $config,
        array $finalizationResults = [],
    ): array {
        $processors = [];
        $reviewRequired = false;

        foreach ($stats as $processorKey => $counts) {
            $finalization = $finalizationResults[$processorKey] ?? null;
            $finalizationMeta = $finalization?->toMeta();
            $processorReviewRequired = $counts['partial_count'] > 0
                || $counts['skipped_count'] > 0
                || $counts['blocked_count'] > 0
                || $counts['failed_count'] > 0
                || ($finalization?->reviewRequired() ?? false);
            $reviewRequired = $reviewRequired || $processorReviewRequired;

            $processors[$processorKey] = [
                'config' => $config[$processorKey] ?? [],
                ...$counts,
                'batch_finalization' => $finalizationMeta,
                'review_required' => $processorReviewRequired,
            ];
        }

        return [
            'configured' => $config !== [],
            'processors' => $processors,
            'review_required' => $reviewRequired,
        ];
    }

    /**
     * @param array<string, array{applied_count: int, unmapped_count: int, missing_count: int}> $stats
     */
    private function recordTreatmentStats(
        array &$stats,
        ContactImportTreatmentResolution $resolution,
    ): void {
        foreach ($resolution->targets as $targetKey => $resolved) {
            $counter = match ($resolved['state']) {
                'applied' => 'applied_count',
                'unmapped' => 'unmapped_count',
                'missing' => 'missing_count',
                default => null,
            };

            if ($counter !== null && isset($stats[$targetKey][$counter])) {
                $stats[$targetKey][$counter]++;
            }
        }
    }

    /**
     * @param array<string, array{applied_count: int, partial_count: int, skipped_count: int, blocked_count: int, failed_count: int}> $stats
     * @param array<string, ContactImportPostProcessResult> $results
     */
    private function recordPostImportStats(array &$stats, array $results): void
    {
        foreach ($results as $processorKey => $result) {
            $counter = $result->state.'_count';

            if (isset($stats[$processorKey][$counter])) {
                $stats[$processorKey][$counter]++;
            }
        }
    }

    /**
     * Preserve an existing meaningful acquisition source across overlapping
     * imports while allowing a generic/blank import source to be enriched.
     *
     * @return array{source: ?string, subsource: ?string}
     */
    private function contactImportSourceValues(
        ?Contact $existingContact,
        ?string $originalSource,
        ?string $originalSubsource,
        bool $fallbackToImport,
    ): array {
        $currentSource = $this->nonEmptyString($existingContact?->source);
        $currentSubsource = $this->nonEmptyString($existingContact?->subsource);

        if ($currentSource !== null && strtolower($currentSource) !== 'import') {
            $subsource = $currentSubsource;

            if ($subsource === null
                && $originalSource !== null
                && strcasecmp($currentSource, $originalSource) === 0
            ) {
                $subsource = $originalSubsource;
            }

            return [
                'source' => $currentSource,
                'subsource' => $subsource,
            ];
        }

        return [
            'source' => $originalSource
                ?? $currentSource
                ?? ($fallbackToImport ? 'import' : null),
            'subsource' => $originalSubsource ?? $currentSubsource,
        ];
    }

    private function actor(mixed $actorUserId): ?Model
    {
        if (! is_numeric($actorUserId) || (int) $actorUserId < 1) {
            return null;
        }

        return User::query()->find((int) $actorUserId);
    }

    /** @return array<int, string> */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $value): string => (string) $value,
            $values,
        ));
    }

    /** @return array<string, string> */
    private function stringMap(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $result = [];

        foreach ($values as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function chunkRows(): int
    {
        $configured = config('contact_imports.processing.chunk_rows', 500);

        return is_numeric($configured)
            ? min(5000, max(1, (int) $configured))
            : 500;
    }
}