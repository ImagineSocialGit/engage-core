<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Data\Delivery\ScheduledMessageTerminalResult;
use App\Modules\Messaging\Events\ScheduledMessageFailed;
use App\Modules\Messaging\Models\ContactPermissionInvitation;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\ContactPermissionInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarkClaimedPermissionInvitationFailedAfterScheduledMessageFailedTest extends TestCase
{
    use RefreshDatabase;

    public function test_claimed_invitation_uses_the_terminal_event_failure_reason(): void
    {
        $contact = Contact::factory()->create([
            'source' => 'import',
            'email' => 'failed-import@example.test',
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
            'status' => ScheduledMessage::STATUS_FAILED,
            'failed_at' => now()->subHour(),
            'failure_reason' => 'Legacy scheduled-message failure.',
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
            'token' => 'failed-claimed-token',
            'channel' => ContactPermissionInvitation::CHANNEL_EMAIL,
            'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
            'status' => ContactPermissionInvitation::STATUS_CLAIMED,
            'claimed_at' => now()->subMinute(),
            'meta' => [],
        ]);

        event(new ScheduledMessageFailed(
            $scheduledMessage,
            new ScheduledMessageTerminalResult(
                scheduledMessageId: (int) $scheduledMessage->getKey(),
                status: ScheduledMessage::STATUS_FAILED,
                occurredAt: now()->toImmutable(),
                deliveryAttemptId: 72,
                attemptNumber: 3,
                provider: 'permission_invitation_test',
                reasonCode: 'provider_rejected',
                reason: 'Authoritative permission invitation failure.',
            ),
        ));

        $invitation->refresh();

        $this->assertSame(
            ContactPermissionInvitation::STATUS_FAILED,
            $invitation->status,
        );
        $this->assertNotNull($invitation->failed_at);
        $this->assertSame(
            'Authoritative permission invitation failure.',
            $invitation->failure_reason,
        );
        $this->assertSame(
            $scheduledMessage->getKey(),
            $invitation->scheduled_message_id,
        );
    }
}