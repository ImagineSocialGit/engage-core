<?php

namespace Tests\Feature\Broadcasts;

use App\Modules\Broadcasts\Actions\CancelBroadcastAction;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancelBroadcastActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_cancels_a_scheduled_broadcast_through_messaging(): void
    {
        $broadcast = Broadcast::factory()->scheduled()->create([
            'meta' => [
                'created_from' => 'test',
            ],
        ]);

        $contact = Contact::factory()->create();

        $recipient = BroadcastRecipient::factory()->scheduled()->create([
            'broadcast_id' => $broadcast->id,
            'contact_id' => $contact->id,
        ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->id,
            'context_type' => $broadcast->getMorphClass(),
            'context_id' => $broadcast->id,
            'status' => ScheduledMessage::STATUS_PENDING,
        ]);

        $cancelledBroadcast = app(CancelBroadcastAction::class)->handle($broadcast);

        $this->assertSame(Broadcast::STATUS_CANCELLED, $cancelledBroadcast->status);
        $this->assertNotNull($cancelledBroadcast->cancelled_at);
        $this->assertSame('test', $cancelledBroadcast->meta['created_from']);
        $this->assertSame('broadcast_cancelled', $cancelledBroadcast->meta['cancellation']['reason']);
        $this->assertSame(1, $cancelledBroadcast->meta['cancellation']['skipped_scheduled_message_count']);

        $recipient->refresh();
        $this->assertSame(BroadcastRecipient::STATUS_CANCELLED, $recipient->status);
        $this->assertSame('broadcast_cancelled', $recipient->skip_reason);

        $scheduledMessage->refresh();
        $this->assertSame(ScheduledMessage::STATUS_SKIPPED, $scheduledMessage->status);
        $this->assertSame('broadcast_cancelled', $scheduledMessage->skip_reason);
        $this->assertNotNull($scheduledMessage->skipped_at);
    }

    public function test_it_uses_a_custom_cancellation_reason(): void
    {
        $broadcast = Broadcast::factory()->scheduled()->create();
        $contact = Contact::factory()->create();

        $recipient = BroadcastRecipient::factory()->scheduled()->create([
            'broadcast_id' => $broadcast->id,
            'contact_id' => $contact->id,
        ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->id,
            'context_type' => $broadcast->getMorphClass(),
            'context_id' => $broadcast->id,
            'status' => ScheduledMessage::STATUS_PENDING,
        ]);

        $cancelledBroadcast = app(CancelBroadcastAction::class)->handle(
            broadcast: $broadcast,
            reason: 'admin_cancelled',
        );

        $this->assertSame('admin_cancelled', $cancelledBroadcast->meta['cancellation']['reason']);

        $recipient->refresh();
        $this->assertSame(BroadcastRecipient::STATUS_CANCELLED, $recipient->status);
        $this->assertSame('admin_cancelled', $recipient->skip_reason);

        $scheduledMessage->refresh();
        $this->assertSame(ScheduledMessage::STATUS_SKIPPED, $scheduledMessage->status);
        $this->assertSame('admin_cancelled', $scheduledMessage->skip_reason);
    }

    public function test_it_does_not_change_terminal_recipients(): void
    {
        $broadcast = Broadcast::factory()->scheduled()->create();

        $sentRecipient = BroadcastRecipient::factory()->create([
            'broadcast_id' => $broadcast->id,
            'status' => BroadcastRecipient::STATUS_SENT,
        ]);

        $skippedRecipient = BroadcastRecipient::factory()->skipped('not_eligible')->create([
            'broadcast_id' => $broadcast->id,
        ]);

        $failedRecipient = BroadcastRecipient::factory()->failed()->create([
            'broadcast_id' => $broadcast->id,
        ]);

        app(CancelBroadcastAction::class)->handle($broadcast);

        $sentRecipient->refresh();
        $skippedRecipient->refresh();
        $failedRecipient->refresh();

        $this->assertSame(BroadcastRecipient::STATUS_SENT, $sentRecipient->status);
        $this->assertNull($sentRecipient->skip_reason);

        $this->assertSame(BroadcastRecipient::STATUS_SKIPPED, $skippedRecipient->status);
        $this->assertSame('not_eligible', $skippedRecipient->skip_reason);

        $this->assertSame(BroadcastRecipient::STATUS_FAILED, $failedRecipient->status);
        $this->assertNull($failedRecipient->skip_reason);
    }

    public function test_it_only_skips_pending_scheduled_messages(): void
    {
        $broadcast = Broadcast::factory()->scheduled()->create();
        $contact = Contact::factory()->create();

        $pendingMessage = ScheduledMessage::factory()->create([
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->id,
            'context_type' => $broadcast->getMorphClass(),
            'context_id' => $broadcast->id,
            'status' => ScheduledMessage::STATUS_PENDING,
        ]);

        $sentMessage = ScheduledMessage::factory()->create([
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->id,
            'context_type' => $broadcast->getMorphClass(),
            'context_id' => $broadcast->id,
            'status' => ScheduledMessage::STATUS_SENT,
        ]);

        app(CancelBroadcastAction::class)->handle($broadcast);

        $pendingMessage->refresh();
        $sentMessage->refresh();

        $this->assertSame(ScheduledMessage::STATUS_SKIPPED, $pendingMessage->status);
        $this->assertSame(ScheduledMessage::STATUS_SENT, $sentMessage->status);
    }
}