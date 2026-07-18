<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Events\ScheduledMessageFailed;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Events\ScheduledMessageSkipped;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageOutboxEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

class ScheduledMessageEventOutbox
{
    public function record(
        ScheduledMessage $scheduledMessage,
        string $eventType,
        ?CarbonInterface $occurredAt = null,
    ): ScheduledMessageOutboxEvent {
        $this->assertEventType($eventType);

        $outboxEvent = ScheduledMessageOutboxEvent::query()
            ->firstOrNew([
                'scheduled_message_id' => $scheduledMessage->getKey(),
            ]);

        if ($outboxEvent->exists && $outboxEvent->event_type !== $eventType) {
            throw new LogicException(
                "ScheduledMessage [{$scheduledMessage->getKey()}] already has terminal outbox event [{$outboxEvent->event_type}].",
            );
        }

        if (! $outboxEvent->exists) {
            $outboxEvent->forceFill([
                'event_type' => $eventType,
                'status' => ScheduledMessageOutboxEvent::STATUS_PENDING,
                'available_at' => $occurredAt ?? now(),
                'attempts' => 0,
            ])->save();
        }

        $outboxEventId = (int) $outboxEvent->getKey();

        DB::afterCommit(function () use ($outboxEventId): void {
            $this->publish($outboxEventId);
        });

        return $outboxEvent;
    }

    public function publishFor(ScheduledMessage $scheduledMessage): bool
    {
        $outboxEvent = ScheduledMessageOutboxEvent::query()
            ->where('scheduled_message_id', $scheduledMessage->getKey())
            ->first();

        return $outboxEvent instanceof ScheduledMessageOutboxEvent
            ? $this->publish((int) $outboxEvent->getKey())
            : false;
    }

    public function publish(int $outboxEventId): bool
    {
        $claimed = $this->claim($outboxEventId);

        if (! $claimed instanceof ScheduledMessageOutboxEvent) {
            return false;
        }

        try {
            Event::dispatch($this->domainEvent($claimed));
            $this->markPublished($claimed);

            return true;
        } catch (Throwable $exception) {
            $this->releaseForRetry($claimed, $exception);

            return false;
        }
    }

    public function publishPending(): int
    {
        $now = now();
        $ids = ScheduledMessageOutboxEvent::query()
            ->where(function ($query) use ($now): void {
                $query
                    ->where(function ($pending) use ($now): void {
                        $pending
                            ->where('status', ScheduledMessageOutboxEvent::STATUS_PENDING)
                            ->where('available_at', '<=', $now);
                    })
                    ->orWhere(function ($processing) use ($now): void {
                        $processing
                            ->where('status', ScheduledMessageOutboxEvent::STATUS_PROCESSING)
                            ->where('claim_expires_at', '<=', $now);
                    });
            })
            ->orderBy('id')
            ->limit($this->batchSize())
            ->pluck('id');

        $published = 0;

        foreach ($ids as $id) {
            if ($this->publish((int) $id)) {
                $published++;
            }
        }

        return $published;
    }

    private function claim(int $outboxEventId): ?ScheduledMessageOutboxEvent
    {
        return DB::transaction(function () use ($outboxEventId): ?ScheduledMessageOutboxEvent {
            $outboxEvent = ScheduledMessageOutboxEvent::query()
                ->lockForUpdate()
                ->find($outboxEventId);

            if (! $outboxEvent instanceof ScheduledMessageOutboxEvent
                || $outboxEvent->status === ScheduledMessageOutboxEvent::STATUS_PUBLISHED
            ) {
                return null;
            }

            if ($outboxEvent->status === ScheduledMessageOutboxEvent::STATUS_PROCESSING
                && $outboxEvent->claim_expires_at?->isFuture()
            ) {
                return null;
            }

            if ($outboxEvent->status === ScheduledMessageOutboxEvent::STATUS_PENDING
                && $outboxEvent->available_at?->isFuture()
            ) {
                return null;
            }

            $claimedAt = now();

            $outboxEvent->forceFill([
                'status' => ScheduledMessageOutboxEvent::STATUS_PROCESSING,
                'claim_token' => (string) Str::uuid(),
                'claim_expires_at' => $claimedAt->copy()->addSeconds($this->claimLeaseSeconds()),
                'attempts' => ((int) $outboxEvent->attempts) + 1,
                'last_attempted_at' => $claimedAt,
            ])->save();

            return $outboxEvent;
        });
    }

