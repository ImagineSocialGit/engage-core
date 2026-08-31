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
        $batch = ContactImportBatch::query()->find($this->contactImportBatchId);

        if (! $batch instanceof ContactImportBatch
            || $batch->status !== ContactImportBatch::STATUS_COMPLETED
        ) {
            return;
        }

        $occurrences = ContactImportOccurrence::query()
            ->where('contact_import_batch_id', $batch->getKey())
            ->where('id', '>', $this->afterOccurrenceId)
            ->orderBy('id')
            ->limit(self::CHUNK_SIZE)
            ->get();

        if ($occurrences->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($occurrences, $outbox): void {
            foreach ($occurrences as $occurrence) {
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
        }, 3);

        if ($occurrences->count() === self::CHUNK_SIZE) {
            self::dispatch(
                $this->contactImportBatchId,
                (int) $occurrences->last()->getKey(),
            );
        }
    }
}