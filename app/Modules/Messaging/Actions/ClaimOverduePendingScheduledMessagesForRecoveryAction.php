<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageDeliveryAttempt;
use App\Modules\Messaging\Services\ScheduledMessageEventOutbox;
use App\Modules\Messaging\Services\ScheduledMessageDeliveryPolicy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class ClaimOverduePendingScheduledMessagesForRecoveryAction
{
    public function __construct(
        private readonly ScheduledMessageDeliveryPolicy $deliveryPolicy,
        private readonly ScheduledMessageEventOutbox $eventOutbox,
    ) {}

    /**
     * Claims a bounded set of durable pending rows. The claim is committed before
     * dispatch; when a queue push is lost or fails, its timestamp expires and a
     * later sweep can retry without depending on Redis state.
     *
     * @return list<ScheduledMessage>
     */
    public function handle(): array
    {
        $now = now();
        $overdueBefore = $now->copy()->subSeconds(max(0, (int) config(
            'messaging.delivery.pending_message_overdue_grace_seconds',
            300,
        )));
        $claimBefore = $now->copy()->subSeconds(max(60, (int) config(
            'messaging.delivery.pending_recovery_retry_seconds',
            300,
        )));
        $batchSize = min(1000, max(1, (int) config(
            'messaging.delivery.pending_recovery_batch_size',
            100,
        )));

        $ids = $this->candidates($overdueBefore, $claimBefore)
            ->orderBy('pending_recovery_claimed_at')
            ->orderBy('id')
            ->limit($batchSize)
            ->pluck('id');

        $claimed = [];

        foreach ($ids as $id) {
            $message = DB::transaction(function () use (
                $id,
                $overdueBefore,
                $claimBefore,
            ): ?ScheduledMessage {
                // Recheck under the same row lock used by the delivery claim,
                // cancellation, and scheduling operations.
                $message = $this->candidates($overdueBefore, $claimBefore)
                    ->whereKey((int) $id)
                    ->lockForUpdate()
                    ->first();

                if (! $message instanceof ScheduledMessage) {
                    return null;
                }

                $firstProviderSubmission = $message->deliveryAttempts()
                    ->whereNotNull('provider_submission_started_at')
                    ->orderBy('provider_submission_started_at')
                    ->first();

                $safeToRetry = ! $firstProviderSubmission
                    || (filled($message->provider_idempotency_key)
                        && $this->deliveryPolicy
                            ->canSafelyRetryProviderSubmission($firstProviderSubmission));

                if (! $safeToRetry) {
                    // This is an outcome-unknown failure, not permission to
                    // submit again. Retain the prior attempt's evidence and
                    // record a separate terminal recovery attempt.
                    $lastAttemptNumber = (int) $message->deliveryAttempts()
                        ->max('attempt_number');
                    $reason = 'Overdue pending delivery was not retried because a prior provider submission has no current idempotency guarantee.';
                    $failedAt = now();
                    $failedAttempt = $message->deliveryAttempts()->create([
                        'attempt_number' => $lastAttemptNumber + 1,
                        'claim_token' => (string) Str::uuid(),
                        'status' => ScheduledMessageDeliveryAttempt::STATUS_FAILED,
                        'claimed_at' => $failedAt,
                        'lease_expires_at' => $failedAt,
                        'completed_at' => $failedAt,
                        'reason_code' => 'pending_recovery_provider_outcome_unknown',
                        'reason' => $reason,
                    ]);

                    $message->forceFill([
                        'status' => ScheduledMessage::STATUS_FAILED,
                        'pending_recovery_claimed_at' => $failedAt,
                    ])->save();

                    $this->eventOutbox->record(
                        scheduledMessage: $message,
                        eventType: ScheduledMessage::STATUS_FAILED,
                        occurredAt: $failedAt,
                        deliveryAttempt: $failedAttempt,
                        reasonCode: 'pending_recovery_provider_outcome_unknown',
                        reason: $reason,
                    );

                    Log::critical(
                        'Overdue pending ScheduledMessage failed safely because the prior provider submission outcome is unknown.',
                        [
                            'scheduled_message_id' => $message->getKey(),
                            'provider_submission_started_at' => $firstProviderSubmission
                                ->provider_submission_started_at?->toISOString(),
                        ],
                    );

                    return null;
                }

                $message->forceFill([
                    'pending_recovery_claimed_at' => now(),
                ])->save();

                return $message;
            });

            if ($message instanceof ScheduledMessage) {
                $claimed[] = $message;
            }
        }

        return $claimed;
    }

    private function candidates(
        CarbonInterface $overdueBefore,
        CarbonInterface $claimBefore,
    ): Builder
    {
        return ScheduledMessage::query()
            ->backgroundEligible()
            ->where('status', ScheduledMessage::STATUS_PENDING)
            ->where('operational_state', ScheduledMessage::OPERATIONAL_ACTIVE)
            ->whereNotNull('send_at')
            ->where('send_at', '<=', $overdueBefore)
            ->where(function (Builder $query) use ($claimBefore): void {
                $query
                    ->whereNull('pending_recovery_claimed_at')
                    ->orWhere('pending_recovery_claimed_at', '<=', $claimBefore);
            })
            ->whereDoesntHave(
                'deliveryAttempts',
                fn (Builder $attempts) => $attempts->active(),
            );
    }
}