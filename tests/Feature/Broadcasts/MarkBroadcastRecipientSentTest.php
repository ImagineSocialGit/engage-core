<?php

namespace Tests\Feature\Broadcasts;

use App\Modules\Broadcasts\Listeners\MarkBroadcastRecipientSent;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MarkBroadcastRecipientSentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_a_broadcast_recipient_sent_from_a_sent_scheduled_message(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

        $broadcast = Broadcast::factory()->scheduled()->create();
        $contact = Contact::factory()->create();

        $recipient = BroadcastRecipient::factory()->scheduled([123])->create([
            'broadcast_id' => $broadcast->id,
            'contact_id' => $contact->id,
        ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->id,
            'context_type' => $broadcast->getMorphClass(),
            'context_id' => $broadcast->id,
            'status' => ScheduledMessage::STATUS_SENT,
            'sent_at' => now(),
            'meta' => [
                'broadcast_id' => $broadcast->id,
                'broadcast_recipient_id' => $recipient->id,
            ],
        ]);

        app(MarkBroadcastRecipientSent::class)->handle(
            new ScheduledMessageSent($scheduledMessage),
        );

        $recipient->refresh();

        $this->assertSame(BroadcastRecipient::STATUS_SENT, $recipient->status);
        $this->assertSame('2026-07-01 12:00:00', $recipient->sent_at->toDateTimeString());
        $this->assertNull($recipient->skip_reason);
        $this->assertSame($scheduledMessage->id, $recipient->meta['delivery']['scheduled_message_id']);
        $this->assertSame('2026-07-01T12:00:00.000000Z', $recipient->meta['delivery']['sent_at']);
    }

    public function test_it_completes_the_broadcast_when_all_recipients_are_terminal(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

        $broadcast = Broadcast::factory()->scheduled()->create();
        $contact = Contact::factory()->create();

        $recipient = BroadcastRecipient::factory()->scheduled([123])->create([
            'broadcast_id' => $broadcast->id,
            'contact_id' => $contact->id,
        ]);

        BroadcastRecipient::factory()->skipped('not_scheduled_by_messaging')->create([
            'broadcast_id' => $broadcast->id,
        ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->id,
            'context_type' => $broadcast->getMorphClass(),
            'context_id' => $broadcast->id,
            'status' => ScheduledMessage::STATUS_SENT,
            'sent_at' => now(),
            'meta' => [
                'broadcast_recipient_id' => $recipient->id,
            ],
        ]);

        app(MarkBroadcastRecipientSent::class)->handle(
            new ScheduledMessageSent($scheduledMessage),
        );

        $broadcast->refresh();

        $this->assertSame(Broadcast::STATUS_COMPLETED, $broadcast->status);
        $this->assertSame('2026-07-01 12:00:00', $broadcast->completed_at->toDateTimeString());
    }

    public function test_it_does_not_complete_the_broadcast_while_recipients_are_still_open(): void
    {
        $broadcast = Broadcast::factory()->scheduled()->create();
        $contact = Contact::factory()->create();

        $recipient = BroadcastRecipient::factory()->scheduled([123])->create([
            'broadcast_id' => $broadcast->id,
            'contact_id' => $contact->id,
        ]);

        BroadcastRecipient::factory()->scheduled([456])->create([
            'broadcast_id' => $broadcast->id,
        ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->id,
            'context_type' => $broadcast->getMorphClass(),
            'context_id' => $broadcast->id,
            'status' => ScheduledMessage::STATUS_SENT,
            'sent_at' => now(),
            'meta' => [
                'broadcast_recipient_id' => $recipient->id,
            ],
        ]);

        app(MarkBroadcastRecipientSent::class)->handle(
            new ScheduledMessageSent($scheduledMessage),
        );

        $broadcast->refresh();

        $this->assertSame(Broadcast::STATUS_SCHEDULED, $broadcast->status);
        $this->assertNull($broadcast->completed_at);
    }

    public function test_it_ignores_scheduled_messages_for_other_contexts(): void
    {
        $broadcast = Broadcast::factory()->scheduled()->create();
        $contact = Contact::factory()->create();

        $recipient = BroadcastRecipient::factory()->scheduled([123])->create([
            'broadcast_id' => $broadcast->id,
            'contact_id' => $contact->id,
        ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->id,
            'context_type' => Contact::class,
            'context_id' => $contact->id,
            'status' => ScheduledMessage::STATUS_SENT,
            'sent_at' => now(),
        ]);

        app(MarkBroadcastRecipientSent::class)->handle(
            new ScheduledMessageSent($scheduledMessage),
        );

        $recipient->refresh();
        $broadcast->refresh();

        $this->assertSame(BroadcastRecipient::STATUS_SCHEDULED, $recipient->status);
        $this->assertNull($recipient->sent_at);
        $this->assertSame(Broadcast::STATUS_SCHEDULED, $broadcast->status);
    }
}