<?php

namespace App\Modules\Messaging\Jobs;

use App\Modules\Messaging\Actions\RecoverStaleScheduledMessageClaimsAction;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\ScheduledMessageDeliveryPolicy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
    ): void {
        $result = $recoverStaleClaims->handle();

        ScheduledMessage::query()
            ->where('status', ScheduledMessage::STATUS_PENDING)
            ->whereNotNull('recovered_at')
            ->orderBy('recovered_at')
            ->orderBy('id')
            ->limit($deliveryPolicy->recoveryBatchSize())
            ->get()
            ->each(function (ScheduledMessage $message): void {
                $pendingDispatch = SendScheduledMessageJob::dispatch(
                    (int) $message->getKey(),
                );

                if (filled($message->queue)) {
                    $pendingDispatch->onQueue($message->queue);
                }
            });
    }
}