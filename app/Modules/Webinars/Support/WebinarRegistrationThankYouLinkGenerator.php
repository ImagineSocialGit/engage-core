<?php

namespace App\Modules\Webinars\Support;

use App\Modules\Webinars\Models\WebinarRegistration;
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

        return $this->routeOrigin($seriesSlug).$path;
    }

    private function routeOrigin(string $seriesSlug): string
    {
        $showUrl = route('webinar.show', [
            'seriesSlug' => $seriesSlug,
        ]);
        $scheme = parse_url($showUrl, PHP_URL_SCHEME);
        $host = parse_url($showUrl, PHP_URL_HOST);
        $port = parse_url($showUrl, PHP_URL_PORT);

        if (! is_string($scheme)
            || trim($scheme) === ''
            || ! is_string($host)
            || trim($host) === ''
        ) {
            throw new LogicException(
                'The Webinar route origin could not be resolved.',
            );
        }

        return sprintf(
            '%s://%s%s',
            $scheme,
            $host,
            is_int($port) ? ':'.$port : '',
        );
    }
}