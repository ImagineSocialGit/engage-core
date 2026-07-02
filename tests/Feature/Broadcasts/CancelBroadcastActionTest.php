<?php

namespace Tests\Feature\Broadcasts;

use App\Modules\Broadcasts\Actions\CancelBroadcastAction;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ContactPermissionInvitation;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\ContactPermissionInvitationService;
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

    public function test_it_does_not_create_permission_invitation_rows_when_cancelled_before_send(): void
    {
        $broadcast = Broadcast::factory()->scheduled()->create([
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'dispatch_key' => Broadcast::PERMISSION_INVITATION_DISPATCH_KEY,
            'message_type' => Broadcast::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'recipient_filter' => [
                'type' => 'imported',
            ],
        ]);

        $contact = Contact::factory()->create([
            'source' => 'import',
            'email' => 'imported@example.com',
        ]);

        $recipient = BroadcastRecipient::factory()->scheduled()->create([
            'broadcast_id' => $broadcast->id,
            'contact_id' => $contact->id,
        ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->id,
            'context_type' => $broadcast->getMorphClass(),
            'context_id' => $broadcast->id,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'message_type' => ContactPermissionInvitationService::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'status' => ScheduledMessage::STATUS_PENDING,
            'meta' => [
                'consent_policy' => [
                    'permission_invitation' => [
                        'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
                        'one_time' => true,
                    ],
                ],
            ],
        ]);

        $cancelledBroadcast = app(CancelBroadcastAction::class)->handle($broadcast);

        $this->assertSame(Broadcast::STATUS_CANCELLED, $cancelledBroadcast->status);

        $recipient->refresh();
        $this->assertSame(BroadcastRecipient::STATUS_CANCELLED, $recipient->status);
        $this->assertSame('broadcast_cancelled', $recipient->skip_reason);

        $scheduledMessage->refresh();
        $this->assertSame(ScheduledMessage::STATUS_SKIPPED, $scheduledMessage->status);
        $this->assertSame('broadcast_cancelled', $scheduledMessage->skip_reason);

        $this->assertDatabaseMissing('contact_permission_invitations', [
            'contact_id' => $contact->id,
            'channel' => ContactPermissionInvitation::CHANNEL_EMAIL,
            'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
        ]);
    }

    public function test_it_preserves_existing_permission_invitation_rows_when_broadcast_is_cancelled(): void
    {
        $broadcast = Broadcast::factory()->scheduled()->create([
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'dispatch_key' => Broadcast::PERMISSION_INVITATION_DISPATCH_KEY,
            'message_type' => Broadcast::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'recipient_filter' => [
                'type' => 'imported',
            ],
        ]);

        $contact = Contact::factory()->create([
            'source' => 'import',
            'email' => 'claimed@example.com',
        ]);

        $sentMessage = ScheduledMessage::factory()->create([
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->id,
            'context_type' => $broadcast->getMorphClass(),
            'context_id' => $broadcast->id,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'message_type' => ContactPermissionInvitationService::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'status' => ScheduledMessage::STATUS_SENT,
            'sent_at' => now()->subMinutes(5),
        ]);

        $invitation = ContactPermissionInvitation::query()->create([
            'contact_id' => $contact->id,
            'scheduled_message_id' => $sentMessage->id,
            'token' => 'existing-token',
            'channel' => ContactPermissionInvitation::CHANNEL_EMAIL,
            'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
            'status' => ContactPermissionInvitation::STATUS_SENT,
            'claimed_at' => now()->subMinutes(6),
            'sent_at' => now()->subMinutes(5),
        ]);

        $sentRecipient = BroadcastRecipient::factory()->create([
            'broadcast_id' => $broadcast->id,
            'contact_id' => $contact->id,
            'status' => BroadcastRecipient::STATUS_SENT,
            'scheduled_message_ids' => [$sentMessage->id],
            'sent_at' => now()->subMinutes(5),
        ]);

        app(CancelBroadcastAction::class)->handle($broadcast);

        $sentMessage->refresh();
        $this->assertSame(ScheduledMessage::STATUS_SENT, $sentMessage->status);

        $sentRecipient->refresh();
        $this->assertSame(BroadcastRecipient::STATUS_SENT, $sentRecipient->status);
        $this->assertNull($sentRecipient->skip_reason);

        $invitation->refresh();
        $this->assertSame(ContactPermissionInvitation::STATUS_SENT, $invitation->status);
        $this->assertSame($sentMessage->id, $invitation->scheduled_message_id);
        $this->assertSame('existing-token', $invitation->token);

        $this->assertSame(1, ContactPermissionInvitation::query()
            ->where('contact_id', $contact->id)
            ->where('channel', ContactPermissionInvitation::CHANNEL_EMAIL)
            ->where('source', ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT)
            ->count());
    }
}