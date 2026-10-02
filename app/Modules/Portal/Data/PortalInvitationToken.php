<?php

namespace App\Modules\Portal\Data;

use App\Modules\Portal\Models\PortalInvitation;

final readonly class PortalInvitationToken
{
    public function __construct(
        public PortalInvitation $invitation,
        public string $token,
    ) {}
}