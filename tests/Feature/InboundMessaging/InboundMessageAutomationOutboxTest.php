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
use Mockery;
use RuntimeException;
use Tests\TestCase;

class InboundMessageAutomationOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_reply_and_automation_event_are_committed_together(): void
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
        ]);
        $this->assertDatabaseHas('automation_event_outbox_events', [
            'event_key' => RecordInboundMessageAction::NORMAL_REPLY_AUTOMATION_EVENT_KEY,
            'subject_type' => $message->getMorphClass(),
            'subject_id' => (string) $message->getKey(),
        ]);
        Event::assertDispatchedTimes(InboundMessageReceived::class, 1);
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
            'provider_event_id' => 'evt_batch_10',
            'provider_message_id' => 'msg_batch_10',
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
}