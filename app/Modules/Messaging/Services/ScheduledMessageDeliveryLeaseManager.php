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
    ) {}

    public function beginProviderSubmission(ScheduledMessage $claimedMessage): bool
    {
        return DB::transaction(function () use ($claimedMessage): bool {
            $message = $this->lockedActiveClaim($claimedMessage);

            if (! $message instanceof ScheduledMessage) {
                return false;
            }

            $startedAt = now();

            $message->forceFill([
                'provider_submission_started_at' => $startedAt,
                'claim_expires_at' => $this->deliveryPolicy->leaseExpiresAt($startedAt),
            ])->save();

            $this->attempt($message)->forceFill([
                'status' => ScheduledMessageDeliveryAttempt::STATUS_SUBMITTING,
                'provider_submission_started_at' => $startedAt,
                'lease_expires_at' => $message->claim_expires_at,
            ])->save();

            $this->syncClaimedMessage($claimedMessage, $message);

            return true;
        });
    }

    public function complete(
        ScheduledMessage $claimedMessage,
        string $status,
        MessageSendResult $result,
        ?Throwable $exception = null,
    ): ?ScheduledMessage {
        if (! in_array($status, [
            ScheduledMessage::STATUS_SENT,
            ScheduledMessage::STATUS_SKIPPED,
            ScheduledMessage::STATUS_FAILED,
        ], true)) {
            throw new InvalidArgumentException("Unsupported ScheduledMessage terminal status [{$status}].");
        }

        $completed = DB::transaction(function () use (
            $claimedMessage,
            $status,
            $result,
            $exception,
        ): ?ScheduledMessage {
            $message = $this->lockedActiveClaim($claimedMessage);

            if (! $message instanceof ScheduledMessage) {
                return null;
            }

            $attempt = $this->attempt($message);
            $completedAt = now();
            $attributes = [
                'status' => $status,
                'sending_at' => null,
                'claim_token' => null,
                'claim_expires_at' => null,
                'recovered_at' => null,
                'provider' => $result->provider,
                'provider_message_id' => $result->providerMessageId,
                'meta' => $this->deliveryMeta($message, $result),
            ];

            if ($status === ScheduledMessage::STATUS_SENT) {
                $attributes += [
                    'sent_at' => $completedAt,
                    'skipped_at' => null,
                    'failed_at' => null,
                    'failure_reason' => null,
                    'skip_reason' => null,
                ];
            } elseif ($status === ScheduledMessage::STATUS_SKIPPED) {
                $attributes += [
                    'sent_at' => null,
                    'skipped_at' => $completedAt,
                    'failed_at' => null,
                    'failure_reason' => null,
                    'skip_reason' => $result->reason ?? 'Message delivery was skipped.',
                ];
            } else {
                $attributes += [
                    'sent_at' => null,
                    'skipped_at' => null,
                    'failed_at' => $completedAt,
                    'failure_reason' => $exception?->getMessage()
                        ?? $result->reason
                        ?? 'Message delivery failed.',
                    'skip_reason' => null,
                ];
            }

            $message->forceFill($attributes)->save();

            $attemptStatus = match ($status) {
                ScheduledMessage::STATUS_SENT => ScheduledMessageDeliveryAttempt::STATUS_SENT,
                ScheduledMessage::STATUS_SKIPPED => ScheduledMessageDeliveryAttempt::STATUS_SKIPPED,
                default => ScheduledMessageDeliveryAttempt::STATUS_FAILED,
            };

            $attempt->forceFill([
                'status' => $attemptStatus,
                'completed_at' => $completedAt,
                'provider' => $result->provider,
                'provider_message_id' => $result->providerMessageId,
                'reason_code' => $result->reasonCode,
                'reason' => $exception?->getMessage() ?? $result->reason,
                'meta' => $result->meta,
            ])->save();

            return $message;
        });

        if ($completed instanceof ScheduledMessage) {
            $this->syncClaimedMessage($claimedMessage, $completed);
        }

        return $completed;
    }

    public function releaseForRetry(
        ScheduledMessage $claimedMessage,
        Throwable $exception,
    ): ?ScheduledMessage {
        $result = MessageSendResult::failed(
            reasonCode: 'message_delivery_retryable_exception',
            reason: $exception->getMessage(),
            retryable: true,
        );

        $released = DB::transaction(function () use (
            $claimedMessage,
            $exception,
            $result,
        ): ?ScheduledMessage {
            $message = $this->lockedActiveClaim($claimedMessage);

            if (! $message instanceof ScheduledMessage) {
                return null;
            }

            $attempt = $this->attempt($message);
            $releasedAt = now();

            $message->forceFill([
                'status' => ScheduledMessage::STATUS_PENDING,
                'sending_at' => null,
                'claim_token' => null,
                'claim_expires_at' => null,
                'provider_submission_started_at' => null,
                'recovered_at' => null,
                'failed_at' => null,
                'failure_reason' => $exception->getMessage(),
                'meta' => $this->deliveryMeta($message, $result),
            ])->save();

            $attempt->forceFill([
                'status' => ScheduledMessageDeliveryAttempt::STATUS_RELEASED,
                'completed_at' => $releasedAt,
                'reason_code' => $result->reasonCode,
                'reason' => $exception->getMessage(),
                'meta' => $result->meta,
            ])->save();

            return $message;
        });

        if ($released instanceof ScheduledMessage) {
            $this->syncClaimedMessage($claimedMessage, $released);
        }

        return $released;
    }

    public function ownsActiveClaim(ScheduledMessage $claimedMessage): bool
    {
        if (! filled($claimedMessage->claim_token)) {
            return false;
        }

        return ScheduledMessage::query()
            ->whereKey($claimedMessage->getKey())
            ->where('status', ScheduledMessage::STATUS_SENDING)
            ->where('claim_token', $claimedMessage->claim_token)
            ->exists();
    }

    public function canRetryAfterProviderSubmission(ScheduledMessage $message): bool
    {
        return $this->deliveryPolicy->canSafelyRetryProviderSubmission($message);
    }

    private function lockedActiveClaim(
        ScheduledMessage $claimedMessage,
    ): ?ScheduledMessage {
        if (! filled($claimedMessage->claim_token)) {
            return null;
        }

        return ScheduledMessage::query()
            ->lockForUpdate()
            ->whereKey($claimedMessage->getKey())
            ->where('status', ScheduledMessage::STATUS_SENDING)
            ->where('claim_token', $claimedMessage->claim_token)
            ->first();
    }

    private function attempt(
        ScheduledMessage $message,
    ): ScheduledMessageDeliveryAttempt {
        return ScheduledMessageDeliveryAttempt::query()
            ->where('scheduled_message_id', $message->getKey())
            ->where('claim_token', $message->claim_token)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function syncClaimedMessage(
        ScheduledMessage $claimedMessage,
        ScheduledMessage $persistedMessage,
    ): void {
        $claimedMessage->setRawAttributes($persistedMessage->getAttributes(), true);
    }

    private function deliveryMeta(
        ScheduledMessage $message,
        MessageSendResult $result,
    ): array {
        return array_replace_recursive(
            is_array($message->meta) ? $message->meta : [],
            [
                'delivery' => [
                    ...$result->toMeta(),
                    'attempt' => (int) $message->send_attempts,
                    'attempted_at' => ($message->last_attempted_at ?? now())->toISOString(),
                    'provider_idempotency_key' => $message->provider_idempotency_key,
                ],
            ],
        );
    }
}