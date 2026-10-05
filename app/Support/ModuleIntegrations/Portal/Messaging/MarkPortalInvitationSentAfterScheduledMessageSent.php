<?php

namespace App\Support\ModuleIntegrations\Portal\Messaging;

use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Portal\Actions\MarkPortalInvitationSentAction;
use App\Modules\Portal\Models\PortalInvitation;
use DomainException;

final class MarkPortalInvitationSentAfterScheduledMessageSent
{
    public function __construct(
        private readonly MarkPortalInvitationSentAction $markSent,
    ) {}

    public function handle(ScheduledMessageSent $event): void
    {
        $message = $event->scheduledMessage;

        if ($message->scope !== 'portal'
            || $message->message_type !== 'portal_invitation'
        ) {
            return;
        }

        $invitation = $message->context;

        if (! $invitation instanceof PortalInvitation
            || $invitation->status !== PortalInvitation::STATUS_PENDING
        ) {
            return;
        }

        try {
            $this->markSent->handle(
                invitation: $invitation,
                sentAt: $event->terminalResult->occurredAt,
            );
        } catch (DomainException) {
            // A late terminal event must not resurrect an expired/revoked invitation.
        }
    }
}