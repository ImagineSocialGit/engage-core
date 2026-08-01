<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Messaging\Actions\SkipScheduledMessagesAction;
use App\Modules\Messaging\Data\Delivery\ScheduledMessageTerminalResult;
use App\Modules\Messaging\Events\ScheduledMessageSkipped;
use App\Modules\Messaging\Models\ContactPermissionInvitation;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\ContactPermissionInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SkipScheduledMessagesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_context_skip_emits_one_lifecycle_event_for_each_newly_skipped_message(): void
    {
        Event::fake([ScheduledMessageSkipped::class]);

        $batch = ContactImportBatch::factory()->create();

        $pending = ScheduledMessage::factory()
            ->count(2)
            ->create([
                'context_type' => $batch->getMorphClass(),
                'context_id' => $batch->getKey(),
                'status' => ScheduledMessage::STATUS_PENDING,
            ]);

        ScheduledMessage::factory()->sent()->create([
            'context_type' => $batch->getMorphClass(),
            'context_id' => $batch->getKey(),
        ]);

        $skipped = app(SkipScheduledMessagesAction::class)->forContext(
            context: $batch,
            reason: 'batch_cancelled',
        );

        $this->assertSame(2, $skipped);
        $this->assertDatabaseCount('scheduled_messages', 3);

        foreach ($pending as $message) {
            $this->assertDatabaseHas('scheduled_messages', [
                'id' => $message->getKey(),
                'status' => ScheduledMessage::STATUS_SKIPPED,
            ]);
            $message->refresh();
            $this->assertParentDeliverySummaryUnwritten($message);
            $this->assertDatabaseHas('scheduled_message_outbox_events', [
                'scheduled_message_id' => $message->getKey(),
                'delivery_attempt_id' => null,
                'event_type' => ScheduledMessage::STATUS_SKIPPED,
                'reason_code' => 'scheduled_message_skipped_before_claim',
                'reason' => 'batch_cancelled',
            ]);
        }

        Event::assertDispatchedTimes(ScheduledMessageSkipped::class, 2);
        Event::assertDispatched(
            ScheduledMessageSkipped::class,
            fn (ScheduledMessageSkipped $event): bool => in_array(
                $event->scheduledMessage->getKey(),
                $pending->modelKeys(),
                true,
            ),
        );
    }

    public function test_bulk_permission_invitation_skip_reconciles_a_claimed_invitation_through_the_event_listener(): void
    {
        $batch = ContactImportBatch::factory()->create();
        $contact = Contact::factory()->create([
            'email' => 'imported@example.test',
            'source' => 'import',
            'contact_import_batch_id' => $batch->getKey(),
        ]);

        $message = ScheduledMessage::factory()->create([
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->getKey(),
            'context_type' => $batch->getMorphClass(),
            'context_id' => $batch->getKey(),
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'message_type' => ContactPermissionInvitationService::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'status' => ScheduledMessage::STATUS_PENDING,
        ]);

        $invitation = ContactPermissionInvitation::query()->create([
            'contact_id' => $contact->getKey(),
            'scheduled_message_id' => $message->getKey(),
            'token' => 'claimed-token',
            'channel' => ContactPermissionInvitation::CHANNEL_EMAIL,
            'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
            'status' => ContactPermissionInvitation::STATUS_CLAIMED,
            'claimed_at' => now()->subMinute(),
            'meta' => [],
        ]);

        $skipped = app(SkipScheduledMessagesAction::class)
            ->importedContactPermissionInvitationsForImportBatch($batch);

        $this->assertSame(1, $skipped);

        $message->refresh();
        $invitation->refresh();

        $terminalResult = ScheduledMessageTerminalResult::fromScheduledMessage(
            $message->load('terminalOutboxEvent.deliveryAttempt'),
        );

        $this->assertSame(ScheduledMessage::STATUS_SKIPPED, $message->status);
        $this->assertParentDeliverySummaryUnwritten($message);
        $this->assertSame('permission_invitation_cancelled', $terminalResult->reason);
        $this->assertSame(ContactPermissionInvitation::STATUS_FAILED, $invitation->status);
        $this->assertSame('permission_invitation_cancelled', $invitation->failure_reason);
        $this->assertNotNull($invitation->failed_at);
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