<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Webinars\Contracts\WebinarRegistrationEligibilityProvider;
use App\Modules\Webinars\Models\Webinar;
use Throwable;

class WebinarRegistrationEligibilityService
{
    public function __construct(
        private readonly WebinarProviderManager $providerManager,
    ) {}

    public function blockReason(Webinar $webinar, string $email): ?string
    {
        $email = mb_strtolower(trim($email));

        if (
            $email === ''
            || blank($webinar->providerKey())
            || blank($webinar->external_id)
        ) {
            return null;
        }

        try {
            $provider = $this->providerManager->forWebinar($webinar);

            if (! $provider instanceof WebinarRegistrationEligibilityProvider) {
                return null;
            }

            return $provider->registrationBlockReason($webinar, $email);
        } catch (Throwable) {
            // Eligibility is a user-experience preflight, not the durable
            // provider submission. If it cannot be checked, let normal
            // finalization perform and classify the real provider request.
            return null;
        }
    }
}