<?php

namespace App\Modules\Messaging\Data\Delivery;

use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageDeliveryAttempt;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class ScheduledMessageTerminalResult
{
    public function __construct(
        public int $scheduledMessageId,
        public string $status,
        public CarbonImmutable $occurredAt,
        public ?int $deliveryAttemptId = null,
        public ?int $attemptNumber = null,
        public ?string $provider = null,
        public ?string $providerMessageId = null,
        public ?string $reasonCode = null,
        public ?string $reason = null,
    ) {
        if (! in_array($this->status, [
            ScheduledMessage::STATUS_SENT,
            ScheduledMessage::STATUS_SKIPPED,
            ScheduledMessage::STATUS_FAILED,
        ], true)) {
            throw new InvalidArgumentException(
                "Unsupported ScheduledMessage terminal status [{$this->status}].",
            );
        }
    }

    public static function fromScheduledMessage(
        ScheduledMessage $scheduledMessage,
    ): self {
        $status = (string) $scheduledMessage->status;
        $attempt = self::terminalAttempt($scheduledMessage);
        $occurredAt = $attempt?->completed_at
            ?? self::terminalTimestamp($scheduledMessage)
            ?? $scheduledMessage->updated_at
            ?? now();

        return new self(
            scheduledMessageId: (int) $scheduledMessage->getKey(),
            status: $status,
            occurredAt: CarbonImmutable::instance($occurredAt),
            deliveryAttemptId: $attempt?->getKey() !== null
                ? (int) $attempt->getKey()
                : null,
            attemptNumber: $attempt?->attempt_number !== null
                ? (int) $attempt->attempt_number
                : null,
            provider: self::normalizedString(
                $attempt?->provider ?? $scheduledMessage->provider,
            ),
            providerMessageId: self::normalizedString(
                $attempt?->provider_message_id
                    ?? $scheduledMessage->provider_message_id,
            ),
            reasonCode: self::normalizedString($attempt?->reason_code),
            reason: self::terminalReason(
                scheduledMessage: $scheduledMessage,
                attempt: $attempt,
            ),
        );
    }

    public function isSent(): bool
    {
        return $this->status === ScheduledMessage::STATUS_SENT;
    }

    public function isSkipped(): bool
    {
        return $this->status === ScheduledMessage::STATUS_SKIPPED;
    }

    public function isFailed(): bool
    {
        return $this->status === ScheduledMessage::STATUS_FAILED;
    }

    private static function terminalAttempt(
        ScheduledMessage $scheduledMessage,
    ): ?ScheduledMessageDeliveryAttempt {
        $expectedStatus = match ($scheduledMessage->status) {
            ScheduledMessage::STATUS_SENT => ScheduledMessageDeliveryAttempt::STATUS_SENT,
            ScheduledMessage::STATUS_SKIPPED => ScheduledMessageDeliveryAttempt::STATUS_SKIPPED,
            ScheduledMessage::STATUS_FAILED => ScheduledMessageDeliveryAttempt::STATUS_FAILED,
            default => throw new InvalidArgumentException(
                "ScheduledMessage [{$scheduledMessage->getKey()}] is not terminal.",
            ),
        };
        $loadedAttempt = $scheduledMessage->relationLoaded('latestDeliveryAttempt')
            ? $scheduledMessage->getRelation('latestDeliveryAttempt')
            : null;

        if ($loadedAttempt instanceof ScheduledMessageDeliveryAttempt
            && $loadedAttempt->status === $expectedStatus
        ) {
            return $loadedAttempt;
        }

        return ScheduledMessageDeliveryAttempt::query()
            ->where('scheduled_message_id', $scheduledMessage->getKey())
            ->where('status', $expectedStatus)
            ->orderByDesc('attempt_number')
            ->first();
    }

    private static function terminalTimestamp(
        ScheduledMessage $scheduledMessage,
    ): ?CarbonInterface {
        return match ($scheduledMessage->status) {
            ScheduledMessage::STATUS_SENT => $scheduledMessage->sent_at,
            ScheduledMessage::STATUS_SKIPPED => $scheduledMessage->skipped_at,
            ScheduledMessage::STATUS_FAILED => $scheduledMessage->failed_at,
            default => throw new InvalidArgumentException(
                "ScheduledMessage [{$scheduledMessage->getKey()}] is not terminal.",
            ),
        };
    }

    private static function terminalReason(
        ScheduledMessage $scheduledMessage,
        ?ScheduledMessageDeliveryAttempt $attempt,
    ): ?string {
        if ($scheduledMessage->status === ScheduledMessage::STATUS_SENT) {
            return null;
        }

        return self::normalizedString($attempt?->reason)
            ?? match ($scheduledMessage->status) {
                ScheduledMessage::STATUS_SKIPPED => self::normalizedString(
                    $scheduledMessage->skip_reason,
                ),
                ScheduledMessage::STATUS_FAILED => self::normalizedString(
                    $scheduledMessage->failure_reason,
                ),
                default => null,
            };
    }

    private static function normalizedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}