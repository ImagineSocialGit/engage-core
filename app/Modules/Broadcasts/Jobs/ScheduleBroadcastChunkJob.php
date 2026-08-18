<?php

namespace App\Modules\Broadcasts\Jobs;

use App\Modules\Broadcasts\Actions\ScheduleBroadcastRecipientChunkAction;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Messaging\Services\BulkMessageDeliveryPolicy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
        BulkMessageDeliveryPolicy $bulkDeliveryPolicy,
    ): void {
        $result = $scheduleChunk->handle(
            broadcastId: $this->broadcastId,
            bulk: true,
        );

        if (! $result['has_more']) {
            return;
        }

        $broadcast = Broadcast::query()->find($this->broadcastId);
        $interval = is_numeric(data_get(
            $broadcast?->meta,
            'scheduling.bulk.release_interval_seconds',
        ))
            ? min(3600, max(1, (int) data_get(
                $broadcast?->meta,
                'scheduling.bulk.release_interval_seconds',
            )))
            : $bulkDeliveryPolicy->releaseIntervalSeconds();

        self::dispatch($this->broadcastId)
            ->delay(now()->addSeconds($interval))
            ->afterCommit()
            ->onQueue($bulkDeliveryPolicy->queue());
    }

    public function failed(Throwable $exception): void
    {
        $broadcast = Broadcast::query()->find($this->broadcastId);

        if (! $broadcast instanceof Broadcast
            || ! in_array($broadcast->status, [
                Broadcast::STATUS_SCHEDULED,
                Broadcast::STATUS_SENDING,
            ], true)
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
    }
}