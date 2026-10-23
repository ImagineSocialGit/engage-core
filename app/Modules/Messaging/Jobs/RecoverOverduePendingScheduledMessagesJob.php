<?php

namespace App\Modules\Messaging\Jobs;

use App\Modules\Messaging\Actions\ClaimOverduePendingScheduledMessagesForRecoveryAction;
use App\Support\Queues\QueueContract;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RecoverOverduePendingScheduledMessagesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $uniqueFor = 55;

    public function uniqueId(): string
    {
        return 'messaging:overdue-pending-message-recovery';
    }

    public function handle(
        ClaimOverduePendingScheduledMessagesForRecoveryAction $claim,
        QueueContract $queueContract,
    ): void {
        foreach ($claim->handle() as $message) {
            try {
                $queue = $queueContract->assertDispatchable($message->queue);

                SendScheduledMessageJob::dispatch(
                    (int) $message->getKey(),
                )->onQueue($queue);
            } catch (Throwable $exception) {
                // The durable claim expires. A later sweep will try again;
                // no provider call is made by this recovery job.
                Log::critical(
                    'Overdue pending ScheduledMessage could not be redispatched.',
                    [
                        'scheduled_message_id' => $message->getKey(),
                        'queue' => $message->queue,
                        'reason' => $exception->getMessage(),
                    ],
                );
            }
        }
    }
}