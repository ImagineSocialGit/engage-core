<?php

namespace App\Modules\Webinars\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Webinars\Actions\CancelWebinarRegistrationAction;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

class WebinarRegistrationCancellationController extends Controller
{
    public function show(WebinarRegistration $registration): Response
    {
        $registration->loadMissing([
            'contact',
            'webinar',
            'webinar.webinarSeries',
        ]);

        $cancelUrl = URL::temporarySignedRoute(
            name: 'webinar.registration.cancellation.store',
            expiration: now()->addMinutes(30),
            parameters: [
                'registration' => $registration,
            ],
            absolute: false,
        );

        return response()->view('webinar.registration-cancellation-confirm', [
            'registration' => $registration,
            'cancelUrl' => $cancelUrl,
        ]);
    }

    public function store(
        WebinarRegistration $registration,
        CancelWebinarRegistrationAction $cancelWebinarRegistrationAction,
    ): Response {
        $registration = $cancelWebinarRegistrationAction->handle(
            registration: $registration,
            source: 'email_link_confirmation',
        );

        $registration->loadMissing([
            'contact',
            'webinar',
            'webinar.webinarSeries',
        ]);

        return response()->view('webinar.registration-cancelled', [
            'registration' => $registration,
        ]);
    }

}