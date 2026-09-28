<?php

namespace App\Modules\Campaigns\Listeners;

use App\Modules\Campaigns\Models\CampaignAllocationAssignment;
use App\Modules\Messaging\Events\ScheduledMessageCancelled;
use App\Modules\Messaging\Events\ScheduledMessageFailed;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Events\ScheduledMessageSkipped;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Support\Facades\DB;

final class ReconcileCampaignAllocationAssignmentFromScheduledMessageTerminal
{
    public function handle(
        ScheduledMessageSent|ScheduledMessageSkipped|ScheduledMessageFailed|ScheduledMessageCancelled $event,
    ): void {
        $result = $event->terminalResult;

        DB::transaction(function () use ($result): void {
            $assignment = CampaignAllocationAssignment::query()
                ->where(
                    'scheduled_message_id',
                    $result->scheduledMessageId,
                )
                ->lockForUpdate()
                ->first();

            if (! $assignment instanceof CampaignAllocationAssignment) {
                return;
            }

            $delivery = array_filter([
                'status' => $result->status,
                'occurred_at' => $result->occurredAt->toIso8601String(),
                'delivery_attempt_id' => $result->deliveryAttemptId,
                'attempt_number' => $result->attemptNumber,
                'provider' => $result->provider,
                'provider_message_id' => $result->providerMessageId,
                'reason_code' => $result->reasonCode,
                'reason' => $result->reason,
            ], static fn (mixed $value): bool => $value !== null);

            $attributes = [
                'meta' => array_replace_recursive(
                    is_array($assignment->meta) ? $assignment->meta : [],
                    ['delivery' => $delivery],
                ),
            ];

            if ($result->status === ScheduledMessage::STATUS_SENT) {
                $attributes['sent_at'] = $result->occurredAt;
            }

            $assignment->forceFill($attributes)->save();
        }, 3);
    }
}