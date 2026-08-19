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
     * @return array{
     *     requeued: array<int, ScheduledMessage>,
     *     failed: array<int, ScheduledMessage>
     * }
     */
    public function handle(): array
    {
        $result = [
            'requeued' => [],
            'failed' => [],
        ];

        $ids = ScheduledMessageDeliveryAttempt::query()
            ->active()
            ->where('lease_expires_at', '<=', now())
            ->whereHas(
                'scheduledMessage',
                fn ($query) => $query->backgroundEligible(),
            )
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

    /**
     * @return array{
     *     outcome: 'requeued'|'failed',
     *     message: ScheduledMessage
     * }|null
     */
    private function recoverOne(int $attemptId): ?array
    {
        return DB::transaction(function () use (
            $attemptId,
        ): ?array {
            $attempt = ScheduledMessageDeliveryAttempt::query()
                ->lockForUpdate()
                ->find($attemptId);

            if (! $attempt instanceof ScheduledMessageDeliveryAttempt
                || ! $attempt->isActive()
                || $attempt->lease_expires_at->isFuture()
            ) {
                return null;
            }

            $message = ScheduledMessage::query()
                ->lockForUpdate()
                ->find($attempt->scheduled_message_id);

            if (! $message instanceof ScheduledMessage
                || $message->status !== ScheduledMessage::STATUS_SENDING
            ) {
                return null;
            }

            $recoveredAt = now();
            $attempt->setRelation('scheduledMessage', $message);

            $submissionIsAmbiguous = ! $this->deliveryPolicy
                ->canSafelyRetryProviderSubmission($attempt);

            $reason = $submissionIsAmbiguous
                ? 'Delivery outcome is unknown after a stale provider submission without a current idempotency guarantee; automatic retry was blocked.'
                : 'Expired ScheduledMessage delivery claim was recovered for retry.';

            if ($submissionIsAmbiguous) {
                $message->forceFill([
                    'status' => ScheduledMessage::STATUS_FAILED,
                ])->save();

                $attempt->forceFill([
                    'status' => ScheduledMessageDeliveryAttempt::STATUS_FAILED,
                    'completed_at' => $recoveredAt,
                    'reason_code' =>
                        'stale_provider_submission_outcome_unknown',
                    'reason' => $reason,
                ])->save();

                $this->eventOutbox->record(
                    scheduledMessage: $message,
                    eventType: ScheduledMessage::STATUS_FAILED,
                    occurredAt: $recoveredAt,
                    deliveryAttempt: $attempt,
                );

                return [
                    'outcome' => 'failed',
                    'message' => $message,
                ];
            }

            $message->forceFill([
                'status' => ScheduledMessage::STATUS_PENDING,
            ])->save();

            $attempt->forceFill([
                'status' =>
                    ScheduledMessageDeliveryAttempt::STATUS_RECOVERED,
                'completed_at' => $recoveredAt,
                'reason_code' => 'stale_claim_recovered',
                'reason' => $reason,
            ])->save();

            return [
                'outcome' => 'requeued',
                'message' => $message,
            ];
        });
    }
}