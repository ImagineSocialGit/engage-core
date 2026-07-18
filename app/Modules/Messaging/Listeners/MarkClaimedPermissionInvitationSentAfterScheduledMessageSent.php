<?php

namespace App\Modules\Messaging\Listeners;

use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Models\ContactPermissionInvitation;
use App\Modules\Messaging\Services\ContactPermissionInvitationService;
use Illuminate\Support\Facades\DB;

class MarkClaimedPermissionInvitationSentAfterScheduledMessageSent
{
    public function __construct(
        private readonly ContactPermissionInvitationService $permissionInvitationService,
    ) {}

    public function handle(ScheduledMessageSent $event): void
    {
        DB::transaction(function () use ($event): void {
            $scheduledMessage = $event->scheduledMessage;

            $invitation = ContactPermissionInvitation::query()
                ->where('scheduled_message_id', $scheduledMessage->getKey())
                ->where('channel', ContactPermissionInvitation::CHANNEL_EMAIL)
                ->where('source', ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT)
                ->where('status', ContactPermissionInvitation::STATUS_CLAIMED)
                ->lockForUpdate()
                ->first();

            if (! $invitation) {
                return;
            }

            $this->permissionInvitationService->markSent(
                invitation: $invitation,
                scheduledMessage: $scheduledMessage,
            );
        });
    }
}