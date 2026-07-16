<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Support\Facades\DB;

class ClaimScheduledMessageForSendingAction
{
    public function handle(int|ScheduledMessage $scheduledMessage): ?ScheduledMessage
    {
        $scheduledMessageId = $scheduledMessage instanceof ScheduledMessage
            ? $scheduledMessage->getKey()
            : $scheduledMessage;

        $claimed = DB::transaction(function () use ($scheduledMessageId): ?ScheduledMessage {
            $message = ScheduledMessage::query()
                ->lockForUpdate()
                ->find($scheduledMessageId);

            if (! $message instanceof ScheduledMessage
                || $message->status !== ScheduledMessage::STATUS_PENDING
            ) {
                return null;
            }

            $attemptedAt = now();

            $message->forceFill([
                'status' => ScheduledMessage::STATUS_SENDING,
                'sending_at' => $attemptedAt,
                'last_attempted_at' => $attemptedAt,
                'send_attempts' => ((int) $message->send_attempts) + 1,
                'skip_reason' => null,
                'failed_at' => null,
            ])->save();

            return $message;
        });

        return $claimed?->load(['recipient', 'context']);
    }
}
