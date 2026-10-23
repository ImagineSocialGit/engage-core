<?php

namespace App\Modules\Broadcasts\Actions;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Broadcasts\Services\BroadcastChunkReleaseSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Durable, bounded reconstruction of Redis-lost broadcast chunk dispatches.
 * This action claims eligibility only; it never creates ScheduledMessages.
 */
final class RecoverInterruptedBroadcastChunksAction
{
    private const MAX_DISPATCHES = 20;
    private const MAX_CANDIDATES = 500;
    private const QUERY_BATCH = 100;
    private const OVERDUE_GRACE_SECONDS = 90;
    private const CLAIM_LEASE_SECONDS = 180;

    public function __construct(
        private readonly BroadcastChunkReleaseSchedule $releaseSchedule,
    ) {}

    /**
     * @return list<array{broadcast_id: int, queue: string}>
     */
    public function handle(): array
    {
        $claimed = [];
        $seen = 0;
        $afterId = 0;
        $cutoff = now()->subSeconds(self::OVERDUE_GRACE_SECONDS);

        while ($seen < self::MAX_CANDIDATES
            && count($claimed) < self::MAX_DISPATCHES
        ) {
            $candidates = Broadcast::query()
                ->where('id', '>', $afterId)
                ->whereIn('status', [
                    Broadcast::STATUS_SCHEDULED,
                    Broadcast::STATUS_SENDING,
                ])
                ->where('send_at', '<=', $cutoff)
                ->where('meta->scheduling->bulk->enabled', true)
                ->whereIn('meta->scheduling->state', ['queued', 'processing'])
                ->whereHas('recipients', fn ($query) => $query
                    ->where('status', BroadcastRecipient::STATUS_PENDING))
                ->orderBy('id')
                ->limit(min(
                    self::QUERY_BATCH,
                    self::MAX_CANDIDATES - $seen,
                ))
                ->pluck('id');

            if ($candidates->isEmpty()) {
                break;
            }

            foreach ($candidates as $id) {
                $afterId = (int) $id;
                $seen++;

                try {
                    $dispatch = $this->claim((int) $id, $cutoff);

                    if ($dispatch !== null) {
                        $claimed[] = $dispatch;
                    }
                } catch (Throwable $exception) {
                    // One invalid legacy queue or unavailable broadcast must
                    // not starve later, independently recoverable broadcasts.
                    Log::error('Broadcast chunk recovery claim failed.', [
                        'broadcast_id' => (int) $id,
                        'reason' => $exception->getMessage(),
                    ]);
                }

                if (count($claimed) >= self::MAX_DISPATCHES) {
                    break;
                }
            }
        }

        return $claimed;
    }

    /**
     * @return array{broadcast_id: int, queue: string}|null
     */
    private function claim(int $id, Carbon $cutoff): ?array
    {
        return DB::transaction(function () use ($id, $cutoff): ?array {
            $broadcast = Broadcast::query()->lockForUpdate()->find($id);

            if (! $broadcast instanceof Broadcast
                || ! $this->releaseSchedule->isRecoverable($broadcast)
                || $this->releaseSchedule->nextReleaseAt($broadcast)->gt($cutoff)
            ) {
                return null;
            }

            $index = $this->releaseSchedule->nextChunkIndex($broadcast);
            $claimedIndex = data_get(
                $broadcast->meta,
                'scheduling.recovery.claimed_chunk_index',
            );
            $claimedAt = data_get(
                $broadcast->meta,
                'scheduling.recovery.claimed_at',
            );

            if (is_numeric($claimedIndex)
                && (int) $claimedIndex === $index
                && is_string($claimedAt)
                && trim($claimedAt) !== ''
                && Carbon::parse($claimedAt)->gt(
                    now()->subSeconds(self::CLAIM_LEASE_SECONDS),
                )
            ) {
                return null;
            }

            // Preserve the saved dispatch queue. Invalid queues fail closed
            // before taking a durable claim, so a corrected config can retry.
            $queue = $this->releaseSchedule->queue($broadcast);

            if (! $broadcast->recipients()
                ->where('status', BroadcastRecipient::STATUS_PENDING)
                ->exists()
            ) {
                return null;
            }

            $broadcast->forceFill([
                'meta' => array_replace_recursive($broadcast->meta ?? [], [
                    'scheduling' => [
                        'recovery' => [
                            'claimed_chunk_index' => $index,
                            'claimed_at' => now()->toISOString(),
                        ],
                    ],
                ]),
            ])->save();

            return [
                'broadcast_id' => (int) $broadcast->getKey(),
                'queue' => $queue,
            ];
        }, 3);
    }
}