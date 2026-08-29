<?php

namespace App\Modules\Core\Jobs;

use App\Modules\Core\Services\Contacts\ContactImportBatchProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessContactImportBatchChunkJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $contactImportBatchId,
    ) {
        $queue = config('contacts.queues.ingestion', 'default');

        $this->onQueue(
            is_string($queue) && trim($queue) !== ''
                ? trim($queue)
                : 'default',
        );
    }


    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 15, 30, 60];
    }

    public function uniqueId(): string
    {
        return 'contact-import-process:'.$this->contactImportBatchId;
    }

    public function handle(ContactImportBatchProcessor $processor): void
    {
        $readyToFinalize = $processor->processNextChunk(
            $this->contactImportBatchId,
        );

        if ($readyToFinalize) {
            FinalizeContactImportBatchJob::dispatch(
                $this->contactImportBatchId,
            )->afterCommit();

            return;
        }

        if ($processor->isRunnable($this->contactImportBatchId)) {
            self::dispatch($this->contactImportBatchId)->afterCommit();
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(ContactImportBatchProcessor::class)->markFailed(
            contactImportBatchId: $this->contactImportBatchId,
            exception: $exception,
        );
    }

}