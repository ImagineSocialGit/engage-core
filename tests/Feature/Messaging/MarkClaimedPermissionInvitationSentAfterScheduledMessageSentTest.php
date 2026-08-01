<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Data\Delivery\ScheduledMessageTerminalResult;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Models\ContactPermissionInvitation;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\ContactPermissionInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarkClaimedPermissionInvitationSentAfterScheduledMessageSentTest extends TestCase
{
    use RefreshDatabase;

    public function test_claimed_invitation_uses_the_terminal_event_occurrence(): void
    {
        $contact = Contact::factory()->create([
            'source' => 'import',
            'email' => 'sent-import@example.test',
        ]);
        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->getKey(),
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
            'status' => ScheduledMessage::STATUS_SENT,
            'meta' => [
                'conditions' => [],
                'consent_policy' => [
                    'permission_invitation' => [
                        'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
                        'one_time' => true,
                    ],
                ],
            ],
        ]);
        $invitation = ContactPermissionInvitation::query()->create([
            'contact_id' => $contact->getKey(),
            'scheduled_message_id' => $scheduledMessage->getKey(),
            'token' => 'sent-claimed-token',
            'channel' => ContactPermissionInvitation::CHANNEL_EMAIL,
            'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
            'status' => ContactPermissionInvitation::STATUS_CLAIMED,
            'claimed_at' => now()->subMinute(),
            'meta' => [],
        ]);
        $occurredAt = now()->subSeconds(15)->toImmutable();

        event(new ScheduledMessageSent(
            $scheduledMessage,
            new ScheduledMessageTerminalResult(
                scheduledMessageId: (int) $scheduledMessage->getKey(),
                status: ScheduledMessage::STATUS_SENT,
                occurredAt: $occurredAt,
                deliveryAttemptId: 91,
                attemptNumber: 2,
                provider: 'permission_invitation_test',
                providerMessageId: 'permission-invitation-provider-message',
            ),
        ));

        $invitation->refresh();

        $this->assertSame(
            ContactPermissionInvitation::STATUS_SENT,
            $invitation->status,
        );
        $this->assertSame(
            $occurredAt->copy()->startOfSecond()->toISOString(),
            $invitation->sent_at?->copy()->startOfSecond()->toISOString(),
        );
        $this->assertNull($invitation->failed_at);
        $this->assertNull($invitation->failure_reason);
    }
}