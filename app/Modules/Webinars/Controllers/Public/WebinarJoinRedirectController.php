<?php

namespace App\Modules\Webinars\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Webinars\Actions\ResolveWebinarJoinUrlAction;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Support\WebinarJoinBrowserProof;
use App\Modules\Webinars\Support\WebinarRegistrationThankYouLinkGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class WebinarJoinRedirectController extends Controller
{
    public function show(
        string $token,
        ResolveWebinarJoinUrlAction $resolveWebinarJoinUrlAction,
        WebinarRegistrationThankYouLinkGenerator $thankYouLinks,
    ): Response|RedirectResponse {
        $registration = $this->registrationForToken($token);
        $destination = $resolveWebinarJoinUrlAction->handle($registration);

        if (blank($destination)) {
            return $this->replacementRecoveryResponse(
                registration: $registration,
                resolveWebinarJoinUrlAction: $resolveWebinarJoinUrlAction,
                thankYouLinks: $thankYouLinks,
            );
        }

        $displayRegistration = $resolveWebinarJoinUrlAction
            ->canonicalRegistration($registration);

        if (
            $displayRegistration->webinar
            && ! $displayRegistration->is($registration)
        ) {
            $registration->setRelation(
                'webinar',
                $displayRegistration->webinar,
            );
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
            'displayRegistration' => $displayRegistration,
            'continueUrl' => $continueUrl,
        ]);
    }

    public function store(
        string $token,
        Request $request,
        ResolveWebinarJoinUrlAction $resolveWebinarJoinUrlAction,
        WebinarJoinBrowserProof $browserProof,
        WebinarRegistrationThankYouLinkGenerator $thankYouLinks,
    ): RedirectResponse {
        $registration = $this->registrationForToken($token);
        $submittedProof = trim((string) $request->input('browser_proof', ''));

        if (
            $submittedProof !== ''
            && ! $browserProof->validFor($submittedProof, $registration)
        ) {
            return redirect()
                ->route('webinar.join.redirect', [
                    'token' => $registration->join_token,
                ])
                ->with(
                    'join_auto_continue_failed',
                    'Automatic continuation expired. Use the button below to continue safely.',
                );
        }

        $destination = $resolveWebinarJoinUrlAction->execute($registration);

        if (blank($destination)) {
            return $this->replacementRecoveryResponse(
                registration: $registration,
                resolveWebinarJoinUrlAction: $resolveWebinarJoinUrlAction,
                thankYouLinks: $thankYouLinks,
            );
        }

        return redirect()->away($destination);
    }

    private function replacementRecoveryResponse(
        WebinarRegistration $registration,
        ResolveWebinarJoinUrlAction $resolveWebinarJoinUrlAction,
        WebinarRegistrationThankYouLinkGenerator $thankYouLinks,
    ): RedirectResponse {
        if (! $resolveWebinarJoinUrlAction->requiresReplacementRecovery($registration)) {
            throw new NotFoundHttpException(
                'No join URL is available for this registration.',
            );
        }

        return redirect()->to(
            $thankYouLinks->forRegistration(
                $resolveWebinarJoinUrlAction->canonicalRegistration($registration),
            ),
        );
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