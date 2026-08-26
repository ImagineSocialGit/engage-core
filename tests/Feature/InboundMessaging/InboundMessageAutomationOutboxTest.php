<?php

namespace Tests\Feature\InboundMessaging;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Actions\RecordInboundMessageAction;
use App\Modules\InboundMessaging\Events\InboundMessageReceived;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use App\Support\AutomationEvents\Services\AutomationEventOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class InboundMessageAutomationOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_reply_message_identity_and_automation_event_are_committed_together(): void
    {
        Event::fake([
            InboundMessageReceived::class,
            AutomationEventRecorded::class,
        ]);

        $contact = Contact::factory()->create();

        $message = app(RecordInboundMessageAction::class)->handle(
            data: $this->normalReplyData(),
            sender: $contact,
        );

        $this->assertDatabaseHas('inbound_messages', [
            'id' => $message->getKey(),
            'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            'provider_event_key' => $this->providerKey('event', 'evt_batch_11'),
            'provider_message_key' => $this->providerKey('message', 'msg_batch_11'),
        ]);
        $this->assertDatabaseHas('automation_event_outbox_events', [
            'event_key' => RecordInboundMessageAction::NORMAL_REPLY_AUTOMATION_EVENT_KEY,
            'subject_type' => $message->getMorphClass(),
            'subject_id' => (string) $message->getKey(),
        ]);
        $this->assertFalse(Schema::hasTable('inbound_message_receipts'));
        $this->assertFalse(Schema::hasColumn('inbound_messages', 'meta'));
        Event::assertNotDispatched(InboundMessageReceived::class);
    }

    public function test_repeated_provider_identity_returns_one_message_and_one_automation_event(): void
    {
        Event::fake([
            InboundMessageReceived::class,
            AutomationEventRecorded::class,
        ]);

        $contact = Contact::factory()->create();
        $action = app(RecordInboundMessageAction::class);
        $data = $this->normalReplyData();

        $first = $action->handle($data, $contact);
        $sameMessage = $action->handle([
            ...$data,
            'provider_event_id' => 'evt_batch_11_retry',
        ], $contact);
        $sameEvent = $action->handle([
            ...$data,
            'provider_message_id' => 'msg_batch_11_retry',
        ], $contact);

        $this->assertTrue($first->is($sameMessage));
        $this->assertTrue($first->is($sameEvent));
        $this->assertDatabaseCount('inbound_messages', 1);
        $this->assertDatabaseCount('automation_event_outbox_events', 1);
        Event::assertNotDispatched(InboundMessageReceived::class);
    }

    public function test_event_and_message_identity_cannot_point_to_different_inbound_messages(): void
    {
        $action = app(RecordInboundMessageAction::class);

        $action->handle([
            ...$this->normalReplyData(),
            'provider_event_id' => 'evt-one',
            'provider_message_id' => 'msg-one',
        ]);
        $action->handle([
            ...$this->normalReplyData(),
            'provider_event_id' => 'evt-two',
            'provider_message_id' => 'msg-two',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Inbound provider event and message identifiers resolve to different messages.',
        );

        $action->handle([
            ...$this->normalReplyData(),
            'provider_event_id' => 'evt-one',
            'provider_message_id' => 'msg-two',
        ]);
    }

    public function test_inbound_message_rolls_back_when_its_outbox_record_cannot_be_written(): void
    {
        Event::fake([InboundMessageReceived::class]);

        $outbox = Mockery::mock(AutomationEventOutbox::class);
        $outbox->shouldReceive('record')
            ->once()
            ->andThrow(new RuntimeException('Simulated inbound outbox failure.'));
        app()->instance(AutomationEventOutbox::class, $outbox);

        $contact = Contact::factory()->create();

        try {
            app(RecordInboundMessageAction::class)->handle(
                data: $this->normalReplyData(),
                sender: $contact,
            );
            $this->fail('The inbound message should have rolled back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated inbound outbox failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('inbound_messages', 0);
        $this->assertDatabaseCount('automation_event_outbox_events', 0);
        Event::assertNotDispatched(InboundMessageReceived::class);
    }

    /** @return array<string, mixed> */
    private function normalReplyData(): array
    {
        return [
            'channel' => 'sms',
            'provider' => 'telnyx',
            'provider_event_id' => 'evt_batch_11',
            'provider_message_id' => 'msg_batch_11',
            'from_type' => 'phone',
            'from_value' => '+15551234567',
            'to_type' => 'phone',
            'to_value' => '+15550001111',
            'body' => 'Please call me.',
            'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            'purpose' => 'marketing',
            'received_at' => now(),
        ];
    }

    private function providerKey(string $type, string $identifier): string
    {
        return hash('sha256', implode("\0", [
            (string) config('client.key', ''),
            'telnyx',
            $type,
            $identifier,
        ]));
    }
}