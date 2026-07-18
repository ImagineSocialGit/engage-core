<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageDeliveryAttempt;
use App\Modules\Messaging\Services\ScheduledMessageDeliveryPolicy;
use App\Modules\Messaging\Services\ScheduledMessageEventOutbox;
use Illuminate\Support\Facades\DB;

class RecoverStaleScheduledMessageClaimsAction
{
    public function __construct(
        private readonly ScheduledMessageDeliveryPolicy $deliveryPolicy,
        private readonly ScheduledMessageEventOutbox $eventOutbox,
    ) {}

    /**
     * @return array{requeued: array<int, ScheduledMessage>, failed: array<int, ScheduledMessage>}
     */
    public function handle(): array
    {
        $result = [
            'requeued' => [],
            'failed' => [],
        ];

        $ids = ScheduledMessage::query()
            ->where('status', ScheduledMessage::STATUS_SENDING)
            ->whereNotNull('claim_expires_at')
            ->where('claim_expires_at', '<=', now())
            ->orderBy('id')
            ->limit($this->deliveryPolicy->recoveryBatchSize())
            ->pluck('id');

        foreach ($ids as $id) {
            $recovered = $this->recoverOne((int) $id);

            if (! is_array($recovered)) {
                continue;
            }

            $result[$recovered['outcome']][] = $recovered['message'];
        }

        return $result;
    }

    /** @return array{outcome: 'requeued'|'failed', message: ScheduledMessage}|null */
    private function recoverOne(int $id): ?array
    {
        return DB::transaction(function () use ($id): ?array {
            $message = ScheduledMessage::query()
                ->lockForUpdate()
                ->find($id);

            if (! $message instanceof ScheduledMessage
                || $message->status !== ScheduledMessage::STATUS_SENDING
                || $message->claim_expires_at === null
                || $message->claim_expires_at->isFuture()
            ) {
                return null;
            }

            $recoveredAt = now();
            $claimToken = $message->claim_token;
            $submissionIsAmbiguous = ! $this->deliveryPolicy
                ->canSafelyRetryProviderSubmission($message);
            $recoveryCount = ((int) data_get(
                $message->meta,
                'delivery.recovery.count',
                0,
            )) + 1;
            $reason = $submissionIsAmbiguous
                ? 'Delivery outcome is unknown after a stale provider submission without a current idempotency guarantee; automatic retry was blocked.'
                : 'Expired ScheduledMessage delivery claim was recovered for retry.';

            $meta = array_replace_recursive(
                is_array($message->meta) ? $message->meta : [],
                [
                    'delivery' => [
                        'recovery' => [
                            'count' => $recoveryCount,
                            'recovered_at' => $recoveredAt->toISOString(),
                            'reason' => $reason,
                            'previous_claim_token' => $claimToken,
                        ],
                    ],
                ],
            );

            if ($submissionIsAmbiguous) {
                $message->forceFill([
                    'status' => ScheduledMessage::STATUS_FAILED,
                    'sending_at' => null,
                    'claim_token' => null,
                    'claim_expires_at' => null,
                    'recovered_at' => null,
                    'failed_at' => $recoveredAt,
                    'failure_reason' => $reason,
                    'skip_reason' => null,
                    'meta' => $meta,
                ])->save();

                $this->completeAttempt(
                    message: $message,
                    claimToken: $claimToken,
                    status: ScheduledMessageDeliveryAttempt::STATUS_FAILED,
                    completedAt: $recoveredAt,
                    reasonCode: 'stale_provider_submission_outcome_unknown',
                    reason: $reason,
                );

                $this->eventOutbox->record(
                    scheduledMessage: $message,
                    eventType: ScheduledMessage::STATUS_FAILED,
                    occurredAt: $recoveredAt,
                );

                return [
                    'outcome' => 'failed',
                    'message' => $message,
                ];
            }

            $message->forceFill([
                'status' => ScheduledMessage::STATUS_PENDING,
                'sending_at' => null,
                'claim_token' => null,
                'claim_expires_at' => null,
                'provider_submission_started_at' => null,
                'recovered_at' => $recoveredAt,
                'failed_at' => null,
                'failure_reason' => null,
                'skip_reason' => null,
                'meta' => $meta,
            ])->save();

            $this->completeAttempt(
                message: $message,
                claimToken: $claimToken,
                status: ScheduledMessageDeliveryAttempt::STATUS_RECOVERED,
                completedAt: $recoveredAt,
                reasonCode: 'stale_claim_recovered',
                reason: $reason,
            );

            return [
                'outcome' => 'requeued',
                'message' => $message,
            ];
        });
    }

    private function completeAttempt(
        ScheduledMessage $message,
        ?string $claimToken,
        string $status,
        mixed $completedAt,
        string $reasonCode,
        string $reason,
    ): void {
        if (! filled($claimToken)) {
            return;
        }

        ScheduledMessageDeliveryAttempt::query()
            ->where('scheduled_message_id', $message->getKey())
            ->where('claim_token', $claimToken)
            ->update([
                'status' => $status,
                'completed_at' => $completedAt,
                'reason_code' => $reasonCode,
                'reason' => $reason,
                'updated_at' => $completedAt,
            ]);
    }
}