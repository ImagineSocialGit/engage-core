<?php

namespace App\Modules\Portal\Actions;

use App\Modules\Portal\Contracts\PortalAccountNotificationTransport;
use App\Modules\Portal\Data\PortalInvitationToken;
use App\Modules\Portal\Models\PortalInvitation;
use DomainException;

final class DeliverPortalInvitationAction
{
    public function __construct(
        private readonly PortalAccountNotificationTransport $transport,
    ) {}

    public function handle(PortalInvitationToken $issued): PortalInvitation
    {
        if (! $this->transport->available()) {
            throw new DomainException(
                'Portal account notification delivery is unavailable.',
            );
        }

        if (! $this->transport->deliverInvitation(
            invitation: $issued->invitation,
            token: $issued->token,
        )) {
            throw new DomainException(
                'Portal invitation could not be scheduled for delivery.',
            );
        }

        return $issued->invitation->fresh() ?? $issued->invitation;
    }
}