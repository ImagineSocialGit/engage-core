<?php

namespace App\Modules\Webinars\Support;

use App\Modules\Webinars\Models\WebinarRegistration;
use App\Support\Urls\AbsoluteUrl;
use Illuminate\Support\Facades\URL;
use LogicException;

class WebinarRegistrationThankYouLinkGenerator
{
    public function forRegistration(WebinarRegistration $registration): string
    {
        $registration->loadMissing('webinar.webinarSeries');

        $seriesSlug = $registration->webinar?->webinarSeries?->slug;

        if (! is_string($seriesSlug) || trim($seriesSlug) === '') {
            throw new LogicException(
                'A Webinar registration thank-you link requires a Webinar series slug.',
            );
        }

        $expirationMinutes = max(
            5,
            (int) config(
                'webinars.registration.thank_you.link_expiration_minutes',
                10080,
            ),
        );

        $path = URL::temporarySignedRoute(
            name: 'webinar.thank-you',
            expiration: now()->addMinutes($expirationMinutes),
            parameters: [
                'seriesSlug' => $seriesSlug,
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