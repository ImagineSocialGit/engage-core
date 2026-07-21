<?php

namespace App\Modules\Webinars\Support;

use App\Modules\Webinars\Models\WebinarRegistration;
use App\Support\Urls\AbsoluteUrl;
use Illuminate\Support\Facades\URL;

class WebinarRegistrationCancelLinkGenerator
{
    public function forRegistration(WebinarRegistration $registration): string
    {
        $path = URL::temporarySignedRoute(
            name: 'webinar.registration.cancellation.show',
            expiration: now()->addDays(30),
            parameters: [
                'registration' => $registration,
            ],
            absolute: false,
        );

        return AbsoluteUrl::join(
            config('app.webinar_url', config('app.url')),
            $path,
        );
    }
}