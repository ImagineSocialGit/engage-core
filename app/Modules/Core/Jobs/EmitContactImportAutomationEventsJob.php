<?php

namespace App\Modules\Core\Jobs;

use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Services\AutomationEventOutbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class EmitContactImportAutomationEventsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const CHUNK_SIZE = 250;

    public int $tries = 5;
    public int $timeout = 120;
    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $contactImportBatchId,
        public readonly int $afterOccurrenceId = 0,
    ) {
        $queue = config('contacts.queues.ingestion', 'default');

        $this->onQueue(is_string($queue) && trim($queue) !== '' ? trim($queue) : 'default');
    }

    public function uniqueId(): string
    {
        return implode(':', [
            'contact-import-automation-events',
            $this->contactImportBatchId,
            $this->afterOccurrenceId,
        ]);
    }

    public function handle(AutomationEventOutbox $outbox): void
    {
        // The nullable cursor preserves the old queue contract for imports
        // already completed before this migration. Their existing queued
        // jobs still run; they are not enrolled in periodic backfill.
        $batch = ContactImportBatch::query()->find($this->contactImportBatchId);

        if (! $batch instanceof ContactImportBatch
            || $batch->status !== ContactImportBatch::STATUS_COMPLETED
        ) {
            return;
        }

        if ($batch->automation_events_cursor === null) {
            $this->emitLegacyChunk($outbox);

            return;
        }

        // All new imports use one database-serialized cursor regardless of
        // the offset embedded in a lost, duplicate or out-of-order queue job.
        $nextCursor = DB::transaction(function () use ($outbox): ?int {
            $batch = ContactImportBatch::query()
                ->lockForUpdate()
                ->find($this->contactImportBatchId);

            if (! $batch instanceof ContactImportBatch
                || $batch->status !== ContactImportBatch::STATUS_COMPLETED
                || $batch->automation_events_cursor === null
                || $batch->automation_events_completed_at !== null
            ) {
                return null;
            }

            $occurrences = $this->occurrencesAfter((int) $batch->automation_events_cursor);

            if ($occurrences->isEmpty()) {
                $batch->forceFill([
                    'automation_events_completed_at' => now(),
                ])->save();

                return null;
            }

            foreach ($occurrences as $occurrence) {
                $this->recordOccurrence($outbox, $occurrence);
            }

            $lastId = (int) $occurrences->last()->getKey();
            $more = ContactImportOccurrence::query()
                ->where('contact_import_batch_id', $batch->getKey())
                ->where('id', '>', $lastId)
                ->exists();

            $batch->forceFill([
                'automation_events_cursor' => $lastId,
                'automation_events_completed_at' => $more ? null : now(),
                'automation_events_recovery_dispatched_at' => null,
            ])->save();

            return $more ? $lastId : null;
        }, 3);

        if ($nextCursor !== null) {
            self::dispatch($this->contactImportBatchId, $nextCursor);
        }
    }

    private function emitLegacyChunk(AutomationEventOutbox $outbox): void
    {
        $occurrences = $this->occurrencesAfter($this->afterOccurrenceId);

        if ($occurrences->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($occurrences, $outbox): void {
            foreach ($occurrences as $occurrence) {
                $this->recordOccurrence($outbox, $occurrence);
            }
        }, 3);

        if ($occurrences->count() === self::CHUNK_SIZE) {
            self::dispatch(
                $this->contactImportBatchId,
                (int) $occurrences->last()->getKey(),
            );
        }
    }

    private function occurrencesAfter(int $cursor): \Illuminate\Database\Eloquent\Collection
    {
        return ContactImportOccurrence::query()
            ->where('contact_import_batch_id', $this->contactImportBatchId)
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->limit(self::CHUNK_SIZE)
            ->get();
    }

    private function recordOccurrence(
        AutomationEventOutbox $outbox,
        ContactImportOccurrence $occurrence,
    ): void {
        $outbox->record(
            AutomationEventData::forSubject(
                eventKey: 'contact.imported',
                subject: $occurrence,
                contactId: (int) $occurrence->contact_id,
                payload: [
                    'contact_import' => [
                        'batch_id' => (int) $occurrence->contact_import_batch_id,
                        'occurrence_id' => (int) $occurrence->getKey(),
                        'row_number' => (int) $occurrence->row_number,
                        'outcome' => (string) $occurrence->outcome,
                    ],
                ],
                meta: ['source_module' => 'core'],
            ),
            idempotencyKey: 'contact-imported:'.$occurrence->getKey(),
        );
    }
}