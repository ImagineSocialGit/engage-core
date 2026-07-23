<?php

namespace App\Modules\Webinars\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Webinars\Actions\CancelWebinarRegistrationAction;
use App\Modules\Webinars\Actions\ResolveWebinarRegistrationReplacementChainAction;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarRegistrationCancellationPolicy;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use LogicException;

class WebinarRegistrationCancellationController extends Controller
{
    public function show(
        WebinarRegistration $registration,
        ResolveWebinarRegistrationReplacementChainAction $resolveReplacementChain,
        WebinarRegistrationCancellationPolicy $cancellationPolicy,
    ): Response {
        $chain = $resolveReplacementChain->handle($registration);

        abort_unless($chain->safeForPublicLifecycle(), 404);

        $cancellationState = $cancellationPolicy->stateFor($chain->canonical);
        $cancelUrl = $cancellationState
            === WebinarRegistrationCancellationPolicy::STATE_CANCELLABLE
                ? URL::temporarySignedRoute(
                    name: 'webinar.registration.cancellation.store',
                    expiration: now()->addMinutes(30),
                    parameters: [
                        'registration' => $chain->original,
                    ],
                    absolute: false,
                )
                : null;

        return $this->confirmationResponse(
            registration: $chain->canonical,
            cancellationState: $cancellationState,
            cancelUrl: $cancelUrl,
        );
    }

    public function store(
        WebinarRegistration $registration,
        CancelWebinarRegistrationAction $cancelWebinarRegistrationAction,
        ResolveWebinarRegistrationReplacementChainAction $resolveReplacementChain,
        WebinarRegistrationCancellationPolicy $cancellationPolicy,
    ): Response {
        $chain = $resolveReplacementChain->handle($registration);

        abort_unless($chain->safeForPublicLifecycle(), 404);

        $cancellationState = $cancellationPolicy->stateFor($chain->canonical);

        if (
            $cancellationState
            === WebinarRegistrationCancellationPolicy::STATE_INELIGIBLE
        ) {
            return $this->confirmationResponse(
                registration: $chain->canonical,
                cancellationState: $cancellationState,
            );
        }

        try {
            $registration = $cancelWebinarRegistrationAction->handle(
                registration: $registration,
                source: 'email_link_confirmation',
            );
        } catch (LogicException $exception) {
            $chain = $resolveReplacementChain->handle($registration);

            abort_unless($chain->safeForPublicLifecycle(), 404);

            $cancellationState = $cancellationPolicy->stateFor(
                $chain->canonical,
            );

            if (
                $cancellationState
                !== WebinarRegistrationCancellationPolicy::STATE_INELIGIBLE
            ) {
                throw $exception;
            }

            return $this->confirmationResponse(
                registration: $chain->canonical,
                cancellationState: $cancellationState,
            );
        }

        $registration->loadMissing([
            'contact',
            'webinar',
            'webinar.webinarSeries',
        ]);

        return response()->view('webinar.registration-cancelled', [
            'registration' => $registration,
        ]);
    }

    private function confirmationResponse(
        WebinarRegistration $registration,
        string $cancellationState,
        ?string $cancelUrl = null,
    ): Response {
        $registration->loadMissing([
            'contact',
            'webinar',
            'webinar.webinarSeries',
        ]);

        return response()->view(
            'webinar.registration-cancellation-confirm',
            [
                'registration' => $registration,
                'cancellationState' => $cancellationState,
                'cancelUrl' => $cancelUrl,
            ],
        );
    }
}