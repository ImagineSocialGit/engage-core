<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageDeliveryAttempt;
use App\Modules\Messaging\Services\ScheduledMessageDeliveryPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClaimScheduledMessageForSendingAction
{
    public function __construct(
        private readonly ScheduledMessageDeliveryPolicy $deliveryPolicy,
    ) {}

    public function handle(
        int|ScheduledMessage $scheduledMessage,
    ): ?ScheduledMessageDeliveryAttempt {
        $scheduledMessageId = $scheduledMessage instanceof ScheduledMessage
            ? $scheduledMessage->getKey()
            : $scheduledMessage;

        $attempt = DB::transaction(function () use (
            $scheduledMessageId,
        ): ?ScheduledMessageDeliveryAttempt {
            $message = ScheduledMessage::query()
                ->lockForUpdate()
                ->find($scheduledMessageId);

            if (! $message instanceof ScheduledMessage
                || $message->status !== ScheduledMessage::STATUS_PENDING
            ) {
                return null;
            }

            $attemptedAt = now();
            $attemptNumber = ((int) ScheduledMessageDeliveryAttempt::query()
                ->where('scheduled_message_id', $message->getKey())
                ->max('attempt_number')) + 1;
            $providerIdempotencyKey = filled($message->provider_idempotency_key)
                ? $message->provider_idempotency_key
                : 'scheduled-message-'.$message->getKey().'-'.Str::uuid();

            $message->forceFill([
                'status' => ScheduledMessage::STATUS_SENDING,
                'provider_idempotency_key' => $providerIdempotencyKey,
            ])->save();

            return ScheduledMessageDeliveryAttempt::query()->create([
                'scheduled_message_id' => $message->getKey(),
                'attempt_number' => $attemptNumber,
                'claim_token' => (string) Str::uuid(),
                'status' => ScheduledMessageDeliveryAttempt::STATUS_CLAIMED,
                'claimed_at' => $attemptedAt,
                'lease_expires_at' => $this->deliveryPolicy
                    ->leaseExpiresAt($attemptedAt),
            ]);
        });

        return $attempt?->load([
            'scheduledMessage.recipient',
            'scheduledMessage.context',
        ]);
    }
}