<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Data\ProviderRegistrationData;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarProviderManager;

class AddRegistrantToWebinarProviderAction
{
    public function __construct(
        private readonly WebinarProviderManager $webinarProviderManager,
    ) {}

    public function handle(Webinar $webinar, WebinarRegistration $registration): ProviderRegistrationData
    {
        return $this->webinarProviderManager
            ->forWebinar($webinar)
            ->registerAttendee($webinar, $registration);
    }
}