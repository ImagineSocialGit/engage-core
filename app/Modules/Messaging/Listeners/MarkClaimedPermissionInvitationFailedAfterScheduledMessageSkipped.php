<?php

namespace App\Modules\Messaging\Listeners;

use App\Modules\Messaging\Events\ScheduledMessageSkipped;
use App\Modules\Messaging\Models\ContactPermissionInvitation;
use App\Modules\Messaging\Services\ContactPermissionInvitationService;

class MarkClaimedPermissionInvitationFailedAfterScheduledMessageSkipped
{
    public function __construct(
        private readonly ContactPermissionInvitationService $permissionInvitationService,
    ) {}

    public function handle(ScheduledMessageSkipped $event): void
    {
        $scheduledMessage = $event->scheduledMessage;

        $invitation = ContactPermissionInvitation::query()
            ->where('scheduled_message_id', $scheduledMessage->getKey())
            ->where('channel', ContactPermissionInvitation::CHANNEL_EMAIL)
            ->where('source', ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT)
            ->where('status', ContactPermissionInvitation::STATUS_CLAIMED)
            ->first();

        if (! $invitation) {
            return;
        }

        $reason = is_string($scheduledMessage->skip_reason)
            && trim($scheduledMessage->skip_reason) !== ''
                ? trim($scheduledMessage->skip_reason)
                : 'Scheduled permission invitation was skipped after claim.';

        $this->permissionInvitationService->markFailed(
            invitation: $invitation,
            scheduledMessage: $scheduledMessage,
            reason: $reason,
        );
    }
}