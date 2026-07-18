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
            $claimToken = (string) Str::uuid();
            $providerIdempotencyKey = filled($message->provider_idempotency_key)
                ? $message->provider_idempotency_key
                : 'scheduled-message-'.$message->getKey().'-'.Str::uuid();
            $attemptNumber = ((int) $message->send_attempts) + 1;

            $message->forceFill([
                'status' => ScheduledMessage::STATUS_SENDING,
                'sending_at' => $attemptedAt,
                'claim_token' => $claimToken,
                'claim_expires_at' => $this->deliveryPolicy->leaseExpiresAt($attemptedAt),
                'provider_idempotency_key' => $providerIdempotencyKey,
                'provider_submission_started_at' => null,
                'recovered_at' => null,
                'last_attempted_at' => $attemptedAt,
                'send_attempts' => $attemptNumber,
                'skip_reason' => null,
                'failed_at' => null,
            ])->save();

            ScheduledMessageDeliveryAttempt::query()->create([
                'scheduled_message_id' => $message->getKey(),
                'claim_token' => $claimToken,
                'provider_idempotency_key' => $providerIdempotencyKey,
                'attempt_number' => $attemptNumber,
                'status' => ScheduledMessageDeliveryAttempt::STATUS_CLAIMED,
                'claimed_at' => $attemptedAt,
                'lease_expires_at' => $message->claim_expires_at,
                'meta' => [],
            ]);

            return $message;
        });

        return $claimed?->load(['recipient', 'context']);
    }
}