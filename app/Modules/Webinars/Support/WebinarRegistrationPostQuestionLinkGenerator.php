<?php

namespace App\Modules\Webinars\Support;

use App\Modules\Webinars\Models\WebinarRegistration;
use App\Support\Urls\AbsoluteUrl;
use Illuminate\Support\Facades\URL;
use LogicException;

class WebinarRegistrationPostQuestionLinkGenerator
{
    public function forRegistration(WebinarRegistration $registration): string
    {
        return AbsoluteUrl::join(
            config('app.webinar_url', config('app.url')),
            $this->signedPath(
                routeName: 'webinar.registration.questions.show',
                registration: $registration,
            ),
        );
    }

    public function formAction(WebinarRegistration $registration): string
    {
        return $this->signedPath(
            routeName: 'webinar.registration.questions.store',
            registration: $registration,
        );
    }

    private function signedPath(
        string $routeName,
        WebinarRegistration $registration,
    ): string {
        $registration->loadMissing('webinar.webinarSeries');
        $seriesSlug = $registration->webinar?->webinarSeries?->slug;

        if (! is_string($seriesSlug) || trim($seriesSlug) === '') {
            throw new LogicException(
                'A Webinar post-registration question link requires a Webinar series slug.',
            );
        }

        $expirationMinutes = max(
            5,
            (int) config(
                'webinars.registration.thank_you.link_expiration_minutes',
                10080,
            ),
        );

        return URL::temporarySignedRoute(
            name: $routeName,
            expiration: now()->addMinutes($expirationMinutes),
            parameters: [
                'seriesSlug' => $seriesSlug,
                'registration' => $registration,
            ],
            absolute: false,
        );
    }
}