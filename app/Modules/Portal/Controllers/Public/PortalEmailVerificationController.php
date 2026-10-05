<?php

namespace App\Modules\Portal\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Contracts\PortalAccountNotificationTransport;
use App\Modules\Portal\Services\PortalAuthContext;
use App\Modules\Portal\Services\PortalPresentationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

final class PortalEmailVerificationController extends Controller
{
    public function notice(
        PortalAuthContext $context,
        PortalAccountNotificationTransport $transport,
        PortalPresentationResolver $presentation,
    ): View|RedirectResponse {
        $user = $context->requireUser();

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('portal.home');
        }

        return view('portal.auth.verify-email', [
            'portalUser' => $user,
            'deliveryAvailable' => $transport->available(),
            'presentation' => $presentation->resolve('Verify email'),
        ]);
    }

    public function send(
        PortalAuthContext $context,
        PortalAccountNotificationTransport $transport,
    ): RedirectResponse {
        $user = $context->requireUser();

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('portal.home');
        }

        abort_unless($transport->available(), 404);

        $url = URL::temporarySignedRoute(
            'portal.verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
            ],
        );

        $transport->deliverEmailVerification($user, $url);

        return back()->with(
            'status',
            'If this email can receive messages, a verification link will be sent.',
        );
    }

    public function verify(
        Request $request,
        int $id,
        string $hash,
        PortalAuthContext $context,
    ): RedirectResponse {
        abort_unless($request->hasValidSignature(), 403);

        $user = $context->requireUser();

        abort_unless((int) $user->getKey() === $id, 403);
        abort_unless(
            hash_equals(sha1($user->getEmailForVerification()), $hash),
            403,
        );

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return redirect()->route('portal.home');
    }
}