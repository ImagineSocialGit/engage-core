<?php

namespace App\Modules\Webinars\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Webinars\Actions\ResolveWebinarJoinUrlAction;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class WebinarJoinRedirectController extends Controller
{
    public function show(
        string $token,
        ResolveWebinarJoinUrlAction $resolveWebinarJoinUrlAction,
    ): Response {
        $registration = $this->registrationForToken($token);
        $destination = $resolveWebinarJoinUrlAction->handle($registration);

        if (blank($destination)) {
            throw new NotFoundHttpException('No join URL is available for this registration.');
        }

        $continueUrl = URL::temporarySignedRoute(
            name: 'webinar.join.continue',
            expiration: now()->addMinutes($this->confirmationLinkExpirationMinutes()),
            parameters: [
                'token' => $registration->join_token,
            ],
            absolute: false,
        );

        return response()->view('webinar.join-confirm', [
            'registration' => $registration,
            'continueUrl' => $continueUrl,
        ]);
    }

    public function store(
        string $token,
        ResolveWebinarJoinUrlAction $resolveWebinarJoinUrlAction,
    ): RedirectResponse {
        $registration = $this->registrationForToken($token);
        $destination = $resolveWebinarJoinUrlAction->execute($registration);

        if (blank($destination)) {
            throw new NotFoundHttpException('No join URL is available for this registration.');
        }

        return redirect()->away($destination);
    }

    private function registrationForToken(string $token): WebinarRegistration
    {
        return WebinarRegistration::query()
            ->with([
                'webinar',
                'webinar.webinarSeries',
            ])
            ->where('join_token', $token)
            ->firstOrFail();
    }

    private function confirmationLinkExpirationMinutes(): int
    {
        return max(
            1,
            (int) config(
                'webinars.registration.join_confirmation.link_expiration_minutes',
                30,
            ),
        );
    }
}