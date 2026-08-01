<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Contracts\Email\EmailMessage;
use App\Modules\Messaging\Data\Delivery\MessageSendResult;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageDeliveryAttempt;
use App\Modules\Messaging\Models\ScheduledMessageOutboxEvent;
use App\Modules\Messaging\Services\Email\EmailMessagingService;
use App\Modules\Messaging\Services\ScheduledMessageEventOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Event;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ScheduledMessageEventOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_listener_failure_retries_without_resubmitting_to_provider(): void
    {
        config()->set(
            'messaging.delivery.event_outbox.retry_backoff_seconds',
            [0],
        );

        $contact = Contact::factory()->create([
            'email' => 'listener-failure@example.com',
        ]);

        MessageConsent::query()->create([
            'contact_id' => $contact->getKey(),
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'consented_at' => now()->subMinute(),
            'source' => 'test',
        ]);

        $scheduledMessage = ScheduledMessage::factory()->email()->create([
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->getKey(),
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'outbox_fault_injection',
            'payload_class' => OutboxTestEmailPayload::class,
            'payload' => [
                'to' => $contact->email,
            ],
            'status' => ScheduledMessage::STATUS_PENDING,
            'meta' => [
                'conditions' => [],
            ],
        ]);

        $emailService = Mockery::mock(EmailMessagingService::class);
        $emailService
            ->shouldReceive('send')
            ->once()
            ->with(Mockery::type(OutboxTestEmailPayload::class))
            ->andReturn(MessageSendResult::sent(
                provider: 'fault_injection_email',
                providerMessageId: 'provider-message-1',
            ));

        app()->instance(EmailMessagingService::class, $emailService);

        $listenerAttempts = 0;
        $terminalResults = [];

        Event::listen(ScheduledMessageSent::class, function (
            ScheduledMessageSent $event,
        ) use (&$listenerAttempts, &$terminalResults): void {
            $listenerAttempts++;
            $terminalResults[] = [
                'scheduled_message_id' => $event->terminalResult->scheduledMessageId,
                'status' => $event->terminalResult->status,
                'attempt_number' => $event->terminalResult->attemptNumber,
                'provider' => $event->terminalResult->provider,
                'provider_message_id' => $event->terminalResult->providerMessageId,
                'occurred_at' => $event->terminalResult->occurredAt->toISOString(),
            ];

            if ($listenerAttempts === 1) {
                throw new RuntimeException('Injected downstream listener failure.');
            }
        });

        app()->call([
            new SendScheduledMessageJob((int) $scheduledMessage->getKey()),
            'handle',
        ]);

        $scheduledMessage->refresh();
        $outboxEvent = ScheduledMessageOutboxEvent::query()->firstOrFail();

        $attempt = ScheduledMessageDeliveryAttempt::query()->firstOrFail();

        $this->assertSame(ScheduledMessage::STATUS_SENT, $scheduledMessage->status);
        $this->assertSame(1, ScheduledMessageDeliveryAttempt::query()->count());
        $this->assertSame('provider-message-1', $attempt->provider_message_id);
        $this->assertSame(ScheduledMessage::STATUS_SENT, $outboxEvent->event_type);
        $this->assertSame($attempt->getKey(), $outboxEvent->delivery_attempt_id);
        $this->assertSame(
            $attempt->completed_at?->toISOString(),
            $outboxEvent->occurred_at?->toISOString(),
        );
        $this->assertParentDeliverySummaryUnwritten($scheduledMessage);
        $this->assertSame(ScheduledMessageOutboxEvent::STATUS_PENDING, $outboxEvent->status);
        $this->assertSame(1, $outboxEvent->attempts);
        $this->assertSame('Injected downstream listener failure.', $outboxEvent->last_error);
        $this->assertSame(1, $listenerAttempts);
        $this->assertEquals([
            'scheduled_message_id' => $scheduledMessage->id,
            'status' => ScheduledMessage::STATUS_SENT,
            'attempt_number' => 1,
            'provider' => 'fault_injection_email',
            'provider_message_id' => 'provider-message-1',
            'occurred_at' => $outboxEvent->occurred_at->toISOString(),
        ], $terminalResults[0]);

        $this->assertSame(
            1,
            app(ScheduledMessageEventOutbox::class)->publishPending(),
        );

        $outboxEvent->refresh();

        $this->assertSame(ScheduledMessageOutboxEvent::STATUS_PUBLISHED, $outboxEvent->status);
        $this->assertSame(2, $outboxEvent->attempts);
        $this->assertNotNull($outboxEvent->published_at);
        $this->assertNull($outboxEvent->last_error);
        $this->assertSame(2, $listenerAttempts);
        $this->assertEquals($terminalResults[0], $terminalResults[1]);

        app()->call([
            new SendScheduledMessageJob((int) $scheduledMessage->getKey()),
            'handle',
        ]);

        $this->assertSame(
            1,
            ScheduledMessageDeliveryAttempt::query()
                ->where('scheduled_message_id', $scheduledMessage->getKey())
                ->count(),
        );
    }
    private function assertParentDeliverySummaryUnwritten(
        ScheduledMessage $message,
    ): void {
        $this->assertNull($message->sending_at);
        $this->assertNull($message->last_attempted_at);
        $this->assertSame(0, (int) $message->send_attempts);
        $this->assertNull($message->provider);
        $this->assertNull($message->provider_message_id);
        $this->assertNull($message->sent_at);
        $this->assertNull($message->skipped_at);
        $this->assertNull($message->failed_at);
        $this->assertNull($message->failure_reason);
        $this->assertNull($message->skip_reason);
    }

}

class OutboxTestEmailPayload implements EmailMessage
{
    public function __construct(
        private readonly string $to,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self((string) $payload['to']);
    }

    public function to(): string
    {
        return $this->to;
    }

    public function mailable(): Mailable
    {
        return new class extends Mailable {};
    }

    public function devPayload(): array
    {
        return ['to' => $this->to];
    }
}