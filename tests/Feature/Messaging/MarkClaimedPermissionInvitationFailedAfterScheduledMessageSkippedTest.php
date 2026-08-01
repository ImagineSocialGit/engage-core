<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Data\Delivery\ScheduledMessageTerminalResult;
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
        ]);
        $occurredAt = now()->subMinute()->toImmutable();

        $invitation = ContactPermissionInvitation::query()->create([
            'contact_id' => $contact->id,
            'scheduled_message_id' => $scheduledMessage->id,
            'token' => 'claimed-token',
            'channel' => ContactPermissionInvitation::CHANNEL_EMAIL,
            'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
            'status' => ContactPermissionInvitation::STATUS_CLAIMED,
            'claimed_at' => now()->subMinutes(2),
            'meta' => [],
        ]);

        event(new ScheduledMessageSkipped(
            $scheduledMessage,
            $this->skippedResult(
                $scheduledMessage,
                $occurredAt,
                'Message payload contains unresolved token(s): {missing_token}.',
            ),
        ));

        $invitation->refresh();

        $this->assertSame(ContactPermissionInvitation::STATUS_FAILED, $invitation->status);
        $this->assertSame(
            $occurredAt->copy()->startOfSecond()->toISOString(),
            $invitation->failed_at?->copy()->startOfSecond()->toISOString(),
        );
        $this->assertSame(
            'Message payload contains unresolved token(s): {missing_token}.',
            $invitation->failure_reason,
        );
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
        ]);

        event(new ScheduledMessageSkipped(
            $scheduledMessage,
            $this->skippedResult(
                $scheduledMessage,
                now()->toImmutable(),
                'Message eligibility gate denied send.',
            ),
        ));

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
        ]);

        event(new ScheduledMessageSkipped(
            $duplicateMessage,
            $this->skippedResult(
                $duplicateMessage,
                now()->toImmutable(),
                'Imported contact permission invitation was already used.',
            ),
        ));

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

    private function skippedResult(
        ScheduledMessage $scheduledMessage,
        \Carbon\CarbonImmutable $occurredAt,
        string $reason,
    ): ScheduledMessageTerminalResult {
        return new ScheduledMessageTerminalResult(
            scheduledMessageId: (int) $scheduledMessage->getKey(),
            status: ScheduledMessage::STATUS_SKIPPED,
            occurredAt: $occurredAt,
            reasonCode: 'permission_invitation_skipped',
            reason: $reason,
        );
    }
}