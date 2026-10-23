<?php

namespace App\Modules\Broadcasts\Jobs;

use App\Modules\Broadcasts\Actions\RecoverInterruptedBroadcastChunksAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RecoverInterruptedBroadcastChunksJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $uniqueFor = 55;

    public function uniqueId(): string
    {
        return 'broadcasts:interrupted-chunk-recovery';
    }

    public function handle(RecoverInterruptedBroadcastChunksAction $recovery): void
    {
        foreach ($recovery->handle() as $claim) {
            try {
                ScheduleBroadcastChunkJob::dispatch($claim['broadcast_id'])
                    ->onQueue($claim['queue']);
            } catch (Throwable $exception) {
                // Claims are in MySQL. A lost or rejected Redis dispatch can
                // be claimed again after its bounded database lease expires.
                Log::critical('Broadcast chunk recovery dispatch failed.', [
                    'broadcast_id' => $claim['broadcast_id'],
                    'queue' => $claim['queue'],
                    'reason' => $exception->getMessage(),
                ]);
            }
        }
    }
}