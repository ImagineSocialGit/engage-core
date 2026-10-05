<?php

namespace App\Modules\Portal\Contracts;

use App\Modules\Portal\Models\PortalInvitation;
use App\Modules\Portal\Models\PortalUser;

interface PortalAccountNotificationTransport
{
    public function available(): bool;

    public function deliverInvitation(
        PortalInvitation $invitation,
        string $token,
    ): bool;

    public function deliverEmailVerification(
        PortalUser $user,
        string $verificationUrl,
    ): bool;

    public function deliverPasswordReset(
        PortalUser $user,
        string $token,
    ): bool;
}