<?php

namespace App\Modules\Portal\Services;

use App\Modules\Portal\Contracts\PortalAccountNotificationTransport;
use App\Modules\Portal\Models\PortalInvitation;
use App\Modules\Portal\Models\PortalUser;

final class UnavailablePortalAccountNotificationTransport implements PortalAccountNotificationTransport
{
    public function available(): bool
    {
        return false;
    }

    public function deliverInvitation(PortalInvitation $invitation, string $token): bool
    {
        return false;
    }

    public function deliverEmailVerification(PortalUser $user, string $verificationUrl): bool
    {
        return false;
    }

    public function deliverPasswordReset(PortalUser $user, string $token): bool
    {
        return false;
    }
}