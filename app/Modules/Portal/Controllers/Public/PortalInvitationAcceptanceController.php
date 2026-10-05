<?php

namespace App\Modules\Portal\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Actions\AcceptPortalInvitationAction;
use App\Modules\Portal\Actions\AuthenticatePortalUserAction;
use App\Modules\Portal\Models\PortalInvitation;
use App\Modules\Portal\Services\PortalPresentationResolver;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

final class PortalInvitationAcceptanceController extends Controller
{
    public function show(PortalInvitation $invitation, string $token, PortalPresentationResolver $presentation): View
    {
        abort_unless($this->available($invitation), 404);

        return view('portal.invitations.accept', [
            'invitation' => $invitation,
            'token' => $token,
            'emailLocked' => filled($invitation->email),
            'presentation' => $presentation->resolve('Activate account'),
        ]);
    }

    public function store(
        Request $request,
        PortalInvitation $invitation,
        string $token,
        AcceptPortalInvitationAction $accept,
        AuthenticatePortalUserAction $authenticate,
    ): RedirectResponse {
        abort_unless($this->available($invitation), 404);

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:100'],
            'password' => ['required', 'confirmed', 'string', 'min:8', 'max:255'],
        ];

        if (! filled($invitation->email)) {
            $rules['email'] = ['required', 'email', 'max:255'];
        }

        $data = $request->validate($rules);

        try {
            $user = $accept->handle(
                invitation: $invitation,
                token: $token,
                name: (string) $data['name'],
                password: (string) $data['password'],
                email: filled($invitation->email) ? $invitation->email : (string) ($data['email'] ?? ''),
                phone: isset($data['phone']) ? (string) $data['phone'] : null,
                acceptedIp: $request->ip(),
                acceptedUserAgent: $request->userAgent(),
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'invitation' => $exception->getMessage(),
            ]);
        }

        $authenticated = $authenticate->handle(
            email: (string) $user->email,
            password: (string) $data['password'],
        );

        if ($authenticated === null) {
            return redirect()->route('portal.login');
        }

        $request->session()->regenerate();

        return redirect()->route('portal.home');
    }

    private function available(PortalInvitation $invitation): bool
    {
        return $invitation->status === PortalInvitation::STATUS_SENT
            && ($invitation->expires_at === null || $invitation->expires_at->isFuture());
    }
}