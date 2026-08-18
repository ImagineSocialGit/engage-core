<?php

namespace App\Modules\Messaging\Services;

use App\Support\Queues\QueueContract;
use Illuminate\Support\Carbon;

class BulkMessageDeliveryPolicy
{
    public function __construct(
        private readonly QueueContract $queueContract,
    ) {}

    public function chunkSize(): int
    {
        return min(1000, max(
            1,
            (int) config('messaging.bulk_delivery.chunk_size', 100),
        ));
    }

    public function releaseIntervalSeconds(): int
    {
        return min(3600, max(
            1,
            (int) config('messaging.bulk_delivery.release_interval_seconds', 15),
        ));
    }

    public function shouldChunk(int $recipientCount): bool
    {
        return $recipientCount > $this->chunkSize();
    }

    public function queue(): string
    {
        return $this->queueContract->assertDispatchable(
            QueueContract::BULK_MESSAGES,
        );
    }

    /**
     * @return array{
     *     queue: string,
     *     chunk_size: int,
     *     release_interval_seconds: int,
     * }
     */
    public function snapshot(): array
    {
        return [
            'queue' => $this->queue(),
            'chunk_size' => $this->chunkSize(),
            'release_interval_seconds' => $this->releaseIntervalSeconds(),
        ];
    }

    public function releaseAt(
        Carbon|string $baseSendAt,
        int $chunkIndex,
        ?int $releaseIntervalSeconds = null,
    ): Carbon {
        $chunkIndex = max(0, $chunkIndex);
        $interval = $releaseIntervalSeconds === null
            ? $this->releaseIntervalSeconds()
            : min(3600, max(1, $releaseIntervalSeconds));

        $planned = Carbon::parse($baseSendAt)
            ->addSeconds($chunkIndex * $interval);

        return $planned->isFuture()
            ? $planned
            : now();
    }
}