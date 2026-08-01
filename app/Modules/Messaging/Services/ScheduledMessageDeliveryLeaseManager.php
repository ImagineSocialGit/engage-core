<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Data\Delivery\MessageSendResult;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageDeliveryAttempt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class ScheduledMessageDeliveryLeaseManager
{
    public function __construct(
        private readonly ScheduledMessageDeliveryPolicy $deliveryPolicy,
        private readonly ScheduledMessageEventOutbox $eventOutbox,
    ) {}

    public function beginProviderSubmission(
        ScheduledMessageDeliveryAttempt $claimedAttempt,
        ?string $destination,
    ): bool {
        return DB::transaction(function () use (
            $claimedAttempt,
            $destination,
        ): bool {
            $attempt = $this->lockedActiveAttempt($claimedAttempt);

            if (! $attempt instanceof ScheduledMessageDeliveryAttempt) {
                return false;
            }

            $message = $this->lockedSendingMessage($attempt);

            if (! $message instanceof ScheduledMessage) {
                return false;
            }

            $startedAt = now();

            $attempt->forceFill([
                'status' => ScheduledMessageDeliveryAttempt::STATUS_SUBMITTING,
                'provider_submission_started_at' => $startedAt,
                'lease_expires_at' => $this->deliveryPolicy
                    ->leaseExpiresAt($startedAt),
                'destination' => $this->destination($destination),
            ])->save();

            $this->syncClaimedAttempt($claimedAttempt, $attempt);

            return true;
        });
    }

    public function complete(
        ScheduledMessageDeliveryAttempt $claimedAttempt,
        string $status,
        MessageSendResult $result,
        ?Throwable $exception = null,
    ): ?ScheduledMessage {
        if (! in_array($status, [
            ScheduledMessage::STATUS_SENT,
            ScheduledMessage::STATUS_SKIPPED,
            ScheduledMessage::STATUS_FAILED,
        ], true)) {
            throw new InvalidArgumentException(
                "Unsupported ScheduledMessage terminal status [{$status}].",
            );
        }

        $completed = DB::transaction(function () use (
            $claimedAttempt,
            $status,
            $result,
            $exception,
        ): ?ScheduledMessage {
            $attempt = $this->lockedActiveAttempt($claimedAttempt);

            if (! $attempt instanceof ScheduledMessageDeliveryAttempt) {
                return null;
            }

            $message = $this->lockedSendingMessage($attempt);

            if (! $message instanceof ScheduledMessage) {
                return null;
            }

            $completedAt = now();
            $reason = match ($status) {
                ScheduledMessage::STATUS_SENT => null,
                ScheduledMessage::STATUS_SKIPPED => $result->reason
                    ?? 'Message delivery was skipped.',
                default => $exception?->getMessage()
                    ?? $result->reason
                    ?? 'Message delivery failed.',
            };
            $message->forceFill([
                'status' => $status,
            ])->save();

            $attempt->forceFill([
                'status' => match ($status) {
                    ScheduledMessage::STATUS_SENT =>
                        ScheduledMessageDeliveryAttempt::STATUS_SENT,
                    ScheduledMessage::STATUS_SKIPPED =>
                        ScheduledMessageDeliveryAttempt::STATUS_SKIPPED,
                    default =>
                        ScheduledMessageDeliveryAttempt::STATUS_FAILED,
                },
                'completed_at' => $completedAt,
                'provider' => $result->provider,
                'provider_message_id' => $result->providerMessageId,
                'reason_code' => $result->reasonCode,
                'reason' => $reason,
            ])->save();

            $this->eventOutbox->record(
                scheduledMessage: $message,
                eventType: $status,
                occurredAt: $completedAt,
                deliveryAttempt: $attempt,
            );

            return $message;
        });

        if ($completed instanceof ScheduledMessage) {
            $this->syncClaimedAttempt($claimedAttempt);
        }

        return $completed;
    }

    public function releaseForRetry(
        ScheduledMessageDeliveryAttempt $claimedAttempt,
        Throwable $exception,
    ): ?ScheduledMessage {
        $result = MessageSendResult::failed(
            reasonCode: 'message_delivery_retryable_exception',
            reason: $exception->getMessage(),
            retryable: true,
        );

        $released = DB::transaction(function () use (
            $claimedAttempt,
            $exception,
            $result,
        ): ?ScheduledMessage {
            $attempt = $this->lockedActiveAttempt($claimedAttempt);

            if (! $attempt instanceof ScheduledMessageDeliveryAttempt) {
                return null;
            }

            $message = $this->lockedSendingMessage($attempt);

            if (! $message instanceof ScheduledMessage) {
                return null;
            }

            $releasedAt = now();

            $message->forceFill([
                'status' => ScheduledMessage::STATUS_PENDING,
            ])->save();

            $attempt->forceFill([
                'status' => ScheduledMessageDeliveryAttempt::STATUS_RELEASED,
                'completed_at' => $releasedAt,
                'reason_code' => $result->reasonCode,
                'reason' => $exception->getMessage(),
            ])->save();

            return $message;
        });

        if ($released instanceof ScheduledMessage) {
            $this->syncClaimedAttempt($claimedAttempt);
        }

        return $released;
    }

    public function ownsActiveClaim(
        ScheduledMessageDeliveryAttempt $claimedAttempt,
    ): bool {
        if (! filled($claimedAttempt->claim_token)) {
            return false;
        }

        return ScheduledMessageDeliveryAttempt::query()
            ->whereKey($claimedAttempt->getKey())
            ->where('claim_token', $claimedAttempt->claim_token)
            ->active()
            ->whereHas(
                'scheduledMessage',
                fn ($query) => $query->where(
                    'status',
                    ScheduledMessage::STATUS_SENDING,
                ),
            )
            ->exists();
    }

    public function canRetryAfterProviderSubmission(
        ScheduledMessageDeliveryAttempt $attempt,
    ): bool {
        return $this->deliveryPolicy
            ->canSafelyRetryProviderSubmission($attempt);
    }

    private function lockedActiveAttempt(
        ScheduledMessageDeliveryAttempt $claimedAttempt,
    ): ?ScheduledMessageDeliveryAttempt {
        if (! filled($claimedAttempt->claim_token)) {
            return null;
        }

        return ScheduledMessageDeliveryAttempt::query()
            ->lockForUpdate()
            ->whereKey($claimedAttempt->getKey())
            ->where('claim_token', $claimedAttempt->claim_token)
            ->active()
            ->first();
    }

    private function lockedSendingMessage(
        ScheduledMessageDeliveryAttempt $attempt,
    ): ?ScheduledMessage {
        return ScheduledMessage::query()
            ->lockForUpdate()
            ->whereKey($attempt->scheduled_message_id)
            ->where('status', ScheduledMessage::STATUS_SENDING)
            ->first();
    }

    private function syncClaimedAttempt(
        ScheduledMessageDeliveryAttempt $claimedAttempt,
        ?ScheduledMessageDeliveryAttempt $persistedAttempt = null,
    ): void {
        $persistedAttempt ??= $claimedAttempt->fresh();

        if (! $persistedAttempt instanceof ScheduledMessageDeliveryAttempt) {
            return;
        }

        $claimedAttempt->setRawAttributes(
            $persistedAttempt->getAttributes(),
            true,
        );
    }

    private function destination(?string $destination): ?string
    {
        if (! is_string($destination)) {
            return null;
        }

        $destination = trim($destination);

        return $destination === ''
            ? null
            : mb_substr($destination, 0, 255);
    }
}