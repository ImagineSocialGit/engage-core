<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Events\ScheduledMessageSkipped;
use App\Modules\Messaging\Models\ContactPermissionInvitation;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\ContactPermissionInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarkClaimedPermissionInvitationFailedAfterScheduledMessageSkippedTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_a_claimed_permission_invitation_failed_when_its_scheduled_message_is_skipped(): void
    {
        $contact = Contact::factory()->create([
            'source' => 'import',
            'email' => 'imported@example.test',
        ]);

        $scheduledMessage = $this->permissionInvitationMessage($contact, [
            'status' => ScheduledMessage::STATUS_SKIPPED,
            'skipped_at' => now(),
            'skip_reason' => 'Message payload contains unresolved token(s): {missing_token}.',
        ]);

        $invitation = ContactPermissionInvitation::query()->create([
            'contact_id' => $contact->id,
            'scheduled_message_id' => $scheduledMessage->id,
            'token' => 'claimed-token',
            'channel' => ContactPermissionInvitation::CHANNEL_EMAIL,
            'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
            'status' => ContactPermissionInvitation::STATUS_CLAIMED,
            'claimed_at' => now()->subMinute(),
            'meta' => [],
        ]);

        event(new ScheduledMessageSkipped($scheduledMessage));

        $invitation->refresh();

        $this->assertSame(ContactPermissionInvitation::STATUS_FAILED, $invitation->status);
        $this->assertNotNull($invitation->failed_at);
        $this->assertSame($scheduledMessage->skip_reason, $invitation->failure_reason);
        $this->assertSame($scheduledMessage->id, $invitation->scheduled_message_id);
    }

    public function test_it_does_not_create_an_invitation_when_a_permission_invitation_message_is_skipped_before_claim(): void
    {
        $contact = Contact::factory()->create([
            'source' => 'import',
            'email' => 'imported@example.test',
        ]);

        $scheduledMessage = $this->permissionInvitationMessage($contact, [
            'status' => ScheduledMessage::STATUS_SKIPPED,
            'skipped_at' => now(),
            'skip_reason' => 'Message eligibility gate denied send.',
        ]);

        event(new ScheduledMessageSkipped($scheduledMessage));

        $this->assertDatabaseMissing('contact_permission_invitations', [
            'contact_id' => $contact->id,
            'scheduled_message_id' => $scheduledMessage->id,
        ]);
    }

    public function test_it_does_not_change_an_existing_invitation_owned_by_a_different_scheduled_message(): void
    {
        $contact = Contact::factory()->create([
            'source' => 'import',
            'email' => 'imported@example.test',
        ]);

        $originalMessage = $this->permissionInvitationMessage($contact, [
            'status' => ScheduledMessage::STATUS_SENT,
            'sent_at' => now()->subMinutes(5),
        ]);

        $existingInvitation = ContactPermissionInvitation::query()->create([
            'contact_id' => $contact->id,
            'scheduled_message_id' => $originalMessage->id,
            'token' => 'existing-token',
            'channel' => ContactPermissionInvitation::CHANNEL_EMAIL,
            'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
            'status' => ContactPermissionInvitation::STATUS_SENT,
            'claimed_at' => now()->subMinutes(6),
            'sent_at' => now()->subMinutes(5),
            'meta' => [],
        ]);

        $duplicateMessage = $this->permissionInvitationMessage($contact, [
            'status' => ScheduledMessage::STATUS_SKIPPED,
            'skipped_at' => now(),
            'skip_reason' => 'Imported contact permission invitation was already used.',
        ]);

        event(new ScheduledMessageSkipped($duplicateMessage));

        $existingInvitation->refresh();

        $this->assertSame(ContactPermissionInvitation::STATUS_SENT, $existingInvitation->status);
        $this->assertNull($existingInvitation->failed_at);
        $this->assertNull($existingInvitation->failure_reason);
        $this->assertSame($originalMessage->id, $existingInvitation->scheduled_message_id);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function permissionInvitationMessage(Contact $contact, array $attributes = []): ScheduledMessage
    {
        return ScheduledMessage::factory()->create(array_replace([
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'message_type' => ContactPermissionInvitationService::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'payload_class' => EmailPayload::class,
            'payload' => [
                'to' => $contact->email,
                'subject' => 'Confirm your preferences',
                'body' => 'Please confirm your communication preferences.',
            ],
            'status' => ScheduledMessage::STATUS_PENDING,
            'meta' => [
                'conditions' => [],
                'consent_policy' => [
                    'permission_invitation' => [
                        'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
                        'one_time' => true,
                    ],
                ],
            ],
        ], $attributes));
    }
}
