<?php

namespace App\Modules\Messaging\Data\Delivery;

use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageDeliveryAttempt;
use App\Modules\Messaging\Models\ScheduledMessageOutboxEvent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use LogicException;

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
        $outboxEvent = $scheduledMessage->relationLoaded('terminalOutboxEvent')
            ? $scheduledMessage->getRelation('terminalOutboxEvent')
            : $scheduledMessage->terminalOutboxEvent()
                ->with('deliveryAttempt')
                ->first();

        if ($outboxEvent instanceof ScheduledMessageOutboxEvent) {
            if ($scheduledMessage->status !== $outboxEvent->event_type) {
                throw new LogicException(
                    "ScheduledMessage [{$scheduledMessage->getKey()}] status [{$scheduledMessage->status}] does not match terminal outbox event [{$outboxEvent->event_type}].",
                );
            }

            if (! $outboxEvent->relationLoaded('deliveryAttempt')) {
                $outboxEvent->load('deliveryAttempt');
            }

            return self::fromOutboxEvent($outboxEvent);
        }

        return self::fromLegacyScheduledMessage($scheduledMessage);
    }

    public static function fromOutboxEvent(
        ScheduledMessageOutboxEvent $outboxEvent,
    ): self {
        $attempt = $outboxEvent->relationLoaded('deliveryAttempt')
            ? $outboxEvent->getRelation('deliveryAttempt')
            : $outboxEvent->deliveryAttempt()->first();

        if ($outboxEvent->occurred_at === null) {
            throw new LogicException(
                "ScheduledMessage outbox event [{$outboxEvent->getKey()}] has no terminal occurrence.",
            );
        }

        self::assertAttemptMatches($outboxEvent, $attempt);

        return new self(
            scheduledMessageId: (int) $outboxEvent->scheduled_message_id,
            status: (string) $outboxEvent->event_type,
            occurredAt: CarbonImmutable::instance($outboxEvent->occurred_at),
            deliveryAttemptId: $attempt?->getKey() !== null
                ? (int) $attempt->getKey()
                : null,
            attemptNumber: $attempt?->attempt_number !== null
                ? (int) $attempt->attempt_number
                : null,
            provider: self::normalizedString($attempt?->provider),
            providerMessageId: self::normalizedString(
                $attempt?->provider_message_id,
            ),
            reasonCode: self::normalizedString($attempt?->reason_code)
                ?? self::normalizedString($outboxEvent->reason_code),
            reason: self::normalizedString($attempt?->reason)
                ?? self::normalizedString($outboxEvent->reason),
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

    private static function fromLegacyScheduledMessage(
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

    private static function assertAttemptMatches(
        ScheduledMessageOutboxEvent $outboxEvent,
        mixed $attempt,
    ): void {
        if ($attempt === null) {
            if ($outboxEvent->delivery_attempt_id !== null) {
                throw new LogicException(
                    "ScheduledMessage outbox event [{$outboxEvent->getKey()}] references a missing delivery attempt.",
                );
            }

            if ($outboxEvent->event_type !== ScheduledMessage::STATUS_SKIPPED) {
                throw new LogicException(
                    "ScheduledMessage terminal event [{$outboxEvent->event_type}] requires a delivery attempt.",
                );
            }

            return;
        }

        if (! $attempt instanceof ScheduledMessageDeliveryAttempt
            || (int) $attempt->scheduled_message_id !== (int) $outboxEvent->scheduled_message_id
        ) {
            throw new LogicException(
                "ScheduledMessage outbox event [{$outboxEvent->getKey()}] has a mismatched delivery attempt.",
            );
        }

        $expectedAttemptStatus = match ($outboxEvent->event_type) {
            ScheduledMessage::STATUS_SENT => ScheduledMessageDeliveryAttempt::STATUS_SENT,
            ScheduledMessage::STATUS_SKIPPED => ScheduledMessageDeliveryAttempt::STATUS_SKIPPED,
            ScheduledMessage::STATUS_FAILED => ScheduledMessageDeliveryAttempt::STATUS_FAILED,
            default => throw new InvalidArgumentException(
                "Unsupported ScheduledMessage outbox event [{$outboxEvent->event_type}].",
            ),
        };

        if ($attempt->status !== $expectedAttemptStatus
            || $attempt->completed_at === null
        ) {
            throw new LogicException(
                "ScheduledMessage delivery attempt [{$attempt->getKey()}] is not the matching terminal attempt for [{$outboxEvent->event_type}].",
            );
        }
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