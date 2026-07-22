<?php

namespace App\Modules\Webinars\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Webinars\Actions\CancelWebinarRegistrationAction;
use App\Modules\Webinars\Actions\ResolveWebinarRegistrationReplacementChainAction;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

class WebinarRegistrationCancellationController extends Controller
{
    public function show(
        WebinarRegistration $registration,
        ResolveWebinarRegistrationReplacementChainAction $resolveReplacementChain,
    ): Response {
        $chain = $resolveReplacementChain->handle($registration);

        abort_unless($chain->safeForPublicLifecycle(), 404);

        $cancelUrl = URL::temporarySignedRoute(
            name: 'webinar.registration.cancellation.store',
            expiration: now()->addMinutes(30),
            parameters: [
                'registration' => $chain->original,
            ],
            absolute: false,
        );

        return response()->view('webinar.registration-cancellation-confirm', [
            'registration' => $chain->canonical,
            'cancelUrl' => $cancelUrl,
        ]);
    }

    public function store(
        WebinarRegistration $registration,
        CancelWebinarRegistrationAction $cancelWebinarRegistrationAction,
        ResolveWebinarRegistrationReplacementChainAction $resolveReplacementChain,
    ): Response {
        abort_unless(
            $resolveReplacementChain
                ->handle($registration)
                ->safeForPublicLifecycle(),
            404,
        );

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