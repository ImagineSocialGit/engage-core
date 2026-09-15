<?php

namespace App\Modules\Webinars\Contracts;

use App\Modules\Webinars\Models\Webinar;

interface WebinarRegistrationEligibilityProvider
{
    public function registrationBlockReason(Webinar $webinar, string $email): ?string;
}