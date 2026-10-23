<?php

namespace App\Modules\Broadcasts\Services;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Messaging\Services\BulkMessageDeliveryPolicy;
use App\Support\Queues\QueueContract;
use App\Support\Modules\ModuleManager;
use Illuminate\Support\Carbon;

final class BroadcastChunkReleaseSchedule
{
    public function __construct(
        private readonly BulkMessageDeliveryPolicy $bulkDeliveryPolicy,
        private readonly QueueContract $queueContract,
        private readonly ModuleManager $modules,
    ) {}

    public function isRecoverable(Broadcast $broadcast): bool
    {
        return $this->modules->enabled('broadcasts')
            && in_array($broadcast->status, [
            Broadcast::STATUS_SCHEDULED,
            Broadcast::STATUS_SENDING,
        ], true)
            && data_get($broadcast->meta, 'scheduling.bulk.enabled') === true
            && in_array(data_get($broadcast->meta, 'scheduling.state'), [
                'queued',
                'processing',
            ], true)
            && $broadcast->send_at !== null;
    }

    public function nextChunkIndex(Broadcast $broadcast): int
    {
        $last = data_get($broadcast->meta, 'scheduling.last_chunk_index');

        return is_numeric($last) ? max(0, (int) $last + 1) : 0;
    }

    public function releaseIntervalSeconds(Broadcast $broadcast): int
    {
        $snapshot = data_get(
            $broadcast->meta,
            'scheduling.bulk.release_interval_seconds',
        );

        return is_numeric($snapshot)
            ? min(3600, max(1, (int) $snapshot))
            : $this->bulkDeliveryPolicy->releaseIntervalSeconds();
    }

    public function nextReleaseAt(Broadcast $broadcast): Carbon
    {
        $interval = $this->releaseIntervalSeconds($broadcast);
        $planned = Carbon::parse($broadcast->send_at ?? now())
            ->addSeconds($this->nextChunkIndex($broadcast) * $interval);

        $lastProcessed = data_get($broadcast->meta, 'scheduling.last_chunk_processed_at');

        if (is_string($lastProcessed) && trim($lastProcessed) !== '') {
            $afterPrevious = Carbon::parse($lastProcessed)->addSeconds($interval);

            if ($afterPrevious->gt($planned)) {
                $planned = $afterPrevious;
            }
        }

        return $planned;
    }

    public function queue(Broadcast $broadcast): string
    {
        $snapshot = data_get($broadcast->meta, 'scheduling.bulk.queue');

        return $this->queueContract->assertDispatchable(
            is_string($snapshot) && trim($snapshot) !== ''
                ? trim($snapshot)
                : $this->bulkDeliveryPolicy->queue(),
        );
    }
}