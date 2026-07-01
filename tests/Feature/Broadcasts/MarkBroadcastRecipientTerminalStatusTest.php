<?php

namespace Tests\Feature\Broadcasts;

use App\Modules\Broadcasts\Listeners\MarkBroadcastRecipientFailed;
use App\Modules\Broadcasts\Listeners\MarkBroadcastRecipientSkipped;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Events\ScheduledMessageFailed;
use App\Modules\Messaging\Events\ScheduledMessageSkipped;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MarkBroadcastRecipientTerminalStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_a_broadcast_recipient_skipped_from_a_skipped_scheduled_message(): void
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
            'status' => ScheduledMessage::STATUS_SKIPPED,
            'skipped_at' => now(),
            'skip_reason' => 'consent_missing',
            'meta' => [
                'broadcast_id' => $broadcast->id,
                'broadcast_recipient_id' => $recipient->id,
            ],
        ]);

        app(MarkBroadcastRecipientSkipped::class)->handle(
            new ScheduledMessageSkipped($scheduledMessage),
        );

        $recipient->refresh();

        $this->assertSame(BroadcastRecipient::STATUS_SKIPPED, $recipient->status);
        $this->assertSame('consent_missing', $recipient->skip_reason);
        $this->assertSame($scheduledMessage->id, $recipient->meta['delivery']['scheduled_message_id']);
        $this->assertSame('consent_missing', $recipient->meta['delivery']['skip_reason']);
        $this->assertSame('2026-07-01T12:00:00.000000Z', $recipient->meta['delivery']['skipped_at']);
    }

    public function test_it_marks_a_broadcast_recipient_failed_from_a_failed_scheduled_message(): void
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
            'status' => ScheduledMessage::STATUS_FAILED,
            'failed_at' => now(),
            'failure_reason' => 'Provider failure.',
            'meta' => [
                'broadcast_id' => $broadcast->id,
                'broadcast_recipient_id' => $recipient->id,
            ],
        ]);

        app(MarkBroadcastRecipientFailed::class)->handle(
            new ScheduledMessageFailed($scheduledMessage),
        );

        $recipient->refresh();

        $this->assertSame(BroadcastRecipient::STATUS_FAILED, $recipient->status);
        $this->assertNull($recipient->skip_reason);
        $this->assertSame($scheduledMessage->id, $recipient->meta['delivery']['scheduled_message_id']);
        $this->assertSame('Provider failure.', $recipient->meta['delivery']['failure_reason']);
        $this->assertSame('2026-07-01T12:00:00.000000Z', $recipient->meta['delivery']['failed_at']);
    }

    public function test_it_completes_the_broadcast_when_skipped_recipient_makes_all_recipients_terminal(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

        $broadcast = Broadcast::factory()->scheduled()->create();
        $contact = Contact::factory()->create();

        $recipient = BroadcastRecipient::factory()->scheduled([123])->create([
            'broadcast_id' => $broadcast->id,
            'contact_id' => $contact->id,
        ]);

        BroadcastRecipient::factory()->sent()->create([
            'broadcast_id' => $broadcast->id,
        ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->id,
            'context_type' => $broadcast->getMorphClass(),
            'context_id' => $broadcast->id,
            'status' => ScheduledMessage::STATUS_SKIPPED,
            'skipped_at' => now(),
            'skip_reason' => 'suppressed',
            'meta' => [
                'broadcast_recipient_id' => $recipient->id,
            ],
        ]);

        app(MarkBroadcastRecipientSkipped::class)->handle(
            new ScheduledMessageSkipped($scheduledMessage),
        );

        $broadcast->refresh();

        $this->assertSame(Broadcast::STATUS_COMPLETED, $broadcast->status);
        $this->assertSame('2026-07-01 12:00:00', $broadcast->completed_at->toDateTimeString());
    }

    public function test_it_completes_the_broadcast_when_failed_recipient_makes_all_recipients_terminal(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

        $broadcast = Broadcast::factory()->scheduled()->create();
        $contact = Contact::factory()->create();

        $recipient = BroadcastRecipient::factory()->scheduled([123])->create([
            'broadcast_id' => $broadcast->id,
            'contact_id' => $contact->id,
        ]);

        BroadcastRecipient::factory()->skipped('not_eligible')->create([
            'broadcast_id' => $broadcast->id,
        ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->id,
            'context_type' => $broadcast->getMorphClass(),
            'context_id' => $broadcast->id,
            'status' => ScheduledMessage::STATUS_FAILED,
            'failed_at' => now(),
            'failure_reason' => 'Provider failure.',
            'meta' => [
                'broadcast_recipient_id' => $recipient->id,
            ],
        ]);

        app(MarkBroadcastRecipientFailed::class)->handle(
            new ScheduledMessageFailed($scheduledMessage),
        );

        $broadcast->refresh();

        $this->assertSame(Broadcast::STATUS_COMPLETED, $broadcast->status);
        $this->assertSame('2026-07-01 12:00:00', $broadcast->completed_at->toDateTimeString());
    }

    public function test_it_does_not_change_terminal_recipients(): void
    {
        $broadcast = Broadcast::factory()->scheduled()->create();
        $contact = Contact::factory()->create();

        $recipient = BroadcastRecipient::factory()->sent()->create([
            'broadcast_id' => $broadcast->id,
            'contact_id' => $contact->id,
        ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->id,
            'context_type' => $broadcast->getMorphClass(),
            'context_id' => $broadcast->id,
            'status' => ScheduledMessage::STATUS_SKIPPED,
            'skipped_at' => now(),
            'skip_reason' => 'suppressed',
            'meta' => [
                'broadcast_recipient_id' => $recipient->id,
            ],
        ]);

        app(MarkBroadcastRecipientSkipped::class)->handle(
            new ScheduledMessageSkipped($scheduledMessage),
        );

        $recipient->refresh();

        $this->assertSame(BroadcastRecipient::STATUS_SENT, $recipient->status);
        $this->assertNotNull($recipient->sent_at);
        $this->assertNull($recipient->skip_reason);
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
            'status' => ScheduledMessage::STATUS_SKIPPED,
            'skipped_at' => now(),
            'skip_reason' => 'suppressed',
        ]);

        app(MarkBroadcastRecipientSkipped::class)->handle(
            new ScheduledMessageSkipped($scheduledMessage),
        );

        $recipient->refresh();
        $broadcast->refresh();

        $this->assertSame(BroadcastRecipient::STATUS_SCHEDULED, $recipient->status);
        $this->assertNull($recipient->skip_reason);
        $this->assertSame(Broadcast::STATUS_SCHEDULED, $broadcast->status);
    }
}