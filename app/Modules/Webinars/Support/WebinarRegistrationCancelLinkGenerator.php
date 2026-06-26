<?php

namespace App\Modules\Webinars\Support;

use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Support\Facades\URL;

class WebinarRegistrationCancelLinkGenerator
{
    public function forRegistration(WebinarRegistration $registration): string
    {
        $path = URL::temporarySignedRoute(
            name: 'webinar.registration.cancel',
            expiration: now()->addDays(30),
            parameters: [
                'registration' => $registration,
            ],
            absolute: false,
        );

        return rtrim(config('app.webinar_url', config('app.url')), '/').$path;
    }
}