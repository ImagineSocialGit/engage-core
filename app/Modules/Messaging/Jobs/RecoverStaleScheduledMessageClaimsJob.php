<?php

namespace App\Modules\Messaging\Jobs;

use App\Modules\Messaging\Actions\RecoverStaleScheduledMessageClaimsAction;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\ScheduledMessageDeliveryPolicy;
use App\Support\Queues\QueueContract;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class RecoverStaleScheduledMessageClaimsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $uniqueFor = 55;

    public function uniqueId(): string
    {
        return 'messaging:scheduled-message-stale-claim-recovery';
    }

    public function handle(
        RecoverStaleScheduledMessageClaimsAction $recoverStaleClaims,
        ScheduledMessageDeliveryPolicy $deliveryPolicy,
        ?QueueContract $queueContract = null,
    ): void {
        $queueContract ??= app(QueueContract::class);

        $this->reportQueueProblems($queueContract);

        $recoverStaleClaims->handle();

        ScheduledMessage::query()
            ->where('status', ScheduledMessage::STATUS_PENDING)
            ->whereNotNull('recovered_at')
            ->orderBy('recovered_at')
            ->orderBy('id')
            ->limit($deliveryPolicy->recoveryBatchSize())
            ->get()
            ->each(function (ScheduledMessage $message) use ($queueContract): void {
                try {
                    $queue = $queueContract->assertDispatchable($message->queue);
                } catch (InvalidArgumentException $exception) {
                    Log::critical(
                        'Recovered ScheduledMessage was blocked from invalid queue redispatch.',
                        [
                            'scheduled_message_id' => $message->getKey(),
                            'queue' => $message->queue,
                            'reason' => $exception->getMessage(),
                        ],
                    );

                    return;
                }

                SendScheduledMessageJob::dispatch(
                    (int) $message->getKey(),
                )->onQueue($queue);
            });
    }

    private function reportQueueProblems(QueueContract $queueContract): void
    {
        $contractIssues = $queueContract->validationIssues();
        $unsupportedPendingQueues = [];
        $unconsumedPendingQueues = [];

        $pendingQueueValues = ScheduledMessage::query()
            ->where('status', ScheduledMessage::STATUS_PENDING)
            ->distinct()
            ->pluck('queue');

        foreach ($pendingQueueValues as $storedQueue) {
            $rawQueue = is_string($storedQueue) && trim($storedQueue) !== ''
                ? trim($storedQueue)
                : null;
            $resolvedQueue = $queueContract->resolve($rawQueue);
            $count = $this->pendingCountForQueue($storedQueue);

            if (! $queueContract->isSupported($resolvedQueue)) {
                $unsupportedPendingQueues[$resolvedQueue] = $count;

                continue;
            }

            if (
                $queueContract->hasHorizonEnvironmentConfiguration()
                && ! $queueContract->isConsumed($resolvedQueue)
            ) {
                $unconsumedPendingQueues[$resolvedQueue] = $count;
            }
        }

        $graceSeconds = max(0, (int) config(
            'messaging.delivery.pending_message_overdue_grace_seconds',
            300,
        ));
        $overdueBefore = now()->subSeconds($graceSeconds);
        $overdueQuery = ScheduledMessage::query()
            ->where('status', ScheduledMessage::STATUS_PENDING)
            ->whereNotNull('send_at')
            ->where('send_at', '<=', $overdueBefore);
        $overdueCount = (clone $overdueQuery)->count();

        if (
            $contractIssues === []
            && $unsupportedPendingQueues === []
            && $unconsumedPendingQueues === []
            && $overdueCount === 0
        ) {
            return;
        }

        Log::critical('Messaging queue audit detected a queue contract violation.', [
            'environment' => $queueContract->environment(),
            'contract_issues' => $contractIssues,
            'unsupported_pending_queues' => $unsupportedPendingQueues,
            'unconsumed_pending_queues' => $unconsumedPendingQueues,
            'overdue_pending_count' => $overdueCount,
            'overdue_pending_ids' => (clone $overdueQuery)
                ->orderBy('id')
                ->limit(25)
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all(),
            'overdue_before' => $overdueBefore->toISOString(),
        ]);
    }

    private function pendingCountForQueue(mixed $queue): int
    {
        return ScheduledMessage::query()
            ->where('status', ScheduledMessage::STATUS_PENDING)
            ->when(
                $queue === null,
                fn ($query) => $query->whereNull('queue'),
                fn ($query) => $query->where('queue', $queue),
            )
            ->count();
    }
}