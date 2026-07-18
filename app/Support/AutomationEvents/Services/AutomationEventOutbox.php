<?php

namespace App\Support\AutomationEvents\Services;

use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

class AutomationEventOutbox
{
    public function record(
        AutomationEventData $event,
        ?string $idempotencyKey = null,
    ): AutomationEventOutboxEvent {
        if (! $event->isValid()) {
            throw new InvalidArgumentException('A non-empty automation event key is required.');
        }

        $idempotencyKey = $this->nullableString($idempotencyKey);

        $outboxEvent = $idempotencyKey === null
            ? new AutomationEventOutboxEvent()
            : AutomationEventOutboxEvent::query()->firstOrNew([
                'idempotency_key' => $idempotencyKey,
            ]);

        if ($outboxEvent->exists) {
            $this->assertSameEvent($outboxEvent, $event);
        } else {
            $outboxEvent->forceFill([
                'event_id' => $event->hasDurableIdentity()
                    ? $event->eventId
                    : (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'event_key' => trim($event->eventKey),
                'contact_id' => $event->contactId,
                'subject_type' => $event->subjectType,
                'subject_id' => $event->subjectId !== null
                    ? (string) $event->subjectId
                    : null,
                'occurred_at' => $event->occurredAt ?? now(),
                'payload' => $event->payload,
                'meta' => $event->meta,
                'status' => AutomationEventOutboxEvent::STATUS_PENDING,
                'available_at' => now(),
                'attempts' => 0,
            ])->save();
        }

        $outboxEventId = (int) $outboxEvent->getKey();

        DB::afterCommit(function () use ($outboxEventId): void {
            $this->publish($outboxEventId);
        });

        return $outboxEvent;
    }

    public function publish(int $outboxEventId): bool
    {
        $claimed = $this->claim($outboxEventId);

        if (! $claimed instanceof AutomationEventOutboxEvent) {
            return false;
        }

        try {
            Event::dispatch(new AutomationEventRecorded(
                $this->eventData($claimed),
            ));

            $this->markPublished($claimed);

            return true;
        } catch (Throwable $exception) {
            $this->releaseForRetry($claimed, $exception);

            return false;
        }
    }

    public function publishPending(): int
    {
        $ids = AutomationEventOutboxEvent::query()
            ->readyToPublish()
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

    private function claim(int $outboxEventId): ?AutomationEventOutboxEvent
    {
        return DB::transaction(function () use ($outboxEventId): ?AutomationEventOutboxEvent {
            $outboxEvent = AutomationEventOutboxEvent::query()
                ->lockForUpdate()
                ->find($outboxEventId);

            if (! $outboxEvent instanceof AutomationEventOutboxEvent
                || $outboxEvent->status === AutomationEventOutboxEvent::STATUS_PUBLISHED
            ) {
                return null;
            }

            if ($outboxEvent->status === AutomationEventOutboxEvent::STATUS_PROCESSING
                && $outboxEvent->claim_expires_at?->isFuture()
            ) {
                return null;
            }

            if ($outboxEvent->status === AutomationEventOutboxEvent::STATUS_PENDING
                && $outboxEvent->available_at?->isFuture()
            ) {
                return null;
            }

            $claimedAt = now();

            $outboxEvent->forceFill([
                'status' => AutomationEventOutboxEvent::STATUS_PROCESSING,
                'claim_token' => (string) Str::uuid(),
                'claim_expires_at' => $claimedAt->copy()->addSeconds($this->claimLeaseSeconds()),
                'attempts' => ((int) $outboxEvent->attempts) + 1,
                'last_attempted_at' => $claimedAt,
            ])->save();

            return $outboxEvent;
        });
    }

    private function markPublished(AutomationEventOutboxEvent $claimed): void
    {
        AutomationEventOutboxEvent::query()
            ->whereKey($claimed->getKey())
            ->where('status', AutomationEventOutboxEvent::STATUS_PROCESSING)
            ->where('claim_token', $claimed->claim_token)
            ->update([
                'status' => AutomationEventOutboxEvent::STATUS_PUBLISHED,
                'claim_token' => null,
                'claim_expires_at' => null,
                'published_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);
    }

    private function releaseForRetry(
        AutomationEventOutboxEvent $claimed,
        Throwable $exception,
    ): void {
        AutomationEventOutboxEvent::query()
            ->whereKey($claimed->getKey())
            ->where('status', AutomationEventOutboxEvent::STATUS_PROCESSING)
            ->where('claim_token', $claimed->claim_token)
            ->update([
                'status' => AutomationEventOutboxEvent::STATUS_PENDING,
                'available_at' => now()->addSeconds($this->retryDelay((int) $claimed->attempts)),
                'claim_token' => null,
                'claim_expires_at' => null,
                'last_error' => $exception->getMessage(),
                'updated_at' => now(),
            ]);
    }

    private function eventData(AutomationEventOutboxEvent $outboxEvent): AutomationEventData
    {
        return new AutomationEventData(
            eventKey: $outboxEvent->event_key,
            contactId: $outboxEvent->contact_id,
            subjectType: $outboxEvent->subject_type,
            subjectId: $this->subjectId($outboxEvent->subject_id),
            occurredAt: $outboxEvent->occurred_at,
            payload: is_array($outboxEvent->payload) ? $outboxEvent->payload : [],
            meta: is_array($outboxEvent->meta) ? $outboxEvent->meta : [],
            eventId: $outboxEvent->event_id,
        );
    }

    private function subjectId(?string $value): int|string|null
    {
        if ($value === null) {
            return null;
        }

        if (ctype_digit($value)) {
            $integer = (int) $value;

            if ((string) $integer === $value) {
                return $integer;
            }
        }

        return $value;
    }

    private function assertSameEvent(
        AutomationEventOutboxEvent $stored,
        AutomationEventData $candidate,
    ): void {
        if ($stored->event_key !== trim($candidate->eventKey)
            || $stored->contact_id !== $candidate->contactId
            || $stored->subject_type !== $candidate->subjectType
            || (string) $stored->subject_id !== (string) $candidate->subjectId
        ) {
            throw new LogicException(
                "Automation event idempotency key [{$stored->idempotency_key}] was reused for a different event.",
            );
        }
    }

    private function nullableString(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function claimLeaseSeconds(): int
    {
        return max(30, (int) config(
            'automation_events.outbox.claim_lease_seconds',
            300,
        ));
    }

    private function batchSize(): int
    {
        return max(1, (int) config(
            'automation_events.outbox.batch_size',
            100,
        ));
    }

    private function retryDelay(int $attempt): int
    {
        $backoff = config(
            'automation_events.outbox.retry_backoff_seconds',
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