    private function markPublished(ScheduledMessageOutboxEvent $claimed): void
    {
        ScheduledMessageOutboxEvent::query()
            ->whereKey($claimed->getKey())
            ->where('status', ScheduledMessageOutboxEvent::STATUS_PROCESSING)
            ->where('claim_token', $claimed->claim_token)
            ->update([
                'status' => ScheduledMessageOutboxEvent::STATUS_PUBLISHED,
                'claim_token' => null,
                'claim_expires_at' => null,
                'published_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);
    }

    private function releaseForRetry(
        ScheduledMessageOutboxEvent $claimed,
        Throwable $exception,
    ): void {
        ScheduledMessageOutboxEvent::query()
            ->whereKey($claimed->getKey())
            ->where('status', ScheduledMessageOutboxEvent::STATUS_PROCESSING)
            ->where('claim_token', $claimed->claim_token)
            ->update([
                'status' => ScheduledMessageOutboxEvent::STATUS_PENDING,
                'available_at' => now()->addSeconds($this->retryDelay((int) $claimed->attempts)),
                'claim_token' => null,
                'claim_expires_at' => null,
                'last_error' => $exception->getMessage(),
                'updated_at' => now(),
            ]);
    }

    private function domainEvent(ScheduledMessageOutboxEvent $outboxEvent): object
    {
        $scheduledMessage = ScheduledMessage::query()
            ->find($outboxEvent->scheduled_message_id);

        if (! $scheduledMessage instanceof ScheduledMessage) {
            throw new RuntimeException(
                "ScheduledMessage [{$outboxEvent->scheduled_message_id}] no longer exists for outbox event [{$outboxEvent->getKey()}].",
            );
        }

        if ($scheduledMessage->status !== $outboxEvent->event_type) {
            throw new LogicException(
                "ScheduledMessage [{$scheduledMessage->getKey()}] status [{$scheduledMessage->status}] does not match outbox event [{$outboxEvent->event_type}].",
            );
        }

        return match ($outboxEvent->event_type) {
            ScheduledMessage::STATUS_SENT => new ScheduledMessageSent($scheduledMessage),
            ScheduledMessage::STATUS_SKIPPED => new ScheduledMessageSkipped($scheduledMessage),
            ScheduledMessage::STATUS_FAILED => new ScheduledMessageFailed($scheduledMessage),
            default => throw new InvalidArgumentException(
                "Unsupported ScheduledMessage outbox event [{$outboxEvent->event_type}].",
            ),
        };
    }

    private function assertEventType(string $eventType): void
    {
        if (! in_array($eventType, [
            ScheduledMessage::STATUS_SENT,
            ScheduledMessage::STATUS_SKIPPED,
            ScheduledMessage::STATUS_FAILED,
        ], true)) {
            throw new InvalidArgumentException(
                "Unsupported ScheduledMessage outbox event [{$eventType}].",
            );
        }
    }

    private function claimLeaseSeconds(): int
    {
        return max(30, (int) config(
            'messaging.delivery.event_outbox.claim_lease_seconds',
            300,
        ));
    }

    private function batchSize(): int
    {
        return max(1, (int) config(
            'messaging.delivery.event_outbox.batch_size',
            100,
        ));
    }

    private function retryDelay(int $attempt): int
    {
        $backoff = config(
            'messaging.delivery.event_outbox.retry_backoff_seconds',
            [60, 300, 900],
        );

        if (! is_array($backoff) || $backoff === []) {
            return 60;
        }

        $backoff = array_values(array_filter(array_map(
            static fn (mixed $seconds): ?int => is_numeric($seconds) && (int) $seconds >= 0
                ? (int) $seconds
                : null,
            $backoff,
        ), static fn (?int $seconds): bool => $seconds !== null));

        if ($backoff === []) {
            return 60;
        }

        return $backoff[min(max(1, $attempt) - 1, count($backoff) - 1)];
    }
}