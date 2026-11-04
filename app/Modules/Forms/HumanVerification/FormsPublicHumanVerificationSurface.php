<?php

namespace App\Modules\Forms\HumanVerification;

use App\Support\HumanVerification\Contracts\RequiredPublicHumanVerificationSurface;

final class FormsPublicHumanVerificationSurface implements RequiredPublicHumanVerificationSurface
{
    public function moduleKey(): string
    {
        return 'forms';
    }

    public function surface(): string
    {
        return 'forms';
    }

    public function action(): string
    {
        return 'forms';
    }
}