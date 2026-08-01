<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Data\Delivery\ScheduledMessageTerminalResult;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Events\ScheduledMessageSkipped;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageDeliveryAttempt;
use App\Modules\Messaging\Models\ScheduledMessageOutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class ScheduledMessageTerminalEventContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_derives_an_immutable_terminal_result_from_the_durable_outbox_attempt(): void
    {
        $message = ScheduledMessage::factory()->create([
            'status' => ScheduledMessage::STATUS_SENT,
        ]);
        $completedAt = now();

        $attempt = ScheduledMessageDeliveryAttempt::query()->create([
            'scheduled_message_id' => $message->getKey(),
            'attempt_number' => 2,
            'claim_token' => 'terminal-event-contract',
            'status' => ScheduledMessageDeliveryAttempt::STATUS_SENT,
            'claimed_at' => now()->subMinutes(2),
            'lease_expires_at' => now()->subMinute(),
            'provider_submission_started_at' => now()->subMinutes(2),
            'completed_at' => $completedAt,
            'provider' => 'authoritative_provider',
            'provider_message_id' => 'authoritative-provider-message',
        ]);

        ScheduledMessageOutboxEvent::query()->create([
            'scheduled_message_id' => $message->getKey(),
            'delivery_attempt_id' => $attempt->getKey(),
            'event_type' => ScheduledMessage::STATUS_SENT,
            'occurred_at' => $completedAt,
            'status' => ScheduledMessageOutboxEvent::STATUS_PUBLISHED,
            'available_at' => $completedAt,
            'attempts' => 1,
            'last_attempted_at' => $completedAt,
            'published_at' => $completedAt,
        ]);

        $event = new ScheduledMessageSent(
            $message->load('terminalOutboxEvent.deliveryAttempt'),
        );

        $this->assertSame($message->getKey(), $event->terminalResult->scheduledMessageId);
        $this->assertSame(ScheduledMessage::STATUS_SENT, $event->terminalResult->status);
        $this->assertSame($attempt->getKey(), $event->terminalResult->deliveryAttemptId);
        $this->assertSame(2, $event->terminalResult->attemptNumber);
        $this->assertSame('authoritative_provider', $event->terminalResult->provider);
        $this->assertSame(
            'authoritative-provider-message',
            $event->terminalResult->providerMessageId,
        );
        $this->assertSame(
            $completedAt->startOfSecond()->toISOString(),
            $event->terminalResult->occurredAt->startOfSecond()->toISOString(),
        );
        $this->assertNull($event->terminalResult->reason);
    }

    public function test_direct_skip_ignores_a_nonterminal_delivery_attempt_summary(): void
    {
        $skippedAt = now()->toImmutable();
        $message = ScheduledMessage::factory()->create([
            'status' => ScheduledMessage::STATUS_SKIPPED,
        ]);

        ScheduledMessageDeliveryAttempt::query()->create([
            'scheduled_message_id' => $message->getKey(),
            'attempt_number' => 1,
            'claim_token' => 'released-before-direct-skip',
            'status' => ScheduledMessageDeliveryAttempt::STATUS_RELEASED,
            'claimed_at' => now()->subMinutes(5),
            'lease_expires_at' => now()->subMinutes(4),
            'completed_at' => now()->subMinutes(3),
            'reason_code' => 'message_delivery_retryable_exception',
            'reason' => 'Retryable provider exception.',
        ]);

        ScheduledMessageOutboxEvent::query()->create([
            'scheduled_message_id' => $message->getKey(),
            'delivery_attempt_id' => null,
            'event_type' => ScheduledMessage::STATUS_SKIPPED,
            'occurred_at' => $skippedAt,
            'reason_code' => 'cancelled_by_owner',
            'reason' => 'cancelled_by_owner',
            'status' => ScheduledMessageOutboxEvent::STATUS_PUBLISHED,
            'available_at' => $skippedAt,
            'attempts' => 0,
            'published_at' => $skippedAt,
        ]);

        $event = new ScheduledMessageSkipped(
            $message->load('terminalOutboxEvent.deliveryAttempt'),
        );

        $this->assertNull($event->terminalResult->deliveryAttemptId);
        $this->assertSame('cancelled_by_owner', $event->terminalResult->reason);
        $this->assertSame(
            $skippedAt->copy()->startOfSecond()->toISOString(),
            $event->terminalResult->occurredAt->copy()->startOfSecond()->toISOString(),
        );
    }

    public function test_event_requires_a_durable_terminal_outbox_record(): void
    {
        $message = ScheduledMessage::factory()->create([
            'status' => ScheduledMessage::STATUS_SENT,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            "ScheduledMessage [{$message->getKey()}] has no durable terminal outbox event.",
        );

        new ScheduledMessageSent($message);
    }

    public function test_event_rejects_a_terminal_result_for_another_message(): void
    {
        $message = ScheduledMessage::factory()->create([
            'status' => ScheduledMessage::STATUS_SENT,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'ScheduledMessageSent requires a matching sent terminal result.',
        );

        new ScheduledMessageSent(
            $message,
            new ScheduledMessageTerminalResult(
                scheduledMessageId: ((int) $message->getKey()) + 1,
                status: ScheduledMessage::STATUS_SENT,
                occurredAt: now()->toImmutable(),
            ),
        );
    }
}