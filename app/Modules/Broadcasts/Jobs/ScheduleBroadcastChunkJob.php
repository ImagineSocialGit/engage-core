<?php

namespace App\Modules\Broadcasts\Jobs;

use App\Modules\Broadcasts\Actions\ScheduleBroadcastRecipientChunkAction;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Services\BroadcastChunkReleaseSchedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ScheduleBroadcastChunkJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [5, 30, 120, 300];

    public function __construct(
        public readonly int $broadcastId,
    ) {}

    public function handle(
        ScheduleBroadcastRecipientChunkAction $scheduleChunk,
        BroadcastChunkReleaseSchedule $releaseSchedule,
    ): void {
        DB::transaction(function () use ($scheduleChunk, $releaseSchedule): void {
            $broadcast = Broadcast::query()
                ->lockForUpdate()
                ->find($this->broadcastId);

            if (! $broadcast instanceof Broadcast
                || ! $releaseSchedule->isRecoverable($broadcast)
                || $releaseSchedule->nextReleaseAt($broadcast)->isFuture()
            ) {
                // A duplicate, early, cancelled, completed, or stalled job
                // cannot release another chunk. The normal successor or the
                // database recovery sweep will handle any missing job.
                return;
            }

            $beforeIndex = $releaseSchedule->nextChunkIndex($broadcast);
            $result = $scheduleChunk->handle(
                broadcastId: $this->broadcastId,
                bulk: true,
            );

            if (! $result['has_more'] || $result['processed_count'] === 0) {
                return;
            }

            $broadcast->refresh();

            if (! $releaseSchedule->isRecoverable($broadcast)
                || $releaseSchedule->nextChunkIndex($broadcast) <= $beforeIndex
            ) {
                // A successful chunk must advance its durable checkpoint.
                // Do not queue an uncontrolled loop on inconsistent state.
                return;
            }

            self::dispatch($this->broadcastId)
                ->delay($releaseSchedule->nextReleaseAt($broadcast))
                ->afterCommit()
                ->onQueue($releaseSchedule->queue($broadcast));
        }, 3);
    }

    public function failed(Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $broadcast = Broadcast::query()
                ->lockForUpdate()
                ->find($this->broadcastId);

            if (! $broadcast instanceof Broadcast
                || ! in_array($broadcast->status, [
                    Broadcast::STATUS_SCHEDULED,
                    Broadcast::STATUS_SENDING,
                ], true)
                || data_get($broadcast->meta, 'scheduling.state') === 'complete'
            ) {
                return;
            }

            $broadcast->forceFill([
                'meta' => array_replace_recursive($broadcast->meta ?? [], [
                    'scheduling' => [
                        'state' => 'stalled',
                        'stalled_at' => now()->toISOString(),
                        'failure' => mb_substr($exception->getMessage(), 0, 1000),
                    ],
                ]),
            ])->save();
        });
    }
}